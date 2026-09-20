<?php
declare(strict_types=1);

use App\Database\SettingsRepository;
use App\Strategy\CandleRepository;
use App\Strategy\DirectionGridConfig;

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$pdo = $api['pdo'];
$config = $api['config'];

$settings = new SettingsRepository($pdo);
$configGrid = DirectionGridConfig::normalize(
    (static function (SettingsRepository $settings) {
        $raw = $settings->get(DirectionGridConfig::SETTING_KEY);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    })($settings)
);

$stateRaw = $settings->get(DirectionGridConfig::STATE_KEY);
$decodedState = null;
if (is_string($stateRaw) && $stateRaw !== '') {
    try {
        $decodedState = json_decode($stateRaw, true, 512, JSON_THROW_ON_ERROR);
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

$extremum = $candles->extremumLastMinutes($symbol, '1', (int) $configGrid['period_minutes']);
$overlay = DirectionGridConfig::chartOverlay($configGrid, $state, $extremum);

$mode = (string) $configGrid['mode'];
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

$json([
    'ok' => true,
    'enabled' => !empty($configGrid['enabled']),
    'mode' => $mode,
    'price' => $price,
    'levels' => $overlay['levels'],
    'overlay' => $overlay,
    'triggered' => $triggered,
    'triggered_levels' => $triggeredLevels,
    'updated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
]);
