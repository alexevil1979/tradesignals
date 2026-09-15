<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Database\SettingsRepository;
use App\Strategy\CandleRepository;
use App\Strategy\DirectionGridConfig;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!$auth->check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация.'], JSON_THROW_ON_ERROR);
    exit;
}

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

$symbol = (string) $config['bybit']['symbol'];
$candles = new CandleRepository($pdo);
$price = null;
$m1 = $candles->latestConfirmed($symbol, '1', 1);
if ($m1 !== []) {
    $price = (float) $m1[array_key_last($m1)]['close_price'];
}

$mode = (string) $configGrid['mode'];
$extremum = $candles->extremumLastMinutes($symbol, '1', (int) $configGrid['period_minutes']);
$overlay = DirectionGridConfig::chartOverlay($configGrid, $state, $extremum);

$triggeredLevels = [];
$triggered = false;
if ($price !== null) {
    foreach ($overlay['levels'] as $lvl) {
        $idx = (int) ($lvl['index'] ?? -1);
        if (!DirectionGridConfig::levelSoundEnabled($configGrid, $idx)) {
            continue;
        }
        $lvlPrice = (float) $lvl['price'];
        $hit = $mode === 'low' ? $price > $lvlPrice : $price < $lvlPrice;
        if ($hit) {
            $triggered = true;
            $triggeredLevels[] = [
                'index' => $idx,
                'title' => (string) ($lvl['title'] ?? ('L' . ($idx + 1))),
                'price' => $lvlPrice,
            ];
        }
    }
}

echo json_encode([
    'ok' => true,
    'enabled' => !empty($configGrid['enabled']),
    'mode' => $mode,
    'price' => $price,
    'levels' => $overlay['levels'],
    'triggered' => $triggered,
    'triggered_levels' => $triggeredLevels,
    'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
], JSON_THROW_ON_ERROR);
