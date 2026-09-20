<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$pdo = $api['pdo'];
$config = $api['config'];

$symbol = (string) $config['bybit']['symbol'];

$orders = $pdo->prepare(
    'SELECT order_link_id, bybit_order_id, side, order_type, status, quantity, price, average_price,
            take_profit, stop_loss, created_at, updated_at
     FROM orders WHERE symbol = :symbol ORDER BY id DESC LIMIT 100'
);
$orders->execute(['symbol' => $symbol]);

$positions = $pdo->prepare(
    'SELECT symbol, side, quantity, entry_price, mark_price, unrealised_pnl, realised_pnl,
            take_profit, stop_loss, is_open, updated_at
     FROM positions WHERE symbol = :symbol ORDER BY is_open DESC, id DESC LIMIT 50'
);
$positions->execute(['symbol' => $symbol]);

$json([
    'ok' => true,
    'symbol' => $symbol,
    'orders' => $orders->fetchAll(),
    'positions' => $positions->fetchAll(),
]);
