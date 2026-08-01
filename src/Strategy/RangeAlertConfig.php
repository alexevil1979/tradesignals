<?php
declare(strict_types=1);

namespace App\Strategy;

/**
 * Диапазон: уведомления при уходе цены выше «верх» или ниже «низ».
 */
final class RangeAlertConfig
{
    public const SETTING_KEY = 'range_alert';
    public const STATE_KEY = 'range_alert_state';

    /**
     * @return array{
     *   enabled: bool,
     *   low: float|int|null,
     *   high: float|int|null,
     *   notify_count: int
     * }
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'low' => null,
            'high' => null,
            'notify_count' => 3,
        ];
    }

    /**
     * @return array{zone: ?string, sent: int, episode_key: ?string}
     */
    public static function defaultState(): array
    {
        return [
            'zone' => null,
            'sent' => 0,
            'episode_key' => null,
        ];
    }

    /**
     * @param mixed $raw
     * @return array{
     *   enabled: bool,
     *   low: float|int|null,
     *   high: float|int|null,
     *   notify_count: int
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }

        $low = self::toPrice($raw['low'] ?? null);
        $high = self::toPrice($raw['high'] ?? null);
        if ($low !== null && $high !== null && $low > $high) {
            [$low, $high] = [$high, $low];
        }

        $notify = isset($raw['notify_count']) && is_numeric($raw['notify_count'])
            ? (int) $raw['notify_count']
            : $defaults['notify_count'];

        return [
            'enabled' => self::toBool($raw['enabled'] ?? false),
            'low' => $low,
            'high' => $high,
            'notify_count' => max(1, min(20, $notify)),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{
     *   enabled: bool,
     *   low: float|int|null,
     *   high: float|int|null,
     *   notify_count: int
     * }
     */
    public static function fromPost(array $post): array
    {
        return self::normalize([
            'enabled' => isset($post['range_enabled']),
            'low' => $post['range_low'] ?? null,
            'high' => $post['range_high'] ?? null,
            'notify_count' => $post['range_notify_count'] ?? 3,
        ]);
    }

    /**
     * @param mixed $raw
     * @return array{zone: ?string, sent: int, episode_key: ?string}
     */
    public static function normalizeState(mixed $raw): array
    {
        $defaults = self::defaultState();
        if (!is_array($raw)) {
            return $defaults;
        }

        $zone = $raw['zone'] ?? null;
        if (!in_array($zone, ['inside', 'above', 'below'], true)) {
            $zone = null;
        }

        return [
            'zone' => $zone,
            'sent' => max(0, (int) ($raw['sent'] ?? 0)),
            'episode_key' => isset($raw['episode_key']) && is_string($raw['episode_key']) && $raw['episode_key'] !== ''
                ? $raw['episode_key']
                : null,
        ];
    }

    public static function formatPrice(float|int $price): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8F', (float) $price), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private static function toPrice(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $price = 0 + $value;

        return $price > 0 ? $price : null;
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
