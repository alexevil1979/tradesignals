<?php
declare(strict_types=1);

namespace App\Strategy;

/**
 * Price Channel + Trend Flip: уведомления в Telegram по стрелкам индикатора PC.
 */
final class PriceChannelConfig
{
    public const SETTING_KEY = 'price_channel';
    public const STATE_KEY = 'price_channel_state';
    public const DEFAULT_LENGTH = 20;

    public const TIMEFRAMES = ['M1', 'M5', 'M15', 'H1', 'H4', 'D1'];

    /**
     * @return array{
     *   timeframes: array<string, bool>,
     *   channel_enabled: bool,
     *   flip_enabled: bool,
     *   channel_length: int
     * }
     */
    public static function defaults(): array
    {
        $timeframes = [];
        foreach (self::TIMEFRAMES as $tf) {
            $timeframes[$tf] = false;
        }

        return [
            'timeframes' => $timeframes,
            'channel_enabled' => true,
            'flip_enabled' => true,
            'channel_length' => self::DEFAULT_LENGTH,
        ];
    }

    /**
     * @return array<string, array{
     *   last_candle: ?string,
     *   trend: ?string,
     *   last_flip_close: float|null,
     *   extreme_open: float|null,
     *   extreme_close: float|null
     * }>
     */
    public static function defaultState(): array
    {
        $state = [];
        foreach (self::TIMEFRAMES as $tf) {
            $state[$tf] = self::emptyTfState();
        }

        return $state;
    }

    /**
     * @return array{
     *   last_candle: ?string,
     *   trend: ?string,
     *   last_flip_close: float|null,
     *   extreme_open: float|null,
     *   extreme_close: float|null
     * }
     */
    public static function emptyTfState(): array
    {
        return [
            'last_candle' => null,
            'trend' => null,
            'last_flip_close' => null,
            'extreme_open' => null,
            'extreme_close' => null,
        ];
    }

    /**
     * @param mixed $raw
     * @return array{
     *   timeframes: array<string, bool>,
     *   channel_enabled: bool,
     *   flip_enabled: bool,
     *   channel_length: int
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }

        $src = $raw['timeframes'] ?? null;
        if (!is_array($src)) {
            $src = $defaults['timeframes'];
        }

        $timeframes = $defaults['timeframes'];
        foreach (self::TIMEFRAMES as $tf) {
            if (array_key_exists($tf, $src)) {
                $timeframes[$tf] = self::toBool($src[$tf]);
            }
        }

        $length = isset($raw['channel_length']) && is_numeric($raw['channel_length'])
            ? (int) $raw['channel_length']
            : $defaults['channel_length'];

        return [
            'timeframes' => $timeframes,
            'channel_enabled' => array_key_exists('channel_enabled', $raw)
                ? self::toBool($raw['channel_enabled'])
                : true,
            'flip_enabled' => array_key_exists('flip_enabled', $raw)
                ? self::toBool($raw['flip_enabled'])
                : true,
            'channel_length' => max(2, min(200, $length)),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{
     *   timeframes: array<string, bool>,
     *   channel_enabled: bool,
     *   flip_enabled: bool,
     *   channel_length: int
     * }
     */
    public static function fromPost(array $post): array
    {
        $posted = $post['pc'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }

        $timeframes = [];
        foreach (self::TIMEFRAMES as $tf) {
            $timeframes[$tf] = isset($posted[$tf]);
        }

        return self::normalize([
            'timeframes' => $timeframes,
            'channel_enabled' => isset($post['pc_channel_enabled']),
            'flip_enabled' => isset($post['pc_flip_enabled']),
            'channel_length' => $post['pc_channel_length'] ?? self::DEFAULT_LENGTH,
        ]);
    }

    /**
     * @param mixed $raw
     * @return array<string, array<string, mixed>>
     */
    public static function normalizeState(mixed $raw): array
    {
        $defaults = self::defaultState();
        if (!is_array($raw)) {
            return $defaults;
        }

        $out = $defaults;
        foreach (self::TIMEFRAMES as $tf) {
            $row = is_array($raw[$tf] ?? null) ? $raw[$tf] : [];
            $trend = $row['trend'] ?? null;
            if ($trend !== 'up' && $trend !== 'down') {
                $trend = null;
            }
            $lastCandle = $row['last_candle'] ?? null;

            $out[$tf] = [
                'last_candle' => is_string($lastCandle) && $lastCandle !== '' ? $lastCandle : null,
                'trend' => $trend,
                'last_flip_close' => isset($row['last_flip_close']) && is_numeric($row['last_flip_close'])
                    ? 0 + $row['last_flip_close']
                    : null,
                'extreme_open' => isset($row['extreme_open']) && is_numeric($row['extreme_open'])
                    ? 0 + $row['extreme_open']
                    : null,
                'extreme_close' => isset($row['extreme_close']) && is_numeric($row['extreme_close'])
                    ? 0 + $row['extreme_close']
                    : null,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function enabledTimeframes(array $config): array
    {
        $out = [];
        foreach (self::TIMEFRAMES as $tf) {
            if (!empty($config['timeframes'][$tf])) {
                $out[] = $tf;
            }
        }

        return $out;
    }

    public static function isActive(array $config): bool
    {
        if (self::enabledTimeframes($config) === []) {
            return false;
        }

        return !empty($config['channel_enabled']) || !empty($config['flip_enabled']);
    }

    public static function formatPrice(float|int $price): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8F', (float) $price), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        $text = mb_strtolower(trim((string) $value));

        return in_array($text, ['1', 'true', 'on', 'yes', 'вкл'], true);
    }
}
