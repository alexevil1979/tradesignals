<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Helpers\Logger;
use App\Telegram\Bot;

/**
 * Обрабатывает матрицу сигналов (signal_grid): при совпадении серии и галки «сигнал»
 * создаёт запись и отправляет уведомление в Telegram.
 */
final class SignalGridProcessor
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CandleRepository $candles,
        private readonly CandleAnalyzer $analyzer,
        private readonly SignalRepository $signals,
        private readonly Bot $telegram,
        private readonly Logger $logger,
    ) {
    }

    public function process(string $symbol): int
    {
        $grid = $this->loadGrid();
        $created = 0;

        foreach (SignalGridConfig::TIMEFRAMES as $tf) {
            $created += $this->processTimeframe($symbol, $tf, $grid);
        }

        $this->retryPendingTelegram();

        return $created;
    }

    /**
     * @param array{
     *   min_body: array<string, int|float>,
     *   timeframes: array<string, list<array<string, mixed>>>
     * } $grid
     */
    private function processTimeframe(string $symbol, string $tf, array $grid): int
    {
        $map = Intervals::chartMap();
        $intervalCode = $map[$tf] ?? null;
        if ($intervalCode === null) {
            return 0;
        }

        $rows = $grid['timeframes'][$tf] ?? [];
        if ($rows === []) {
            return 0;
        }

        $enabledRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => !empty($row['signal'])
        ));
        if ($enabledRows === []) {
            return 0;
        }

        $maxBars = 1;
        foreach ($rows as $row) {
            $maxBars = max($maxBars, (int) ($row['bars'] ?? 1));
        }

        $candles = $this->candles->latestConfirmed($symbol, $intervalCode, max($maxBars + 5, 20));
        if ($candles === []) {
            $this->logger->warning('Нет закрытых свечей для сигнала.', ['tf' => $tf, 'symbol' => $symbol], 'trading');

            return 0;
        }

        $lastCandle = $candles[array_key_last($candles)];
        $candleOpenTime = (string) $lastCandle['open_time'];

        if (!$this->isCandleFullyClosed($candleOpenTime, $intervalCode)) {
            $this->logger->info('Пропуск ТФ: последняя свеча ещё не закрыта.', [
                'tf' => $tf,
                'candle_open_time' => $candleOpenTime,
            ], 'trading');

            return 0;
        }

        $minBody = (float) ($grid['min_body'][$tf] ?? 0);
        $sequence = $this->analyzer->currentSequence($candles, $minBody);
        $count = (int) ($sequence['count'] ?? 0);
        $direction = $sequence['direction'] ?? null;

        if ($count <= 0 || $direction === null) {
            $this->logger->info('Нет серии для сигнала.', [
                'tf' => $tf,
                'min_body' => $minBody,
                'reason' => $sequence['reason'] ?? $sequence['label'] ?? null,
                'candle_open_time' => $candleOpenTime,
            ], 'trading');

            return 0;
        }

        // Любой включённый уровень bars: серия должна быть >= bars.
        // Берём максимальный подходящий уровень (напр. серия 5 при уровнях 3 и 6 → сигнал на 3).
        $candidates = [];
        foreach ($enabledRows as $row) {
            $levelBars = (int) ($row['bars'] ?? 0);
            if ($levelBars > 0 && $count >= $levelBars) {
                $candidates[] = $row;
            }
        }
        usort(
            $candidates,
            static fn (array $a, array $b): int => ((int) ($b['bars'] ?? 0)) <=> ((int) ($a['bars'] ?? 0))
        );

        if ($candidates === []) {
            $enabledBars = array_map(static fn (array $row): int => (int) ($row['bars'] ?? 0), $enabledRows);
            $this->logger->info('Серия короче всех включённых уровней матрицы.', [
                'tf' => $tf,
                'sequence' => $count . ' ' . $direction,
                'enabled_bars' => $enabledBars,
                'candle_open_time' => $candleOpenTime,
            ], 'trading');

            return 0;
        }

        $row = $candidates[0];
        $levelBars = (int) $row['bars'];
        $side = $direction === 'up' ? 'Sell' : 'Buy';
        $firstOpen = (float) ($sequence['first_open'] ?? 0);
        $lastClose = (float) ($sequence['last_close'] ?? $lastCandle['close_price']);
        $diff = (float) ($sequence['diff'] ?? ($lastClose - $firstOpen));
        $created = 0;

        $signalType = sprintf('grid_%s_%s_%d', $tf, $direction, $levelBars);
        $message = $this->buildMessage(
            $symbol,
            $tf,
            $direction,
            $side,
            $count,
            $lastCandle,
            $row,
            $firstOpen,
            $lastClose,
            $diff,
            $levelBars
        );
        $payload = [
            'source' => 'signal_grid',
            'interval' => $tf,
            'direction' => $direction,
            'level_bars' => $levelBars,
            'sequence_bars' => $count,
            'min_body' => $minBody,
            'size' => $row['size'] ?? null,
            'reserve' => $row['reserve'] ?? null,
            'stop' => $row['stop'] ?? null,
            'profit' => $row['profit'] ?? null,
            'order_enabled' => !empty($row['order']),
            'first_open' => $firstOpen,
            'last_close' => $lastClose,
            'diff' => $diff,
            'candle_closed_at' => $this->candleCloseTimeUtc($candleOpenTime, $intervalCode),
            'telegram_text' => $message,
        ];

        $signalId = $this->signals->createOnceForClosedCandle(
            null,
            $symbol,
            $side,
            $signalType,
            $count,
            $candleOpenTime,
            (string) $lastCandle['close_price'],
            $payload,
        );

        if ($signalId === null) {
            $this->logger->info('Сигнал для этой закрытой свечи уже был создан.', [
                'tf' => $tf,
                'signal_type' => $signalType,
                'candle_open_time' => $candleOpenTime,
                'level_bars' => $levelBars,
                'sequence_bars' => $count,
            ], 'trading');

            return 0;
        }

        $created++;
        $this->logger->info(
            'Создан сигнал по закрытой свече.',
            [
                'signal_id' => $signalId,
                'tf' => $tf,
                'direction' => $direction,
                'level_bars' => $levelBars,
                'sequence_bars' => $count,
                'side' => $side,
                'candle_open_time' => $candleOpenTime,
                'price' => $lastCandle['close_price'],
            ],
            'trading'
        );

        $sent = $this->telegram->send($message, [
            'signal_id' => $signalId,
            'tf' => $tf,
            'symbol' => $symbol,
            'candle_open_time' => $candleOpenTime,
            'level_bars' => $levelBars,
        ]);
        if ($sent) {
            $this->signals->markTelegramSent($signalId);
        } else {
            $this->logger->error(
                'Не удалось отправить сигнал в Telegram.',
                [
                    'signal_id' => $signalId,
                    'tf' => $tf,
                    'candle_open_time' => $candleOpenTime,
                    'level_bars' => $levelBars,
                ],
                'telegram'
            );
        }

        if (!empty($row['order'])) {
            $this->logger->info(
                'Для сигнала включён ордер (исполнение ордеров из матрицы — следующий этап).',
                ['signal_id' => $signalId, 'tf' => $tf, 'size' => $row['size'] ?? null],
                'trading'
            );
        }

        return $created;
    }

    /** Свеча полностью закрыта по времени UTC (без узкого окна — дедуп по open_time). */
    private function isCandleFullyClosed(string $openTime, string $intervalCode, ?int $now = null): bool
    {
        $now = $now ?? time();
        $openTs = strtotime($openTime . ' UTC');
        if ($openTs === false) {
            return false;
        }

        return $now >= ($openTs + Intervals::durationSeconds($intervalCode));
    }

    private function candleCloseTimeUtc(string $openTime, string $intervalCode): string
    {
        $openTs = strtotime($openTime . ' UTC');
        if ($openTs === false) {
            return $openTime;
        }

        return gmdate('Y-m-d H:i:s', $openTs + Intervals::durationSeconds($intervalCode));
    }

    private function retryPendingTelegram(): void
    {
        foreach ($this->signals->pendingTelegram(20) as $row) {
            $payload = [];
            if (!empty($row['payload'])) {
                try {
                    $decoded = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
                    $payload = is_array($decoded) ? $decoded : [];
                } catch (\Throwable) {
                    $payload = [];
                }
            }

            $message = (string) ($payload['telegram_text'] ?? '');
            if ($message === '') {
                $message = sprintf(
                    "Сигнал #%d\n%s %s\n%s × %s\nЦена: %s",
                    (int) $row['id'],
                    htmlspecialchars((string) $row['signal_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars((string) $row['symbol'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $row['side'] === 'Buy' ? 'LONG' : 'SHORT',
                    (string) $row['candle_count'],
                    htmlspecialchars((string) $row['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }

            $sent = $this->telegram->send($message, [
                'signal_id' => (int) $row['id'],
                'retry' => true,
            ]);
            if ($sent) {
                $this->signals->markTelegramSent((int) $row['id']);
            }
        }
    }

    /**
     * @param array<string, mixed> $lastCandle
     * @param array<string, mixed> $row
     */
    private function buildMessage(
        string $symbol,
        string $tf,
        string $direction,
        string $side,
        int $count,
        array $lastCandle,
        array $row,
        float $firstOpen,
        float $lastClose,
        float $diff,
        int $levelBars,
    ): string {
        $dirRu = $direction === 'up' ? 'вверх' : 'вниз';
        $sideRu = $side === 'Buy' ? 'LONG' : 'SHORT';
        $diffSign = $diff > 0 ? '+' : '';

        return sprintf(
            "🔔 <b>Сигнал %s</b>\n" .
            "Пара: <b>%s</b>\n" .
            "Уровень матрицы: <b>%d баров</b>\n" .
            "Серия сейчас: <b>%d %s</b>\n" .
            "Сторона: <b>%s</b>\n" .
            "Открытие 1-й свечи: <b>%s</b>\n" .
            "Закрытие последней: <b>%s</b>\n" .
            "Разница: <b>%s%s</b>\n" .
            "Размер: %s | Запас: %s\n" .
            "Стоп: %s | Профит: %s\n" .
            "Ордер в матрице: %s\n" .
            "Свеча: %s",
            htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $levelBars,
            $count,
            $dirRu,
            $sideRu,
            htmlspecialchars($this->formatPrice($firstOpen), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->formatPrice($lastClose), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $diffSign,
            htmlspecialchars($this->formatPrice($diff), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['size'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['reserve'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['stop'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['profit'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            !empty($row['order']) ? 'вкл' : 'выкл',
            htmlspecialchars((string) $lastCandle['open_time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    private function formatPrice(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * @return array{
     *   min_body: array<string, int|float>,
     *   timeframes: array<string, list<array<string, mixed>>>
     * }
     */
    private function loadGrid(): array
    {
        $raw = $this->settings->get(SignalGridConfig::SETTING_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return SignalGridConfig::normalize($decoded);
    }
}
