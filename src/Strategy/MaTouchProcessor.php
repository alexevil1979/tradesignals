<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Bybit\InstrumentService;
use App\Bybit\OrderService;
use App\Bybit\PositionService;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Helpers\Logger;
use App\Telegram\Bot;
use Throwable;

/**
 * MA28: касание (сигнал) + переходы close через MA с ордером после N обновлений экстремума.
 */
final class MaTouchProcessor
{
    private bool $testMode = false;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CandleRepository $candles,
        private readonly SignalRepository $signals,
        private readonly Bot $telegram,
        private readonly Logger $logger,
        private readonly ?OrderService $orders = null,
        private readonly ?PositionService $positions = null,
        private readonly ?InstrumentService $instruments = null,
        private readonly bool $tradingEnabled = false,
    ) {
    }

    public function process(string $symbol): int
    {
        $config = $this->loadConfig();
        $enabled = MaTouchConfig::enabledTimeframes($config);
        if ($enabled === []) {
            return 0;
        }

        $this->testMode = !empty($config['test_mode']);
        $state = $this->loadState();
        $created = 0;
        $map = Intervals::chartMap();
        $stateDirty = false;

        foreach ($enabled as $tf) {
            $code = $map[$tf] ?? null;
            if ($code === null) {
                continue;
            }
            $before = $state[$tf] ?? MaTouchConfig::emptyTfState();
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
        $need = MaTouchConfig::PERIOD + 10;
        $rows = $this->candles->latestConfirmedOhlc($symbol, $intervalCode, $need);
        if (count($rows) < MaTouchConfig::PERIOD + 1) {
            $this->logInfo('недостаточно свечей.', [
                'tf' => $tf,
                'have' => count($rows),
                'need' => MaTouchConfig::PERIOD + 1,
            ]);

            return ['created' => 0, 'state' => $tfState];
        }

        $lastIdx = array_key_last($rows);
        $last = $rows[$lastIdx];
        $candleOpenTime = (string) $last['open_time'];
        if (!$this->isCandleFullyClosed($candleOpenTime, $intervalCode)) {
            return ['created' => 0, 'state' => $tfState];
        }

        $created = 0;
        if (!empty($config['touch_enabled'])) {
            $created += $this->processTouch($symbol, $tf, $rows, $last, $candleOpenTime);
        }

        $crossOn = !empty($config['cross_down_enabled']) || !empty($config['cross_up_enabled']);
        if ($crossOn) {
            $crossResult = $this->processCross(
                $symbol,
                $tf,
                $intervalCode,
                $config,
                $tfState,
                $rows,
                $candleOpenTime,
            );
            $created += $crossResult['created'];
            $tfState = $crossResult['state'];
        }

        return ['created' => $created, 'state' => $tfState];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $last
     */
    private function processTouch(
        string $symbol,
        string $tf,
        array $rows,
        array $last,
        string $candleOpenTime,
    ): int {
        $ma = $this->smaAt($rows, array_key_last($rows), MaTouchConfig::PERIOD);
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
        $message = $this->buildTouchMessage($symbol, $tf, $side, $ma, $low, $high, $open, $close, $candleOpenTime);
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

        $this->logInfo('касание — MA между low/high.', [
            'signal_id' => $signalId,
            'tf' => $tf,
            'ma' => $ma,
            'side' => $side,
            'candle_open_time' => $candleOpenTime,
        ]);

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
     * @param array<string, mixed> $config
     * @param array<string, mixed> $tfState
     * @param list<array<string, mixed>> $rows
     * @return array{created: int, state: array<string, mixed>}
     */
    private function processCross(
        string $symbol,
        string $tf,
        string $intervalCode,
        array $config,
        array $tfState,
        array $rows,
        string $candleOpenTime,
    ): array {
        $created = 0;
        $n = count($rows);
        $lastIdx = $n - 1;
        $prevIdx = $n - 2;

        $maNow = $this->smaAt($rows, $lastIdx, MaTouchConfig::PERIOD);
        $maPrev = $this->smaAt($rows, $prevIdx, MaTouchConfig::PERIOD);
        if ($maNow === null || $maPrev === null) {
            return ['created' => 0, 'state' => $tfState];
        }

        $last = $rows[$lastIdx];
        $prev = $rows[$prevIdx];
        $closeNow = (float) $last['close_price'];
        $closePrev = (float) $prev['close_price'];
        $lowNow = (float) $last['low_price'];
        $highNow = (float) $last['high_price'];

        $relNow = $closeNow >= $maNow ? 'above' : 'below';
        $relPrev = $closePrev >= $maPrev ? 'above' : 'below';

        // Фаза ожидания закрытия позиции / ордера.
        if (in_array($tfState['phase'] ?? '', ['ordered', 'wait_close'], true)) {
            $tfState = $this->syncOrderPhase($symbol, $tf, $tfState, $closeNow);
            if (($tfState['last_candle'] ?? null) !== $candleOpenTime) {
                $tfState['last_candle'] = $candleOpenTime;
                $tfState['prev_rel'] = $relNow;
            }

            return ['created' => 0, 'state' => $tfState];
        }

        // Не дублируем обработку одной и той же закрытой свечи.
        if (($tfState['last_candle'] ?? null) === $candleOpenTime) {
            return ['created' => 0, 'state' => $tfState];
        }

        $crossDown = $relPrev === 'above' && $relNow === 'below';
        $crossUp = $relPrev === 'below' && $relNow === 'above';

        if ($crossDown && !empty($config['cross_down_enabled'])) {
            $tfState = MaTouchConfig::emptyTfState();
            $tfState['phase'] = 'tracking';
            $tfState['direction'] = 'down';
            $tfState['local_extreme'] = $lowNow;
            $tfState['updates'] = 1;
            $tfState['signal_close'] = $closeNow;
            $tfState['signal_candle'] = $candleOpenTime;
            $tfState['last_candle'] = $candleOpenTime;
            $tfState['prev_rel'] = $relNow;
            $this->logInfo('переход сверху вниз — старт трека лоя.', [
                'tf' => $tf,
                'ma' => $maNow,
                'close' => $closeNow,
                'low' => $lowNow,
                'candle' => $candleOpenTime,
            ]);
            $this->notifyCross($symbol, $tf, 'down', $maNow, $closeNow, $lowNow, $highNow, $candleOpenTime, 1, (int) $config['local_bars']);

            if ($tfState['updates'] >= (int) $config['local_bars']) {
                $placed = $this->placeCrossOrder($symbol, $tf, $config, $tfState);
                $created += $placed['created'];
                $tfState = $placed['state'];
            }

            return ['created' => $created, 'state' => $tfState];
        }

        if ($crossUp && !empty($config['cross_up_enabled'])) {
            $tfState = MaTouchConfig::emptyTfState();
            $tfState['phase'] = 'tracking';
            $tfState['direction'] = 'up';
            $tfState['local_extreme'] = $highNow;
            $tfState['updates'] = 1;
            $tfState['signal_close'] = $closeNow;
            $tfState['signal_candle'] = $candleOpenTime;
            $tfState['last_candle'] = $candleOpenTime;
            $tfState['prev_rel'] = $relNow;
            $this->logInfo('переход снизу вверх — старт трека хая.', [
                'tf' => $tf,
                'ma' => $maNow,
                'close' => $closeNow,
                'high' => $highNow,
                'candle' => $candleOpenTime,
            ]);
            $this->notifyCross($symbol, $tf, 'up', $maNow, $closeNow, $lowNow, $highNow, $candleOpenTime, 1, (int) $config['local_bars']);

            if ($tfState['updates'] >= (int) $config['local_bars']) {
                $placed = $this->placeCrossOrder($symbol, $tf, $config, $tfState);
                $created += $placed['created'];
                $tfState = $placed['state'];
            }

            return ['created' => $created, 'state' => $tfState];
        }

        // Продолжение трека локального экстремума.
        if (($tfState['phase'] ?? '') === 'tracking') {
            $direction = (string) ($tfState['direction'] ?? '');
            $extreme = $tfState['local_extreme'];
            $updated = false;

            if ($direction === 'down' && is_numeric($extreme) && $lowNow < (float) $extreme) {
                $tfState['local_extreme'] = $lowNow;
                $tfState['updates'] = (int) $tfState['updates'] + 1;
                $tfState['signal_close'] = $closeNow;
                $tfState['signal_candle'] = $candleOpenTime;
                $updated = true;
            } elseif ($direction === 'up' && is_numeric($extreme) && $highNow > (float) $extreme) {
                $tfState['local_extreme'] = $highNow;
                $tfState['updates'] = (int) $tfState['updates'] + 1;
                $tfState['signal_close'] = $closeNow;
                $tfState['signal_candle'] = $candleOpenTime;
                $updated = true;
            }

            $tfState['last_candle'] = $candleOpenTime;
            $tfState['prev_rel'] = $relNow;

            if ($updated) {
                $this->logInfo('обновление локального экстремума.', [
                    'tf' => $tf,
                    'direction' => $direction,
                    'updates' => $tfState['updates'],
                    'need' => (int) $config['local_bars'],
                    'extreme' => $tfState['local_extreme'],
                    'close' => $closeNow,
                ]);
                $this->notifyTelegram(sprintf(
                    "📉 <b>MA%d экстремум%s</b>\nТФ: <b>%s</b>\nНаправление: <b>%s</b>\nЭкстремум: <b>%s</b>\nClose: <b>%s</b>\nОбновлений: <b>%d/%d</b>",
                    MaTouchConfig::PERIOD,
                    $this->testMode ? ' [TEST]' : '',
                    htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $direction === 'down' ? 'лой ↓' : 'хай ↑',
                    htmlspecialchars($this->fmt((float) $tfState['local_extreme']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($this->fmt($closeNow), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    (int) $tfState['updates'],
                    (int) $config['local_bars']
                ), $tf);
            }

            if ($updated && (int) $tfState['updates'] >= (int) $config['local_bars']) {
                $placed = $this->placeCrossOrder($symbol, $tf, $config, $tfState);
                $created += $placed['created'];
                $tfState = $placed['state'];
            }

            return ['created' => $created, 'state' => $tfState];
        }

        $tfState['last_candle'] = $candleOpenTime;
        $tfState['prev_rel'] = $relNow;

        return ['created' => 0, 'state' => $tfState];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $tfState
     * @return array{created: int, state: array<string, mixed>}
     */
    private function placeCrossOrder(
        string $symbol,
        string $tf,
        array $config,
        array $tfState,
    ): array {
        $direction = (string) ($tfState['direction'] ?? '');
        $close = (float) ($tfState['signal_close'] ?? 0);
        $buffer = (float) $config['buffer'];
        $tpPts = (float) $config['tp_points'];
        $slPts = (float) $config['sl_points'];
        $qty = (string) $config['order_size'];

        if ($direction === 'down') {
            $side = 'Buy';
            $entry = $close - $buffer;
            $tp = $entry + $tpPts;
            $sl = $entry - $slPts;
        } else {
            $side = 'Sell';
            $entry = $close + $buffer;
            $tp = $entry - $tpPts;
            $sl = $entry + $slPts;
        }

        if ($entry <= 0) {
            $this->logWarning('некорректная цена входа, ордер пропущен.', [
                'tf' => $tf,
                'close' => $close,
                'buffer' => $buffer,
            ]);

            return ['created' => 0, 'state' => $tfState];
        }

        if (!$this->testMode && !$this->tradingEnabled) {
            $this->logInfo('trading_enabled=0, ордер перехода не выставляем.', ['tf' => $tf]);
            $tfState['phase'] = 'idle';
            $tfState['direction'] = null;

            return ['created' => 0, 'state' => $tfState];
        }

        if (!$this->testMode && ($this->orders === null || $this->instruments === null)) {
            $this->logWarning('OrderService недоступен.', ['tf' => $tf]);

            return ['created' => 0, 'state' => $tfState];
        }

        $entryStr = $this->fmtPrice($symbol, $entry);
        $tpStr = $this->fmtPrice($symbol, $tp);
        $slStr = $this->fmtPrice($symbol, $sl);
        $linkId = 'ma28' . substr(bin2hex(random_bytes(6)), 0, 10) . $tf;

        $signalType = 'ma28_cross_' . $direction . '_' . $tf;
        $message = $this->buildCrossOrderMessage(
            $symbol,
            $tf,
            $direction,
            $side,
            (float) $entryStr,
            (float) $tpStr,
            (float) $slStr,
            $qty,
            (string) ($tfState['signal_candle'] ?? ''),
            (int) $tfState['updates'],
        );
        $signalId = $this->signals->createOnceForClosedCandle(
            null,
            $symbol,
            $side,
            $signalType,
            MaTouchConfig::PERIOD,
            (string) ($tfState['signal_candle'] ?? ''),
            $entryStr,
            [
                'source' => 'ma_cross',
                'direction' => $direction,
                'interval' => $tf,
                'entry' => (float) $entryStr,
                'tp' => (float) $tpStr,
                'sl' => (float) $slStr,
                'updates' => $tfState['updates'],
                'telegram_text' => $message,
            ],
        );

        if ($this->testMode) {
            $this->logInfo('эмуляция: лимит после перехода.', [
                'tf' => $tf,
                'link_id' => $linkId,
                'side' => $side,
                'price' => $entryStr,
                'tp' => $tpStr,
                'sl' => $slStr,
                'qty' => $qty,
            ]);
        } else {
            try {
                $this->orders->placeLimitOrder(
                    symbol: $symbol,
                    side: $side,
                    quantity: $qty,
                    price: $entryStr,
                    orderLinkId: $linkId,
                    takeProfit: $tpStr,
                    stopLoss: $slStr,
                    signalId: $signalId,
                );
                $this->logInfo('лимит после перехода отправлен.', [
                    'tf' => $tf,
                    'link_id' => $linkId,
                    'side' => $side,
                    'price' => $entryStr,
                    'tp' => $tpStr,
                    'sl' => $slStr,
                ]);
            } catch (Throwable $exception) {
                $this->logError('ошибка постановки лимита перехода.', [
                    'tf' => $tf,
                    'link_id' => $linkId,
                    'error' => $exception->getMessage(),
                ]);
                $tfState['phase'] = 'idle';

                return ['created' => 0, 'state' => $tfState];
            }
        }

        if ($signalId !== null) {
            try {
                $sent = $this->telegram->send($message, [
                    'signal_id' => $signalId,
                    'tf' => $tf,
                    'source' => 'ma_cross',
                ]);
                if ($sent) {
                    $this->signals->markTelegramSent($signalId);
                }
            } catch (Throwable) {
                // ignore
            }
        }

        $tfState['phase'] = 'ordered';
        $tfState['order_link_id'] = $linkId;
        $tfState['side'] = $side;
        $tfState['entry'] = (float) $entryStr;
        $tfState['tp'] = (float) $tpStr;
        $tfState['sl'] = (float) $slStr;
        $tfState['test_position_open'] = false;

        return ['created' => 1, 'state' => $tfState];
    }

    /**
     * @param array<string, mixed> $tfState
     * @return array<string, mixed>
     */
    private function syncOrderPhase(string $symbol, string $tf, array $tfState, float $lastClose): array
    {
        $side = (string) ($tfState['side'] ?? 'Buy');
        $tp = $tfState['tp'] ?? null;
        $sl = $tfState['sl'] ?? null;
        $link = (string) ($tfState['order_link_id'] ?? '');

        if ($this->testMode) {
            if (!empty($tfState['test_position_open']) && is_numeric($tp) && is_numeric($sl)) {
                $hitTp = $side === 'Buy' ? $lastClose >= (float) $tp : $lastClose <= (float) $tp;
                $hitSl = $side === 'Buy' ? $lastClose <= (float) $sl : $lastClose >= (float) $sl;
                if ($hitTp || $hitSl) {
                    $this->logInfo($hitTp ? 'эмуляция: TP перехода.' : 'эмуляция: SL перехода.', [
                        'tf' => $tf,
                        'price' => $lastClose,
                        'tp' => $tp,
                        'sl' => $sl,
                    ]);
                    $this->notifyTelegram(sprintf(
                        "%s <b>MA%d переход [TEST]</b>\nТФ: <b>%s</b>\nЦена: <b>%s</b>\nTP: <b>%s</b> · SL: <b>%s</b>",
                        $hitTp ? '✅ TP' : '🛑 SL',
                        MaTouchConfig::PERIOD,
                        htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($this->fmt($lastClose), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($this->fmt((float) $tp), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($this->fmt((float) $sl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    ), $tf);

                    return MaTouchConfig::emptyTfState();
                }
            }

            // Эмуляция fill: цена коснулась лимита.
            $entry = $tfState['entry'] ?? null;
            if (($tfState['phase'] ?? '') === 'ordered' && is_numeric($entry) && empty($tfState['test_position_open'])) {
                $hit = $side === 'Buy' ? $lastClose <= (float) $entry : $lastClose >= (float) $entry;
                if ($hit) {
                    $tfState['test_position_open'] = true;
                    $tfState['phase'] = 'wait_close';
                    $this->logInfo('эмуляция: fill перехода.', [
                        'tf' => $tf,
                        'entry' => $entry,
                        'price' => $lastClose,
                    ]);
                    $this->notifyTelegram(sprintf(
                        "📥 <b>Fill MA%d [TEST]</b>\nТФ: <b>%s</b>\n%s @ <b>%s</b>\nClose: <b>%s</b>",
                        MaTouchConfig::PERIOD,
                        htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($side, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($this->fmt((float) $entry), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        htmlspecialchars($this->fmt($lastClose), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    ), $tf);
                }
            }

            return $tfState;
        }

        if ($this->orders === null || $this->positions === null) {
            return $tfState;
        }

        try {
            $openPos = $this->positions->fetch($symbol);
        } catch (Throwable) {
            $openPos = null;
        }

        if ($openPos !== null && (float) ($openPos['size'] ?? 0) > 0) {
            $tfState['phase'] = 'wait_close';

            return $tfState;
        }

        // Нет позиции — проверяем, жив ли лимит.
        if ($link !== '' && ($tfState['phase'] ?? '') === 'ordered') {
            try {
                $order = $this->orders->findOpenOrderByLinkId($symbol, $link);
                if ($order !== null) {
                    return $tfState;
                }
            } catch (Throwable $exception) {
                $this->logWarning('не удалось проверить ордер перехода.', [
                    'tf' => $tf,
                    'error' => $exception->getMessage(),
                ]);

                return $tfState;
            }
        }

        // Ордера нет и позиции нет — цикл завершён (fill+close или отмена).
        $this->logInfo('цикл перехода завершён, возврат в idle.', ['tf' => $tf, 'link_id' => $link]);

        return MaTouchConfig::emptyTfState();
    }

    /**
     * @param list<array{close_price?: string|float}> $rows
     */
    private function smaAt(array $rows, int $endIndex, int $period): ?float
    {
        if ($endIndex < $period - 1 || $endIndex >= count($rows)) {
            return null;
        }
        $sum = 0.0;
        for ($i = $endIndex - $period + 1; $i <= $endIndex; $i++) {
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

    private function fmtPrice(string $symbol, float $price): string
    {
        try {
            if ($this->instruments !== null) {
                return $this->instruments->formatPrice($symbol, $price);
            }
        } catch (Throwable) {
            // fallback
        }

        return MaTouchConfig::formatPrice($price);
    }

    private function buildTouchMessage(
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

    private function notifyCross(
        string $symbol,
        string $tf,
        string $direction,
        float $ma,
        float $close,
        float $low,
        float $high,
        string $candle,
        int $updates,
        int $need,
    ): void {
        $title = $direction === 'down' ? 'переход сверху вниз' : 'переход снизу вверх';
        $msg = sprintf(
            "🔀 <b>MA%d %s%s</b>\nТФ: <b>%s</b>\nClose: <b>%s</b> · MA: <b>%s</b>\nLow: <b>%s</b> · High: <b>%s</b>\nТрек экстремума: <b>%d/%d</b>\nСвеча: %s",
            MaTouchConfig::PERIOD,
            $title,
            $this->testMode ? ' [TEST]' : '',
            htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($close), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($ma), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($low), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($high), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $updates,
            $need,
            htmlspecialchars($candle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        try {
            $this->telegram->send($msg, ['tf' => $tf, 'source' => 'ma_cross']);
        } catch (Throwable) {
            // ignore
        }
    }

    private function notifyTelegram(string $message, string $tf): void
    {
        try {
            $this->telegram->send($message, ['tf' => $tf, 'source' => 'ma_cross']);
        } catch (Throwable) {
            // ignore
        }
    }

    private function buildCrossOrderMessage(
        string $symbol,
        string $tf,
        string $direction,
        string $side,
        float $entry,
        float $tp,
        float $sl,
        string $qty,
        string $candle,
        int $updates,
    ): string {
        return sprintf(
            "🎯 <b>MA%d ордер после перехода%s</b>\n" .
            "Пара: <b>%s</b> · ТФ: <b>%s</b>\n" .
            "Переход: <b>%s</b> → <b>%s</b>\n" .
            "Вход: <b>%s</b> · Qty: <b>%s</b>\n" .
            "TP: <b>%s</b> · SL: <b>%s</b>\n" .
            "Обновлений экстремума: <b>%d</b>\n" .
            "Свеча сигнала: %s",
            MaTouchConfig::PERIOD,
            $this->testMode ? ' [TEST]' : '',
            htmlspecialchars($symbol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($tf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $direction === 'down' ? 'сверху↓вниз' : 'снизу↑вверх',
            $side === 'Buy' ? 'Buy' : 'Sell',
            htmlspecialchars($this->fmt($entry), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($qty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($tp), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->fmt($sl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $updates,
            htmlspecialchars($candle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    private function fmt(float $value): string
    {
        return MaTouchConfig::formatPrice($value);
    }

    private function modePrefix(): string
    {
        return $this->testMode ? '[TEST] ' : '[LIVE] ';
    }

    /** @param array<string, mixed> $context */
    private function logInfo(string $message, array $context): void
    {
        $this->logger->info('MA28: ' . $this->modePrefix() . $message, $context + [
            'test_mode' => $this->testMode,
        ], 'trading');
    }

    /** @param array<string, mixed> $context */
    private function logWarning(string $message, array $context): void
    {
        $this->logger->warning('MA28: ' . $this->modePrefix() . $message, $context + [
            'test_mode' => $this->testMode,
        ], 'trading');
    }

    /** @param array<string, mixed> $context */
    private function logError(string $message, array $context): void
    {
        $this->logger->error('MA28: ' . $this->modePrefix() . $message, $context + [
            'test_mode' => $this->testMode,
        ], 'trading');
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        $raw = $this->settings->get(MaTouchConfig::SETTING_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $decoded = null;
            }
        }

        return MaTouchConfig::normalize($decoded);
    }

    /** @return array<string, array<string, mixed>> */
    private function loadState(): array
    {
        $raw = $this->settings->get(MaTouchConfig::STATE_KEY);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $decoded = null;
            }
        }

        return MaTouchConfig::normalizeState($decoded);
    }

    /** @param array<string, array<string, mixed>> $state */
    private function saveState(array $state): void
    {
        $this->settings->set(
            MaTouchConfig::STATE_KEY,
            json_encode(MaTouchConfig::normalizeState($state), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
