<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Bybit\InstrumentService;
use App\Bybit\OrderService;
use App\Bybit\PositionService;
use App\Database\SettingsRepository;
use App\Helpers\Logger;
use App\Telegram\Bot;
use Throwable;

/**
 * Сетка лимитов от хая/лоя за период.
 * Пока нет fill — двигаем сетку за экстремумом; после fill — ждём TP/SL.
 */
final class DirectionGridProcessor
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CandleRepository $candles,
        private readonly OrderService $orders,
        private readonly PositionService $positions,
        private readonly InstrumentService $instruments,
        private readonly Bot $telegram,
        private readonly Logger $logger,
        private readonly bool $tradingEnabled,
    ) {
    }

    public function process(string $symbol): int
    {
        $config = $this->loadConfig();
        if (empty($config['enabled'])) {
            return 0;
        }

        $state = $this->loadState();
        if (!empty($state['stopped'])) {
            return 0;
        }

        if (!$this->tradingEnabled) {
            $this->logger->info('Direction grid: trading_enabled=0, ордера не выставляем.', [], 'trading');

            return 0;
        }

        $periodMinutes = (int) $config['period_minutes'];
        $extremum = $this->candles->extremumLastMinutes($symbol, '1', $periodMinutes);
        if ($extremum === null) {
            $this->logger->warning('Direction grid: нет экстремума за период.', [
                'period_minutes' => $periodMinutes,
            ], 'trading');

            return 0;
        }

        $mode = (string) $config['mode'];
        $anchor = $mode === 'low' ? (float) $extremum['low'] : (float) $extremum['high'];
        $side = $mode === 'low' ? 'Sell' : 'Buy';
        $actions = 0;

        // Синхронизация статусов уровней с биржей.
        $state = $this->syncLevelStatuses($symbol, $state);
        $openPosition = $this->positions->fetch($symbol);

        if (!empty($state['filled_any']) || !empty($state['wait_close'])) {
            return $this->handleFilledPhase($symbol, $config, $state, $openPosition, $anchor, $side);
        }

        $lastClose = $this->lastClose($symbol);
        $needReplace = $state['grid_id'] === null
            || $state['levels'] === []
            || $this->anchorChanged($state['anchor'], $anchor)
            || $this->missingOpenLevels($symbol, $state);

        if (!$needReplace) {
            return 0;
        }

        $actions += $this->cancelGridLevels($symbol, $state);
        $placed = $this->placeGrid($symbol, $config, $anchor, $side, $lastClose);
        $this->saveState($placed);
        $actions += count($placed['levels']);

        if ($placed['levels'] !== []) {
            $this->notify(
                sprintf(
                    "📐 <b>Сетка слежения</b>\nРежим: <b>%s</b>\nЭкстремум: <b>%s</b>\nTP: <b>%s</b> · SL: <b>%s</b>\nУровней: <b>%d</b>",
                    $mode === 'low' ? 'Low → Sell' : 'High → Buy',
                    htmlspecialchars(DirectionGridConfig::formatPrice($anchor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars(DirectionGridConfig::formatPrice((float) $placed['tp']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars(DirectionGridConfig::formatPrice((float) $placed['sl']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    count($placed['levels'])
                )
            );
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $openPosition
     */
    private function handleFilledPhase(
        string $symbol,
        array $config,
        array $state,
        ?array $openPosition,
        float $anchor,
        string $side,
    ): int {
        $state['wait_close'] = true;
        $state['filled_any'] = true;

        if ($openPosition !== null) {
            // Позиция ещё открыта — TP/SL не трогаем, сетку не двигаем.
            $this->saveState($state);
            $this->logger->info('Direction grid: ждём закрытия позиции.', [
                'side' => $openPosition['side'] ?? null,
                'size' => $openPosition['size'] ?? null,
            ], 'trading');

            return 0;
        }

        // Позиции нет — считаем цикл завершённым (TP/SL/ручное закрытие).
        $actions = $this->cancelGridLevels($symbol, $state);
        $afterTp = (string) ($config['after_tp'] ?? 'rebuild');

        if ($afterTp === 'stop') {
            $state = DirectionGridConfig::defaultState();
            $state['stopped'] = true;
            $this->saveState($state);
            // Выключаем стратегию в конфиге.
            $config['enabled'] = false;
            $this->settings->set(
                DirectionGridConfig::SETTING_KEY,
                json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $this->logger->info('Direction grid: после закрытия — остановка.', [], 'trading');
            $this->notify("⏹ <b>Сетка слежения остановлена</b>\nПосле закрытия позиции торговля остановлена.");

            return $actions;
        }

        // rebuild
        $lastClose = $this->lastClose($symbol);
        $periodMinutes = (int) $config['period_minutes'];
        $extremum = $this->candles->extremumLastMinutes($symbol, '1', $periodMinutes);
        if ($extremum !== null) {
            $anchor = ($config['mode'] ?? 'high') === 'low'
                ? (float) $extremum['low']
                : (float) $extremum['high'];
        }
        $placed = $this->placeGrid($symbol, $config, $anchor, $side, $lastClose);
        $this->saveState($placed);
        $actions += count($placed['levels']);
        $this->logger->info('Direction grid: после закрытия — новая сетка.', [
            'anchor' => $anchor,
            'levels' => count($placed['levels']),
        ], 'trading');
        $this->notify(
            sprintf(
                "🔁 <b>Новая сетка слежения</b>\nЭкстремум: <b>%s</b>\nУровней: <b>%d</b>",
                htmlspecialchars(DirectionGridConfig::formatPrice($anchor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                count($placed['levels'])
            )
        );

        return $actions;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function syncLevelStatuses(string $symbol, array $state): array
    {
        if ($state['levels'] === []) {
            return $state;
        }

        $openByLink = [];
        try {
            foreach ($this->orders->getOpenOrders($symbol) as $order) {
                $link = (string) ($order['orderLinkId'] ?? '');
                if ($link !== '') {
                    $openByLink[$link] = $order;
                }
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Direction grid: не удалось получить open orders.', [
                'error' => $exception->getMessage(),
            ], 'trading');

            return $state;
        }

        $filledAny = !empty($state['filled_any']);
        $openPosition = null;
        try {
            $openPosition = $this->positions->fetch($symbol);
        } catch (Throwable) {
            $openPosition = null;
        }

        foreach ($state['levels'] as &$level) {
            $link = (string) ($level['link_id'] ?? '');
            if ($link === '') {
                continue;
            }
            if (isset($openByLink[$link])) {
                $status = (string) ($openByLink[$link]['orderStatus'] ?? 'New');
                $level['status'] = $status;
                if (in_array($status, ['PartiallyFilled', 'Filled'], true)) {
                    $filledAny = true;
                }
                continue;
            }

            // Ордера нет в open.
            $prev = (string) ($level['status'] ?? 'New');
            if (in_array($prev, ['Filled', 'Cancelled'], true)) {
                if ($prev === 'Filled') {
                    $filledAny = true;
                }
                continue;
            }

            // Исчез: fill только если есть открытая позиция, иначе считаем отменённым.
            if ($openPosition !== null && (float) ($openPosition['size'] ?? 0) > 0) {
                $level['status'] = 'Filled';
                $filledAny = true;
            } else {
                $level['status'] = 'Cancelled';
            }
        }
        unset($level);

        $state['filled_any'] = $filledAny;
        if ($filledAny) {
            $state['wait_close'] = true;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   grid_id: string,
     *   anchor: float,
     *   tp: float,
     *   sl: float,
     *   filled_any: bool,
     *   stopped: bool,
     *   wait_close: bool,
     *   levels: list<array{index: int, link_id: string, status: string, price: float|null}>
     * }
     */
    private function placeGrid(
        string $symbol,
        array $config,
        float $anchor,
        string $side,
        ?float $lastClose,
    ): array {
        $gridId = 'dg' . substr(bin2hex(random_bytes(6)), 0, 10);
        $profit = (float) $config['profit'];
        $stop = (float) $config['stop'];
        $mode = (string) $config['mode'];
        $tp = $mode === 'low' ? $anchor - $profit : $anchor + $profit;
        $sl = $mode === 'low' ? $anchor + $stop : $anchor - $stop;
        $tpStr = $this->instruments->formatPrice($symbol, $tp);
        $slStr = $this->instruments->formatPrice($symbol, $sl);

        $levels = [];
        foreach ($config['levels'] as $index => $row) {
            $offset = (float) $row['offset'];
            $size = (string) $row['size'];
            $rawPrice = $mode === 'low' ? $anchor + $offset : $anchor - $offset;
            $priceStr = $this->instruments->formatPrice($symbol, $rawPrice);
            $price = (float) $priceStr;

            if ($lastClose !== null) {
                if ($side === 'Buy' && $price >= $lastClose) {
                    $this->logger->info('Direction grid: пропуск Buy — цена не ниже рынка.', [
                        'price' => $price,
                        'last_close' => $lastClose,
                        'level' => $index + 1,
                    ], 'trading');
                    continue;
                }
                if ($side === 'Sell' && $price <= $lastClose) {
                    $this->logger->info('Direction grid: пропуск Sell — цена не выше рынка.', [
                        'price' => $price,
                        'last_close' => $lastClose,
                        'level' => $index + 1,
                    ], 'trading');
                    continue;
                }
            }

            $linkId = sprintf('%s-L%d', $gridId, $index + 1);
            try {
                $this->orders->placeLimitOrder(
                    symbol: $symbol,
                    side: $side,
                    quantity: $size,
                    price: $priceStr,
                    orderLinkId: $linkId,
                    takeProfit: $tpStr,
                    stopLoss: $slStr,
                );
                $levels[] = [
                    'index' => $index + 1,
                    'link_id' => $linkId,
                    'status' => 'New',
                    'price' => $price,
                ];
            } catch (Throwable $exception) {
                $this->logger->error('Direction grid: ошибка постановки лимита.', [
                    'level' => $index + 1,
                    'link_id' => $linkId,
                    'error' => $exception->getMessage(),
                ], 'trading');
            }
        }

        return [
            'grid_id' => $gridId,
            'anchor' => $anchor,
            'tp' => (float) $tpStr,
            'sl' => (float) $slStr,
            'filled_any' => false,
            'stopped' => false,
            'wait_close' => false,
            'levels' => $levels,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function cancelGridLevels(string $symbol, array $state): int
    {
        $count = 0;
        foreach ($state['levels'] as $level) {
            $link = (string) ($level['link_id'] ?? '');
            $status = (string) ($level['status'] ?? '');
            if ($link === '' || in_array($status, ['Filled', 'Cancelled'], true)) {
                continue;
            }
            $this->orders->cancelByLinkId($symbol, $link);
            $count++;
        }

        return $count;
    }

    private function missingOpenLevels(string $symbol, array $state): bool
    {
        if ($state['levels'] === []) {
            return true;
        }
        try {
            $open = $this->orders->getOpenOrders($symbol);
        } catch (Throwable) {
            return false;
        }
        $links = [];
        foreach ($open as $order) {
            $links[(string) ($order['orderLinkId'] ?? '')] = true;
        }
        foreach ($state['levels'] as $level) {
            $link = (string) ($level['link_id'] ?? '');
            if ($link !== '' && ($level['status'] ?? '') === 'New' && !isset($links[$link])) {
                return true;
            }
        }

        return false;
    }

    private function anchorChanged(?float $prev, float $next): bool
    {
        if ($prev === null) {
            return true;
        }

        return abs($prev - $next) >= 0.01;
    }

    private function lastClose(string $symbol): ?float
    {
        $rows = $this->candles->latestConfirmed($symbol, '1', 1);
        if ($rows === []) {
            return null;
        }

        return (float) $rows[array_key_last($rows)]['close_price'];
    }

    private function notify(string $message): void
    {
        try {
            $this->telegram->send($message, ['source' => 'direction_grid']);
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array{
     *   enabled: bool,
     *   mode: 'high'|'low',
     *   period_minutes: int,
     *   profit: float|int,
     *   stop: float|int,
     *   after_tp: 'rebuild'|'stop',
     *   levels: list<array{offset: float|int, size: string}>
     * }
     */
    private function loadConfig(): array
    {
        $raw = $this->settings->get(DirectionGridConfig::SETTING_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $decoded = null;
            }
        }

        return DirectionGridConfig::normalize($decoded);
    }

    /**
     * @return array{
     *   grid_id: ?string,
     *   anchor: float|null,
     *   tp: float|null,
     *   sl: float|null,
     *   filled_any: bool,
     *   stopped: bool,
     *   wait_close: bool,
     *   levels: list<array{index: int, link_id: string, status: string, price: float|null}>
     * }
     */
    private function loadState(): array
    {
        $raw = $this->settings->get(DirectionGridConfig::STATE_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $decoded = null;
            }
        }

        return DirectionGridConfig::normalizeState($decoded);
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $this->settings->set(
            DirectionGridConfig::STATE_KEY,
            json_encode(DirectionGridConfig::normalizeState($state), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
