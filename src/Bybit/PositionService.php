<?php
declare(strict_types=1);

namespace App\Bybit;

use PDO;

final class PositionService
{
    public function __construct(private readonly Client $client, private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function fetch(string $symbol): ?array
    {
        $response = $this->client->privateRequest('GET', '/v5/position/list', [
            'category' => 'linear',
            'symbol' => $symbol,
        ]);
        foreach ($response['result']['list'] ?? [] as $position) {
            if (is_array($position) && (float) ($position['size'] ?? 0) > 0) {
                return $position;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $position */
    public function sync(string $symbol, ?array $position): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE positions
             SET side = :side, quantity = :quantity, entry_price = :entry_price, mark_price = :mark_price,
                 unrealised_pnl = :unrealised_pnl, realised_pnl = :realised_pnl, is_open = :is_open,
                 bybit_updated_at = NOW()
             WHERE symbol = :symbol AND strategy_id IS NULL'
        );
        $statement->execute([
            'symbol' => $symbol,
            'side' => $position['side'] ?? 'None',
            'quantity' => $position['size'] ?? '0',
            'entry_price' => $position['avgPrice'] ?? null,
            'mark_price' => $position['markPrice'] ?? null,
            'unrealised_pnl' => $position['unrealisedPnl'] ?? '0',
            'realised_pnl' => $position['cumRealisedPnl'] ?? '0',
            'is_open' => $position === null ? 0 : 1,
        ]);

        $exists = $this->pdo->prepare(
            'SELECT id FROM positions WHERE symbol = :symbol AND strategy_id IS NULL LIMIT 1'
        );
        $exists->execute(['symbol' => $symbol]);
        if ($exists->fetchColumn() === false) {
            $statement = $this->pdo->prepare(
                'INSERT INTO positions (symbol, side, quantity, entry_price, mark_price, unrealised_pnl,
                 realised_pnl, is_open, bybit_updated_at)
                 VALUES (:symbol, :side, :quantity, :entry_price, :mark_price, :unrealised_pnl,
                 :realised_pnl, :is_open, NOW())'
            );
            $statement->execute([
                'symbol' => $symbol,
                'side' => $position['side'] ?? 'None',
                'quantity' => $position['size'] ?? '0',
                'entry_price' => $position['avgPrice'] ?? null,
                'mark_price' => $position['markPrice'] ?? null,
                'unrealised_pnl' => $position['unrealisedPnl'] ?? '0',
                'realised_pnl' => $position['cumRealisedPnl'] ?? '0',
                'is_open' => $position === null ? 0 : 1,
            ]);
        }
    }
}
