<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

final class AdminAuth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function startSession(string $name): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($name);
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'cookie_samesite' => 'Strict',
            ]);
        }
    }

    public function attempt(string $username, string $password): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id, password_hash FROM users WHERE username = :username AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $user['id']]);

        return true;
    }

    public function check(): bool
    {
        return isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
    }

    public function requireLogin(): void
    {
        if (!$this->check()) {
            header('Location: /admin/login.php', true, 302);
            exit;
        }
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
    }

    public function verifyCsrf(?string $token): bool
    {
        return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
