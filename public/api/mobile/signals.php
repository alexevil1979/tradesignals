<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$pdo = $api['pdo'];
$config = $api['config'];

$symbol = (string) $config['bybit']['symbol'];
$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

$stmt = $pdo->prepare(
    'SELECT id, side, signal_type, price, candle_open_time, telegram_sent_at, created_at, payload
     FROM signals WHERE symbol = :symbol ORDER BY id DESC LIMIT :limit'
);
$stmt->bindValue('symbol', $symbol);
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$json([
    'ok' => true,
    'symbol' => $symbol,
    'signals' => $stmt->fetchAll(),
]);
