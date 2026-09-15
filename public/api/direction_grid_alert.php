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
$l1 = null;
$l1Source = null;

// Живой L1 из выставленной сетки, иначе превью от экстремума периода.
foreach ($state['levels'] as $level) {
    if ((int) ($level['index'] ?? -1) === 0 && isset($level['price']) && is_numeric($level['price'])) {
        $l1 = (float) $level['price'];
        $l1Source = 'grid';
        break;
    }
}

if ($l1 === null && isset($state['anchor']) && is_numeric($state['anchor'])) {
    $offset = (float) ($configGrid['levels'][0]['offset'] ?? 0);
    $anchor = (float) $state['anchor'];
    $l1 = $mode === 'low' ? $anchor + $offset : $anchor - $offset;
    $l1Source = 'anchor';
}

if ($l1 === null) {
    $extremum = $candles->extremumLastMinutes($symbol, '1', (int) $configGrid['period_minutes']);
    if ($extremum !== null) {
        $anchor = $mode === 'low' ? (float) $extremum['low'] : (float) $extremum['high'];
        $offset = (float) ($configGrid['levels'][0]['offset'] ?? 0);
        $l1 = $mode === 'low' ? $anchor + $offset : $anchor - $offset;
        $l1Source = 'preview';
    }
}

$triggered = false;
if ($price !== null && $l1 !== null && !empty($configGrid['sound_l1'])) {
    $triggered = $mode === 'low'
        ? $price > $l1
        : $price < $l1;
}

echo json_encode([
    'ok' => true,
    'sound_l1' => !empty($configGrid['sound_l1']),
    'enabled' => !empty($configGrid['enabled']),
    'mode' => $mode,
    'price' => $price,
    'l1' => $l1,
    'l1_source' => $l1Source,
    'triggered' => $triggered,
    'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
], JSON_THROW_ON_ERROR);
