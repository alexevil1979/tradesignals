<?php
declare(strict_types=1);

/**
 * Ручная проверка Telegram:
 *   php bin/test_telegram.php
 */
use App\Telegram\Bot;

require dirname(__DIR__) . '/bootstrap.php';

$bot = new Bot($config['telegram'], $logger);
$ok = $bot->send(
    "✅ Тест Telegram от Bybit Grid Bot\nВремя: <code>" . gmdate('Y-m-d H:i:s') . " UTC</code>",
    ['source' => 'bin/test_telegram.php']
);

echo $ok ? "OK: сообщение отправлено.\n" : "FAIL: см. логи (канал telegram).\n";
exit($ok ? 0 : 1);
