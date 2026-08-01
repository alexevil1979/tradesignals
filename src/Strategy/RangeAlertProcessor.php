<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Database\SettingsRepository;
use App\Helpers\Logger;
use App\Telegram\Bot;

/**
 * Уведомления о выходе цены из диапазона [low, high] по закрытию M1.
 * На один эпизод выхода сразу отправляется notify_count сообщений;
 * после возврата внутрь диапазона можно снова уведомлять при следующем выходе.
 */
final class RangeAlertProcessor
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
        if (empty($config['enabled'])) {
            return 0;
        }

        $low = $config['low'];
        $high = $config['high'];
        $notifyCount = (int) $config['notify_count'];
        if ($low === null || $high === null || $low >= $high) {
            $this->logger->warning('Диапазон: не заданы корректные низ/верх.', [
                'low' => $low,
                'high' => $high,
            ], 'trading');

            return 0;
        }

        $candles = $this->candles->latestConfirmed($symbol, '1', 2);
        if ($candles === []) {
            return 0;
        }

        $curr = $candles[array_key_last($candles)];
        $price = (float) $curr['close_price'];
        $candleOpenTime = (string) $curr['open_time'];
        $zone = $this->zoneForPrice($price, (float) $low, (float) $high);

        $state = $this->loadState();
        $prevZone = $state['zone'];
        $sent = (int) $state['sent'];
        $episodeKey = $state['episode_key'];

        if ($zone === 'inside') {
            if ($prevZone !== 'inside' || $sent > 0 || $episodeKey !== null) {
                $this->saveState([
                    'zone' => 'inside',
                    'sent' => 0,
                    'episode_key' => null,
                ]);
                $this->logger->info('Диапазон: цена вернулась внутрь.', [
                    'price' => $price,
                    'low' => $low,
                    'high' => $high,
                    'prev_zone' => $prevZone,
                ], 'trading');
            }

            return 0;
        }

        // Уже уведомили об этом выходе — ждём возврата внутрь.
        $alreadyNotified = $prevZone === $zone
            && $episodeKey !== null
            && $sent >= $notifyCount;
        if ($alreadyNotified) {
            return 0;
        }

        // Новый выход (смена стороны / первый уход) — новый эпизод.
        if ($prevZone !== $zone || $prevZone === 'inside' || $prevZone === null || $episodeKey === null) {
            $episodeKey = $zone . '_' . $candleOpenTime;
            $sent = 0;
        }

        $created = 0;
        $side = $zone === 'above' ? 'Buy' : 'Sell';
        $signalType = 'range_exit_' . $zone;

        for ($index = $sent + 1; $index <= $notifyCount; $index++) {
            $message = $this->buildMessage(
                $symbol,
                $zone,
                $side,
                $price,
                (float) $low,
                (float) $high,
                $index,
                $notifyCount,
                $candleOpenTime
            );
            $payload = [
                'source' => 'range_alert',
                'zone' => $zone,
                'low' => $low,
                'high' => $high,
                'notify_index' => $index,
                'notify_count' => $notifyCount,
                'episode_key' => $episodeKey,
                'telegram_text' => $message,
            ];

            $signalId = $this->signals->createOnce(
                null,
                $symbol,
                $side,
                $signalType,
                $index,
                $candleOpenTime,
                (string) $price,
                $payload,
            );

            if ($signalId === null) {
                $sent = $index;
                continue;
            }

            $created++;
            $sentOk = $this->telegram->send($message, [
                'signal_id' => $signalId,
                'source' => 'range_alert',
                'zone' => $zone,
                'notify_index' => $index,
            ]);
            if ($sentOk) {
                $this->signals->markTelegramSent($signalId);
            } else {
                $this->logger->error('Диапазон: не удалось отправить уведомление в Telegram.', [
                    'signal_id' => $signalId,
                    'zone' => $zone,
                    'notify_index' => $index,
                ], 'telegram');
            }

            $sent = $index;
        }

        $this->saveState([
            'zone' => $zone,
            'sent' => $sent,
            'episode_key' => $episodeKey,
        ]);

        if ($created > 0) {
            $this->logger->info('Диапазон: уведомления о выходе отправлены.', [
                'zone' => $zone,
                'price' => $price,
                'low' => $low,
                'high' => $high,
                'created' => $created,
                'notify_count' => $notifyCount,
            ], 'trading');
        }

        return $created;
    }

    private function zoneForPrice(float $price, float $low, float $high): string
    {
        if ($price > $high) {
            return 'above';
        }
        if ($price < $low) {
            return 'below';
        }

        return 'inside';
    }

    private function buildMessage(
        string $symbol,
        string $zone,
        string $side,
        float $price,
        float $low,
        float $high,
        int $index,
        int $notifyCount,
        string $candleOpenTime,
    ): string {
        $event = $zone === 'above' ? 'выше верхней границы' : 'ниже нижней границы';
        $sideRu = $side === 'Buy' ? 'LONG' : 'SHORT';

        return sprintf(
            "⚠️ <b>Выход из диапазона</b> (%d/%d)\n" .
            "Пара: <b>%s</b>\n" .
            "Событие: <b>%s</b>\n" .
            "Сторона: <b>%s</b>\n" .
            "Цена: <b>%s</b>\n" .
            "Диапазон: <b>%s</b> … <b>%s</b>\n" .
            "Свеча M1: %s",
            $index,
            $notifyCount,
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $event,
            $sideRu,
            htmlspecialchars(RangeAlertConfig::formatPrice($price), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(RangeAlertConfig::formatPrice($low), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(RangeAlertConfig::formatPrice($high), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($candleOpenTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * @return array{enabled: bool, low: float|int|null, high: float|int|null, notify_count: int}
     */
    private function loadConfig(): array
    {
        $raw = $this->settings->get(RangeAlertConfig::SETTING_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return RangeAlertConfig::normalize($decoded);
    }

    /**
     * @return array{zone: ?string, sent: int, episode_key: ?string}
     */
    private function loadState(): array
    {
        $raw = $this->settings->get(RangeAlertConfig::STATE_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return RangeAlertConfig::normalizeState($decoded);
    }

    /**
     * @param array{zone: ?string, sent: int, episode_key: ?string} $state
     */
    private function saveState(array $state): void
    {
        $this->settings->set(
            RangeAlertConfig::STATE_KEY,
            json_encode(RangeAlertConfig::normalizeState($state), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
