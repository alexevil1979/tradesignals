<?php
declare(strict_types=1);

namespace App\Strategy;

use App\Bybit\OrderService;
use App\Bybit\PositionService;
use App\Bybit\InstrumentService;
use App\Helpers\Logger;
use App\Telegram\Bot;

final class TradingProcessor
{
    public function __construct(
        private readonly RuleEngine $engine,
        private readonly GridManager $grid,
        private readonly SignalRepository $signals,
        private readonly OrderService $orders,
        private readonly PositionService $positions,
        private readonly InstrumentService $instruments,
        private readonly Bot $telegram,
        private readonly Logger $logger,
        private readonly bool $tradingEnabled,
    ) {
    }

    /**
     * @param array<string, mixed> $strategy
     * @param list<array{open_time:string,open_price:string,close_price:string}> $candles
     */
    public function process(array $strategy, string $symbol, array $candles): void
    {
        $evaluation = $this->engine->evaluate($strategy, $candles);
        if ($evaluation === null) {
            return;
        }

        $lastCandle = $candles[array_key_last($candles)];
        $volume = $this->grid->volumeForLevel(
            $strategy['volumes'],
            $strategy['min_count'],
            $evaluation['candle_count'],
        );
        $signalId = $this->signals->createOnce(
            $strategy['id'],
            $symbol,
            $evaluation['side'],
            $strategy['rule_type'],
            $evaluation['candle_count'],
            $lastCandle['open_time'],
            $lastCandle['close_price'],
            ['strategy_name' => $strategy['name'], 'volume' => $volume],
        );
        if ($signalId === null) {
            return;
        }

        $message = sprintf(
            'Сигнал <b>%s</b>: %s %s BTCUSDT, уровень %d, объём %s BTC, цена %s.',
            htmlspecialchars($strategy['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $evaluation['side'] === 'Buy' ? 'LONG' : 'SHORT',
            $symbol,
            $evaluation['candle_count'],
            $volume,
            $lastCandle['close_price'],
        );
        if ($this->telegram->send($message)) {
            $this->signals->markTelegramSent($signalId);
        }

        if (!$this->tradingEnabled) {
            $this->logger->info('Сигнал зарегистрирован в режиме dry-run.', ['signal_id' => $signalId], 'trading');
            return;
        }

        $position = $this->positions->fetch($symbol);
        if ($this->grid->shouldCloseOnReverse($strategy['close_on_reverse'], $position['side'] ?? null, $evaluation['side'])) {
            $this->orders->closePosition($symbol, $position, $signalId);
            $position = null;
        }

        $price = (float) $lastCandle['close_price'];
        $takeProfit = $this->targetPrice($symbol, $price, $evaluation['side'], (float) $strategy['take_profit_percent'], true);
        $stopLoss = $this->targetPrice($symbol, $price, $evaluation['side'], (float) $strategy['stop_loss_percent'], false);
        $this->orders->placeMarketOrder(
            symbol: $symbol,
            side: $evaluation['side'],
            quantity: $volume,
            takeProfit: $takeProfit,
            stopLoss: $stopLoss,
            strategyId: $strategy['id'],
            signalId: $signalId,
        );
        $this->positions->sync($symbol, $this->positions->fetch($symbol));
    }

    private function targetPrice(string $symbol, float $price, string $side, float $percent, bool $isTakeProfit): string
    {
        $direction = $side === 'Buy' ? 1 : -1;
        $multiplier = 1 + ($direction * ($isTakeProfit ? $percent : -$percent) / 100);

        return $this->instruments->formatPrice($symbol, $price * $multiplier);
    }
}
