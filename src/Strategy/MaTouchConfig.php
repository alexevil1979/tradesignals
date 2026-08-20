<?php
declare(strict_types=1);

namespace App\Strategy;

/**
 * MA28: касание + слежение переходов close через MA.
 *
 * Переход сверху вниз → трек локального лоя → Buy.
 * Переход снизу вверх → трек локального хая → Sell.
 * После N баров, обновивших экстремум — лимит по close ± запас, TP/SL в $ от входа.
 */
final class MaTouchConfig
{
    public const SETTING_KEY = 'ma_touch';
    public const STATE_KEY = 'ma_touch_state';
    public const PERIOD = 28;

    public const TIMEFRAMES = ['M1', 'M5', 'M15', 'H1', 'H4', 'D1'];

    /**
     * @return array{
     *   timeframes: array<string, bool>,
     *   touch_enabled: bool,
     *   cross_down_enabled: bool,
     *   cross_up_enabled: bool,
     *   local_bars: int,
     *   buffer: float|int,
     *   tp_points: float|int,
     *   sl_points: float|int,
     *   order_size: string,
     *   test_mode: bool
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
            'touch_enabled' => true,
            'cross_down_enabled' => false,
            'cross_up_enabled' => false,
            'local_bars' => 3,
            'buffer' => 50,
            'tp_points' => 300,
            'sl_points' => 900,
            'order_size' => '0.001',
            'test_mode' => true,
        ];
    }

    /**
     * @return array<string, array{
     *   phase: string,
     *   direction: ?string,
     *   local_extreme: float|null,
     *   updates: int,
     *   signal_close: float|null,
     *   signal_candle: ?string,
     *   last_candle: ?string,
     *   prev_rel: ?string,
     *   order_link_id: ?string,
     *   side: ?string,
     *   entry: float|null,
     *   tp: float|null,
     *   sl: float|null,
     *   test_position_open: bool
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
     *   phase: string,
     *   direction: ?string,
     *   local_extreme: float|null,
     *   updates: int,
     *   signal_close: float|null,
     *   signal_candle: ?string,
     *   last_candle: ?string,
     *   prev_rel: ?string,
     *   order_link_id: ?string,
     *   side: ?string,
     *   entry: float|null,
     *   tp: float|null,
     *   sl: float|null,
     *   test_position_open: bool
     * }
     */
    public static function emptyTfState(): array
    {
        return [
            'phase' => 'idle',
            'direction' => null,
            'local_extreme' => null,
            'updates' => 0,
            'signal_close' => null,
            'signal_candle' => null,
            'last_candle' => null,
            'prev_rel' => null,
            'order_link_id' => null,
            'side' => null,
            'entry' => null,
            'tp' => null,
            'sl' => null,
            'test_position_open' => false,
        ];
    }

    /**
     * @param mixed $raw
     * @return array{
     *   timeframes: array<string, bool>,
     *   touch_enabled: bool,
     *   cross_down_enabled: bool,
     *   cross_up_enabled: bool,
     *   local_bars: int,
     *   buffer: float|int,
     *   tp_points: float|int,
     *   sl_points: float|int,
     *   order_size: string,
     *   test_mode: bool
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }

        // Обратная совместимость: старый формат только timeframes / плоский map.
        $src = $raw['timeframes'] ?? null;
        if ($src === null && self::looksLikeTimeframeMap($raw)) {
            $src = $raw;
        }
        if (!is_array($src)) {
            $src = $defaults['timeframes'];
        }

        $timeframes = $defaults['timeframes'];
        foreach (self::TIMEFRAMES as $tf) {
            if (array_key_exists($tf, $src)) {
                $timeframes[$tf] = self::toBool($src[$tf]);
            }
        }

        $localBars = isset($raw['local_bars']) && is_numeric($raw['local_bars'])
            ? (int) $raw['local_bars']
            : $defaults['local_bars'];
        $localBars = max(1, min(50, $localBars));

        return [
            'timeframes' => $timeframes,
            'touch_enabled' => array_key_exists('touch_enabled', $raw)
                ? self::toBool($raw['touch_enabled'])
                : true,
            'cross_down_enabled' => self::toBool($raw['cross_down_enabled'] ?? false),
            'cross_up_enabled' => self::toBool($raw['cross_up_enabled'] ?? false),
            'local_bars' => $localBars,
            'buffer' => isset($raw['buffer']) && is_numeric($raw['buffer'])
                ? max(0, 0 + $raw['buffer'])
                : $defaults['buffer'],
            'tp_points' => isset($raw['tp_points']) && is_numeric($raw['tp_points'])
                ? max(0.01, 0 + $raw['tp_points'])
                : $defaults['tp_points'],
            'sl_points' => isset($raw['sl_points']) && is_numeric($raw['sl_points'])
                ? max(0.01, 0 + $raw['sl_points'])
                : $defaults['sl_points'],
            'order_size' => self::toSize($raw['order_size'] ?? $defaults['order_size']),
            'test_mode' => self::toBool($raw['test_mode'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{
     *   timeframes: array<string, bool>,
     *   touch_enabled: bool,
     *   cross_down_enabled: bool,
     *   cross_up_enabled: bool,
     *   local_bars: int,
     *   buffer: float|int,
     *   tp_points: float|int,
     *   sl_points: float|int,
     *   order_size: string,
     *   test_mode: bool
     * }
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

        return self::normalize([
            'timeframes' => $timeframes,
            'touch_enabled' => isset($post['ma_touch_enabled']),
            'cross_down_enabled' => isset($post['ma_cross_down']),
            'cross_up_enabled' => isset($post['ma_cross_up']),
            'local_bars' => $post['ma_local_bars'] ?? 3,
            'buffer' => $post['ma_buffer'] ?? 50,
            'tp_points' => $post['ma_tp_points'] ?? 300,
            'sl_points' => $post['ma_sl_points'] ?? 900,
            'order_size' => $post['ma_order_size'] ?? '0.001',
            'test_mode' => isset($post['ma_test_mode']),
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
            $phase = (string) ($row['phase'] ?? 'idle');
            if (!in_array($phase, ['idle', 'tracking', 'ordered', 'wait_close'], true)) {
                $phase = 'idle';
            }
            $direction = $row['direction'] ?? null;
            if ($direction !== 'down' && $direction !== 'up') {
                $direction = null;
            }
            $prevRel = $row['prev_rel'] ?? null;
            if ($prevRel !== 'above' && $prevRel !== 'below') {
                $prevRel = null;
            }
            $side = $row['side'] ?? null;
            if ($side !== 'Buy' && $side !== 'Sell') {
                $side = null;
            }

            $out[$tf] = [
                'phase' => $phase,
                'direction' => $direction,
                'local_extreme' => isset($row['local_extreme']) && is_numeric($row['local_extreme'])
                    ? 0 + $row['local_extreme']
                    : null,
                'updates' => isset($row['updates']) && is_numeric($row['updates'])
                    ? max(0, (int) $row['updates'])
                    : 0,
                'signal_close' => isset($row['signal_close']) && is_numeric($row['signal_close'])
                    ? 0 + $row['signal_close']
                    : null,
                'signal_candle' => isset($row['signal_candle']) && is_string($row['signal_candle']) && $row['signal_candle'] !== ''
                    ? $row['signal_candle']
                    : null,
                'last_candle' => isset($row['last_candle']) && is_string($row['last_candle']) && $row['last_candle'] !== ''
                    ? $row['last_candle']
                    : null,
                'prev_rel' => $prevRel,
                'order_link_id' => isset($row['order_link_id']) && is_string($row['order_link_id']) && $row['order_link_id'] !== ''
                    ? $row['order_link_id']
                    : null,
                'side' => $side,
                'entry' => isset($row['entry']) && is_numeric($row['entry']) ? 0 + $row['entry'] : null,
                'tp' => isset($row['tp']) && is_numeric($row['tp']) ? 0 + $row['tp'] : null,
                'sl' => isset($row['sl']) && is_numeric($row['sl']) ? 0 + $row['sl'] : null,
                'test_position_open' => self::toBool($row['test_position_open'] ?? false),
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

    public static function formatPrice(float|int $price): string
    {
        $formatted = rtrim(rtrim(sprintf('%.8F', (float) $price), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /** @param array<string, mixed> $raw */
    private static function looksLikeTimeframeMap(array $raw): bool
    {
        foreach (array_keys($raw) as $key) {
            if (!is_string($key) || !in_array($key, self::TIMEFRAMES, true)) {
                return false;
            }
        }

        return $raw !== [];
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
