<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1'
        );
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : $value;
    }

    public function set(string $key, string $value, bool $isEncrypted = false): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, is_encrypted)
             VALUES (:key, :value, :encrypted)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                 is_encrypted = VALUES(is_encrypted)'
        );
        $statement->execute([
            'key' => $key,
            'value' => $value,
            'encrypted' => (int) $isEncrypted,
        ]);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->pdo->query('SELECT setting_key, setting_value FROM settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
