<?php
declare(strict_types=1);

namespace App\Helpers;

use PDO;
use Throwable;

final class Logger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->write('info', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->write('warning', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = [], string $channel = 'app'): void
    {
        $this->write('error', $message, $context, $channel);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context, string $channel): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO logs (level, channel, message, context) VALUES (:level, :channel, :message, :context)'
            );
            $statement->execute([
                'level' => $level,
                'channel' => $channel,
                'message' => $message,
                'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            // Логирование не должно останавливать торговый процесс.
        }
    }
}
