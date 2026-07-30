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

    public function send(string $message): bool
    {
        if ($this->config['token'] === '' || $this->config['chat_id'] === '') {
            $this->logger->warning('Telegram не настроен.', [], 'telegram');
            return false;
        }

        $curl = curl_init('https://api.telegram.org/bot' . $this->config['token'] . '/sendMessage');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $this->config['chat_id'],
                'text' => $message,
                'parse_mode' => 'HTML',
            ], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        $decoded = is_string($response) ? json_decode($response, true) : null;

        return is_array($decoded) && ($decoded['ok'] ?? false) === true;
    }
}
