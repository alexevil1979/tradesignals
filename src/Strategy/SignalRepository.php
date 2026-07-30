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
        int $strategyId,
        string $symbol,
        string $side,
        string $signalType,
        int $candleCount,
        string $candleOpenTime,
        string $price,
        array $payload,
    ): ?int {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO signals
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

        return $statement->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    public function markTelegramSent(int $signalId): void
    {
        $statement = $this->pdo->prepare('UPDATE signals SET telegram_sent_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $signalId]);
    }
}
