<?php
declare(strict_types=1);

namespace App\Strategy;

use PDO;

final class CandleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{open_time:string,open_price:string,close_price:string}>
     */
    public function latestConfirmed(string $symbol, string $interval, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT open_time, open_price, close_price
             FROM candles
             WHERE symbol = :symbol AND interval_code = :interval AND is_confirmed = 1
             ORDER BY open_time DESC
             LIMIT :limit'
        );
        $statement->bindValue('symbol', $symbol);
        $statement->bindValue('interval', $interval);
        $statement->bindValue('limit', max(2, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_reverse($statement->fetchAll());
    }
}
