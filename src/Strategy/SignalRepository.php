<?php
declare(strict_types=1);

namespace App\Strategy;

use PDO;

final class SignalRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return int|null ID нового сигнала либо null, если такой сигнал уже обработан.
     */
    public function createOnce(
        ?int $strategyId,
        string $symbol,
        string $side,
        string $signalType,
        int $candleCount,
        string $candleOpenTime,
        string $price,
        array $payload,
    ): ?int {
        $exists = $this->pdo->prepare(
            'SELECT id FROM signals
             WHERE strategy_id <=> :strategy_id
               AND symbol = :symbol
               AND signal_type = :signal_type
               AND candle_count = :candle_count
               AND candle_open_time = :candle_open_time
             LIMIT 1'
        );
        $exists->execute([
            'strategy_id' => $strategyId,
            'symbol' => $symbol,
            'signal_type' => $signalType,
            'candle_count' => $candleCount,
            'candle_open_time' => $candleOpenTime,
        ]);
        if ($exists->fetchColumn() !== false) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO signals
             (strategy_id, symbol, side, signal_type, candle_count, candle_open_time, price, payload)
             VALUES (:strategy_id, :symbol, :side, :signal_type, :candle_count, :candle_open_time, :price, :payload)'
        );
        $statement->execute([
            'strategy_id' => $strategyId,
            'symbol' => $symbol,
            'side' => $side,
            'signal_type' => $signalType,
            'candle_count' => $candleCount,
            'candle_open_time' => $candleOpenTime,
            'price' => $price,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Один сигнал на закрытую свечу (symbol + signal_type + open_time),
     * без учёта candle_count — чтобы не слать повторно каждую минуту.
     *
     * @param array<string, mixed> $payload
     * @return int|null ID нового сигнала либо null, если для этой свечи уже есть сигнал.
     */
    public function createOnceForClosedCandle(
        ?int $strategyId,
        string $symbol,
        string $side,
        string $signalType,
        int $candleCount,
        string $candleOpenTime,
        string $price,
        array $payload,
    ): ?int {
        $exists = $this->pdo->prepare(
            'SELECT id FROM signals
             WHERE symbol = :symbol
               AND signal_type = :signal_type
               AND candle_open_time = :candle_open_time
             LIMIT 1'
        );
        $exists->execute([
            'symbol' => $symbol,
            'signal_type' => $signalType,
            'candle_open_time' => $candleOpenTime,
        ]);
        if ($exists->fetchColumn() !== false) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO signals
             (strategy_id, symbol, side, signal_type, candle_count, candle_open_time, price, payload)
             VALUES (:strategy_id, :symbol, :side, :signal_type, :candle_count, :candle_open_time, :price, :payload)'
        );
        try {
            $statement->execute([
                'strategy_id' => $strategyId,
                'symbol' => $symbol,
                'side' => $side,
                'signal_type' => $signalType,
                'candle_count' => $candleCount,
                'candle_open_time' => $candleOpenTime,
                'price' => $price,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        } catch (\PDOException) {
            return null;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function markTelegramSent(int $signalId): void
    {
        $statement = $this->pdo->prepare('UPDATE signals SET telegram_sent_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $signalId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingTelegram(int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, strategy_id, symbol, side, signal_type, candle_count, candle_open_time, price, payload
             FROM signals
             WHERE telegram_sent_at IS NULL
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Последний отправленный в Telegram сигнал по каждому ТФ (grid_M1_… и т.п.).
     *
     * @return array<string, array{
     *   id:int,
     *   side:string,
     *   signal_type:string,
     *   candle_count:int,
     *   direction:?string,
     *   level_bars:?int,
     *   label:string,
     *   telegram_sent_at:string,
     *   sent_at_label:string
     * }>
     */
    public function latestTelegramSentByTimeframe(string $symbol): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, side, signal_type, candle_count, price, telegram_sent_at, payload
             FROM signals
             WHERE symbol = :symbol
               AND telegram_sent_at IS NOT NULL
               AND signal_type LIKE \'grid_%\'
             ORDER BY telegram_sent_at DESC, id DESC
             LIMIT 300'
        );
        $statement->execute(['symbol' => $symbol]);

        $out = [];
        foreach ($statement->fetchAll() as $row) {
            $parsed = self::parseGridSignalType((string) $row['signal_type']);
            if ($parsed === null) {
                continue;
            }
            $tf = $parsed['tf'];
            if (isset($out[$tf])) {
                continue;
            }

            $payload = [];
            if (!empty($row['payload'])) {
                try {
                    $decoded = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
                    $payload = is_array($decoded) ? $decoded : [];
                } catch (\Throwable) {
                    $payload = [];
                }
            }

            $direction = $parsed['direction']
                ?? (isset($payload['direction']) ? (string) $payload['direction'] : null);
            $levelBars = $parsed['level_bars']
                ?? (isset($payload['level_bars']) ? (int) $payload['level_bars'] : null);
            if ($levelBars === null && isset($row['candle_count'])) {
                $levelBars = (int) $row['candle_count'];
            }

            $dirRu = $direction === 'up' ? 'вверх' : ($direction === 'down' ? 'вниз' : '—');
            $barsPart = $levelBars !== null ? (string) $levelBars : (string) $row['candle_count'];
            $sentAt = (string) $row['telegram_sent_at'];
            $sentLabel = $sentAt;
            $ts = strtotime($sentAt . ' UTC');
            if ($ts !== false) {
                $sentLabel = gmdate('d.m H:i:s', $ts) . ' UTC';
            }

            $out[$tf] = [
                'id' => (int) $row['id'],
                'side' => (string) $row['side'],
                'signal_type' => (string) $row['signal_type'],
                'candle_count' => (int) $row['candle_count'],
                'direction' => $direction,
                'level_bars' => $levelBars,
                'label' => $barsPart . ' ' . $dirRu,
                'telegram_sent_at' => $sentAt,
                'sent_at_label' => $sentLabel,
            ];
        }

        return $out;
    }

    /**
     * @return array{tf:string,direction:?string,level_bars:?int}|null
     */
    public static function parseGridSignalType(string $signalType): ?array
    {
        if (preg_match('/^grid_(M1|M5|M15|H1|H4|D1)_(up|down)_(\d+)$/', $signalType, $match) === 1) {
            return [
                'tf' => $match[1],
                'direction' => $match[2],
                'level_bars' => (int) $match[3],
            ];
        }
        if (preg_match('/^grid_(M1|M5|M15|H1|H4|D1)_(up|down)$/', $signalType, $match) === 1) {
            return [
                'tf' => $match[1],
                'direction' => $match[2],
                'level_bars' => null,
            ];
        }

        return null;
    }
}
