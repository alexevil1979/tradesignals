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
}
