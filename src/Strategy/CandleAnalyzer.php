<?php
declare(strict_types=1);

namespace App\Strategy;

final class CandleAnalyzer
{
    /** @param list<array{open_price:string|float, close_price:string|float}> $candles */
    public function countTrailingDirection(array $candles, string $direction): int
    {
        $count = 0;
        for ($index = count($candles) - 1; $index >= 0; $index--) {
            $candle = $candles[$index];
            $isUp = (float) $candle['close_price'] > (float) $candle['open_price'];
            $isDown = (float) $candle['close_price'] < (float) $candle['open_price'];
            if (($direction === 'up' && $isUp) || ($direction === 'down' && $isDown)) {
                $count++;
                continue;
            }
            break;
        }

        return $count;
    }

    /**
     * Текущая хвостовая последовательность с учётом мин. размера тела бара.
     * Бар с телом меньше порога обнуляет последовательность.
     *
     * @param list<array{open:float|int|string, close:float|int|string}|array{open_price:string|float, close_price:string|float}> $candles
     * @return array{count:int, direction:'up'|'down'|null, label:string}
     */
    public function currentSequence(array $candles, float $minBody): array
    {
        $empty = ['count' => 0, 'direction' => null, 'label' => '—'];
        if ($candles === []) {
            return $empty;
        }

        $count = 0;
        $direction = null;

        for ($index = count($candles) - 1; $index >= 0; $index--) {
            $candle = $candles[$index];
            $open = (float) ($candle['open_price'] ?? $candle['open'] ?? 0);
            $close = (float) ($candle['close_price'] ?? $candle['close'] ?? 0);
            $body = abs($close - $open);

            if ($body < $minBody) {
                break;
            }

            $isUp = $close > $open;
            $isDown = $close < $open;
            if (!$isUp && !$isDown) {
                break;
            }

            $candleDirection = $isUp ? 'up' : 'down';
            if ($direction === null) {
                $direction = $candleDirection;
                $count = 1;
                continue;
            }

            if ($candleDirection !== $direction) {
                break;
            }

            $count++;
        }

        if ($count <= 0 || $direction === null) {
            return $empty;
        }

        return [
            'count' => $count,
            'direction' => $direction,
            'label' => $count . ' ' . ($direction === 'up' ? 'вверх' : 'вниз'),
        ];
    }

    /** @param list<array{open_price:string|float, close_price:string|float}> $candles */
    public function precededBy(array $candles, int $count, string $direction): bool
    {
        $index = count($candles) - $count - 1;
        if ($index < 0) {
            return false;
        }
        $candle = $candles[$index];

        return $direction === 'up'
            ? (float) $candle['close_price'] > (float) $candle['open_price']
            : (float) $candle['close_price'] < (float) $candle['open_price'];
    }
}
