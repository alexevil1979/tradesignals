<?php
declare(strict_types=1);

use App\Helpers\Intervals;
use App\Strategy\CandleAnalyzer;
use App\Strategy\CandleRepository;
use App\Strategy\SignalGridConfig;
use App\Strategy\SignalRepository;
use App\Database\SettingsRepository;

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$pdo = $api['pdo'];
$config = $api['config'];

$intervals = Intervals::chartMap();
$requested = (string) ($_GET['interval'] ?? 'all');
$limitRaw = (string) ($_GET['limit'] ?? '200');
$limit = ($limitRaw === 'all' || $limitRaw === '0') ? 0 : min(2000, max(1, (int) $limitRaw));
$symbol = (string) $config['bybit']['symbol'];
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
        $dt = new DateTimeImmutable($row['open_time'] . ' UTC');
        $candles[] = [
            'time' => in_array($intervalCode, ['D', 'W', 'M'], true)
                ? $dt->format('Y-m-d')
                : $dt->getTimestamp(),
            'open' => (float) $row['open_price'],
            'high' => (float) $row['high_price'],
            'low' => (float) $row['low_price'],
            'close' => (float) $row['close_price'],
            'confirmed' => (bool) ($row['is_confirmed'] ?? true),
        ];
    }

    return $candles;
};

if ($requested === 'all') {
    $payload = [
        'ok' => true,
        'symbol' => $symbol,
        'intervals' => [],
    ];
    foreach ($intervals as $label => $code) {
        $fetchLimit = $limit === 0 ? 300 : $limit;
        $candles = $buildSeries($repository->latestForChart($symbol, $code, $fetchLimit), $code);
        $payload['intervals'][$label] = [
            'code' => $code,
            'candles' => $candles,
            'sequence' => $analyzer->currentSequence($candles, (float) ($signalGrid['min_body'][$label] ?? 0)),
            'last_signal' => $lastSignals[$label] ?? null,
        ];
    }
    $json($payload);
}

if (!isset($intervals[$requested]) && !in_array($requested, $intervals, true)) {
    $json(['ok' => false, 'error' => 'Неизвестный интервал.'], 400);
}

$code = $intervals[$requested] ?? $requested;
$label = array_search($code, $intervals, true) ?: $code;
$fetchLimit = $limit === 0 ? 500 : $limit;
$candles = $buildSeries($repository->latestForChart($symbol, $code, $fetchLimit), $code);

$json([
    'ok' => true,
    'symbol' => $symbol,
    'label' => $label,
    'code' => $code,
    'candles' => $candles,
    'sequence' => $analyzer->currentSequence($candles, (float) ($signalGrid['min_body'][$label] ?? 0)),
    'last_signal' => $lastSignals[$label] ?? null,
]);
