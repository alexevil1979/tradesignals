<?php
declare(strict_types=1);

namespace App\Strategy;

/**
 * Слежение за хаем/лоем: сетка из 3 лимитных уровней.
 */
final class DirectionGridConfig
{
    public const SETTING_KEY = 'direction_grid';
    public const STATE_KEY = 'direction_grid_state';

    /** @var list<int> */
    public const PERIODS_MINUTES = [15, 60, 240, 1440, 2880];

    /**
     * @return array{
     *   enabled: bool,
     *   mode: 'high'|'low',
     *   period_minutes: int,
     *   profit: float|int,
     *   stop: float|int,
     *   after_tp: 'rebuild'|'stop',
     *   levels: list<array{offset: float|int, size: string}>
     * }
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'mode' => 'high',
            'period_minutes' => 60,
            'profit' => 300,
            'stop' => 900,
            'after_tp' => 'rebuild',
            'levels' => [
                ['offset' => 300, 'size' => '0.001'],
                ['offset' => 600, 'size' => '0.001'],
                ['offset' => 900, 'size' => '0.001'],
            ],
        ];
    }

    /**
     * @return array{
     *   grid_id: ?string,
     *   anchor: float|null,
     *   tp: float|null,
     *   sl: float|null,
     *   filled_any: bool,
     *   stopped: bool,
     *   wait_close: bool,
     *   levels: list<array{index: int, link_id: string, status: string, price: float|null}>
     * }
     */
    public static function defaultState(): array
    {
        return [
            'grid_id' => null,
            'anchor' => null,
            'tp' => null,
            'sl' => null,
            'filled_any' => false,
            'stopped' => false,
            'wait_close' => false,
            'levels' => [],
        ];
    }

    /**
     * @param mixed $raw
     * @return array{
     *   enabled: bool,
     *   mode: 'high'|'low',
     *   period_minutes: int,
     *   profit: float|int,
     *   stop: float|int,
     *   after_tp: 'rebuild'|'stop',
     *   levels: list<array{offset: float|int, size: string}>
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }

        $mode = (string) ($raw['mode'] ?? 'high');
        if ($mode !== 'low') {
            $mode = 'high';
        }

        $period = isset($raw['period_minutes']) && is_numeric($raw['period_minutes'])
            ? (int) $raw['period_minutes']
            : $defaults['period_minutes'];
        if (!in_array($period, self::PERIODS_MINUTES, true)) {
            $period = $defaults['period_minutes'];
        }

        $afterTp = (string) ($raw['after_tp'] ?? 'rebuild');
        if ($afterTp !== 'stop') {
            $afterTp = 'rebuild';
        }

        $levels = [];
        $rawLevels = is_array($raw['levels'] ?? null) ? $raw['levels'] : $defaults['levels'];
        for ($i = 0; $i < 3; $i++) {
            $row = is_array($rawLevels[$i] ?? null) ? $rawLevels[$i] : $defaults['levels'][$i];
            $offset = isset($row['offset']) && is_numeric($row['offset']) ? 0 + $row['offset'] : $defaults['levels'][$i]['offset'];
            $levels[] = [
                'offset' => max(0.01, $offset),
                'size' => self::toSize($row['size'] ?? $defaults['levels'][$i]['size']),
            ];
        }

        return [
            'enabled' => self::toBool($raw['enabled'] ?? false),
            'mode' => $mode,
            'period_minutes' => $period,
            'profit' => isset($raw['profit']) && is_numeric($raw['profit']) ? max(0.01, 0 + $raw['profit']) : $defaults['profit'],
            'stop' => isset($raw['stop']) && is_numeric($raw['stop']) ? max(0.01, 0 + $raw['stop']) : $defaults['stop'],
            'after_tp' => $afterTp,
            'levels' => $levels,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{
     *   enabled: bool,
     *   mode: 'high'|'low',
     *   period_minutes: int,
     *   profit: float|int,
     *   stop: float|int,
     *   after_tp: 'rebuild'|'stop',
     *   levels: list<array{offset: float|int, size: string}>
     * }
     */
    public static function fromPost(array $post): array
    {
        $levels = [];
        $postedLevels = is_array($post['dg_level'] ?? null) ? $post['dg_level'] : [];
        for ($i = 0; $i < 3; $i++) {
            $row = is_array($postedLevels[$i] ?? null) ? $postedLevels[$i] : [];
            $levels[] = [
                'offset' => $row['offset'] ?? null,
                'size' => $row['size'] ?? '0.001',
            ];
        }

        return self::normalize([
            'enabled' => isset($post['dg_enabled']),
            'mode' => $post['dg_mode'] ?? 'high',
            'period_minutes' => $post['dg_period_minutes'] ?? 60,
            'profit' => $post['dg_profit'] ?? 300,
            'stop' => $post['dg_stop'] ?? 900,
            'after_tp' => $post['dg_after_tp'] ?? 'rebuild',
            'levels' => $levels,
        ]);
    }

    /**
     * @param mixed $raw
     * @return array{
     *   grid_id: ?string,
     *   anchor: float|null,
     *   tp: float|null,
     *   sl: float|null,
     *   filled_any: bool,
     *   stopped: bool,
     *   wait_close: bool,
     *   levels: list<array{index: int, link_id: string, status: string, price: float|null}>
     * }
     */
    public static function normalizeState(mixed $raw): array
    {
        $defaults = self::defaultState();
        if (!is_array($raw)) {
            return $defaults;
        }

        $levels = [];
        if (is_array($raw['levels'] ?? null)) {
            foreach ($raw['levels'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $levels[] = [
                    'index' => (int) ($row['index'] ?? 0),
                    'link_id' => (string) ($row['link_id'] ?? ''),
                    'status' => (string) ($row['status'] ?? 'New'),
                    'price' => isset($row['price']) && is_numeric($row['price']) ? 0 + $row['price'] : null,
                ];
            }
        }

        return [
            'grid_id' => isset($raw['grid_id']) && is_string($raw['grid_id']) && $raw['grid_id'] !== ''
                ? $raw['grid_id']
                : null,
            'anchor' => isset($raw['anchor']) && is_numeric($raw['anchor']) ? 0 + $raw['anchor'] : null,
            'tp' => isset($raw['tp']) && is_numeric($raw['tp']) ? 0 + $raw['tp'] : null,
            'sl' => isset($raw['sl']) && is_numeric($raw['sl']) ? 0 + $raw['sl'] : null,
            'filled_any' => self::toBool($raw['filled_any'] ?? false),
            'stopped' => self::toBool($raw['stopped'] ?? false),
            'wait_close' => self::toBool($raw['wait_close'] ?? false),
            'levels' => $levels,
        ];
    }

    public static function periodHours(int $periodMinutes): float
    {
        return max(0.25, $periodMinutes / 60);
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
}
