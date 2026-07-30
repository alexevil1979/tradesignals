<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Helpers\Intervals;
use App\Strategy\CandleRepository;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
header('Content-Type: application/json; charset=utf-8');

if (!$auth->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация.'], JSON_THROW_ON_ERROR);
    exit;
}

$intervals = Intervals::chartMap();
$requested = (string) ($_GET['interval'] ?? 'all');
$limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
$symbol = $config['bybit']['symbol'];
$repository = new CandleRepository($pdo);

$buildSeries = static function (array $rows): array {
    $candles = [];
    foreach ($rows as $row) {
        $candles[] = [
            'time' => (new DateTimeImmutable($row['open_time'] . ' UTC'))->getTimestamp(),
            'open' => (float) $row['open_price'],
            'high' => (float) $row['high_price'],
            'low' => (float) $row['low_price'],
            'close' => (float) $row['close_price'],
        ];
    }

    return $candles;
};

if ($requested === 'all') {
    $payload = [
        'symbol' => $symbol,
        'limit' => $limit,
        'intervals' => [],
    ];
    foreach ($intervals as $label => $code) {
        $payload['intervals'][$label] = [
            'code' => $code,
            'candles' => $buildSeries($repository->latestForChart($symbol, $code, $limit)),
        ];
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

if (!isset($intervals[$requested]) && !in_array($requested, $intervals, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Неизвестный интервал.'], JSON_THROW_ON_ERROR);
    exit;
}

$code = $intervals[$requested] ?? $requested;
$label = array_search($code, $intervals, true) ?: $code;

echo json_encode([
    'symbol' => $symbol,
    'label' => $label,
    'code' => $code,
    'limit' => $limit,
    'candles' => $buildSeries($repository->latestForChart($symbol, $code, $limit)),
], JSON_THROW_ON_ERROR);
