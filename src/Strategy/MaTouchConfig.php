<?php
declare(strict_types=1);

namespace App\Strategy;

/**
 * Касание MA28: сигнал, если SMA(28) лежит между low и high закрытой свечи.
 * Отдельный свитч отслеживания на каждый таймфрейм.
 */
final class MaTouchConfig
{
    public const SETTING_KEY = 'ma_touch';
    public const PERIOD = 28;

    public const TIMEFRAMES = ['M1', 'M5', 'M15', 'H1', 'H4', 'D1'];

    /**
     * @return array{timeframes: array<string, bool>}
     */
    public static function defaults(): array
    {
        $timeframes = [];
        foreach (self::TIMEFRAMES as $tf) {
            $timeframes[$tf] = false;
        }

        return ['timeframes' => $timeframes];
    }

    /**
     * @param mixed $raw
     * @return array{timeframes: array<string, bool>}
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }

        $src = $raw['timeframes'] ?? $raw;
        if (!is_array($src)) {
            return $defaults;
        }

        $timeframes = $defaults['timeframes'];
        foreach (self::TIMEFRAMES as $tf) {
            if (array_key_exists($tf, $src)) {
                $timeframes[$tf] = self::toBool($src[$tf]);
            }
        }

        return ['timeframes' => $timeframes];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{timeframes: array<string, bool>}
     */
    public static function fromPost(array $post): array
    {
        $posted = $post['ma_touch'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }

        $timeframes = [];
        foreach (self::TIMEFRAMES as $tf) {
            $timeframes[$tf] = isset($posted[$tf]);
        }

        return self::normalize(['timeframes' => $timeframes]);
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
