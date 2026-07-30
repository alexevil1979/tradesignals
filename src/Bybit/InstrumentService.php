<?php
declare(strict_types=1);

namespace App\Bybit;

use RuntimeException;

final class InstrumentService
{
    /** @var array<string, string> */
    private array $tickSizes = [];

    public function __construct(private readonly Client $client)
    {
    }

    public function formatPrice(string $symbol, float $price): string
    {
        $tickSize = $this->tickSizes[$symbol] ??= $this->fetchTickSize($symbol);
        $decimals = $this->decimalPlaces($tickSize);
        $rounded = floor($price / (float) $tickSize) * (float) $tickSize;

        return number_format($rounded, $decimals, '.', '');
    }

    private function fetchTickSize(string $symbol): string
    {
        $response = $this->client->publicGet('/v5/market/instruments-info', [
            'category' => 'linear',
            'symbol' => $symbol,
        ]);
        $tickSize = $response['result']['list'][0]['priceFilter']['tickSize'] ?? null;
        if (!is_string($tickSize) || (float) $tickSize <= 0) {
            throw new RuntimeException('Bybit не вернул допустимый шаг цены инструмента.');
        }

        return $tickSize;
    }

    private function decimalPlaces(string $value): int
    {
        $value = rtrim($value, '0');
        $decimal = strpos($value, '.');

        return $decimal === false ? 0 : strlen($value) - $decimal - 1;
    }
}
