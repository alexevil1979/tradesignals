<?php
declare(strict_types=1);

namespace App\Bybit;

use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Загрузка котировок по логике example/bbb/bothour/bybit_fill_tables.php:
 * - публичный MAINNET Bybit (как на bybit.com/trade/usdt/BTCUSDT);
 * - первичная история до 5000 баров страницами по 200 с параметром end;
 * - дальше только обновление последних N баров без start/end.
 */
final class KlineService
{
    private const PAGE_LIMIT = 200;
    private const MAX_INIT_BARS = 5000;
    private const MAINNET_KLINE_URL = 'https://api.bybit.com/v5/market/kline';

    /** Сколько последних баров подтягивать при обычном обновлении (как в example). */
    private const UPDATE_BARS = [
        '1' => 60,
        '5' => 50,
        '15' => 40,
        '60' => 30,
        '240' => 20,
        'D' => 15,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $category = 'linear',
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * @return array{saved:int,mode:string,pages:int,history_complete:bool}
     */
    public function syncInterval(string $symbol, string $interval): array
    {
        $count = $this->countCandles($symbol, $interval);
        if ($count === 0) {
            $result = $this->initialBackfill($symbol, $interval);
            $this->settings?->set($this->historyCompleteKey($interval), '1');

            return [
                'saved' => $result['saved'],
                'mode' => 'full_backfill',
                'pages' => $result['pages'],
                'history_complete' => true,
            ];
        }

        $result = $this->updateRecent($symbol, $interval);

        return [
            'saved' => $result['saved'],
            'mode' => 'incremental',
            'pages' => 1,
            'history_complete' => true,
        ];
    }

    /**
     * Ищет разрывы между свечами и догружает пропущенные бары с mainnet.
     *
     * @return array{
     *   gaps_found: int,
     *   saved: int,
     *   gaps: list<array{type: string, after: ?string, before: ?string, missing_bars: int}>
     * }
     */
    public function repairInterval(string $symbol, string $interval): array
    {
        if ($this->countCandles($symbol, $interval) === 0) {
            $sync = $this->syncInterval($symbol, $interval);

            return [
                'gaps_found' => 0,
                'saved' => $sync['saved'],
                'gaps' => [],
                'mode' => $sync['mode'],
            ];
        }

        $gaps = $this->findGaps($symbol, $interval);
        $saved = 0;
        foreach ($gaps as $gap) {
            $saved += $this->fillRange(
                $symbol,
                $interval,
                (int) $gap['from_ts'],
                (int) $gap['to_ts'],
            );
        }

        $recent = $this->updateRecent($symbol, $interval);
        $saved += $recent['saved'];

        return [
            'gaps_found' => count($gaps),
            'saved' => $saved,
            'gaps' => array_map(static fn (array $gap): array => [
                'type' => (string) ($gap['type'] ?? 'internal'),
                'after' => isset($gap['after']) ? (string) $gap['after'] : null,
                'before' => isset($gap['before']) ? (string) $gap['before'] : null,
                'missing_bars' => (int) ($gap['missing_bars'] ?? 0),
            ], $gaps),
            'mode' => 'repair',
        ];
    }

    /**
     * @return array{total_saved: int, intervals: array<string, array<string, mixed>>}
     */
    public function repairAll(string $symbol): array
    {
        $intervals = [];
        $totalSaved = 0;
        foreach (Intervals::codes() as $interval) {
            $result = $this->repairInterval($symbol, $interval);
            $intervals[$interval] = $result;
            $totalSaved += (int) ($result['saved'] ?? 0);
        }

        return [
            'total_saved' => $totalSaved,
            'intervals' => $intervals,
        ];
    }

    /**
     * @return list<array{type: string, after?: string, before?: string, from_ts: int, to_ts: int, missing_bars: int}>
     */
    public function findGaps(string $symbol, string $interval): array
    {
        $step = $this->intervalSeconds($interval);
        $statement = $this->pdo->prepare(
            'SELECT open_time FROM candles
             WHERE symbol = :symbol AND interval_code = :interval
             ORDER BY open_time ASC'
        );
        $statement->execute(['symbol' => $symbol, 'interval' => $interval]);
        /** @var list<string> $times */
        $times = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($times === []) {
            return [];
        }

        $gaps = [];
        for ($i = 0; $i < count($times) - 1; $i++) {
            $t1 = strtotime($times[$i] . ' UTC');
            $t2 = strtotime($times[$i + 1] . ' UTC');
            if ($t1 === false || $t2 === false) {
                continue;
            }
            $expectedNext = $t1 + $step;
            if ($t2 <= $expectedNext) {
                continue;
            }
            $missing = (int) floor(($t2 - $expectedNext) / $step) + 1;
            if ($missing < 1) {
                continue;
            }
            $gaps[] = [
                'type' => 'internal',
                'after' => $times[$i],
                'before' => $times[$i + 1],
                'from_ts' => $expectedNext,
                'to_ts' => $t2 - $step,
                'missing_bars' => $missing,
            ];
        }

        $lastTime = $times[array_key_last($times)];
        $lastTs = strtotime($lastTime . ' UTC');
        if ($lastTs === false) {
            return $gaps;
        }

        $now = time();
        $cursor = $lastTs + $step;
        $missingTail = 0;
        while ($cursor + $step <= $now) {
            $missingTail++;
            $cursor += $step;
        }
        if ($missingTail > 0) {
            $gaps[] = [
                'type' => 'tail',
                'after' => $lastTime,
                'from_ts' => $lastTs + $step,
                'to_ts' => $now,
                'missing_bars' => $missingTail,
            ];
        }

        return $gaps;
    }

    /** Полная очистка свечей инструмента (кривые данные). */
    public function clearAll(string $symbol): int
    {
        $statement = $this->pdo->prepare('DELETE FROM candles WHERE symbol = :symbol');
        $statement->execute(['symbol' => $symbol]);
        $deleted = $statement->rowCount();

        if ($this->settings !== null) {
            foreach (['1', '5', '15', '60', '240', 'D'] as $interval) {
                $this->settings->set($this->historyCompleteKey($interval), '0');
            }
        }

        return $deleted;
    }

    /** @return list<array<string, mixed>> */
    public function fetch(string $symbol, string $interval, int $limit = 200): array
    {
        return $this->fetchMainnetPage($symbol, $interval, min(self::PAGE_LIMIT, max(1, $limit)));
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
            $statement->execute(['symbol' => $symbol, 'interval' => $interval] + $candle);
            $saved++;
        }

        return $saved;
    }

    /** @return array{saved:int,pages:int} */
    private function initialBackfill(string $symbol, string $interval): array
    {
        $stepSeconds = $this->intervalSeconds($interval);
        $endTime = time();
        $totalInserted = 0;
        $pages = 0;

        while ($totalInserted < self::MAX_INIT_BARS) {
            $pages++;
            $rows = $this->fetchMainnetPage($symbol, $interval, self::PAGE_LIMIT, null, $endTime * 1000);
            if ($rows === []) {
                break;
            }

            usort($rows, static fn (array $a, array $b): int => strcmp($a['open_time'], $b['open_time']));
            $batch = [];
            $minOpenTs = null;
            foreach ($rows as $row) {
                $batch[] = $row;
                $openTs = strtotime($row['open_time'] . ' UTC');
                if ($minOpenTs === null || $openTs < $minOpenTs) {
                    $minOpenTs = $openTs;
                }
                if ($totalInserted + count($batch) >= self::MAX_INIT_BARS) {
                    break;
                }
            }

            $totalInserted += $this->save($symbol, $interval, $batch);

            if ($minOpenTs === null || count($rows) < self::PAGE_LIMIT) {
                break;
            }

            // Как в example: следующий end = самый старый бар минус один период.
            $endTime = $minOpenTs - $stepSeconds;
            if ($endTime <= 0) {
                break;
            }
            usleep(200_000);
        }

        return ['saved' => $totalInserted, 'pages' => $pages];
    }

    /** @return array{saved:int} */
    private function updateRecent(string $symbol, string $interval): array
    {
        $limit = self::UPDATE_BARS[$interval] ?? 30;
        $rows = $this->fetchMainnetPage($symbol, $interval, $limit);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['open_time'], $b['open_time']));

        return ['saved' => $this->save($symbol, $interval, $rows)];
    }

    /** Догрузка диапазона [fromTs, toTs] (unix sec, UTC). */
    private function fillRange(string $symbol, string $interval, int $fromTs, int $toTs): int
    {
        if ($fromTs > $toTs) {
            return 0;
        }

        $step = $this->intervalSeconds($interval);
        $startMs = $fromTs * 1000;
        $endMs = $toTs * 1000;
        $saved = 0;
        $cursorEnd = $endMs;
        $pages = 0;

        while ($startMs <= $cursorEnd && $pages < 50) {
            $pages++;
            $rows = $this->fetchMainnetPage($symbol, $interval, self::PAGE_LIMIT, $startMs, $cursorEnd);
            if ($rows === []) {
                break;
            }

            usort($rows, static fn (array $a, array $b): int => strcmp($a['open_time'], $b['open_time']));
            $saved += $this->save($symbol, $interval, $rows);

            $minOpenTs = null;
            foreach ($rows as $row) {
                $openTs = strtotime($row['open_time'] . ' UTC');
                if ($openTs === false) {
                    continue;
                }
                if ($minOpenTs === null || $openTs < $minOpenTs) {
                    $minOpenTs = $openTs;
                }
            }

            if ($minOpenTs === null || count($rows) < self::PAGE_LIMIT) {
                break;
            }

            if ($minOpenTs * 1000 <= $startMs) {
                break;
            }

            $cursorEnd = ($minOpenTs - $step) * 1000;
            usleep(200_000);
        }

        return $saved;
    }

    /**
     * Публичный kline всегда с mainnet — как в working example и на bybit.com.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchMainnetPage(
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

        $url = self::MAINNET_KLINE_URL . '?' . http_build_query($query);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($response) || $error !== '') {
            throw new \RuntimeException('Bybit kline недоступен: ' . ($error !== '' ? $error : 'пустой ответ'));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['retCode'] ?? -1) !== 0) {
            throw new \RuntimeException('Bybit kline error: ' . (is_array($decoded) ? ($decoded['retMsg'] ?? 'unknown') : 'bad json'));
        }

        $list = $decoded['result']['list'] ?? [];
        if (!is_array($list) || $list === []) {
            return [];
        }

        $intervalSeconds = $this->intervalSeconds($interval);
        $now = time();
        $candles = [];

        foreach ($list as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            // Формат Bybit: [ts_ms, open, high, low, close, volume, turnover]
            $openTimestamp = intdiv((int) $row[0], 1000);
            $candles[] = [
                'open_time' => (new DateTimeImmutable('@' . $openTimestamp))
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'open' => (string) $row[1],
                'high' => (string) $row[2],
                'low' => (string) $row[3],
                'close' => (string) $row[4],
                'volume' => (string) ($row[5] ?? '0'),
                'turnover' => (string) ($row[6] ?? '0'),
                'is_confirmed' => (int) ($openTimestamp + $intervalSeconds <= $now),
            ];
        }

        return $candles;
    }

    private function countCandles(string $symbol, string $interval): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM candles WHERE symbol = :symbol AND interval_code = :interval'
        );
        $statement->execute(['symbol' => $symbol, 'interval' => $interval]);

        return (int) $statement->fetchColumn();
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
