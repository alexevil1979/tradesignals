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
