<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

/**
 * Bearer-токены для мобильного API (расчёты на сервере, клиент — управление).
 */
final class ApiTokenAuth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{token: string, user_id: int, expires_at: ?string}
     */
    public function issue(int $userId, ?string $deviceName = null, ?int $ttlDays = 90): array
    {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $prefix = substr($plain, 0, 12);
        $expiresAt = $ttlDays !== null
            ? gmdate('Y-m-d H:i:s', time() + ($ttlDays * 86400))
            : null;

        $statement = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, token_hash, token_prefix, device_name, expires_at)
             VALUES (:user_id, :token_hash, :token_prefix, :device_name, :expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $hash,
            'token_prefix' => $prefix,
            'device_name' => $deviceName !== null && $deviceName !== '' ? mb_substr($deviceName, 0, 128) : null,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $plain,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{id: int, user_id: int, username: string}|null
     */
    public function authenticate(?string $bearerToken): ?array
    {
        $token = $this->extractBearer($bearerToken);
        if ($token === null) {
            return null;
        }

        $hash = hash('sha256', $token);
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.user_id, u.username
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash
               AND t.revoked_at IS NULL
               AND u.is_active = 1
               AND (t.expires_at IS NULL OR t.expires_at > UTC_TIMESTAMP())
             LIMIT 1'
        );
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }

        $this->pdo->prepare('UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => (int) $row['id']]);

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
        ];
    }

    public function revokeByPlainToken(string $plainToken): void
    {
        $hash = hash('sha256', $plainToken);
        $this->pdo->prepare(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :hash AND revoked_at IS NULL'
        )->execute(['hash' => $hash]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND revoked_at IS NULL'
        )->execute(['user_id' => $userId]);
    }

    public function attemptLogin(string $username, string $password): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash FROM users WHERE username = :username AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $user['id']]);

        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
        ];
    }

    private function extractBearer(?string $headerOrToken): ?string
    {
        if ($headerOrToken === null) {
            return null;
        }
        $value = trim($headerOrToken);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $value, $matches) === 1) {
            return $matches[1];
        }
        // Допускаем сырой токен в заголовке X-API-Token.
        if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
            return $value;
        }

        return null;
    }
}
