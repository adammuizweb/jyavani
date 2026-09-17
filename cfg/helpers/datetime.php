<?php
/**
 * Format an explicitly UTC database datetime in the configured site timezone.
 *
 * Contoh output:
 * 29/12/25 (11:55)
 */
if (!function_exists('format_dt_short_id')) {
    function format_dt_short_id(?string $mysqlDt): string
    {
        if (empty($mysqlDt)) return '-';

        try {
            $dt = function_exists('app_utc_mysql_to_site')
                ? app_utc_mysql_to_site($mysqlDt)
                : new DateTime($mysqlDt, new DateTimeZone('UTC'));
            if (!$dt) return '-';
            if (!function_exists('app_utc_mysql_to_site')) $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Throwable $e) {
            return $mysqlDt; // fallback aman
        }

        return $dt->format('d/m/y (H:i)');
    }
}
