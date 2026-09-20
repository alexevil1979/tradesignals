<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$pdo = $api['pdo'];

$limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
$channel = trim((string) ($_GET['channel'] ?? ''));
$level = trim((string) ($_GET['level'] ?? ''));

$sql = 'SELECT id, level, channel, message, context, created_at FROM logs WHERE 1=1';
$params = [];
if ($channel !== '') {
    $sql .= ' AND channel = :channel';
    $params['channel'] = $channel;
}
if (in_array($level, ['info', 'warning', 'error'], true)) {
    $sql .= ' AND level = :level';
    $params['level'] = $level;
}
$sql .= ' ORDER BY id DESC LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$json([
    'ok' => true,
    'logs' => $stmt->fetchAll(),
]);
