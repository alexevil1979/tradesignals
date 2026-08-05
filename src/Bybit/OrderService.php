<?php
declare(strict_types=1);

namespace App\Bybit;

use App\Helpers\Logger;
use PDO;
use RuntimeException;

final class OrderService
{
    public function __construct(
        private readonly Client $client,
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly ?InstrumentService $instruments = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function placeMarketOrder(
        string $symbol,
        string $side,
        string $quantity,
        ?string $takeProfit = null,
        ?string $stopLoss = null,
        ?int $strategyId = null,
        ?int $signalId = null,
        bool $reduceOnly = false,
        ?int $positionIdx = null,
    ): array {
        $orderLinkId = 'bot-' . bin2hex(random_bytes(12));
        $payload = array_filter([
            'category' => 'linear',
            'symbol' => $symbol,
            'side' => $side,
            'orderType' => 'Market',
            'qty' => $quantity,
            'orderLinkId' => $orderLinkId,
            'takeProfit' => $takeProfit,
            'stopLoss' => $stopLoss,
            'reduceOnly' => $reduceOnly,
            // Hedge mode, как в example/bbb: Buy=1 (Long), Sell=2 (Short).
            'positionIdx' => $positionIdx ?? self::hedgePositionIdx($side),
        ], static fn (mixed $value): bool => $value !== null && $value !== false);
        $response = $this->client->privateRequest('POST', '/v5/order/create', $payload);
        $result = $response['result'];

        $this->insertOrderRow(
            strategyId: $strategyId,
            signalId: $signalId,
            bybitOrderId: isset($result['orderId']) ? (string) $result['orderId'] : null,
            orderLinkId: $orderLinkId,
            symbol: $symbol,
            side: $side,
            orderType: 'Market',
            quantity: $quantity,
            price: null,
            takeProfit: $takeProfit,
            stopLoss: $stopLoss,
            reduceOnly: $reduceOnly,
            rawResponse: $response,
        );
        $this->logger->info('Рыночный ордер отправлен.', ['order_link_id' => $orderLinkId], 'trading');

        return $response;
    }

    /**
     * Лимитный ордер (GTC), опционально с TP/SL на момент создания.
     *
     * @return array<string, mixed>
     */
    public function placeLimitOrder(
        string $symbol,
        string $side,
        string $quantity,
        string $price,
        string $orderLinkId,
        ?string $takeProfit = null,
        ?string $stopLoss = null,
        ?int $strategyId = null,
        ?int $signalId = null,
    ): array {
        if ($this->instruments !== null) {
            $price = $this->instruments->formatPrice($symbol, (float) $price);
            if ($takeProfit !== null) {
                $takeProfit = $this->instruments->formatPrice($symbol, (float) $takeProfit);
            }
            if ($stopLoss !== null) {
                $stopLoss = $this->instruments->formatPrice($symbol, (float) $stopLoss);
            }
        }

        $payload = array_filter([
            'category' => 'linear',
            'symbol' => $symbol,
            'side' => $side,
            'orderType' => 'Limit',
            'qty' => $quantity,
            'price' => $price,
            'timeInForce' => 'GTC',
            'orderLinkId' => $orderLinkId,
            'takeProfit' => $takeProfit,
            'stopLoss' => $stopLoss,
            'reduceOnly' => false,
            // Hedge mode, как в example/bbb/bothour/bybit_bot.php.
            'positionIdx' => self::hedgePositionIdx($side),
        ], static fn (mixed $value): bool => $value !== null && $value !== false);

        $response = $this->client->privateRequest('POST', '/v5/order/create', $payload);
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];

        $this->insertOrderRow(
            strategyId: $strategyId,
            signalId: $signalId,
            bybitOrderId: isset($result['orderId']) ? (string) $result['orderId'] : null,
            orderLinkId: $orderLinkId,
            symbol: $symbol,
            side: $side,
            orderType: 'Limit',
            quantity: $quantity,
            price: $price,
            takeProfit: $takeProfit,
            stopLoss: $stopLoss,
            reduceOnly: false,
            rawResponse: $response,
        );
        $this->logger->info('Лимитный ордер отправлен.', [
            'order_link_id' => $orderLinkId,
            'side' => $side,
            'price' => $price,
            'qty' => $quantity,
        ], 'trading');

        return $response;
    }

    /** @return array<string, mixed> */
    public function cancelByLinkId(string $symbol, string $orderLinkId): array
    {
        try {
            $response = $this->client->privateRequest('POST', '/v5/order/cancel', [
                'category' => 'linear',
                'symbol' => $symbol,
                'orderLinkId' => $orderLinkId,
            ]);
        } catch (RuntimeException $exception) {
            $this->logger->warning('Не удалось отменить ордер.', [
                'order_link_id' => $orderLinkId,
                'error' => $exception->getMessage(),
            ], 'trading');

            return ['retCode' => -1, 'retMsg' => $exception->getMessage()];
        }

        $this->pdo->prepare(
            'UPDATE orders SET status = :status, updated_at = NOW() WHERE order_link_id = :link'
        )->execute([
            'status' => 'Cancelled',
            'link' => $orderLinkId,
        ]);
        $this->logger->info('Ордер отменён.', ['order_link_id' => $orderLinkId], 'trading');

        return $response;
    }

    /**
     * Открытые ордера по символу.
     *
     * @return list<array<string, mixed>>
     */
    public function getOpenOrders(string $symbol): array
    {
        $response = $this->client->privateRequest('GET', '/v5/order/realtime', [
            'category' => 'linear',
            'symbol' => $symbol,
            'openOnly' => 0,
            'limit' => 50,
        ]);
        $list = $response['result']['list'] ?? [];

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** Статус ордера по orderLinkId из realtime (или null). */
    public function findOpenOrderByLinkId(string $symbol, string $orderLinkId): ?array
    {
        foreach ($this->getOpenOrders($symbol) as $order) {
            if (($order['orderLinkId'] ?? '') === $orderLinkId) {
                return $order;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $position */
    public function closePosition(string $symbol, array $position, ?int $signalId = null): array
    {
        $posSide = (string) ($position['side'] ?? '');
        $side = $posSide === 'Buy' ? 'Sell' : 'Buy';
        $quantity = (string) ($position['size'] ?? '0');
        // Idx берём у позиции (не у стороны закрывающего ордера).
        $positionIdx = isset($position['positionIdx']) && is_numeric($position['positionIdx'])
            ? (int) $position['positionIdx']
            : self::hedgePositionIdx($posSide === '' ? $side : $posSide);

        return $this->placeMarketOrder(
            symbol: $symbol,
            side: $side,
            quantity: $quantity,
            strategyId: null,
            signalId: $signalId,
            reduceOnly: true,
            positionIdx: $positionIdx,
        );
    }

    /**
     * Hedge mode Bybit linear: 1 = Long (Buy), 2 = Short (Sell).
     * One-way mode использует 0 — у аккаунта из логов включён hedge.
     */
    private static function hedgePositionIdx(string $side): int
    {
        return $side === 'Buy' ? 1 : 2;
    }

    /**
     * @param array<string, mixed> $rawResponse
     */
    private function insertOrderRow(
        ?int $strategyId,
        ?int $signalId,
        ?string $bybitOrderId,
        string $orderLinkId,
        string $symbol,
        string $side,
        string $orderType,
        string $quantity,
        ?string $price,
        ?string $takeProfit,
        ?string $stopLoss,
        bool $reduceOnly,
        array $rawResponse,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO orders (strategy_id, signal_id, bybit_order_id, order_link_id, symbol, side, order_type, status,
             quantity, price, take_profit, stop_loss, reduce_only, raw_response)
             VALUES (:strategy_id, :signal_id, :bybit_order_id, :order_link_id, :symbol, :side, :order_type, "New",
             :quantity, :price, :take_profit, :stop_loss, :reduce_only, :raw_response)'
        );
        $statement->execute([
            'strategy_id' => $strategyId,
            'signal_id' => $signalId,
            'bybit_order_id' => $bybitOrderId,
            'order_link_id' => $orderLinkId,
            'symbol' => $symbol,
            'side' => $side,
            'order_type' => $orderType,
            'quantity' => $quantity,
            'price' => $price,
            'take_profit' => $takeProfit,
            'stop_loss' => $stopLoss,
            'reduce_only' => (int) $reduceOnly,
            'raw_response' => json_encode($rawResponse, JSON_THROW_ON_ERROR),
        ]);
    }
}
