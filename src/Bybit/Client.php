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
        $lastError = 'неизвестная ошибка';
        $lastRetCode = null;
        $lastHttpStatus = 0;

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
            $curlError = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $lastHttpStatus = $status;

            if (is_string($response) && $curlError === '') {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && ($decoded['retCode'] ?? -1) === 0) {
                    return $decoded;
                }

                $retCode = is_array($decoded) && isset($decoded['retCode']) ? (int) $decoded['retCode'] : null;
                $retMsg = is_array($decoded)
                    ? (string) ($decoded['retMsg'] ?? 'Неизвестная ошибка Bybit')
                    : 'Некорректный ответ API';
                $lastRetCode = $retCode;
                $lastError = $retCode !== null ? sprintf('retCode=%d %s', $retCode, $retMsg) : $retMsg;

                $this->logger->warning('Ошибка запроса к Bybit.', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'http_status' => $status,
                    'ret_code' => $retCode,
                    'error' => $lastError,
                    'testnet' => !empty($this->config['testnet']),
                ], 'bybit');

                // Бизнес-ошибки API не ретраим (ключ, баланс, параметры ордера и т.п.).
                if ($retCode !== null && !$this->isRetryableRetCode($retCode, $status)) {
                    throw new RuntimeException('Bybit: ' . $lastError);
                }
            } else {
                $lastError = $curlError !== '' ? $curlError : ('HTTP ' . $status);
                $lastRetCode = null;
                $this->logger->warning('Ошибка запроса к Bybit.', [
                    'path' => $path,
                    'attempt' => $attempt,
                    'http_status' => $status,
                    'error' => $lastError,
                    'testnet' => !empty($this->config['testnet']),
                ], 'bybit');
            }

            usleep($attempt * 250_000);
        }

        $suffix = $lastRetCode !== null
            ? $lastError
            : sprintf('%s (HTTP %d)', $lastError, $lastHttpStatus);
        throw new RuntimeException('Bybit API недоступен после повторных попыток: ' . $suffix);
    }

    private function isRetryableRetCode(int $retCode, int $httpStatus): bool
    {
        // Сетевые/временные: rate limit, server busy, timestamp drift иногда проходит после паузы.
        if ($httpStatus === 429 || $httpStatus >= 500) {
            return true;
        }

        return in_array($retCode, [
            10006, // Too many visits
            10016, // Server error
            10018, // Exceeded IP rate limit
            10000, // Server Timeout (иногда)
        ], true);
    }
}
