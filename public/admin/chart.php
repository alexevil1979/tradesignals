<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Helpers\Intervals;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
$auth->requireLogin();

$intervals = Intervals::chartMap();
$active = (string) ($_GET['tf'] ?? 'M15');
if (!isset($intervals[$active])) {
    $active = 'M15';
}
$symbol = htmlspecialchars($config['bybit']['symbol'], ENT_QUOTES, 'UTF-8');
$category = htmlspecialchars((string) $config['bybit']['category'], ENT_QUOTES, 'UTF-8');
$marketLabel = $category === 'linear' ? 'USDT Perpetual' : $category;
$csrfToken = htmlspecialchars($auth->csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>График — Bybit Grid Bot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/admin.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin/">Bybit Grid Bot</a>
        <div class="navbar-nav">
            <a class="nav-link" href="/admin/">Dashboard</a>
            <a class="nav-link active" href="/admin/chart.php">График</a>
            <a class="nav-link" href="/admin/strategies.php">Стратегии</a>
            <a class="nav-link" href="/admin/orders.php">Ордера</a>
            <a class="nav-link" href="/admin/settings.php">Настройки</a>
            <a class="nav-link" href="/admin/logs.php">Логи</a>
            <a class="nav-link" href="/admin/signals.php">Сигналы</a>
        </div>
    </div>
</nav>
<main class="container-fluid px-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">
                График
                <a class="link-light" href="https://www.bybit.com/ru-RU/trade/usdt/BTCUSDT" target="_blank" rel="noopener noreferrer"><?= $symbol ?></a>
            </h1>
            <p class="text-secondary mb-0"><?= $marketLabel ?> · все загруженные бары выбранного таймфрейма</p>
        </div>
        <div class="btn-group" role="group" aria-label="Таймфреймы">
            <?php foreach ($intervals as $label => $code): ?>
                <a class="btn btn-sm <?= $label === $active ? 'btn-primary' : 'btn-outline-secondary' ?>"
                   href="/admin/chart.php?tf=<?= urlencode($label) ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card bg-black border-secondary">
        <div class="card-header border-secondary d-flex justify-content-between">
            <span><?= htmlspecialchars($active, ENT_QUOTES, 'UTF-8') ?></span>
            <small class="text-secondary" id="chart-count">загрузка…</small>
            <span class="badge text-bg-secondary ms-2" id="quotes-refresh-status">автообновление 60с</span>
        </div>
        <div class="card-body p-2">
            <div class="chart-host chart-host-lg" id="main-chart"></div>
        </div>
    </div>
</main>
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script src="/admin/assets/js/charts.js?v=20260731-3"></script>
<script>
    document.addEventListener('DOMContentLoaded', async () => {
        const chart = window.TradeSignalsCharts.createSingleChart({
            endpoint: '/api/candles.php?interval=<?= rawurlencode($active) ?>&limit=all',
            containerId: 'main-chart',
            viewKey: 'single:<?= rawurlencode($active) ?>',
        });

        const updateCount = async () => {
            const count = await chart.load();
            const meta = document.getElementById('chart-count');
            if (meta) {
                meta.textContent = count ? `${count} баров (все загруженные)` : 'нет данных — запустите fetch_candles';
            }
        };

        const refreshQuotes = window.TradeSignalsCharts.createQuotesAutoRefresh({
            refreshEndpoint: '/api/refresh_quotes.php',
            csrfToken: '<?= $csrfToken ?>',
            statusSelector: '#quotes-refresh-status',
            intervalMs: 60_000,
            onAfterRefresh: updateCount,
        });
        refreshQuotes.start();
    });
</script>
</body>
</html>
