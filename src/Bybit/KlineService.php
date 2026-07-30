<?php
declare(strict_types=1);

namespace App\Bybit;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class KlineService
{
    public function __construct(
        private readonly Client $client,
        private readonly PDO $pdo,
        private readonly string $category = 'linear',
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function fetch(string $symbol, string $interval, int $limit = 200): array
    {
        $response = $this->client->publicGet('/v5/market/kline', [
            'category' => $this->category,
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
        ]);
        $rows = $response['result']['list'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $intervalSeconds = $this->intervalSeconds($interval);
        $now = time();

        return array_map(static function (array $candle) use ($intervalSeconds, $now): array {
            $openTimestamp = intdiv((int) $candle[0], 1000);

            return [
                'open_time' => (new DateTimeImmutable('@' . $openTimestamp))
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'open' => (string) $candle[1],
                'high' => (string) $candle[2],
                'low' => (string) $candle[3],
                'close' => (string) $candle[4],
                'volume' => (string) $candle[5],
                'turnover' => (string) $candle[6],
                'is_confirmed' => (int) ($openTimestamp + $intervalSeconds <= $now),
            ];
        }, $rows);
    }

    /** @param list<array<string, mixed>> $candles */
    public function save(string $symbol, string $interval, array $candles): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO candles
                (symbol, interval_code, open_time, open_price, high_price, low_price, close_price, volume, turnover, is_confirmed)
             VALUES (:symbol, :interval, :open_time, :open, :high, :low, :close, :volume, :turnover, :is_confirmed)
             ON DUPLICATE KEY UPDATE open_price = VALUES(open_price), high_price = VALUES(high_price),
                low_price = VALUES(low_price), close_price = VALUES(close_price), volume = VALUES(volume),
                turnover = VALUES(turnover), is_confirmed = VALUES(is_confirmed)'
        );
        foreach ($candles as $candle) {
            $statement->execute(['symbol' => $symbol, 'interval' => $interval] + $candle);
        }

        return count($candles);
    }

    private function intervalSeconds(string $interval): int
    {
        if ($interval === 'D') {
            return 86_400;
        }

        return max(1, (int) $interval) * 60;
    }
}
