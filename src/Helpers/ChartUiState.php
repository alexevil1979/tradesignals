<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Сохранение UI-состояния страницы «График» (таймфрейм) между вкладками админки.
 */
final class ChartUiState
{
    public const TF_COOKIE = 'tradesignals_chart_tf';
    public const TF_DEFAULT = 'M15';

    /** @param array<string, string> $intervals */
    public static function resolveTimeframe(array $intervals, ?string $fromGet = null): string
    {
        $fromGet = is_string($fromGet) ? $fromGet : null;
        if ($fromGet !== null && isset($intervals[$fromGet])) {
            return $fromGet;
        }

        $fromCookie = $_COOKIE[self::TF_COOKIE] ?? null;
        if (is_string($fromCookie) && isset($intervals[$fromCookie])) {
            return $fromCookie;
        }

        return isset($intervals[self::TF_DEFAULT]) ? self::TF_DEFAULT : (string) array_key_first($intervals);
    }

    public static function rememberTimeframe(string $tf): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]) === 'https');

        setcookie(self::TF_COOKIE, $tf, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::TF_COOKIE] = $tf;
    }

    /** @param array<string, string> $intervals */
    public static function chartHref(array $intervals): string
    {
        $tf = self::resolveTimeframe($intervals, null);

        return '/admin/chart.php?tf=' . rawurlencode($tf);
    }
}
