<?php
declare(strict_types=1);

namespace App\Strategy;

/**
 * Сетка уровней пробития: цены выше/ниже текущей с параметрами сигнала и ордера.
 */
final class LevelGridConfig
{
    public const SETTING_KEY = 'level_grid';

    /**
     * @return array{
     *   enabled: bool,
     *   above: list<array{
     *     price: float|int,
     *     signal: bool,
     *     order: bool,
     *     size: string,
     *     reserve: float|int,
     *     stop: float|int,
     *     profit: float|int
     *   }>,
     *   below: list<array{
     *     price: float|int,
     *     signal: bool,
     *     order: bool,
     *     size: string,
     *     reserve: float|int,
     *     stop: float|int,
     *     profit: float|int
     *   }>
     * }
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'above' => [],
            'below' => [],
        ];
    }

    /**
     * @param mixed $raw
     * @return array{
     *   enabled: bool,
     *   above: list<array<string, mixed>>,
     *   below: list<array<string, mixed>>
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }

        $above = self::normalizeSide($raw['above'] ?? []);
        $below = self::normalizeSide($raw['below'] ?? []);
        // Сверху: от ближайшего к дальнему; снизу: от ближайшего к дальнему.
        usort($above, static fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        usort($below, static fn (array $a, array $b): int => $b['price'] <=> $a['price']);

        return [
            'enabled' => self::toBool($raw['enabled'] ?? true),
            'above' => $above,
            'below' => $below,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{
     *   enabled: bool,
     *   above: list<array<string, mixed>>,
     *   below: list<array<string, mixed>>
     * }
     */
    public static function fromPost(array $post): array
    {
        return self::normalize([
            'enabled' => isset($post['level_enabled']),
            'above' => self::normalizePostedSide($post['level_above'] ?? []),
            'below' => self::normalizePostedSide($post['level_below'] ?? []),
        ]);
    }

    /**
     * @param mixed $rows
     * @return list<array{
     *   price: float|int,
     *   signal: bool,
     *   order: bool,
     *   size: string,
     *   reserve: float|int,
     *   stop: float|int,
     *   profit: float|int
     * }>
     */
    private static function normalizeSide(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['price']) || !is_numeric($row['price'])) {
                continue;
            }
            $price = 0 + $row['price'];
            if ($price <= 0) {
                continue;
            }
            $out[] = [
                'price' => $price,
                'signal' => array_key_exists('signal', $row)
                    ? self::toBool($row['signal'])
                    : isset($row['signal']),
                'order' => array_key_exists('order', $row)
                    ? self::toBool($row['order'])
                    : isset($row['order']),
                'size' => self::toSize($row['size'] ?? '0.001'),
                'reserve' => is_numeric($row['reserve'] ?? null) ? 0 + $row['reserve'] : 10,
                'stop' => is_numeric($row['stop'] ?? null) ? 0 + $row['stop'] : 300,
                'profit' => is_numeric($row['profit'] ?? null) ? 0 + $row['profit'] : 300,
            ];
        }

        return $out;
    }

    /**
     * Нормализация из POST: чекбоксы приходят только если отмечены.
     *
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    public static function normalizePostedSide(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped[] = [
                'price' => $row['price'] ?? null,
                'signal' => isset($row['signal']),
                'order' => isset($row['order']),
                'size' => $row['size'] ?? '0.001',
                'reserve' => $row['reserve'] ?? 10,
                'stop' => $row['stop'] ?? 300,
                'profit' => $row['profit'] ?? 300,
            ];
        }

        return self::normalizeSide($mapped);
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

    private static function toSize(mixed $value): string
    {
        if (is_numeric($value)) {
            $number = (float) $value;
            $formatted = rtrim(rtrim(sprintf('%.8F', $number), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : '0.001';
    }

    public static function formatPriceKey(float|int $price): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8F', (float) $price), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
