<?php
declare(strict_types=1);

/**
 * Общий bootstrap для /api/mobile/*.
 *
 * @return array{
 *   pdo: PDO,
 *   config: array<string, mixed>,
 *   logger: \App\Helpers\Logger,
 *   auth: \App\Auth\ApiTokenAuth,
 *   user: array{id: int, user_id: int, username: string}|null,
 *   json: callable,
 *   requireAuth: callable,
 *   readJson: callable
 * }
 */
function mobile_api_bootstrap(bool $requireAuth = true): array
{
    require dirname(__DIR__, 3) . '/bootstrap.php';

    /** @var PDO $pdo */
    /** @var array<string, mixed> $config */
    /** @var \App\Helpers\Logger $logger */

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Token');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $auth = new \App\Auth\ApiTokenAuth($pdo);

    $json = static function (array $payload, int $status = 200): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    };

    $readJson = static function (): array {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return $_POST;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : $_POST;
        } catch (Throwable) {
            return $_POST;
        }
    };

    $bearer = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? null);
    $user = $auth->authenticate(is_string($bearer) ? $bearer : null);

    $requireAuthFn = static function () use ($user, $json): array {
        if ($user === null) {
            $json(['ok' => false, 'error' => 'Требуется авторизация.'], 401);
        }

        return $user;
    };

    if ($requireAuth) {
        $user = $requireAuthFn();
    }

    return [
        'pdo' => $pdo,
        'config' => $config,
        'logger' => $logger,
        'auth' => $auth,
        'user' => $user,
        'json' => $json,
        'requireAuth' => $requireAuthFn,
        'readJson' => $readJson,
    ];
}
