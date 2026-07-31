<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Database\SettingsRepository;
use App\Helpers\Intervals;
use App\Strategy\CandleAnalyzer;
use App\Strategy\CandleRepository;
use App\Strategy\SignalGridConfig;
use App\Strategy\SignalRepository;

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
$limitRaw = (string) ($_GET['limit'] ?? 'all');
$limit = ($limitRaw === 'all' || $limitRaw === '0') ? 0 : min(10000, max(1, (int) $limitRaw));
$symbol = $config['bybit']['symbol'];
$repository = new CandleRepository($pdo);
$analyzer = new CandleAnalyzer();
$signals = new SignalRepository($pdo);
$lastSignals = $signals->latestTelegramSentByTimeframe($symbol);

$settings = new SettingsRepository($pdo);
$rawGrid = $settings->get(SignalGridConfig::SETTING_KEY);
$decodedGrid = null;
if (is_string($rawGrid) && $rawGrid !== '') {
    try {
        $decodedGrid = json_decode($rawGrid, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedGrid = null;
    }
}
$signalGrid = SignalGridConfig::normalize($decodedGrid);

$buildSeries = static function (array $rows, string $intervalCode): array {
    $candles = [];
    foreach ($rows as $row) {
        $open = (float) $row['open_price'];
        $high = (float) $row['high_price'];
        $low = (float) $row['low_price'];
        $close = (float) $row['close_price'];
        $dt = new DateTimeImmutable($row['open_time'] . ' UTC');
        $candles[] = [
            // Для дневных Lightweight Charts ждёт YYYY-MM-DD.
            'time' => in_array($intervalCode, ['D', 'W', 'M'], true)
                ? $dt->format('Y-m-d')
                : $dt->getTimestamp(),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'confirmed' => (bool) ($row['is_confirmed'] ?? true),
        ];
    }

    return $candles;
};

$sequenceFor = static function (array $candles, string $label) use ($analyzer, $signalGrid): array {
    $minBody = (float) ($signalGrid['min_body'][$label] ?? 0);

    return $analyzer->currentSequence($candles, $minBody);
};

$meta = [
    'symbol' => $symbol,
    'category' => $config['bybit']['category'],
    'market' => 'USDT Perpetual',
    'testnet' => false,
    'quotes_network' => 'mainnet',
    'source' => 'https://www.bybit.com/ru-RU/trade/usdt/BTCUSDT',
    'limit' => $limit === 0 ? 'all' : $limit,
];

if ($requested === 'all') {
    $payload = $meta + ['intervals' => []];
    foreach ($intervals as $label => $code) {
        $candles = $buildSeries($repository->latestForChart($symbol, $code, $limit), $code);
        $payload['intervals'][$label] = [
            'code' => $code,
            'candles' => $candles,
            'sequence' => $sequenceFor($candles, $label),
            'min_body' => $signalGrid['min_body'][$label] ?? null,
            'last_signal' => $lastSignals[$label] ?? null,
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
$candles = $buildSeries($repository->latestForChart($symbol, $code, $limit), $code);

echo json_encode($meta + [
    'label' => $label,
    'code' => $code,
    'candles' => $candles,
    'sequence' => $sequenceFor($candles, (string) $label),
    'min_body' => $signalGrid['min_body'][$label] ?? null,
    'last_signal' => $lastSignals[$label] ?? null,
], JSON_THROW_ON_ERROR);
