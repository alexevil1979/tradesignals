<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

// Обработка Telegram-команд будет добавлена вместе с диспетчером команд.
$logger->info('Получено обновление Telegram.', ['update_id' => $payload['update_id'] ?? null], 'telegram');
http_response_code(200);
echo 'OK';
