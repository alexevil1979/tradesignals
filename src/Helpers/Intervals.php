<?php
declare(strict_types=1);

namespace App\Helpers;

final class Intervals
{
    /** @return array<string, string> label => Bybit interval code */
    public static function chartMap(): array
    {
        return [
            'M1' => '1',
            'M5' => '5',
            'M15' => '15',
            'H1' => '60',
            'H4' => '240',
            'D1' => 'D',
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_values(self::chartMap());
    }
}
