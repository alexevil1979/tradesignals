<?php
declare(strict_types=1);

use App\Auth\AdminAuth;

require dirname(__DIR__, 2) . '/bootstrap.php';

$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);
$auth->requireLogin();

$channel = (string) ($_GET['channel'] ?? '');
$allowedChannels = ['', 'telegram', 'trading', 'cron', 'app', 'bybit'];
if (!in_array($channel, $allowedChannels, true)) {
    $channel = '';
}

$level = (string) ($_GET['level'] ?? '');
$allowedLevels = ['', 'info', 'warning', 'error'];
if (!in_array($level, $allowedLevels, true)) {
    $level = '';
}

$sql = 'SELECT id, level, channel, message, context, created_at FROM logs WHERE 1=1';
$params = [];
if ($channel !== '') {
    $sql .= ' AND channel = :channel';
    $params['channel'] = $channel;
}
if ($level !== '') {
    $sql .= ' AND level = :level';
    $params['level'] = $level;
}
$sql .= ' ORDER BY id DESC LIMIT 200';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$logs = $statement->fetchAll();

$badge = static function (string $level): string {
    return match ($level) {
        'error' => 'danger',
        'warning' => 'warning',
        default => 'secondary',
    };
};
?>
<!doctype html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Логи — Bybit Grid Bot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/admin.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="/admin/">Bybit Grid Bot</a>
        <div class="navbar-nav">
            <a class="nav-link" href="/admin/">Dashboard</a>
            <a class="nav-link" href="/admin/chart.php">График</a>
            <a class="nav-link" href="/admin/strategies.php">Стратегии</a>
            <a class="nav-link" href="/admin/orders.php">Ордера</a>
            <a class="nav-link" href="/admin/settings.php">Настройки</a>
            <a class="nav-link active" href="/admin/logs.php">Логи</a>
            <a class="nav-link" href="/admin/signals.php">Сигналы</a>
        </div>
    </div>
</nav>
<main class="container-fluid px-4 pb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Логи</h1>
            <p class="text-secondary mb-0">Отправки в Telegram, ошибки и торговые события</p>
        </div>
        <form method="get" class="d-flex flex-wrap gap-2">
            <select name="channel" class="form-select form-select-sm bg-dark text-light border-secondary" style="width:auto">
                <option value="">все каналы</option>
                <?php foreach (['telegram', 'trading', 'cron', 'bybit', 'app'] as $item): ?>
                    <option value="<?= $item ?>" <?= $channel === $item ? 'selected' : '' ?>><?= $item ?></option>
                <?php endforeach; ?>
            </select>
            <select name="level" class="form-select form-select-sm bg-dark text-light border-secondary" style="width:auto">
                <option value="">все уровни</option>
                <?php foreach (['info', 'warning', 'error'] as $item): ?>
                    <option value="<?= $item ?>" <?= $level === $item ? 'selected' : '' ?>><?= $item ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-light btn-sm" type="submit">Фильтр</button>
        </form>
    </div>

    <div class="card bg-black border-secondary">
        <div class="table-responsive">
            <table class="table table-sm table-dark table-hover mb-0 align-middle">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Время</th>
                    <th>Уровень</th>
                    <th>Канал</th>
                    <th>Сообщение</th>
                    <th>Контекст</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="6" class="text-secondary">Записей пока нет.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $row): ?>
                    <?php
                    $contextPretty = '';
                    if (!empty($row['context'])) {
                        try {
                            $decoded = json_decode((string) $row['context'], true, 512, JSON_THROW_ON_ERROR);
                            $contextPretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                        } catch (Throwable) {
                            $contextPretty = (string) $row['context'];
                        }
                    }
                    ?>
                    <tr>
                        <td class="text-secondary"><?= (int) $row['id'] ?></td>
                        <td class="text-nowrap small"><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge text-bg-<?= $badge((string) $row['level']) ?>"><?= htmlspecialchars((string) $row['level'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><code><?= htmlspecialchars((string) $row['channel'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="small">
                            <?php if ($contextPretty !== ''): ?>
                                <pre class="mb-0 text-secondary" style="white-space:pre-wrap;max-width:420px;max-height:140px;overflow:auto"><?= htmlspecialchars($contextPretty, ENT_QUOTES, 'UTF-8') ?></pre>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>
