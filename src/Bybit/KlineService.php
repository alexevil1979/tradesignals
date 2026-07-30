<?php
declare(strict_types=1);

namespace App\Bybit;

use App\Database\SettingsRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class KlineService
{
    private const PAGE_LIMIT = 1000;
    private const MAX_BACKFILL_PAGES_PER_RUN = 50;

    public function __construct(
        private readonly Client $client,
        private readonly PDO $pdo,
        private readonly string $category = 'linear',
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * Разовый максимум истории + последующая догрузка только недостающих свечей.
     *
     * @return array{saved:int,mode:string,pages:int,history_complete:bool}
     */
    public function syncInterval(string $symbol, string $interval): array
    {
        $newestMs = $this->newestOpenTimeMs($symbol, $interval);
        $oldestMs = $this->oldestOpenTimeMs($symbol, $interval);
        $historyKey = $this->historyCompleteKey($interval);
        $historyComplete = $this->settings?->get($historyKey, '0') === '1';

        $saved = 0;
        $pages = 0;
        $mode = 'incremental';

        $purged = $this->purgeInvalidCandles($symbol, $interval);
        if ($purged > 0) {
            $this->settings?->set($this->historyCompleteKey($interval), '0');
            $historyComplete = false;
            $newestMs = $this->newestOpenTimeMs($symbol, $interval);
            $oldestMs = $this->oldestOpenTimeMs($symbol, $interval);
        }

        if ($newestMs === null) {
            $mode = 'full_backfill';
            $result = $this->backfillOlder($symbol, $interval, null);
            $saved += $result['saved'];
            $pages += $result['pages'];
            $historyComplete = $result['complete'];
        } else {
            $forward = $this->syncForward($symbol, $interval, $newestMs);
            $saved += $forward['saved'];
            $pages += $forward['pages'];

            if (!$historyComplete) {
                $mode = 'backfill_continue';
                $oldestMs = $this->oldestOpenTimeMs($symbol, $interval);
                $result = $this->backfillOlder($symbol, $interval, $oldestMs);
                $saved += $result['saved'];
                $pages += $result['pages'];
                $historyComplete = $result['complete'];
            }
        }

        if ($historyComplete) {
            $this->settings?->set($historyKey, '1');
        }

        return [
            'saved' => $saved,
            'mode' => $mode,
            'pages' => $pages,
            'history_complete' => $historyComplete,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function fetch(string $symbol, string $interval, int $limit = 200): array
    {
        return $this->fetchPage($symbol, $interval, min(self::PAGE_LIMIT, max(1, $limit)));
    }

    /** @param list<array<string, mixed>> $candles */
    public function save(string $symbol, string $interval, array $candles): int
    {
        if ($candles === []) {
            return 0;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO candles
                (symbol, interval_code, open_time, open_price, high_price, low_price, close_price, volume, turnover, is_confirmed)
             VALUES (:symbol, :interval, :open_time, :open, :high, :low, :close, :volume, :turnover, :is_confirmed)
             ON DUPLICATE KEY UPDATE open_price = VALUES(open_price), high_price = VALUES(high_price),
                low_price = VALUES(low_price), close_price = VALUES(close_price), volume = VALUES(volume),
                turnover = VALUES(turnover), is_confirmed = VALUES(is_confirmed)'
        );

        $saved = 0;
        foreach ($candles as $candle) {
            if (!$this->isValidOhlc($candle)) {
                continue;
            }
            $statement->execute(['symbol' => $symbol, 'interval' => $interval] + $candle);
            $saved++;
        }

        return $saved;
    }

    /** Удаляет заведомо битые OHLC (ломают шкалу графика, как выброс ~1_000_000 на D1). */
    public function purgeInvalidCandles(string $symbol, string $interval): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM candles
             WHERE symbol = :symbol
               AND interval_code = :interval
               AND (
                    open_price <= 0 OR high_price <= 0 OR low_price <= 0 OR close_price <= 0
                    OR high_price < open_price OR high_price < close_price OR high_price < low_price
                    OR low_price > open_price OR low_price > close_price
                    OR open_price < 1000 OR close_price < 1000
                    OR open_price > 500000 OR high_price > 500000 OR low_price > 500000 OR close_price > 500000
               )'
        );
        $statement->execute(['symbol' => $symbol, 'interval' => $interval]);

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $candle */
    private function isValidOhlc(array $candle): bool
    {
        $open = (float) $candle['open'];
        $high = (float) $candle['high'];
        $low = (float) $candle['low'];
        $close = (float) $candle['close'];

        if (min($open, $high, $low, $close) < 1000.0 || max($open, $high, $low, $close) > 500000.0) {
            return false;
        }

        return $high >= $open
            && $high >= $close
            && $high >= $low
            && $low <= $open
            && $low <= $close;
    }

    /**
     * @return array{saved:int,pages:int}
     */
    private function syncForward(string $symbol, string $interval, int $fromOpenTimeMs): array
    {
        $saved = 0;
        $pages = 0;
        $startMs = $fromOpenTimeMs;

        while ($pages < self::MAX_BACKFILL_PAGES_PER_RUN) {
            $candles = $this->fetchPage($symbol, $interval, self::PAGE_LIMIT, $startMs, null);
            $pages++;
            if ($candles === []) {
                break;
            }

            $saved += $this->save($symbol, $interval, $candles);
            $newestInPage = max(array_map(
                static fn (array $candle): int => strtotime($candle['open_time'] . ' UTC') * 1000,
                $candles,
            ));

            if (count($candles) < self::PAGE_LIMIT) {
                break;
            }

            $startMs = $newestInPage + $this->intervalSeconds($interval) * 1000;
            usleep(120_000);
        }

        return ['saved' => $saved, 'pages' => $pages];
    }

    /**
     * @return array{saved:int,pages:int,complete:bool}
     */
    private function backfillOlder(string $symbol, string $interval, ?int $beforeOpenTimeMs): array
    {
        $saved = 0;
        $pages = 0;
        $endMs = $beforeOpenTimeMs === null ? null : $beforeOpenTimeMs - 1;
        $complete = false;

        while ($pages < self::MAX_BACKFILL_PAGES_PER_RUN) {
            $candles = $this->fetchPage($symbol, $interval, self::PAGE_LIMIT, null, $endMs);
            $pages++;
            if ($candles === []) {
                $complete = true;
                break;
            }

            $saved += $this->save($symbol, $interval, $candles);
            $oldestInPage = min(array_map(
                static fn (array $candle): int => strtotime($candle['open_time'] . ' UTC') * 1000,
                $candles,
            ));

            if (count($candles) < self::PAGE_LIMIT) {
                $complete = true;
                break;
            }

            $endMs = $oldestInPage - 1;
            usleep(120_000);
        }

        return ['saved' => $saved, 'pages' => $pages, 'complete' => $complete];
    }

    /** @return list<array<string, mixed>> */
    private function fetchPage(
        string $symbol,
        string $interval,
        int $limit,
        ?int $startMs = null,
        ?int $endMs = null,
    ): array {
        $query = [
            'category' => $this->category,
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
        ];
        if ($startMs !== null) {
            $query['start'] = $startMs;
        }
        if ($endMs !== null) {
            $query['end'] = $endMs;
        }

        $response = $this->client->publicGet('/v5/market/kline', $query);
        $rows = $response['result']['list'] ?? [];
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $intervalSeconds = $this->intervalSeconds($interval);
        $now = time();

        $candles = array_map(static function (array $candle) use ($intervalSeconds, $now): array {
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

        usort(
            $candles,
            static fn (array $left, array $right): int => strcmp($left['open_time'], $right['open_time']),
        );

        return $candles;
    }

    private function newestOpenTimeMs(string $symbol, string $interval): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT open_time FROM candles
             WHERE symbol = :symbol AND interval_code = :interval
             ORDER BY open_time DESC LIMIT 1'
        );
        $statement->execute(['symbol' => $symbol, 'interval' => $interval]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) (strtotime((string) $value . ' UTC') * 1000);
    }

    private function oldestOpenTimeMs(string $symbol, string $interval): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT open_time FROM candles
             WHERE symbol = :symbol AND interval_code = :interval
             ORDER BY open_time ASC LIMIT 1'
        );
        $statement->execute(['symbol' => $symbol, 'interval' => $interval]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) (strtotime((string) $value . ' UTC') * 1000);
    }

    private function historyCompleteKey(string $interval): string
    {
        return 'candle_history_complete_' . $interval;
    }

    private function intervalSeconds(string $interval): int
    {
        if ($interval === 'D') {
            return 86_400;
        }

        return max(1, (int) $interval) * 60;
    }
}
