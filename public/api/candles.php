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
$testnet = (bool) $config['bybit']['testnet'];

$buildSeries = static function (array $rows, string $intervalCode): array {
    $candles = [];
    foreach ($rows as $row) {
        $open = (float) $row['open_price'];
        $high = (float) $row['high_price'];
        $low = (float) $row['low_price'];
        $close = (float) $row['close_price'];

        // Отсекаем битые точки, иначе шкала D1 «взрывается» (как выброс ~1_000_000).
        if (min($open, $high, $low, $close) < 1000.0 || max($open, $high, $low, $close) > 500000.0) {
            continue;
        }
        if ($high < $open || $high < $close || $high < $low || $low > $open || $low > $close) {
            continue;
        }

        $dt = new DateTimeImmutable($row['open_time'] . ' UTC');
        $candles[] = [
            // Для дневных/недельных Lightweight Charts ждёт YYYY-MM-DD, не unix-timestamp.
            'time' => in_array($intervalCode, ['D', 'W', 'M'], true)
                ? $dt->format('Y-m-d')
                : $dt->getTimestamp(),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
        ];
    }

    return $candles;
};

$meta = [
    'symbol' => $symbol,
    'category' => $config['bybit']['category'],
    'market' => 'USDT Perpetual',
    'testnet' => $testnet,
    'source' => $testnet
        ? 'https://testnet.bybit.com/trade/usdt/BTCUSDT'
        : 'https://www.bybit.com/ru-RU/trade/usdt/BTCUSDT',
    'limit' => $limit,
];

if ($requested === 'all') {
    $payload = $meta + ['intervals' => []];
    foreach ($intervals as $label => $code) {
        $payload['intervals'][$label] = [
            'code' => $code,
            'candles' => $buildSeries($repository->latestForChart($symbol, $code, $limit), $code),
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

echo json_encode($meta + [
    'label' => $label,
    'code' => $code,
    'candles' => $buildSeries($repository->latestForChart($symbol, $code, $limit), $code),
], JSON_THROW_ON_ERROR);
