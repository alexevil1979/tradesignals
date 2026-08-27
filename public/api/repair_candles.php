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
    $symbol = (string) $bybitConfig['symbol'];
    $settings = new SettingsRepository($pdo);
    $service = new KlineService($pdo, (string) $bybitConfig['category'], $settings);

    $repair = $service->repairAll($symbol);
    $totalGaps = 0;
    $summary = [];
    foreach (Intervals::chartMap() as $label => $code) {
        $row = $repair['intervals'][$code] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $gapsFound = (int) ($row['gaps_found'] ?? 0);
        $totalGaps += $gapsFound;
        $summary[$label] = [
            'gaps_found' => $gapsFound,
            'saved' => (int) ($row['saved'] ?? 0),
            'gaps' => $row['gaps'] ?? [],
        ];
    }

    $logger->info('Догрузка пропущенных котировок с Dashboard.', [
        'symbol' => $symbol,
        'total_saved' => $repair['total_saved'],
        'total_gaps' => $totalGaps,
        'intervals' => $summary,
    ], 'quotes');

    echo json_encode([
        'ok' => true,
        'total_saved' => $repair['total_saved'],
        'total_gaps' => $totalGaps,
        'intervals' => $summary,
        'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $logger->error('Ошибка догрузки пропущенных котировок.', [
        'error' => $exception->getMessage(),
    ], 'quotes');
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
