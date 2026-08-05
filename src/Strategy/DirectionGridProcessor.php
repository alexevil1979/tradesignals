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
 * test_mode: эмуляция без Bybit, всё пишется в лог с префиксом [TEST]/[LIVE].
 */
final class DirectionGridProcessor
{
    private bool $testMode = false;

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

        $this->testMode = !empty($config['test_mode']);
        $state = $this->loadState();
        if (!empty($state['stopped'])) {
            return 0;
        }

        if (!$this->testMode && !$this->tradingEnabled) {
            $this->logInfo('trading_enabled=0, ордера не выставляем.', []);

            return 0;
        }

        $periodMinutes = (int) $config['period_minutes'];
        $extremum = $this->candles->extremumLastMinutes($symbol, '1', $periodMinutes);
        if ($extremum === null) {
            $this->logWarning('нет экстремума за период.', [
                'period_minutes' => $periodMinutes,
            ]);

            return 0;
        }

        $mode = (string) $config['mode'];
        $anchor = $mode === 'low' ? (float) $extremum['low'] : (float) $extremum['high'];
        $side = $mode === 'low' ? 'Sell' : 'Buy';
        $actions = 0;
        $lastClose = $this->lastClose($symbol);

        $state = $this->syncLevelStatuses($symbol, $state, $config, $side, $lastClose);
        $openPosition = $this->resolveOpenPosition($symbol, $state, $lastClose);

        if (!empty($state['filled_any']) || !empty($state['wait_close'])) {
            return $this->handleFilledPhase($symbol, $config, $state, $openPosition, $anchor, $side);
        }

        $needReplace = $state['grid_id'] === null
            || $state['levels'] === []
            || $this->anchorChanged($state['anchor'], $anchor)
            || $this->missingOpenLevels($symbol, $state);

        if (!$needReplace) {
            $this->logInfo('сетка актуальна, действий нет.', [
                'anchor' => $anchor,
                'levels' => count($state['levels']),
            ]);

            return 0;
        }

        $reason = $state['grid_id'] === null || $state['levels'] === []
            ? 'нет сетки'
            : ($this->anchorChanged($state['anchor'], $anchor) ? 'экстремум изменился' : 'пропали open-ордера');
        $this->logInfo('перестановка сетки.', [
            'reason' => $reason,
            'anchor' => $anchor,
            'prev_anchor' => $state['anchor'],
        ]);

        $actions += $this->cancelGridLevels($symbol, $state);
        $placed = $this->placeGrid($symbol, $config, $anchor, $side, $lastClose);
        $this->saveState($placed);
        $actions += count($placed['levels']);

        if ($placed['levels'] !== []) {
            $this->notify(
                sprintf(
                    "📐 <b>Сетка слежения%s</b>\nРежим: <b>%s</b>\nЭкстремум: <b>%s</b>\nTP: <b>%s</b> · SL: <b>%s</b>\nУровней: <b>%d</b>",
                    $this->testMode ? ' [TEST]' : '',
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
            $this->saveState($state);
            $this->logInfo('ждём закрытия позиции.', [
                'side' => $openPosition['side'] ?? null,
                'size' => $openPosition['size'] ?? null,
                'entry' => $openPosition['avgPrice'] ?? ($openPosition['entry'] ?? null),
            ]);

            return 0;
        }

        $actions = $this->cancelGridLevels($symbol, $state);
        $afterTp = (string) ($config['after_tp'] ?? 'rebuild');
        $this->logInfo('позиция закрыта, отмена оставшихся лимитов.', [
            'cancelled' => $actions,
            'after_tp' => $afterTp,
        ]);

        if ($afterTp === 'stop') {
            $state = DirectionGridConfig::defaultState();
            $state['stopped'] = true;
            $this->saveState($state);
            $config['enabled'] = false;
            $this->settings->set(
                DirectionGridConfig::SETTING_KEY,
                json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $this->logInfo('после закрытия — остановка.', []);
            $this->notify("⏹ <b>Сетка слежения остановлена" . ($this->testMode ? ' [TEST]' : '') . "</b>\nПосле закрытия позиции торговля остановлена.");

            return $actions;
        }

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
        $this->logInfo('после закрытия — новая сетка.', [
            'anchor' => $anchor,
            'levels' => count($placed['levels']),
        ]);
        $this->notify(
            sprintf(
                "🔁 <b>Новая сетка слежения%s</b>\nЭкстремум: <b>%s</b>\nУровней: <b>%d</b>",
                $this->testMode ? ' [TEST]' : '',
                htmlspecialchars(DirectionGridConfig::formatPrice($anchor), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                count($placed['levels'])
            )
        );

        return $actions;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function syncLevelStatuses(
        string $symbol,
        array $state,
        array $config,
        string $side,
        ?float $lastClose,
    ): array {
        if ($state['levels'] === []) {
            return $state;
        }

        if ($this->testMode) {
            return $this->syncTestLevels($state, $side, $lastClose);
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
            $this->logWarning('не удалось получить open orders.', [
                'error' => $exception->getMessage(),
            ]);

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

            $prev = (string) ($level['status'] ?? 'New');
            if (in_array($prev, ['Filled', 'Cancelled'], true)) {
                if ($prev === 'Filled') {
                    $filledAny = true;
                }
                continue;
            }

            if ($openPosition !== null && (float) ($openPosition['size'] ?? 0) > 0) {
                $level['status'] = 'Filled';
                $filledAny = true;
                $this->logInfo('уровень исполнен (нет в open, есть позиция).', [
                    'link_id' => $link,
                    'price' => $level['price'] ?? null,
                ]);
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
     * Эмуляция: fill при касании цены уровня; закрытие виртуальной позиции по TP/SL.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function syncTestLevels(array $state, string $side, ?float $lastClose): array
    {
        if ($lastClose === null) {
            return $state;
        }

        $filledAny = !empty($state['filled_any']);
        $testPos = is_array($state['test_position'] ?? null) ? $state['test_position'] : null;

        // Уже ждём закрытия — проверяем TP/SL.
        if (!empty($testPos['open']) && $state['tp'] !== null && $state['sl'] !== null) {
            $tp = (float) $state['tp'];
            $sl = (float) $state['sl'];
            $hitTp = $side === 'Buy' ? $lastClose >= $tp : $lastClose <= $tp;
            $hitSl = $side === 'Buy' ? $lastClose <= $sl : $lastClose >= $sl;
            if ($hitTp || $hitSl) {
                $this->logInfo($hitTp ? 'эмуляция: сработал TP.' : 'эмуляция: сработал SL.', [
                    'price' => $lastClose,
                    'tp' => $tp,
                    'sl' => $sl,
                ]);
                $state['test_position'] = ['open' => false, 'side' => $side, 'entry' => $testPos['entry'] ?? null];
                $state['filled_any'] = true;
                $state['wait_close'] = true;
            }

            return $state;
        }

        foreach ($state['levels'] as &$level) {
            if (($level['status'] ?? '') !== 'New') {
                if (($level['status'] ?? '') === 'Filled') {
                    $filledAny = true;
                }
                continue;
            }
            $price = $level['price'] ?? null;
            if (!is_numeric($price)) {
                continue;
            }
            $price = (float) $price;
            $hit = $side === 'Buy' ? $lastClose <= $price : $lastClose >= $price;
            if (!$hit) {
                continue;
            }
            $level['status'] = 'Filled';
            $filledAny = true;
            $state['test_position'] = [
                'open' => true,
                'side' => $side,
                'entry' => $price,
            ];
            $this->logInfo('эмуляция: fill уровня.', [
                'level' => $level['index'] ?? null,
                'link_id' => $level['link_id'] ?? null,
                'price' => $price,
                'last_close' => $lastClose,
            ]);
            break; // один fill за тик
        }
        unset($level);

        $state['filled_any'] = $filledAny;
        if ($filledAny) {
            $state['wait_close'] = true;
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function resolveOpenPosition(string $symbol, array $state, ?float $lastClose): ?array
    {
        if ($this->testMode) {
            $tp = is_array($state['test_position'] ?? null) ? $state['test_position'] : null;
            if (!empty($tp['open'])) {
                return [
                    'side' => $tp['side'] ?? null,
                    'size' => '0.001',
                    'avgPrice' => $tp['entry'] ?? null,
                    'entry' => $tp['entry'] ?? null,
                ];
            }

            return null;
        }

        try {
            return $this->positions->fetch($symbol);
        } catch (Throwable $exception) {
            $this->logWarning('не удалось получить позицию.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function placeGrid(
        string $symbol,
        array $config,
        float $anchor,
        string $side,
        ?float $lastClose,
    ): array {
        $gridId = ($this->testMode ? 'tdg' : 'dg') . substr(bin2hex(random_bytes(6)), 0, 10);
        $profit = (float) $config['profit'];
        $stop = (float) $config['stop'];
        $mode = (string) $config['mode'];
        $tp = $mode === 'low' ? $anchor - $profit : $anchor + $profit;
        $sl = $mode === 'low' ? $anchor + $stop : $anchor - $stop;
        $tpStr = $this->fmtPrice($symbol, $tp);
        $slStr = $this->fmtPrice($symbol, $sl);

        $levels = [];
        foreach ($config['levels'] as $index => $row) {
            $offset = (float) $row['offset'];
            $size = (string) $row['size'];
            $rawPrice = $mode === 'low' ? $anchor + $offset : $anchor - $offset;
            $priceStr = $this->fmtPrice($symbol, $rawPrice);
            $price = (float) $priceStr;

            if ($lastClose !== null) {
                if ($side === 'Buy' && $price >= $lastClose) {
                    $this->logInfo('пропуск Buy — цена не ниже рынка.', [
                        'price' => $price,
                        'last_close' => $lastClose,
                        'level' => $index + 1,
                    ]);
                    continue;
                }
                if ($side === 'Sell' && $price <= $lastClose) {
                    $this->logInfo('пропуск Sell — цена не выше рынка.', [
                        'price' => $price,
                        'last_close' => $lastClose,
                        'level' => $index + 1,
                    ]);
                    continue;
                }
            }

            $linkId = sprintf('%s-L%d', $gridId, $index + 1);
            if ($this->testMode) {
                $this->logInfo('эмуляция: постановка лимита.', [
                    'link_id' => $linkId,
                    'side' => $side,
                    'price' => $priceStr,
                    'qty' => $size,
                    'tp' => $tpStr,
                    'sl' => $slStr,
                ]);
                $levels[] = [
                    'index' => $index + 1,
                    'link_id' => $linkId,
                    'status' => 'New',
                    'price' => $price,
                ];
                continue;
            }

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
                $this->logInfo('лимит отправлен на биржу.', [
                    'link_id' => $linkId,
                    'side' => $side,
                    'price' => $priceStr,
                    'qty' => $size,
                    'tp' => $tpStr,
                    'sl' => $slStr,
                ]);
                $levels[] = [
                    'index' => $index + 1,
                    'link_id' => $linkId,
                    'status' => 'New',
                    'price' => $price,
                ];
            } catch (Throwable $exception) {
                $this->logError('ошибка постановки лимита.', [
                    'level' => $index + 1,
                    'link_id' => $linkId,
                    'error' => $exception->getMessage(),
                ]);
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
            'test_position' => null,
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
            if ($this->testMode) {
                $this->logInfo('эмуляция: отмена лимита.', [
                    'link_id' => $link,
                    'price' => $level['price'] ?? null,
                ]);
                $count++;
                continue;
            }
            $this->orders->cancelByLinkId($symbol, $link);
            $this->logInfo('отмена лимита на бирже.', ['link_id' => $link]);
            $count++;
        }

        return $count;
    }

    private function missingOpenLevels(string $symbol, array $state): bool
    {
        if ($state['levels'] === []) {
            return true;
        }
        if ($this->testMode) {
            return false;
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

    private function fmtPrice(string $symbol, float $price): string
    {
        try {
            return $this->instruments->formatPrice($symbol, $price);
        } catch (Throwable) {
            return DirectionGridConfig::formatPrice($price);
        }
    }

    private function modePrefix(): string
    {
        return $this->testMode ? '[TEST] ' : '[LIVE] ';
    }

    /** @param array<string, mixed> $context */
    private function logInfo(string $message, array $context): void
    {
        $this->logger->info('Direction grid: ' . $this->modePrefix() . $message, $context + [
            'test_mode' => $this->testMode,
        ], 'trading');
    }

    /** @param array<string, mixed> $context */
    private function logWarning(string $message, array $context): void
    {
        $this->logger->warning('Direction grid: ' . $this->modePrefix() . $message, $context + [
            'test_mode' => $this->testMode,
        ], 'trading');
    }

    /** @param array<string, mixed> $context */
    private function logError(string $message, array $context): void
    {
        $this->logger->error('Direction grid: ' . $this->modePrefix() . $message, $context + [
            'test_mode' => $this->testMode,
        ], 'trading');
    }

    private function notify(string $message): void
    {
        if ($this->testMode) {
            // В тесте только лог, без Telegram-спама.
            $this->logInfo('telegram (не отправлено в TEST): ' . strip_tags($message), []);

            return;
        }
        try {
            $this->telegram->send($message, ['source' => 'direction_grid']);
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
