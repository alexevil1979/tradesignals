<?php
declare(strict_types=1);

use App\Database\SettingsRepository;
use App\Strategy\CandleRepository;
use App\Strategy\DirectionGridConfig;

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$readJson = $api['readJson'];
$pdo = $api['pdo'];
$config = $api['config'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $json(['ok' => false, 'error' => 'Только POST.'], 405);
}

$payload = $readJson();
$settings = new SettingsRepository($pdo);

$raw = $settings->get(DirectionGridConfig::SETTING_KEY);
$decoded = null;
if (is_string($raw) && $raw !== '') {
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decoded = null;
    }
}
$configGrid = DirectionGridConfig::normalize($decoded);
$prevSig = DirectionGridConfig::signature($configGrid);

$postedLevels = is_array($payload['levels'] ?? null) ? $payload['levels'] : [];
for ($i = 0; $i < 3; $i++) {
    $row = is_array($postedLevels[$i] ?? null) ? $postedLevels[$i] : [];
    if (isset($row['offset']) && is_numeric($row['offset'])) {
        $configGrid['levels'][$i]['offset'] = max(0.01, 0 + $row['offset']);
    }
    if (array_key_exists('sound', $row)) {
        $configGrid['levels'][$i]['sound'] = !empty($row['sound']);
    }
    if (array_key_exists('telegram', $row)) {
        $configGrid['levels'][$i]['telegram'] = !empty($row['telegram']);
    }
}
if (isset($payload['profit']) && is_numeric($payload['profit'])) {
    $configGrid['profit'] = max(0.01, 0 + $payload['profit']);
}
if (isset($payload['stop']) && is_numeric($payload['stop'])) {
    $configGrid['stop'] = max(0.01, 0 + $payload['stop']);
}
if (array_key_exists('enabled', $payload)) {
    $configGrid['enabled'] = !empty($payload['enabled']);
}
if (array_key_exists('test_mode', $payload)) {
    $configGrid['test_mode'] = !empty($payload['test_mode']);
}
if (array_key_exists('chart_h1', $payload)) {
    $configGrid['chart_h1'] = !empty($payload['chart_h1']);
}
if (isset($payload['mode']) && in_array($payload['mode'], ['high', 'low'], true)) {
    $configGrid['mode'] = $payload['mode'];
}
if (isset($payload['period_minutes']) && is_numeric($payload['period_minutes'])) {
    $configGrid['period_minutes'] = (int) $payload['period_minutes'];
}

$configGrid = DirectionGridConfig::normalize($configGrid);
$settings->set(
    DirectionGridConfig::SETTING_KEY,
    json_encode($configGrid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
);

$rawState = $settings->get(DirectionGridConfig::STATE_KEY);
$decodedState = null;
if (is_string($rawState) && $rawState !== '') {
    try {
        $decodedState = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedState = null;
    }
}
$state = DirectionGridConfig::normalizeState($decodedState);
if (DirectionGridConfig::signature($configGrid) !== $prevSig) {
    $state['force_rebuild'] = true;
    if (!empty($configGrid['enabled']) && !empty($state['stopped'])) {
        $state['stopped'] = false;
    }
    $settings->set(
        DirectionGridConfig::STATE_KEY,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    );
}

$symbol = (string) $config['bybit']['symbol'];
$extremum = (new CandleRepository($pdo))->extremumLastMinutes(
    $symbol,
    '1',
    (int) $configGrid['period_minutes']
);

$json([
    'ok' => true,
    'direction_grid' => DirectionGridConfig::chartOverlay($configGrid, $state, $extremum),
    'config' => $configGrid,
]);
