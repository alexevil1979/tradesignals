<?php
declare(strict_types=1);

namespace App\Strategy;

final class RuleEngine
{
    public function __construct(private readonly CandleAnalyzer $analyzer)
    {
    }

    /**
     * @param array{rule_type:string,min_count:int,max_count:int} $strategy
     * @param list<array{open_price:string|float, close_price:string|float}> $candles
     * @return array{side:'Buy'|'Sell',candle_count:int}|null
     */
    public function evaluate(array $strategy, array $candles): ?array
    {
        $type = $strategy['rule_type'];
        $direction = in_array($type, ['up_after_down', 'consecutive_up'], true) ? 'up' : 'down';
        $count = $this->analyzer->countTrailingDirection($candles, $direction);
        if ($count < $strategy['min_count'] || $count > $strategy['max_count']) {
            return null;
        }

        if ($type === 'up_after_down' && !$this->analyzer->precededBy($candles, $count, 'down')) {
            return null;
        }
        if ($type === 'down_after_up' && !$this->analyzer->precededBy($candles, $count, 'up')) {
            return null;
        }

        return [
            'side' => in_array($type, ['up_after_down', 'consecutive_up'], true) ? 'Sell' : 'Buy',
            'candle_count' => $count,
        ];
    }
}
