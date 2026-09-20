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
$user = $api['user'];

$settings = new SettingsRepository($pdo);
$candles = new CandleRepository($pdo);
$symbol = (string) $config['bybit']['symbol'];

$price = null;
$m1 = $candles->latestConfirmed($symbol, '1', 1);
if ($m1 !== []) {
    $price = (float) $m1[array_key_last($m1)]['close_price'];
}

$positionsStmt = $pdo->prepare(
    'SELECT symbol, side, quantity, entry_price, mark_price, unrealised_pnl, take_profit, stop_loss, is_open, updated_at
     FROM positions WHERE symbol = :symbol AND is_open = 1 ORDER BY id DESC LIMIT 20'
);
$positionsStmt->execute(['symbol' => $symbol]);
$positions = $positionsStmt->fetchAll();

$ordersStmt = $pdo->prepare(
    'SELECT order_link_id, side, order_type, status, quantity, price, take_profit, stop_loss, created_at, updated_at
     FROM orders WHERE symbol = :symbol
     ORDER BY id DESC LIMIT 30'
);
$ordersStmt->execute(['symbol' => $symbol]);
$orders = $ordersStmt->fetchAll();

$signalsStmt = $pdo->prepare(
    'SELECT id, side, signal_type, price, candle_open_time, telegram_sent_at, created_at
     FROM signals WHERE symbol = :symbol ORDER BY id DESC LIMIT 20'
);
$signalsStmt->execute(['symbol' => $symbol]);
$signals = $signalsStmt->fetchAll();

$rawDg = $settings->get(DirectionGridConfig::SETTING_KEY);
$decodedDg = null;
if (is_string($rawDg) && $rawDg !== '') {
    try {
        $decodedDg = json_decode($rawDg, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedDg = null;
    }
}
$directionGrid = DirectionGridConfig::normalize($decodedDg);

$rawState = $settings->get(DirectionGridConfig::STATE_KEY);
$decodedState = null;
if (is_string($rawState) && $rawState !== '') {
    try {
        $decodedState = json_decode($rawState, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $decodedState = null;
    }
}
$directionState = DirectionGridConfig::normalizeState($decodedState);
$extremum = $candles->extremumLastMinutes($symbol, '1', (int) $directionGrid['period_minutes']);
$overlay = DirectionGridConfig::chartOverlay($directionGrid, $directionState, $extremum);

$json([
    'ok' => true,
    'user' => [
        'id' => $user['user_id'],
        'username' => $user['username'],
    ],
    'market' => [
        'symbol' => $symbol,
        'category' => $config['bybit']['category'] ?? 'linear',
        'price' => $price,
        'testnet' => !empty($config['bybit']['testnet']),
    ],
    'bot' => [
        'paused' => $settings->get('bot_paused', '1') === '1',
        'trading_enabled' => $settings->get('trading_enabled', '0') === '1',
    ],
    'direction_grid' => [
        'config' => $directionGrid,
        'state' => [
            'anchor' => $directionState['anchor'],
            'filled_any' => $directionState['filled_any'],
            'wait_close' => $directionState['wait_close'],
            'stopped' => $directionState['stopped'],
            'force_rebuild' => $directionState['force_rebuild'],
        ],
        'overlay' => $overlay,
    ],
    'positions' => $positions,
    'orders' => $orders,
    'signals' => $signals,
    'server_time' => gmdate('Y-m-d H:i:s') . ' UTC',
]);
