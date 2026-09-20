<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$auth = $api['auth'];
$user = $api['user'];

$bearer = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? null);

if (is_string($bearer)) {
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($bearer), $m)) {
        $auth->revokeByPlainToken($m[1]);
    } elseif (preg_match('/^[a-f0-9]{64}$/i', trim($bearer))) {
        $auth->revokeByPlainToken(trim($bearer));
    }
}

$json([
    'ok' => true,
    'user_id' => $user['user_id'] ?? null,
]);
