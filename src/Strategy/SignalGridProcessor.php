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

        $candles = $this->candles->latestConfirmed($symbol, $intervalCode, $maxBars + 5);
        if ($candles === []) {
            $this->logger->warning('Нет закрытых свечей для сигнала.', ['tf' => $tf, 'symbol' => $symbol], 'trading');

            return 0;
        }

        $minBody = (float) ($grid['min_body'][$tf] ?? 0);
        $sequence = $this->analyzer->currentSequence($candles, $minBody);
        if (($sequence['count'] ?? 0) <= 0 || ($sequence['direction'] ?? null) === null) {
            return 0;
        }

        $count = (int) $sequence['count'];
        $direction = (string) $sequence['direction'];
        $side = $direction === 'up' ? 'Sell' : 'Buy';
        $lastCandle = $candles[array_key_last($candles)];
        $created = 0;

        foreach ($enabledRows as $row) {
            if ((int) ($row['bars'] ?? 0) !== $count) {
                continue;
            }

            $signalType = sprintf('grid_%s_%s', $tf, $direction);
            $message = $this->buildMessage($symbol, $tf, $direction, $side, $count, $lastCandle, $row);
            $payload = [
                'source' => 'signal_grid',
                'interval' => $tf,
                'direction' => $direction,
                'min_body' => $minBody,
                'size' => $row['size'] ?? null,
                'reserve' => $row['reserve'] ?? null,
                'stop' => $row['stop'] ?? null,
                'profit' => $row['profit'] ?? null,
                'order_enabled' => !empty($row['order']),
                'telegram_text' => $message,
            ];

            $signalId = $this->signals->createOnce(
                null,
                $symbol,
                $side,
                $signalType,
                $count,
                (string) $lastCandle['open_time'],
                (string) $lastCandle['close_price'],
                $payload,
            );

            if ($signalId === null) {
                continue;
            }

            $created++;
            $this->logger->info(
                'Создан сигнал по матрице.',
                [
                    'signal_id' => $signalId,
                    'tf' => $tf,
                    'direction' => $direction,
                    'bars' => $count,
                    'side' => $side,
                    'price' => $lastCandle['close_price'],
                ],
                'trading'
            );

            $sent = $this->telegram->send($message, [
                'signal_id' => $signalId,
                'tf' => $tf,
                'symbol' => $symbol,
            ]);
            if ($sent) {
                $this->signals->markTelegramSent($signalId);
            } else {
                $this->logger->error(
                    'Не удалось отправить сигнал в Telegram.',
                    ['signal_id' => $signalId, 'tf' => $tf],
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
        }

        return $created;
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
    ): string {
        $dirRu = $direction === 'up' ? 'вверх' : 'вниз';
        $sideRu = $side === 'Buy' ? 'LONG' : 'SHORT';

        return sprintf(
            "🔔 <b>Сигнал %s</b>\n" .
            "Пара: <b>%s</b>\n" .
            "Серия: <b>%d %s</b>\n" .
            "Сторона: <b>%s</b>\n" .
            "Цена: <b>%s</b>\n" .
            "Размер: %s | Запас: %s\n" .
            "Стоп: %s | Профит: %s\n" .
            "Ордер в матрице: %s\n" .
            "Свеча: %s",
            htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $count,
            $dirRu,
            $sideRu,
            htmlspecialchars((string) $lastCandle['close_price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['size'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['reserve'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['stop'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['profit'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            !empty($row['order']) ? 'вкл' : 'выкл',
            htmlspecialchars((string) $lastCandle['open_time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
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
