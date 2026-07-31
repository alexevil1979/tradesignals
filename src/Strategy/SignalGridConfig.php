<?php
declare(strict_types=1);

namespace App\Strategy;

/**
 * Матрица сигналов по таймфреймам (из «таблица для сигналов.xlsx»).
 */
final class SignalGridConfig
{
    public const SETTING_KEY = 'signal_grid';

    public const TIMEFRAMES = ['M1', 'M5', 'M15', 'H1', 'H4', 'D1'];

    public const MIN_BODY_NOTE = 'мин размер тела бара для того чтобы считать его в последовательности иначе последовательность обнуляется';

    /**
     * @return array{
     *   min_body: array<string, int|float>,
     *   timeframes: array<string, list<array{
     *     bars: int,
     *     signal: bool,
     *     order: bool,
     *     size: string,
     *     reserve: float|int,
     *     stop: float|int,
     *     profit: float|int
     *   }>>
     * }
     */
    public static function defaults(): array
    {
        $on = static fn (int $bars): array => [
            'bars' => $bars,
            'signal' => true,
            'order' => true,
            'size' => '0.001',
            'reserve' => 10,
            'stop' => 300,
            'profit' => 300,
        ];
        $off = static fn (int $bars): array => [
            'bars' => $bars,
            'signal' => false,
            'order' => false,
            'size' => '0.001',
            'reserve' => 10,
            'stop' => 300,
            'profit' => 300,
        ];

        return [
            'min_body' => [
                'M1' => 10,
                'M5' => 15,
                'M15' => 20,
                'H1' => 25,
                'H4' => 30,
                'D1' => 50,
            ],
            'timeframes' => [
                'M1' => [$on(3), $off(6), $on(7), $off(8), $on(9)],
                'M5' => [$on(2), $off(3), $on(5), $off(6), $on(7)],
                'M15' => [$on(3), $off(6), $on(7), $off(8), $on(9)],
                'H1' => [$on(2), $off(3), $on(5), $off(6), $on(7)],
                'H4' => [$on(3), $off(6), $on(7), $off(8), $on(9)],
                'D1' => [$on(2), $off(3), $on(5), $off(6), $on(7)],
            ],
        ];
    }

    /**
     * @param mixed $raw
     * @return array{
     *   min_body: array<string, int|float>,
     *   timeframes: array<string, list<array{
     *     bars: int,
     *     signal: bool,
     *     order: bool,
     *     size: string,
     *     reserve: float|int,
     *     stop: float|int,
     *     profit: float|int
     *   }>>
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $defaults = self::defaults();
        if (!is_array($raw)) {
            return $defaults;
        }

        $minBody = $defaults['min_body'];
        if (isset($raw['min_body']) && is_array($raw['min_body'])) {
            foreach (self::TIMEFRAMES as $tf) {
                if (array_key_exists($tf, $raw['min_body']) && is_numeric($raw['min_body'][$tf])) {
                    $minBody[$tf] = 0 + $raw['min_body'][$tf];
                }
            }
        }

        $timeframes = $defaults['timeframes'];
        if (isset($raw['timeframes']) && is_array($raw['timeframes'])) {
            foreach (self::TIMEFRAMES as $tf) {
                if (!isset($raw['timeframes'][$tf]) || !is_array($raw['timeframes'][$tf])) {
                    continue;
                }
                $rows = [];
                foreach ($raw['timeframes'][$tf] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rows[] = [
                        'bars' => max(1, (int) ($row['bars'] ?? 1)),
                        'signal' => self::toBool($row['signal'] ?? false),
                        'order' => self::toBool($row['order'] ?? false),
                        'size' => self::toSize($row['size'] ?? '0.001'),
                        'reserve' => 0 + ($row['reserve'] ?? 10),
                        'stop' => 0 + ($row['stop'] ?? 300),
                        'profit' => 0 + ($row['profit'] ?? 300),
                    ];
                }
                if ($rows !== []) {
                    $timeframes[$tf] = $rows;
                }
            }
        }

        return [
            'min_body' => $minBody,
            'timeframes' => $timeframes,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{
     *   min_body: array<string, int|float>,
     *   timeframes: array<string, list<array{
     *     bars: int,
     *     signal: bool,
     *     order: bool,
     *     size: string,
     *     reserve: float|int,
     *     stop: float|int,
     *     profit: float|int
     *   }>>
     * }
     */
    public static function fromPost(array $post): array
    {
        $minBody = [];
        foreach (self::TIMEFRAMES as $tf) {
            $minBody[$tf] = isset($post['min_body'][$tf]) && is_numeric($post['min_body'][$tf])
                ? 0 + $post['min_body'][$tf]
                : self::defaults()['min_body'][$tf];
        }

        $timeframes = [];
        foreach (self::TIMEFRAMES as $tf) {
            $postedRows = $post['tf'][$tf] ?? [];
            if (!is_array($postedRows)) {
                $postedRows = [];
            }
            $rows = [];
            foreach ($postedRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rows[] = [
                    'bars' => max(1, (int) ($row['bars'] ?? 1)),
                    'signal' => isset($row['signal']),
                    'order' => isset($row['order']),
                    'size' => self::toSize($row['size'] ?? '0.001'),
                    'reserve' => is_numeric($row['reserve'] ?? null) ? 0 + $row['reserve'] : 10,
                    'stop' => is_numeric($row['stop'] ?? null) ? 0 + $row['stop'] : 300,
                    'profit' => is_numeric($row['profit'] ?? null) ? 0 + $row['profit'] : 300,
                ];
            }
            $timeframes[$tf] = $rows !== [] ? $rows : self::defaults()['timeframes'][$tf];
        }

        return self::normalize([
            'min_body' => $minBody,
            'timeframes' => $timeframes,
        ]);
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
