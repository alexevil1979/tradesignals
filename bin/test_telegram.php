<?php
declare(strict_types=1);

/**
 * Ручная проверка Telegram (с учётом прокси из config/env):
 *   php bin/test_telegram.php
 */
use App\Telegram\Bot;

require dirname(__DIR__) . '/bootstrap.php';

$bot = new Bot($config['telegram'], $logger);
$proxy = $bot->resolveProxy();
echo $proxy !== ''
    ? 'Proxy: ' . preg_replace('#://([^:/@]+):([^@/]+)@#', '://$1:***@', $proxy) . PHP_EOL
    : "Proxy: (direct, без прокси)\n";

$ok = $bot->send(
    "✅ Тест Telegram от Bybit Grid Bot\nВремя: <code>" . gmdate('Y-m-d H:i:s') . " UTC</code>\nПрокси: <code>" .
    ($proxy !== '' ? 'on' : 'off') . '</code>',
    ['source' => 'bin/test_telegram.php']
);

echo $ok ? "OK: сообщение отправлено.\n" : "FAIL: см. логи (канал telegram).\n";
exit($ok ? 0 : 1);
