<?php
/**
 * Format datetime dari DB (diasumsikan UTC / timezone-less)
 * ke format: dd/mm/yy (hh:mm) — WIB
 *
 * Contoh output:
 * 29/12/25 (11:55)
 */
if (!function_exists('format_dt_short_id')) {
    function format_dt_short_id(?string $mysqlDt): string
    {
        if (empty($mysqlDt)) return '-';

        try {
            // parse sebagai UTC (best practice untuk DB)
            $dt = new DateTime($mysqlDt, new DateTimeZone('UTC'));
            // konversi ke WIB
            $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        } catch (Throwable $e) {
            return $mysqlDt; // fallback aman
        }

        return $dt->format('d/m/y (H:i)');
    }
}
