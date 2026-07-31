<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Bybit\KlineService;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
header('Content-Type: application/json; charset=utf-8');

if (!$auth->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Только POST.'], JSON_THROW_ON_ERROR);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
if (!$auth->verifyCsrf(is_string($csrf) ? $csrf : null)) {
    http_response_code(419);
    echo json_encode(['error' => 'Недействительный CSRF-токен.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $bybitConfig = $config['bybit'];
    $service = new KlineService($pdo, (string) $bybitConfig['category'], new SettingsRepository($pdo));
    $results = [];
    $total = 0;

    foreach (Intervals::codes() as $interval) {
        $result = $service->syncInterval((string) $bybitConfig['symbol'], $interval);
        $results[$interval] = $result;
        $total += $result['saved'];
    }

    $logger->info('Котировки обновлены с Dashboard (1 мин).', ['saved' => $total], 'quotes');

    echo json_encode([
        'ok' => true,
        'saved' => $total,
        'intervals' => $results,
        'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $logger->error('Ошибка автообновления котировок.', ['error' => $exception->getMessage()], 'quotes');
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
