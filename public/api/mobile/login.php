<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(false);
$json = $api['json'];
$readJson = $api['readJson'];
/** @var \App\Auth\ApiTokenAuth $auth */
$auth = $api['auth'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $json(['ok' => false, 'error' => 'Только POST.'], 405);
}

$body = $readJson();
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
$device = isset($body['device_name']) ? trim((string) $body['device_name']) : null;

if ($username === '' || $password === '') {
    $json(['ok' => false, 'error' => 'Укажите логин и пароль.'], 422);
}

$user = $auth->attemptLogin($username, $password);
if ($user === null) {
    $json(['ok' => false, 'error' => 'Неверный логин или пароль.'], 401);
}

$issued = $auth->issue((int) $user['id'], $device);

$json([
    'ok' => true,
    'token' => $issued['token'],
    'expires_at' => $issued['expires_at'],
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
    ],
]);
