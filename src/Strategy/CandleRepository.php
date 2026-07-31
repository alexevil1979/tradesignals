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

    /**
     * @return list<array{open_time:string,open_price:string,high_price:string,low_price:string,close_price:string,volume:string}>
     */
    public function latestForChart(string $symbol, string $interval, int $limit = 100): array
    {
        if ($limit <= 0) {
            return $this->allForChart($symbol, $interval);
        }

        $statement = $this->pdo->prepare(
            'SELECT open_time, open_price, high_price, low_price, close_price, volume
             FROM candles
             WHERE symbol = :symbol AND interval_code = :interval
             ORDER BY open_time DESC
             LIMIT :limit'
        );
        $statement->bindValue('symbol', $symbol);
        $statement->bindValue('interval', $interval);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_reverse($statement->fetchAll());
    }

    /**
     * Все сохранённые свечи таймфрейма по возрастанию времени.
     *
     * @return list<array{open_time:string,open_price:string,high_price:string,low_price:string,close_price:string,volume:string}>
     */
    public function allForChart(string $symbol, string $interval): array
    {
        $statement = $this->pdo->prepare(
            'SELECT open_time, open_price, high_price, low_price, close_price, volume
             FROM candles
             WHERE symbol = :symbol AND interval_code = :interval
             ORDER BY open_time ASC'
        );
        $statement->execute([
            'symbol' => $symbol,
            'interval' => $interval,
        ]);

        return $statement->fetchAll();
    }
}
