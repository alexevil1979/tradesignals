<?php
declare(strict_types=1);

namespace App\Strategy;

use InvalidArgumentException;

final class GridManager
{
    /**
     * Возвращает объём уровня: min_count соответствует первому элементу массива.
     *
     * @param list<string|float|int> $volumes
     */
    public function volumeForLevel(array $volumes, int $minCount, int $candleCount): string
    {
        $level = $candleCount - $minCount;
        if ($level < 0 || !array_key_exists($level, $volumes)) {
            throw new InvalidArgumentException('Для уровня сетки не задан объём.');
        }

        $volume = (string) $volumes[$level];
        if ((float) $volume <= 0) {
            throw new InvalidArgumentException('Объём сетки должен быть положительным.');
        }

        return $volume;
    }

    public function shouldCloseOnReverse(bool $closeOnReverse, ?string $currentSide, string $signalSide): bool
    {
        return $closeOnReverse && $currentSide !== null && $currentSide !== $signalSide;
    }
}
