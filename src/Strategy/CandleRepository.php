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
     * @return list<array{open_time:string,open_price:string,high_price:string,low_price:string,close_price:string,volume:string,is_confirmed:int|string}>
     */
    public function latestForChart(string $symbol, string $interval, int $limit = 100): array
    {
        if ($limit <= 0) {
            return $this->allForChart($symbol, $interval);
        }

        $statement = $this->pdo->prepare(
            'SELECT open_time, open_price, high_price, low_price, close_price, volume, is_confirmed
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
     * @return list<array{open_time:string,open_price:string,high_price:string,low_price:string,close_price:string,volume:string,is_confirmed:int|string}>
     */
    public function allForChart(string $symbol, string $interval): array
    {
        $statement = $this->pdo->prepare(
            'SELECT open_time, open_price, high_price, low_price, close_price, volume, is_confirmed
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

    /**
     * @return list<array{open_time:string,open_price:string,high_price:string,low_price:string,close_price:string}>
     */
    public function latestConfirmedOhlc(string $symbol, string $interval, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT open_time, open_price, high_price, low_price, close_price
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
     * Min low / max high за последние $minutes минут (по confirmed свечам).
     *
     * @return array{low: float, high: float}|null
     */
    public function extremumLastMinutes(string $symbol, string $interval, int $minutes = 15): ?array
    {
        $minutes = max(1, $minutes);
        $since = gmdate('Y-m-d H:i:s', time() - ($minutes * 60));
        $statement = $this->pdo->prepare(
            'SELECT MIN(low_price) AS low_price, MAX(high_price) AS high_price
             FROM candles
             WHERE symbol = :symbol
               AND interval_code = :interval
               AND is_confirmed = 1
               AND open_time >= :since'
        );
        $statement->execute([
            'symbol' => $symbol,
            'interval' => $interval,
            'since' => $since,
        ]);
        $row = $statement->fetch();
        if (!is_array($row) || $row['low_price'] === null || $row['high_price'] === null) {
            return null;
        }

        return [
            'low' => (float) $row['low_price'],
            'high' => (float) $row['high_price'],
        ];
    }

    /**
     * Min low / max high за последние $hours часов (по confirmed свечам).
     *
     * @return array{low: float, high: float}|null
     */
    public function extremumLastHours(string $symbol, string $interval, int $hours = 12): ?array
    {
        return $this->extremumLastMinutes($symbol, $interval, max(1, $hours * 60));
    }
}
