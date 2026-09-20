<?php
declare(strict_types=1);

use App\Database\SettingsRepository;

require_once __DIR__ . '/_bootstrap.php';

$api = mobile_api_bootstrap(true);
$json = $api['json'];
$readJson = $api['readJson'];
$pdo = $api['pdo'];
$logger = $api['logger'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $json(['ok' => false, 'error' => 'Только POST.'], 405);
}

$body = $readJson();
$settings = new SettingsRepository($pdo);
$changed = [];

if (array_key_exists('paused', $body)) {
    $paused = filter_var($body['paused'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($paused === null) {
        $paused = in_array((string) $body['paused'], ['1', 'true', 'on', 'yes'], true);
    }
    $settings->set('bot_paused', $paused ? '1' : '0');
    $changed['paused'] = $paused;
    $logger->info('Mobile: bot_paused изменён.', ['paused' => $paused], 'app');
}

if (array_key_exists('trading_enabled', $body)) {
    $enabled = filter_var($body['trading_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enabled === null) {
        $enabled = in_array((string) $body['trading_enabled'], ['1', 'true', 'on', 'yes'], true);
    }
    $settings->set('trading_enabled', $enabled ? '1' : '0');
    $changed['trading_enabled'] = $enabled;
    $logger->info('Mobile: trading_enabled изменён.', ['trading_enabled' => $enabled], 'app');
}

if ($changed === []) {
    $json(['ok' => false, 'error' => 'Нечего менять. Передайте paused и/или trading_enabled.'], 422);
}

$json([
    'ok' => true,
    'bot' => [
        'paused' => $settings->get('bot_paused', '1') === '1',
        'trading_enabled' => $settings->get('trading_enabled', '0') === '1',
    ],
    'changed' => $changed,
]);
