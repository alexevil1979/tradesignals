<?php
declare(strict_types=1);

namespace App\Bybit;

use App\Helpers\Logger;
use PDO;

final class OrderService
{
    public function __construct(
        private readonly Client $client,
        private readonly PDO $pdo,
        private readonly Logger $logger,
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
        ], static fn (mixed $value): bool => $value !== null);
        $response = $this->client->privateRequest('POST', '/v5/order/create', $payload);
        $result = $response['result'];

        $statement = $this->pdo->prepare(
            'INSERT INTO orders (strategy_id, signal_id, bybit_order_id, order_link_id, symbol, side, order_type, status,
             quantity, take_profit, stop_loss, reduce_only, raw_response)
             VALUES (:strategy_id, :signal_id, :bybit_order_id, :order_link_id, :symbol, :side, "Market", "New",
             :quantity, :take_profit, :stop_loss, :reduce_only, :raw_response)'
        );
        $statement->execute([
            'strategy_id' => $strategyId, 'signal_id' => $signalId,
            'bybit_order_id' => $result['orderId'] ?? null, 'order_link_id' => $orderLinkId,
            'symbol' => $symbol, 'side' => $side, 'quantity' => $quantity,
            'take_profit' => $takeProfit, 'stop_loss' => $stopLoss, 'reduce_only' => (int) $reduceOnly,
            'raw_response' => json_encode($response, JSON_THROW_ON_ERROR),
        ]);
        $this->logger->info('Рыночный ордер отправлен.', ['order_link_id' => $orderLinkId], 'trading');

        return $response;
    }

    /** @param array<string, mixed> $position */
    public function closePosition(string $symbol, array $position, ?int $signalId = null): array
    {
        $side = ($position['side'] ?? '') === 'Buy' ? 'Sell' : 'Buy';
        $quantity = (string) ($position['size'] ?? '0');

        return $this->placeMarketOrder(
            symbol: $symbol,
            side: $side,
            quantity: $quantity,
            strategyId: null,
            signalId: $signalId,
            reduceOnly: true,
        );
    }
}
