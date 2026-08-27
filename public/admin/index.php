<?php
declare(strict_types=1);

use App\Auth\AdminAuth;
use App\Helpers\ChartUiState;
use App\Helpers\Intervals;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
$auth->requireLogin();

$symbol = htmlspecialchars($config['bybit']['symbol'], ENT_QUOTES, 'UTF-8');
$category = htmlspecialchars((string) $config['bybit']['category'], ENT_QUOTES, 'UTF-8');
$marketLabel = $category === 'linear' ? 'USDT Perpetual' : $category;
$intervals = Intervals::chartMap();
$csrfToken = htmlspecialchars($auth->csrfToken(), ENT_QUOTES, 'UTF-8');
$chartNavHref = htmlspecialchars(ChartUiState::chartHref($intervals), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — Bybit Grid Bot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/admin.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin/">Bybit Grid Bot</a>
        <div class="navbar-nav">
            <a class="nav-link active" href="/admin/">Dashboard</a>
            <a class="nav-link" href="<?= $chartNavHref ?>">График</a>
            <a class="nav-link" href="/admin/strategies.php">Стратегии</a>
            <a class="nav-link" href="/admin/orders.php">Ордера</a>
            <a class="nav-link" href="/admin/settings.php">Настройки</a>
            <a class="nav-link" href="/admin/logs.php">Логи</a>
            <a class="nav-link" href="/admin/signals.php">Сигналы</a>
        </div>
    </div>
</nav>
<main class="container-fluid px-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Dashboard</h1>
            <p class="text-secondary mb-0">
                Котировки
                <a class="link-light" href="https://www.bybit.com/ru-RU/trade/usdt/BTCUSDT" target="_blank" rel="noopener noreferrer"><?= $symbol ?> · <?= $marketLabel ?></a>
                — все загруженные свечи по таймфреймам
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge text-bg-success" title="Котировки всегда с api.bybit.com (mainnet), как на bybit.com">Котировки MAINNET</span>
            <?php if (!empty($config['bybit']['testnet'])): ?>
                <span class="badge text-bg-warning" title="Торговые ордера могут идти в testnet">ORDERS TESTNET</span>
            <?php endif; ?>
            <span id="quotes-refresh-status" class="badge text-bg-secondary">автообновление 60с</span>
            <span id="bot-status" class="badge text-bg-secondary">Статус…</span>
            <button type="button" class="btn btn-outline-warning btn-sm" id="toggle-ma" title="SMA 28">МА</button>
            <button type="button" class="btn btn-outline-info btn-sm" id="toggle-pc" title="Price Channel + Trend Flip">PC</button>
            <button type="button" class="btn btn-outline-success btn-sm" id="repair-gaps" title="Проверить и догрузить пропущенные свечи">Догрузить гэпы</button>
            <button type="button" class="btn btn-outline-light btn-sm" id="refresh-charts">Обновить</button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card bg-black border-secondary h-100">
                <div class="card-body">
                    <small class="text-secondary"><?= $symbol ?> · <?= $marketLabel ?></small>
                    <h2 class="mb-0" id="last-price">—</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-black border-secondary h-100">
                <div class="card-body">
                    <small class="text-secondary">Открытые позиции</small>
                    <h2 class="mb-0" id="open-positions">—</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-black border-secondary h-100">
                <div class="card-body">
                    <small class="text-secondary">Последний сигнал</small>
                    <h2 class="mb-0 fs-5" id="last-signal">—</h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3" id="charts-grid">
        <?php foreach ($intervals as $label => $code): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card bg-black border-secondary h-100">
                    <div class="card-header d-flex justify-content-between align-items-center border-secondary gap-2 flex-wrap">
                        <span class="fw-semibold chart-title" data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            <span class="chart-seq text-secondary fw-normal" data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"></span>
                            <span class="chart-last-signal text-secondary fw-normal ms-1" data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"></span>
                        </span>
                        <small class="text-secondary chart-meta" data-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">загрузка…</small>
                    </div>
                    <div class="card-body p-2">
                        <div class="chart-host" id="chart-<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" data-interval="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script src="/admin/assets/js/charts.js?v=20260828-2"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dashboard = window.TradeSignalsCharts.createDashboard({
            endpoint: '/api/candles.php?interval=all&limit=all',
            containerSelector: '.chart-host',
            priceSelector: '#last-price',
        });

        const maBtn = document.getElementById('toggle-ma');
        const syncMaButton = () => {
            if (!maBtn) {
                return;
            }
            const on = dashboard.isMaEnabled();
            maBtn.classList.toggle('btn-warning', on);
            maBtn.classList.toggle('btn-outline-warning', !on);
            maBtn.classList.toggle('text-dark', on);
            maBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            maBtn.title = on ? 'Скрыть SMA 28' : 'Показать SMA 28';
        };
        syncMaButton();
        maBtn?.addEventListener('click', () => {
            dashboard.setMaEnabled(!dashboard.isMaEnabled());
            syncMaButton();
        });

        const pcBtn = document.getElementById('toggle-pc');
        const syncPcButton = () => {
            if (!pcBtn) {
                return;
            }
            const on = dashboard.isPcEnabled();
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
            dashboard.setPcEnabled(!dashboard.isPcEnabled());
            syncPcButton();
        });

        const refreshQuotes = window.TradeSignalsCharts.createQuotesAutoRefresh({
            refreshEndpoint: '/api/refresh_quotes.php',
            csrfToken: '<?= $csrfToken ?>',
            statusSelector: '#quotes-refresh-status',
            intervalMs: 60_000,
            onAfterRefresh: () => dashboard.load(),
        });

        refreshQuotes.start();
        document.getElementById('refresh-charts')?.addEventListener('click', () => refreshQuotes.tick(true));

        const candlesRepair = window.TradeSignalsCharts.createCandlesRepair({
            repairEndpoint: '/api/repair_candles.php',
            csrfToken: '<?= $csrfToken ?>',
            statusSelector: '#quotes-refresh-status',
            onAfterRepair: () => dashboard.load(),
        });
        document.getElementById('repair-gaps')?.addEventListener('click', () => {
            candlesRepair.repair().catch(() => {});
        });
    });
</script>
</body>
</html>
