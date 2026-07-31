<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Database\SettingsRepository;
use App\Helpers\Logger;
use App\Telegram\Bot;

/**
 * Сетка уровней: сигнал при пробое цены через заданный уровень (по закрытию M1).
 * Выше текущего → LONG при пробое вверх; ниже → SHORT при пробое вниз.
 */
final class LevelGridProcessor
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
        $grid = $this->loadGrid();
        if (empty($grid['enabled'])) {
            return 0;
        }

        $above = array_values(array_filter(
            $grid['above'],
            static fn (array $row): bool => !empty($row['signal'])
        ));
        $below = array_values(array_filter(
            $grid['below'],
            static fn (array $row): bool => !empty($row['signal'])
        ));
        if ($above === [] && $below === []) {
            return 0;
        }

        $candles = $this->candles->latestConfirmed($symbol, '1', 3);
        if (count($candles) < 2) {
            $this->logger->warning('Сетка уровней: недостаточно закрытых M1 свечей.', [
                'symbol' => $symbol,
                'count' => count($candles),
            ], 'trading');

            return 0;
        }

        $prev = $candles[count($candles) - 2];
        $curr = $candles[count($candles) - 1];
        $prevClose = (float) $prev['close_price'];
        $currClose = (float) $curr['close_price'];
        $candleOpenTime = (string) $curr['open_time'];

        $created = 0;
        foreach ($above as $row) {
            $level = (float) $row['price'];
            if ($prevClose < $level && $currClose >= $level) {
                $created += $this->emit($symbol, 'above', 'Buy', $level, $row, $prevClose, $currClose, $candleOpenTime);
            }
        }
        foreach ($below as $row) {
            $level = (float) $row['price'];
            if ($prevClose > $level && $currClose <= $level) {
                $created += $this->emit($symbol, 'below', 'Sell', $level, $row, $prevClose, $currClose, $candleOpenTime);
            }
        }

        $this->retryPendingTelegram();

        return $created;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function emit(
        string $symbol,
        string $sideKey,
        string $side,
        float $level,
        array $row,
        float $prevClose,
        float $currClose,
        string $candleOpenTime,
    ): int {
        $priceKey = LevelGridConfig::formatPriceKey($level);
        $signalType = sprintf('levels_%s_%s', $sideKey, $priceKey);
        $message = $this->buildMessage($symbol, $sideKey, $side, $level, $row, $prevClose, $currClose, $candleOpenTime);
        $payload = [
            'source' => 'level_grid',
            'side_key' => $sideKey,
            'level' => $level,
            'prev_close' => $prevClose,
            'curr_close' => $currClose,
            'size' => $row['size'] ?? null,
            'reserve' => $row['reserve'] ?? null,
            'stop' => $row['stop'] ?? null,
            'profit' => $row['profit'] ?? null,
            'order_enabled' => !empty($row['order']),
            'telegram_text' => $message,
        ];

        $signalId = $this->signals->createOnceForClosedCandle(
            null,
            $symbol,
            $side,
            $signalType,
            1,
            $candleOpenTime,
            (string) $currClose,
            $payload,
        );

        if ($signalId === null) {
            $this->logger->info('Сигнал уровня для этой свечи уже был создан.', [
                'signal_type' => $signalType,
                'candle_open_time' => $candleOpenTime,
                'level' => $level,
            ], 'trading');

            return 0;
        }

        $this->logger->info('Создан сигнал по пробою уровня.', [
            'signal_id' => $signalId,
            'side_key' => $sideKey,
            'side' => $side,
            'level' => $level,
            'prev_close' => $prevClose,
            'curr_close' => $currClose,
            'candle_open_time' => $candleOpenTime,
        ], 'trading');

        $sent = $this->telegram->send($message, [
            'signal_id' => $signalId,
            'level' => $level,
            'symbol' => $symbol,
            'candle_open_time' => $candleOpenTime,
        ]);
        if ($sent) {
            $this->signals->markTelegramSent($signalId);
        } else {
            $this->logger->error('Не удалось отправить сигнал уровня в Telegram.', [
                'signal_id' => $signalId,
                'level' => $level,
                'candle_open_time' => $candleOpenTime,
            ], 'telegram');
        }

        if (!empty($row['order'])) {
            $this->logger->info(
                'Для сигнала уровня включён ордер (исполнение — следующий этап).',
                ['signal_id' => $signalId, 'level' => $level, 'size' => $row['size'] ?? null],
                'trading'
            );
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildMessage(
        string $symbol,
        string $sideKey,
        string $side,
        float $level,
        array $row,
        float $prevClose,
        float $currClose,
        string $candleOpenTime,
    ): string {
        $dirRu = $sideKey === 'above' ? 'пробой вверх' : 'пробой вниз';
        $sideRu = $side === 'Buy' ? 'LONG' : 'SHORT';

        return sprintf(
            "📍 <b>Сетка уровней</b>\n" .
            "Пара: <b>%s</b>\n" .
            "Событие: <b>%s</b>\n" .
            "Уровень: <b>%s</b>\n" .
            "Сторона: <b>%s</b>\n" .
            "Пред. close: <b>%s</b>\n" .
            "Текущ. close: <b>%s</b>\n" .
            "Размер: %s | Запас: %s\n" .
            "Стоп: %s | Профит: %s\n" .
            "Ордер: %s\n" .
            "Свеча M1: %s",
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $dirRu,
            htmlspecialchars(LevelGridConfig::formatPriceKey($level), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $sideRu,
            htmlspecialchars(LevelGridConfig::formatPriceKey($prevClose), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(LevelGridConfig::formatPriceKey($currClose), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['size'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['reserve'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['stop'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) ($row['profit'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            !empty($row['order']) ? 'вкл' : 'выкл',
            htmlspecialchars($candleOpenTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
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

            if (($payload['source'] ?? '') !== 'level_grid') {
                continue;
            }

            $message = (string) ($payload['telegram_text'] ?? '');
            if ($message === '') {
                continue;
            }

            $sent = $this->telegram->send($message, [
                'signal_id' => (int) $row['id'],
                'retry' => true,
                'source' => 'level_grid',
            ]);
            if ($sent) {
                $this->signals->markTelegramSent((int) $row['id']);
            }
        }
    }

    /**
     * @return array{
     *   enabled: bool,
     *   above: list<array<string, mixed>>,
     *   below: list<array<string, mixed>>
     * }
     */
    private function loadGrid(): array
    {
        $raw = $this->settings->get(LevelGridConfig::SETTING_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return LevelGridConfig::normalize($decoded);
    }
}
