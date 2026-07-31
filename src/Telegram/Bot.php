<?php
declare(strict_types=1);

namespace App\Telegram;

use App\Helpers\Logger;

final class Bot
{
    /** @param array{token:string,chat_id:string} $config */
    public function __construct(private readonly array $config, private readonly Logger $logger)
    {
    }

    /** @param array<string, mixed> $context */
    public function send(string $message, array $context = []): bool
    {
        $token = trim((string) ($this->config['token'] ?? ''));
        $chatId = trim((string) ($this->config['chat_id'] ?? ''));

        if ($token === '' || $chatId === '' || str_starts_with($token, 'CHANGE_THIS') || str_starts_with($chatId, 'CHANGE_THIS')) {
            $this->logger->error(
                'Telegram не настроен: пустой token или chat_id.',
                $context + ['has_token' => $token !== '', 'has_chat_id' => $chatId !== ''],
                'telegram'
            );

            return false;
        }

        $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        if ($curl === false) {
            $this->logger->error('Не удалось инициализировать cURL для Telegram.', $context, 'telegram');

            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($errno !== 0 || !is_string($response)) {
            $this->logger->error(
                'Ошибка сети при отправке в Telegram.',
                $context + [
                    'curl_errno' => $errno,
                    'curl_error' => $error,
                    'http_code' => $httpCode,
                ],
                'telegram'
            );

            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $this->logger->error(
                'Telegram API отклонил сообщение.',
                $context + [
                    'http_code' => $httpCode,
                    'description' => is_array($decoded) ? ($decoded['description'] ?? null) : null,
                    'error_code' => is_array($decoded) ? ($decoded['error_code'] ?? null) : null,
                    'response' => mb_substr($response, 0, 500),
                ],
                'telegram'
            );

            return false;
        }

        $this->logger->info(
            'Сообщение отправлено в Telegram.',
            $context + [
                'chat_id' => $chatId,
                'message_id' => $decoded['result']['message_id'] ?? null,
                'preview' => mb_substr(strip_tags($message), 0, 160),
            ],
            'telegram'
        );

        return true;
    }
}
