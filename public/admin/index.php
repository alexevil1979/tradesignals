<?php
declare(strict_types=1);

use App\Auth\AdminAuth;

require dirname(__DIR__, 2) . '/bootstrap.php';
$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
$auth->requireLogin();
?>
<!doctype html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bybit Grid Bot — Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">Bybit Grid Bot</h1>
        <span class="badge text-bg-warning">На паузе</span>
    </div>
    <div class="row g-3">
        <div class="col-md-4"><div class="card"><div class="card-body"><small>BTCUSDT</small><h2>—</h2></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><small>Открытые позиции</small><h2>—</h2></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><small>Последний сигнал</small><h2>—</h2></div></div></div>
    </div>
    <p class="text-secondary mt-4 mb-0">Начальный каркас панели. Данные и действия будут добавлены на следующем этапе.</p>
</main>
</body>
</html>
