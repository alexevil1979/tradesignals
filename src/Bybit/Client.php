<?php
declare(strict_types=1);

namespace App\Bybit;

use App\Helpers\Logger;
use RuntimeException;

final class Client
{
    private const MAINNET_URL = 'https://api.bybit.com';
    private const TESTNET_URL = 'https://api-testnet.bybit.com';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function publicGet(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /** @return array<string, mixed> */
    public function privateRequest(string $method, string $path, array $payload = []): array
    {
        $timestamp = (string) floor(microtime(true) * 1000);
        $recvWindow = (string) $this->config['recv_window'];
        $body = $method === 'GET' ? http_build_query($payload) : json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac(
            'sha256',
            $timestamp . $this->config['api_key'] . $recvWindow . $body,
            $this->config['api_secret'],
        );

        return $this->request($method, $path, $payload, [
            'X-BAPI-API-KEY: ' . $this->config['api_key'],
            'X-BAPI-SIGN: ' . $signature,
            'X-BAPI-SIGN-TYPE: 2',
            'X-BAPI-TIMESTAMP: ' . $timestamp,
            'X-BAPI-RECV-WINDOW: ' . $recvWindow,
        ]);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        $attempts = max(1, (int) ($this->config['max_retries'] ?? 3));
        $baseUrl = ($this->config['testnet'] ? self::TESTNET_URL : self::MAINNET_URL) . $path;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $curl = curl_init();
            $isGet = $method === 'GET';
            $url = $baseUrl;
            if ($isGet && $payload !== []) {
                $url .= '?' . http_build_query($payload);
            }

            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) $this->config['timeout'],
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            ]);
            if (!$isGet && $payload !== []) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            }

            $response = curl_exec($curl);
            $error = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (is_string($response) && $error === '') {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && ($decoded['retCode'] ?? -1) === 0) {
                    return $decoded;
                }
                $error = is_array($decoded) ? ($decoded['retMsg'] ?? 'Неизвестная ошибка Bybit') : 'Некорректный ответ API';
            }

            $this->logger->warning('Ошибка запроса к Bybit.', [
                'path' => $path, 'attempt' => $attempt, 'http_status' => $status, 'error' => $error,
            ], 'bybit');
            usleep($attempt * 250_000);
        }

        throw new RuntimeException('Bybit API недоступен после повторных попыток.');
    }
}
