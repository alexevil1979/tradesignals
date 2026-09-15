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
     *   test_mode: bool,
     *   sound_l1: bool,
     *   chart_h1: bool,
     *   mode: 'high'|'low',
     *   period_minutes: int,
     *   profit: float|int,
     *   stop: float|int,
     *   after_tp: 'rebuild'|'stop',
     *   levels: list<array{offset: float|int, size: string, sound: bool, telegram: bool}>
     * }
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'test_mode' => true,
            'sound_l1' => false,
            'chart_h1' => false,
            'mode' => 'high',
            'period_minutes' => 60,
            'profit' => 300,
            'stop' => 900,
            'after_tp' => 'rebuild',
            'levels' => [
                ['offset' => 300, 'size' => '0.001', 'sound' => false, 'telegram' => true],
                ['offset' => 600, 'size' => '0.001', 'sound' => false, 'telegram' => true],
                ['offset' => 900, 'size' => '0.001', 'sound' => false, 'telegram' => true],
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
     *   force_rebuild: bool,
     *   settings_sig: ?string,
     *   test_position: array{open: bool, side: ?string, entry: float|null}|null,
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
            'force_rebuild' => false,
            'settings_sig' => null,
            'test_position' => null,
            'levels' => [],
        ];
    }

    /**
     * Подпись параметров сетки: при смене — перестановка.
     *
     * @param array<string, mixed> $config
     */
    public static function signature(array $config): string
    {
        $levels = [];
        foreach (is_array($config['levels'] ?? null) ? $config['levels'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $levels[] = [
                'offset' => isset($row['offset']) && is_numeric($row['offset']) ? 0 + $row['offset'] : 0,
                'size' => (string) ($row['size'] ?? ''),
            ];
        }

        return hash('sha256', json_encode([
            'test_mode' => !empty($config['test_mode']),
            'mode' => (string) ($config['mode'] ?? 'high'),
            'period_minutes' => (int) ($config['period_minutes'] ?? 0),
            'profit' => isset($config['profit']) && is_numeric($config['profit']) ? 0 + $config['profit'] : 0,
            'stop' => isset($config['stop']) && is_numeric($config['stop']) ? 0 + $config['stop'] : 0,
            'after_tp' => (string) ($config['after_tp'] ?? 'rebuild'),
            'levels' => $levels,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param mixed $raw
     * @return array{
     *   enabled: bool,
     *   test_mode: bool,
     *   sound_l1: bool,
     *   chart_h1: bool,
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
        $legacySoundL1 = self::toBool($raw['sound_l1'] ?? false);
        for ($i = 0; $i < 3; $i++) {
            $row = is_array($rawLevels[$i] ?? null) ? $rawLevels[$i] : $defaults['levels'][$i];
            $offset = isset($row['offset']) && is_numeric($row['offset']) ? 0 + $row['offset'] : $defaults['levels'][$i]['offset'];
            $soundDefault = $i === 0 && $legacySoundL1;
            $levels[] = [
                'offset' => max(0.01, $offset),
                'size' => self::toSize($row['size'] ?? $defaults['levels'][$i]['size']),
                'sound' => array_key_exists('sound', $row)
                    ? self::toBool($row['sound'])
                    : $soundDefault,
                'telegram' => array_key_exists('telegram', $row)
                    ? self::toBool($row['telegram'])
                    : true,
            ];
        }

        return [
            'enabled' => self::toBool($raw['enabled'] ?? false),
            'test_mode' => self::toBool($raw['test_mode'] ?? false),
            'sound_l1' => !empty($levels[0]['sound']),
            'chart_h1' => self::toBool($raw['chart_h1'] ?? false),
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
     *   test_mode: bool,
     *   sound_l1: bool,
     *   chart_h1: bool,
     *   mode: 'high'|'low',
     *   period_minutes: int,
     *   profit: float|int,
     *   stop: float|int,
     *   after_tp: 'rebuild'|'stop',
     *   levels: list<array{offset: float|int, size: string, sound: bool, telegram: bool}>
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
                'sound' => isset($row['sound']),
                'telegram' => isset($row['telegram']),
            ];
        }

        return self::normalize([
            'enabled' => isset($post['dg_enabled']),
            'test_mode' => isset($post['dg_test_mode']),
            'chart_h1' => isset($post['dg_chart_h1']),
            'mode' => $post['dg_mode'] ?? 'high',
            'period_minutes' => $post['dg_period_minutes'] ?? 60,
            'profit' => $post['dg_profit'] ?? 300,
            'stop' => $post['dg_stop'] ?? 900,
            'after_tp' => $post['dg_after_tp'] ?? 'rebuild',
            'levels' => $levels,
        ]);
    }

    /** Уровень с включённым Telegram (0-based). */
    public static function levelTelegramEnabled(array $config, int $index0): bool
    {
        return !empty($config['levels'][$index0]['telegram']);
    }

    /** Уровень с включённым звуком (0-based). */
    public static function levelSoundEnabled(array $config, int $index0): bool
    {
        return !empty($config['levels'][$index0]['sound']);
    }

    /**
     * Уровни для H1 / звука: всегда от текущих отступов в настройках.
     * Якорём берём свежий экстремум (как превью в Стратегиях), иначе anchor из state.
     * TP/SL из state только пока ждём закрытие позиции; иначе — из текущих profit/stop.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     * @param array{low: float, high: float}|null $extremum
     * @return array{
     *   show_h1: bool,
     *   mode: string,
     *   anchor: float|null,
     *   levels: list<array{index: int, title: string, price: float}>,
     *   tp: float|null,
     *   sl: float|null
     * }
     */
    public static function chartOverlay(array $config, array $state, ?array $extremum): array
    {
        $mode = (string) ($config['mode'] ?? 'high');
        if ($mode !== 'low') {
            $mode = 'high';
        }

        $anchor = null;
        if ($extremum !== null) {
            $anchor = $mode === 'low' ? (float) $extremum['low'] : (float) $extremum['high'];
        } elseif (isset($state['anchor']) && is_numeric($state['anchor'])) {
            $anchor = (float) $state['anchor'];
        }

        $levels = [];
        if ($anchor !== null) {
            for ($i = 0; $i < 3; $i++) {
                $offset = (float) ($config['levels'][$i]['offset'] ?? 0);
                $levels[] = [
                    'index' => $i,
                    'title' => 'L' . ($i + 1),
                    'price' => $mode === 'low' ? $anchor + $offset : $anchor - $offset,
                ];
            }
        }

        $waitingClose = !empty($state['wait_close']) || !empty($state['filled_any']);
        $tp = null;
        $sl = null;
        if ($waitingClose) {
            $tp = isset($state['tp']) && is_numeric($state['tp']) ? (float) $state['tp'] : null;
            $sl = isset($state['sl']) && is_numeric($state['sl']) ? (float) $state['sl'] : null;
        }
        if ($anchor !== null) {
            if ($tp === null) {
                $tp = $mode === 'low'
                    ? $anchor - (float) $config['profit']
                    : $anchor + (float) $config['profit'];
            }
            if ($sl === null) {
                $sl = $mode === 'low'
                    ? $anchor + (float) $config['stop']
                    : $anchor - (float) $config['stop'];
            }
        }

        return [
            'show_h1' => !empty($config['chart_h1']),
            'mode' => $mode,
            'anchor' => $anchor,
            'levels' => $levels,
            'tp' => $tp,
            'sl' => $sl,
        ];
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
     *   force_rebuild: bool,
     *   settings_sig: ?string,
     *   test_position: array{open: bool, side: ?string, entry: float|null}|null,
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

        $testPosition = null;
        if (isset($raw['test_position']) && is_array($raw['test_position'])) {
            $tp = $raw['test_position'];
            $testPosition = [
                'open' => self::toBool($tp['open'] ?? false),
                'side' => isset($tp['side']) && is_string($tp['side']) ? $tp['side'] : null,
                'entry' => isset($tp['entry']) && is_numeric($tp['entry']) ? 0 + $tp['entry'] : null,
            ];
        }

        $settingsSig = $raw['settings_sig'] ?? null;

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
            'force_rebuild' => self::toBool($raw['force_rebuild'] ?? false),
            'settings_sig' => is_string($settingsSig) && $settingsSig !== '' ? $settingsSig : null,
            'test_position' => $testPosition,
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
