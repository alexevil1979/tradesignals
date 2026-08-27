<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Helpers\ChartUiState;
use App\Helpers\Intervals;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
$auth->requireLogin();

$intervals = Intervals::chartMap();
$hasTfInQuery = array_key_exists('tf', $_GET);
$active = ChartUiState::resolveTimeframe($intervals, isset($_GET['tf']) ? (string) $_GET['tf'] : null);
ChartUiState::rememberTimeframe($active);

// Канонический URL с tf — чтобы при возврате на «График» и refresh всё совпадало.
if (!$hasTfInQuery || (string) ($_GET['tf'] ?? '') !== $active) {
    header('Location: /admin/chart.php?tf=' . rawurlencode($active), true, 302);
    exit;
}

$symbol = htmlspecialchars($config['bybit']['symbol'], ENT_QUOTES, 'UTF-8');
$category = htmlspecialchars((string) $config['bybit']['category'], ENT_QUOTES, 'UTF-8');
$marketLabel = $category === 'linear' ? 'USDT Perpetual' : $category;
$csrfToken = htmlspecialchars($auth->csrfToken(), ENT_QUOTES, 'UTF-8');
$chartNavHref = htmlspecialchars(ChartUiState::chartHref($intervals), ENT_QUOTES, 'UTF-8');
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
            <a class="nav-link active" href="<?= $chartNavHref ?>">График</a>
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
        <div class="card-header border-secondary d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>
                <?= htmlspecialchars($active, ENT_QUOTES, 'UTF-8') ?>
                <span class="chart-last-signal text-secondary ms-1" id="chart-last-signal"></span>
            </span>
            <span class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-outline-warning btn-sm" id="toggle-ma" title="SMA 28">МА</button>
                <button type="button" class="btn btn-outline-info btn-sm" id="toggle-pc" title="Price Channel + Trend Flip">PC</button>
                <small class="text-secondary" id="chart-count">загрузка…</small>
                <span class="badge text-bg-secondary" id="quotes-refresh-status">автообновление 60с</span>
            </span>
        </div>
        <div class="card-body p-2">
            <div class="chart-host chart-host-lg" id="main-chart"></div>
        </div>
    </div>
</main>
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script src="/admin/assets/js/charts.js?v=20260827-1"></script>
<script>
    document.addEventListener('DOMContentLoaded', async () => {
        const activeTf = <?= json_encode($active, JSON_UNESCAPED_UNICODE) ?>;
        window.TradeSignalsCharts.persistChartTimeframe(activeTf);

        const chart = window.TradeSignalsCharts.createSingleChart({
            endpoint: '/api/candles.php?interval=<?= rawurlencode($active) ?>&limit=all',
            containerId: 'main-chart',
            viewKey: 'single:<?= rawurlencode($active) ?>',
        });

        const maBtn = document.getElementById('toggle-ma');
        const syncMaButton = () => {
            if (!maBtn) {
                return;
            }
            const on = chart.isMaEnabled();
            maBtn.classList.toggle('btn-warning', on);
            maBtn.classList.toggle('btn-outline-warning', !on);
            maBtn.classList.toggle('text-dark', on);
            maBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            maBtn.title = on ? 'Скрыть SMA 28' : 'Показать SMA 28';
        };
        syncMaButton();
        maBtn?.addEventListener('click', () => {
            chart.setMaEnabled(!chart.isMaEnabled());
            syncMaButton();
        });

        const pcBtn = document.getElementById('toggle-pc');
        const syncPcButton = () => {
            if (!pcBtn) {
                return;
            }
            const on = chart.isPcEnabled();
            pcBtn.classList.toggle('btn-info', on);
            pcBtn.classList.toggle('btn-outline-info', !on);
            pcBtn.classList.toggle('text-dark', on);
            pcBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            pcBtn.title = on
                ? 'Скрыть Price Channel + Trend Flip'
                : 'Показать Price Channel + Trend Flip';
        };
        syncPcButton();
        pcBtn?.addEventListener('click', () => {
            chart.setPcEnabled(!chart.isPcEnabled());
            syncPcButton();
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
