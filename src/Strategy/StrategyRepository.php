<?php
declare(strict_types=1);

namespace App\Strategy;

use PDO;

final class StrategyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function active(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, rule_type, min_count, max_count, volumes, take_profit_percent,
                    stop_loss_percent, interval_code, close_on_reverse
             FROM strategies
             WHERE is_active = 1
             ORDER BY id'
        );
        $strategies = $statement->fetchAll();

        foreach ($strategies as &$strategy) {
            $volumes = json_decode((string) $strategy['volumes'], true, 512, JSON_THROW_ON_ERROR);
            $strategy['volumes'] = is_array($volumes) ? $volumes : [];
            $strategy['id'] = (int) $strategy['id'];
            $strategy['min_count'] = (int) $strategy['min_count'];
            $strategy['max_count'] = (int) $strategy['max_count'];
            $strategy['close_on_reverse'] = (bool) $strategy['close_on_reverse'];
        }
        unset($strategy);

        return $strategies;
    }
}
