<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Helpers\Logger;
use App\Telegram\Bot;

/**
 * Сигнал, когда SMA(28) находится между low и high последней закрытой свечи ТФ.
 */
final class MaTouchProcessor
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
        $enabled = MaTouchConfig::enabledTimeframes($config);
        if ($enabled === []) {
            return 0;
        }

        $created = 0;
        $map = Intervals::chartMap();
        foreach ($enabled as $tf) {
            $code = $map[$tf] ?? null;
            if ($code === null) {
                continue;
            }
            $created += $this->processTimeframe($symbol, $tf, $code);
        }

        return $created;
    }

    private function processTimeframe(string $symbol, string $tf, string $intervalCode): int
    {
        $need = MaTouchConfig::PERIOD + 5;
        $rows = $this->candles->latestConfirmedOhlc($symbol, $intervalCode, $need);
        if (count($rows) < MaTouchConfig::PERIOD) {
            $this->logger->info('MA28: недостаточно свечей.', [
                'tf' => $tf,
                'have' => count($rows),
                'need' => MaTouchConfig::PERIOD,
            ], 'trading');

            return 0;
        }

        $last = $rows[array_key_last($rows)];
        $candleOpenTime = (string) $last['open_time'];
        if (!$this->isCandleFullyClosed($candleOpenTime, $intervalCode)) {
            return 0;
        }

        $ma = $this->smaClose($rows, MaTouchConfig::PERIOD);
        if ($ma === null) {
            return 0;
        }

        $low = (float) $last['low_price'];
        $high = (float) $last['high_price'];
        $close = (float) $last['close_price'];
        $open = (float) $last['open_price'];

        if ($ma < $low || $ma > $high) {
            return 0;
        }

        $side = $close >= $ma ? 'Buy' : 'Sell';
        $signalType = 'ma28_touch_' . $tf;
        $message = $this->buildMessage($symbol, $tf, $side, $ma, $low, $high, $open, $close, $candleOpenTime);
        $payload = [
            'source' => 'ma_touch',
            'interval' => $tf,
            'ma_period' => MaTouchConfig::PERIOD,
            'ma' => $ma,
            'low' => $low,
            'high' => $high,
            'open' => $open,
            'close' => $close,
            'telegram_text' => $message,
        ];

        $signalId = $this->signals->createOnceForClosedCandle(
            null,
            $symbol,
            $side,
            $signalType,
            MaTouchConfig::PERIOD,
            $candleOpenTime,
            (string) $close,
            $payload,
        );

        if ($signalId === null) {
            return 0;
        }

        $this->logger->info('MA28: сигнал — средняя между low/high свечи.', [
            'signal_id' => $signalId,
            'tf' => $tf,
            'ma' => $ma,
            'low' => $low,
            'high' => $high,
            'close' => $close,
            'side' => $side,
            'candle_open_time' => $candleOpenTime,
        ], 'trading');

        $sent = $this->telegram->send($message, [
            'signal_id' => $signalId,
            'tf' => $tf,
            'source' => 'ma_touch',
        ]);
        if ($sent) {
            $this->signals->markTelegramSent($signalId);
        } else {
            $this->logger->error('MA28: не удалось отправить в Telegram.', [
                'signal_id' => $signalId,
                'tf' => $tf,
            ], 'telegram');
        }

        return 1;
    }

    /**
     * @param list<array{close_price:string}> $rows
     */
    private function smaClose(array $rows, int $period): ?float
    {
        $n = count($rows);
        if ($n < $period) {
            return null;
        }
        $sum = 0.0;
        for ($i = $n - $period; $i < $n; $i++) {
            $sum += (float) $rows[$i]['close_price'];
        }

        return $sum / $period;
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

    private function buildMessage(
        string $symbol,
        string $tf,
        string $side,
        float $ma,
        float $low,
        float $high,
        float $open,
        float $close,
        string $candleOpenTime,
    ): string {
        $sideRu = $side === 'Buy' ? 'LONG' : 'SHORT';

        return sprintf(
            "📈 <b>MA%d между Low/High</b>\n" .
            "Пара: <b>%s</b>\n" .
            "ТФ: <b>%s</b>\n" .
            "Сторона: <b>%s</b>\n" .
            "MA%d: <b>%s</b>\n" .
            "Low: <b>%s</b> · High: <b>%s</b>\n" .
            "Open: <b>%s</b> · Close: <b>%s</b>\n" .
            "Свеча: %s",
            MaTouchConfig::PERIOD,
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $sideRu,
            MaTouchConfig::PERIOD,
            htmlspecialchars($this->fmt($ma), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($low), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($high), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($open), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($close), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($candleOpenTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    private function fmt(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * @return array{timeframes: array<string, bool>}
     */
    private function loadConfig(): array
    {
        $raw = $this->settings->get(MaTouchConfig::SETTING_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $decoded = null;
            }
        }

        return MaTouchConfig::normalize($decoded);
    }
}
