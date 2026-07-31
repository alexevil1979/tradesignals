<?php
declare(strict_types=1);

namespace App\Telegram;

use App\Helpers\Logger;

/**
 * Отправка через Bot API по образцу botfabric/notify_bot.py:
 * прокси из config → TELEGRAM_PROXY → HTTPS_PROXY/HTTP_PROXY/ALL_PROXY,
 * схемы http(s)/socks4/socks5/socks5h.
 */
final class Bot
{
    /** @param array{token?:string,chat_id?:string,proxy?:string} $config */
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

        if (mb_strlen($message) > 4090) {
            $message = mb_substr($message, 0, 4070) . "\n…";
        }

        $proxy = $this->resolveProxy();
        $proxyUsed = $proxy !== '';

        $curl = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        if ($curl === false) {
            $this->logger->error('Не удалось инициализировать cURL для Telegram.', $context, 'telegram');

            return false;
        }

        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        if ($proxyUsed) {
            $this->applyProxyOptions($opts, $proxy);
        }

        curl_setopt_array($curl, $opts);

        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $proxyContext = [
            'http_proxy_used' => $proxyUsed,
            'http_proxy_masked' => $proxyUsed ? $this->maskProxyUrl($proxy) : '',
        ];

        if ($errno !== 0 || !is_string($response)) {
            $this->logger->error(
                'Ошибка сети при отправке в Telegram.',
                $context + $proxyContext + [
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
                $context + $proxyContext + [
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
            $context + $proxyContext + [
                'chat_id' => $chatId,
                'message_id' => $decoded['result']['message_id'] ?? null,
                'preview' => mb_substr(strip_tags($message), 0, 160),
            ],
            'telegram'
        );

        return true;
    }

    /**
     * Как resolve_notify_http_proxy в botfabric:
     * config.telegram.proxy → TELEGRAM_PROXY → HTTPS_PROXY/HTTP_PROXY/ALL_PROXY.
     */
    public function resolveProxy(): string
    {
        $candidates = [
            (string) ($this->config['proxy'] ?? ''),
            (string) (getenv('TELEGRAM_PROXY') ?: ''),
            (string) (getenv('HTTPS_PROXY') ?: ''),
            (string) (getenv('HTTP_PROXY') ?: ''),
            (string) (getenv('https_proxy') ?: ''),
            (string) (getenv('http_proxy') ?: ''),
            (string) (getenv('ALL_PROXY') ?: ''),
            (string) (getenv('all_proxy') ?: ''),
        ];

        foreach ($candidates as $candidate) {
            $proxy = trim($candidate);
            if ($this->isUsableProxyUrl($proxy)) {
                return $proxy;
            }
        }

        return '';
    }

    public function isUsableProxyUrl(string $proxy): bool
    {
        $low = strtolower(trim($proxy));

        return str_starts_with($low, 'http://')
            || str_starts_with($low, 'https://')
            || str_starts_with($low, 'socks5://')
            || str_starts_with($low, 'socks5h://')
            || str_starts_with($low, 'socks4://');
    }

    /** @param array<int, mixed> $opts */
    private function applyProxyOptions(array &$opts, string $proxy): void
    {
        $low = strtolower($proxy);
        $opts[CURLOPT_PROXY] = $proxy;

        // Как botfabric (rdns=True): DNS через прокси для SOCKS5.
        if (str_starts_with($low, 'socks5h://') || str_starts_with($low, 'socks5://')) {
            if (defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            } else {
                $opts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
            }
        } elseif (str_starts_with($low, 'socks4://')) {
            $opts[CURLOPT_PROXYTYPE] = defined('CURLPROXY_SOCKS4') ? CURLPROXY_SOCKS4 : 4;
        }
    }

    private function maskProxyUrl(string $proxy): string
    {
        $parts = parse_url($proxy);
        if ($parts === false || empty($parts['host'])) {
            return '***';
        }

        $scheme = $parts['scheme'] ?? 'proxy';
        $auth = '';
        if (isset($parts['user']) && $parts['user'] !== '') {
            $auth = rawurlencode((string) $parts['user']) . ':***@';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $auth . $parts['host'] . $port;
    }
}
