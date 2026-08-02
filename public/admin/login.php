<?php
declare(strict_types=1);

use App\Auth\AdminAuth;

require dirname(__DIR__, 2) . '/bootstrap.php';
$auth = new AdminAuth($pdo);
$auth->startSession($config['app']['session_name']);

// Уже вошли — на дашборд.
if ($auth->check()) {
    header('Location: /admin/', true, 302);
    exit;
}

$error = null;
$usernameValue = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue = (string) ($_POST['username'] ?? '');
    $postedToken = $_POST['csrf_token'] ?? null;
    $hasSessionToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '';

    if (!$hasSessionToken || !$auth->verifyCsrf(is_string($postedToken) ? $postedToken : null)) {
        // Сессия/cookie не дошла (часто из‑за Secure/прокси) или устаревшая вкладка.
        unset($_SESSION['csrf_token']);
        $error = 'Сессия устарела или cookie не сохранились. Обновите страницу и войдите ещё раз.';
    } elseif (!$auth->attempt($usernameValue, (string) ($_POST['password'] ?? ''))) {
        $error = 'Неверное имя пользователя или пароль.';
    } else {
        header('Location: /admin/', true, 302);
        exit;
    }
}

// Гарантируем свежий токен в форме (в т.ч. после сбоя CSRF).
$csrfToken = htmlspecialchars($auth->csrfToken(), ENT_QUOTES, 'UTF-8');
$usernameAttr = htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — Bybit Grid Bot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark">
<main class="container py-5">
    <form class="card mx-auto p-4" method="post" action="/admin/login.php" style="max-width: 420px" autocomplete="on">
        <h1 class="h4 mb-4">Вход в панель</h1>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <label class="form-label">Логин
            <input class="form-control" name="username" value="<?= $usernameAttr ?>" autocomplete="username" required>
        </label>
        <label class="form-label">Пароль
            <input class="form-control" type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn btn-primary w-100 mt-3" type="submit">Войти</button>
    </form>
</main>
</body>
</html>
