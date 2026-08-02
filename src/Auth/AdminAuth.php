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
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start([
            'use_strict_mode' => true,
            'cookie_httponly' => true,
            'cookie_secure' => self::isHttps(),
            'cookie_samesite' => 'Lax',
            'cookie_path' => '/',
        ]);
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
        // Новый id сессии — обновим CSRF, чтобы не тащить старый токен из кэша формы.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || $token === '' || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    /** HTTPS с учётом reverse-proxy (X-Forwarded-Proto). */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded !== '') {
            return explode(',', $forwarded)[0] === 'https';
        }

        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}
