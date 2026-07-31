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
     * Незакрытый бар не участвует. Бар с телом меньше порога обнуляет последовательность.
     *
     * @param list<array<string, mixed>> $candles
     * @return array{count:int, direction:'up'|'down'|null, label:string, reason:?string, min_body:float}
     */
    public function currentSequence(array $candles, float $minBody): array
    {
        $base = [
            'count' => 0,
            'direction' => null,
            'label' => '—',
            'reason' => null,
            'min_body' => $minBody,
        ];

        if ($candles === []) {
            return $base + ['label' => 'нет данных', 'reason' => 'нет свечей'];
        }

        $forSeq = $candles;
        $last = $candles[array_key_last($candles)];
        $lastConfirmed = array_key_exists('confirmed', $last)
            ? (bool) $last['confirmed']
            : (array_key_exists('is_confirmed', $last) ? (bool) $last['is_confirmed'] : true);

        // Текущий незакрытый бар часто с крошечным телом и обнуляет серию на M1–H1.
        if (!$lastConfirmed && count($forSeq) > 1) {
            $forSeq = array_slice($forSeq, 0, -1);
        }

        if ($forSeq === []) {
            return $base + ['label' => 'нет данных', 'reason' => 'нет подтверждённых свечей'];
        }

        $lastOpen = (float) ($forSeq[array_key_last($forSeq)]['open_price']
            ?? $forSeq[array_key_last($forSeq)]['open']
            ?? 0);
        $lastClose = (float) ($forSeq[array_key_last($forSeq)]['close_price']
            ?? $forSeq[array_key_last($forSeq)]['close']
            ?? 0);
        $lastBody = abs($lastClose - $lastOpen);

        if ($lastBody < $minBody) {
            $reason = sprintf('тело %.2f < мин. %s', $lastBody, $this->formatNumber($minBody));

            return $base + [
                'label' => $reason,
                'reason' => $reason,
            ];
        }

        $count = 0;
        $direction = null;

        for ($index = count($forSeq) - 1; $index >= 0; $index--) {
            $candle = $forSeq[$index];
            $open = (float) ($candle['open_price'] ?? $candle['open'] ?? 0);
            $close = (float) ($candle['close_price'] ?? $candle['close'] ?? 0);
            $body = abs($close - $open);

            if ($body < $minBody) {
                if ($count === 0) {
                    $reason = sprintf('тело %.2f < мин. %s', $body, $this->formatNumber($minBody));

                    return $base + ['label' => $reason, 'reason' => $reason];
                }
                break;
            }

            $isUp = $close > $open;
            $isDown = $close < $open;
            if (!$isUp && !$isDown) {
                if ($count === 0) {
                    return $base + ['label' => 'дожи', 'reason' => 'последний бар без направления (дожи)'];
                }
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
            return $base + ['label' => 'нет серии', 'reason' => 'не удалось определить направление'];
        }

        return [
            'count' => $count,
            'direction' => $direction,
            'label' => $count . ' ' . ($direction === 'up' ? 'вверх' : 'вниз'),
            'reason' => null,
            'min_body' => $minBody,
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

    private function formatNumber(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
