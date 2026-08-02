<?php
declare(strict_types=1);

use App\Helpers\ChartUiState;
use App\Helpers\Intervals;

/** @var string $title */
$pageTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
$chartNavHref = htmlspecialchars(ChartUiState::chartHref(Intervals::chartMap()), ENT_QUOTES, 'UTF-8');
$current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
?>
<!doctype html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?> — Bybit Grid Bot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin/">Bybit Grid Bot</a>
        <div class="navbar-nav">
            <a class="nav-link" href="/admin/">Dashboard</a>
            <a class="nav-link" href="<?= $chartNavHref ?>">График</a>
            <a class="nav-link" href="/admin/strategies.php">Стратегии</a>
            <a class="nav-link <?= $current === 'orders.php' ? 'active' : '' ?>" href="/admin/orders.php">Ордера</a>
            <a class="nav-link <?= $current === 'settings.php' ? 'active' : '' ?>" href="/admin/settings.php">Настройки</a>
            <a class="nav-link" href="/admin/logs.php">Логи</a>
            <a class="nav-link <?= $current === 'signals.php' ? 'active' : '' ?>" href="/admin/signals.php">Сигналы</a>
        </div>
    </div>
</nav>
<main class="container py-4">
    <h1 class="h3"><?= $pageTitle ?></h1>
    <p class="text-secondary">Раздел в разработке.</p>
</main>
</body>
</html>
