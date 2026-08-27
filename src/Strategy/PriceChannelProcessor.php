<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Helpers\Logger;
use App\Telegram\Bot;

/**
 * Price Channel + Trend Flip: Telegram при появлении стрелок BUY/SELL и разворота ↑/↓.
 */
final class PriceChannelProcessor
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CandleRepository $candles,
        private readonly SignalRepository $signals,
        private readonly Bot $telegram,
        private readonly Logger $logger,
    ) {
    }

    public function process(string $symbol): int
    {
        $config = $this->loadConfig();
        if (!PriceChannelConfig::isActive($config)) {
            return 0;
        }

        $enabled = PriceChannelConfig::enabledTimeframes($config);
        $state = $this->loadState();
        $map = Intervals::chartMap();
        $created = 0;
        $stateDirty = false;

        foreach ($enabled as $tf) {
            $code = $map[$tf] ?? null;
            if ($code === null) {
                continue;
            }
            $before = $state[$tf] ?? PriceChannelConfig::emptyTfState();
            $result = $this->processTimeframe($symbol, $tf, $code, $config, $before);
            $created += $result['created'];
            if ($result['state'] !== $before) {
                $state[$tf] = $result['state'];
                $stateDirty = true;
            }
        }

        if ($stateDirty) {
            $this->saveState($state);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $tfState
     * @return array{created: int, state: array<string, mixed>}
     */
    private function processTimeframe(
        string $symbol,
        string $tf,
        string $intervalCode,
        array $config,
        array $tfState,
    ): array {
        $length = (int) $config['channel_length'];
        $need = max($length + 5, 60);
        $rows = $this->candles->latestConfirmedOhlc($symbol, $intervalCode, $need);
        if ($rows === []) {
            return ['created' => 0, 'state' => $tfState];
        }

        $closedRows = [];
        foreach ($rows as $row) {
            if ($this->isCandleFullyClosed((string) $row['open_time'], $intervalCode)) {
                $closedRows[] = $row;
            }
        }
        if ($closedRows === []) {
            return ['created' => 0, 'state' => $tfState];
        }

        $lastProcessed = $tfState['last_candle'] ?? null;
        if ($lastProcessed === null) {
            foreach ($closedRows as $row) {
                $tfState = $this->advanceFlipState($tfState, $row);
            }
            $last = $closedRows[array_key_last($closedRows)];
            $tfState['last_candle'] = (string) $last['open_time'];

            $this->logger->info('Price Channel: инициализация state без отправки истории.', [
                'tf' => $tf,
                'bars' => count($closedRows),
                'last_candle' => $tfState['last_candle'],
            ], 'trading');

            return ['created' => 0, 'state' => $tfState];
        }

        $created = 0;
        foreach ($closedRows as $idx => $row) {
            $openTime = (string) $row['open_time'];
            if ($openTime <= $lastProcessed) {
                continue;
            }

            if (!empty($config['channel_enabled'])) {
                $created += $this->processChannelSignals(
                    $symbol,
                    $tf,
                    $rows,
                    $row,
                    $length,
                    $openTime,
                );
            }

            $flipBefore = $tfState;
            $tfState = $this->advanceFlipState($tfState, $row);
            if (!empty($config['flip_enabled']) && $this->flipDetected($flipBefore, $tfState)) {
                $created += $this->processFlipSignal(
                    $symbol,
                    $tf,
                    $row,
                    $flipBefore,
                    $tfState,
                    $openTime,
                );
            }

            $tfState['last_candle'] = $openTime;
            $lastProcessed = $openTime;
        }

        return ['created' => $created, 'state' => $tfState];
    }

    /**
     * @param list<array<string, mixed>> $allRows
     * @param array<string, mixed> $row
     */
    private function processChannelSignals(
        string $symbol,
        string $tf,
        array $allRows,
        array $row,
        int $length,
        string $candleOpenTime,
    ): int {
        // Map closed row to index in $allRows by open_time.
        $idx = null;
        foreach ($allRows as $i => $candidate) {
            if ((string) $candidate['open_time'] === $candleOpenTime) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null || $idx < $length) {
            return 0;
        }

        $upperPrev = $this->highestInRange($allRows, $idx - $length, $idx - 1);
        $lowerPrev = $this->lowestInRange($allRows, $idx - $length, $idx - 1);
        $high = (float) $row['high_price'];
        $low = (float) $row['low_price'];
        $close = (float) $row['close_price'];
        $created = 0;

        if ($this->nearlyEqual($high, $upperPrev)) {
            $created += $this->emitSignal(
                $symbol,
                $tf,
                'Buy',
                'pc_buy_' . $tf,
                $candleOpenTime,
                (string) $close,
                $this->buildChannelMessage($symbol, $tf, 'BUY', $high, $upperPrev, $lowerPrev, $close, $candleOpenTime),
                [
                    'kind' => 'channel_buy',
                    'high' => $high,
                    'upper' => $upperPrev,
                    'lower' => $lowerPrev,
                ],
                $length,
            );
        }

        if ($this->nearlyEqual($low, $lowerPrev)) {
            $created += $this->emitSignal(
                $symbol,
                $tf,
                'Sell',
                'pc_sell_' . $tf,
                $candleOpenTime,
                (string) $close,
                $this->buildChannelMessage($symbol, $tf, 'SELL', $low, $upperPrev, $lowerPrev, $close, $candleOpenTime),
                [
                    'kind' => 'channel_sell',
                    'low' => $low,
                    'upper' => $upperPrev,
                    'lower' => $lowerPrev,
                ],
                $length,
            );
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function processFlipSignal(
        string $symbol,
        string $tf,
        array $row,
        array $before,
        array $after,
        string $candleOpenTime,
    ): int {
        $trend = (string) ($after['trend'] ?? '');
        if ($trend !== 'up' && $trend !== 'down') {
            return 0;
        }

        $close = (float) $row['close_price'];
        $lastFlipClose = (float) ($before['last_flip_close'] ?? $close);
        $delta = $trend === 'up'
            ? $lastFlipClose - $close
            : -($lastFlipClose - $close);
        $side = $trend === 'up' ? 'Buy' : 'Sell';
        $signalType = $trend === 'up' ? 'pc_flip_up_' . $tf : 'pc_flip_down_' . $tf;
        $arrow = $trend === 'up' ? '↑' : '↓';

        return $this->emitSignal(
            $symbol,
            $tf,
            $side,
            $signalType,
            $candleOpenTime,
            (string) $close,
            $this->buildFlipMessage($symbol, $tf, $arrow, $delta, $close, $candleOpenTime),
            [
                'kind' => 'trend_flip',
                'trend' => $trend,
                'delta' => $delta,
            ],
            PriceChannelConfig::DEFAULT_LENGTH,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function emitSignal(
        string $symbol,
        string $tf,
        string $side,
        string $signalType,
        string $candleOpenTime,
        string $price,
        string $message,
        array $extra,
        int $candleCount,
    ): int {
        $payload = array_merge([
            'source' => 'price_channel',
            'interval' => $tf,
            'telegram_text' => $message,
        ], $extra);

        $signalId = $this->signals->createOnceForClosedCandle(
            null,
            $symbol,
            $side,
            $signalType,
            $candleCount,
            $candleOpenTime,
            $price,
            $payload,
        );

        if ($signalId === null) {
            return 0;
        }

        $sent = $this->telegram->send($message, [
            'signal_id' => $signalId,
            'tf' => $tf,
            'source' => 'price_channel',
            'signal_type' => $signalType,
        ]);
        if ($sent) {
            $this->signals->markTelegramSent($signalId);
        } else {
            $this->logger->error('Price Channel: не удалось отправить в Telegram.', [
                'signal_id' => $signalId,
                'tf' => $tf,
                'signal_type' => $signalType,
            ], 'telegram');
        }

        $this->logger->info('Price Channel: сигнал отправлен.', [
            'signal_id' => $signalId,
            'tf' => $tf,
            'signal_type' => $signalType,
            'telegram' => $sent,
        ], 'trading');

        return 1;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function advanceFlipState(array $state, array $row): array
    {
        $open = (float) $row['open_price'];
        $close = (float) $row['close_price'];
        $trend = $state['trend'] ?? null;
        $lastFlipClose = $state['last_flip_close'];
        $extremeOpen = $state['extreme_open'];
        $extremeClose = $state['extreme_close'];

        if ($lastFlipClose === null) {
            $state['trend'] = $close > $open ? 'up' : 'down';
            $state['last_flip_close'] = $close;
            $state['extreme_open'] = $open;
            $state['extreme_close'] = $close;

            return $state;
        }

        $trend = (string) $trend;
        if ($trend === 'up') {
            if ($close > $open) {
                if ($extremeClose === null || $close > (float) $extremeClose) {
                    $state['extreme_close'] = $close;
                    $state['extreme_open'] = $open;
                }
            } elseif ($extremeOpen !== null && $close < (float) $extremeOpen) {
                $state['trend'] = 'down';
                $state['extreme_close'] = $close;
                $state['extreme_open'] = $open;
                $state['last_flip_close'] = $close;
            }
        } elseif ($trend === 'down') {
            if ($close < $open) {
                if ($extremeClose === null || $close < (float) $extremeClose) {
                    $state['extreme_close'] = $close;
                    $state['extreme_open'] = $open;
                }
            } elseif ($extremeOpen !== null && $close > (float) $extremeOpen) {
                $state['trend'] = 'up';
                $state['extreme_close'] = $close;
                $state['extreme_open'] = $open;
                $state['last_flip_close'] = $close;
            }
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function flipDetected(array $before, array $after): bool
    {
        $beforeTrend = $before['trend'] ?? null;
        $afterTrend = $after['trend'] ?? null;

        return $beforeTrend !== null
            && $afterTrend !== null
            && $beforeTrend !== $afterTrend;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function highestInRange(array $rows, int $from, int $to): float
    {
        $max = -INF;
        for ($i = $from; $i <= $to; $i += 1) {
            $high = (float) $rows[$i]['high_price'];
            if ($high > $max) {
                $max = $high;
            }
        }

        return $max;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function lowestInRange(array $rows, int $from, int $to): float
    {
        $min = INF;
        for ($i = $from; $i <= $to; $i += 1) {
            $low = (float) $rows[$i]['low_price'];
            if ($low < $min) {
                $min = $low;
            }
        }

        return $min;
    }

    private function nearlyEqual(float $a, float $b): bool
    {
        return abs($a - $b) < 1e-8;
    }

    private function buildChannelMessage(
        string $symbol,
        string $tf,
        string $label,
        float $triggerPrice,
        float $upper,
        float $lower,
        float $close,
        string $candleOpenTime,
    ): string {
        $sideRu = $label === 'BUY' ? 'LONG' : 'SHORT';
        $emoji = $label === 'BUY' ? '🟢' : '🔴';

        return sprintf(
            "%s <b>Price Channel %s</b>\n" .
            "Пара: <b>%s</b> · ТФ: <b>%s</b>\n" .
            "Сторона: <b>%s</b>\n" .
            "High канала: <b>%s</b> · Low канала: <b>%s</b>\n" .
            "Срабатывание: <b>%s</b> · Close: <b>%s</b>\n" .
            "Свеча: %s",
            $emoji,
            $label,
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $sideRu,
            htmlspecialchars(PriceChannelConfig::formatPrice($upper), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(PriceChannelConfig::formatPrice($lower), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(PriceChannelConfig::formatPrice($triggerPrice), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(PriceChannelConfig::formatPrice($close), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($candleOpenTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function buildFlipMessage(
        string $symbol,
        string $tf,
        string $arrow,
        float $delta,
        float $close,
        string $candleOpenTime,
    ): string {
        $trendRu = $arrow === '↑' ? 'вверх' : 'вниз';
        $sideRu = $arrow === '↑' ? 'LONG' : 'SHORT';
        $deltaLabel = $delta > 0 ? (string) (int) round($delta) : '—';

        return sprintf(
            "🔄 <b>Trend Flip %s</b>\n" .
            "Пара: <b>%s</b> · ТФ: <b>%s</b>\n" .
            "Разворот: <b>%s</b> · Сторона: <b>%s</b>\n" .
            "Δ: <b>%s</b> · Close: <b>%s</b>\n" .
            "Свеча: %s",
            $arrow,
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $trendRu,
            $sideRu,
            htmlspecialchars($deltaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(PriceChannelConfig::formatPrice($close), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($candleOpenTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function isCandleFullyClosed(string $openTime, string $intervalCode, ?int $now = null): bool
    {
        $now = $now ?? time();
        try {
            $open = new \DateTimeImmutable(trim($openTime), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $openTs = strtotime($openTime . ' UTC');
            if ($openTs === false) {
                return false;
            }
            $open = (new \DateTimeImmutable('@' . $openTs))->setTimezone(new \DateTimeZone('UTC'));
        }

        return $now >= ($open->getTimestamp() + Intervals::durationSeconds($intervalCode));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $raw = $this->settings->get(PriceChannelConfig::SETTING_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return PriceChannelConfig::normalize($decoded);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadState(): array
    {
        $raw = $this->settings->get(PriceChannelConfig::STATE_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return PriceChannelConfig::normalizeState($decoded);
    }

    /**
     * @param array<string, array<string, mixed>> $state
     */
    private function saveState(array $state): void
    {
        $this->settings->set(
            PriceChannelConfig::STATE_KEY,
            json_encode(PriceChannelConfig::normalizeState($state), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
