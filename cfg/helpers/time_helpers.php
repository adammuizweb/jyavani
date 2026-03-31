<?php
declare(strict_types=1);

// helper tanggal/waktu Indonesia yang aman untuk PHP 8.5
// - tanpa strftime()
// - pakai IntlDateFormatter jika tersedia
// - fallback manual jika intl tidak aktif

if (!function_exists('dtid_jakarta_tz')) {
    function dtid_jakarta_tz(): DateTimeZone
    {
        return new DateTimeZone('Asia/Jakarta');
    }
}

if (!function_exists('dtid_is_empty_mysql_datetime')) {
    function dtid_is_empty_mysql_datetime(?string $mysqlDt): bool
    {
        $v = trim((string)$mysqlDt);
        return $v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00';
    }
}

if (!function_exists('dtid_parse_local')) {
    /**
     * Parse datetime yang diasumsikan sudah waktu lokal Jakarta.
     */
    function dtid_parse_local(?string $mysqlDt): ?DateTimeImmutable
    {
        if (dtid_is_empty_mysql_datetime($mysqlDt)) {
            return null;
        }

        try {
            return new DateTimeImmutable((string)$mysqlDt, dtid_jakarta_tz());
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('dtid_parse_utc_to_jakarta')) {
    /**
     * Parse datetime yang diasumsikan UTC lalu dikonversi ke Jakarta.
     */
    function dtid_parse_utc_to_jakarta(?string $mysqlDt): ?DateTimeImmutable
    {
        if (dtid_is_empty_mysql_datetime($mysqlDt)) {
            return null;
        }

        try {
            $d = new DateTimeImmutable((string)$mysqlDt, new DateTimeZone('UTC'));
            return $d->setTimezone(dtid_jakarta_tz());
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('dtid_months')) {
    function dtid_months(): array
    {
        return [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];
    }
}

if (!function_exists('dtid_days')) {
    function dtid_days(): array
    {
        return [
            0 => 'Minggu',
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
        ];
    }
}

if (!function_exists('dtid_format_with_intl')) {
    function dtid_format_with_intl(DateTimeInterface $d, string $pattern): ?string
    {
        if (!class_exists('IntlDateFormatter')) {
            return null;
        }

        $fmt = IntlDateFormatter::create(
            'id_ID',
            IntlDateFormatter::FULL,
            IntlDateFormatter::FULL,
            'Asia/Jakarta',
            IntlDateFormatter::GREGORIAN,
            $pattern
        );

        if (!$fmt) {
            return null;
        }

        $out = $fmt->format($d);
        if ($out === false) {
            return null;
        }

        return trim((string)$out);
    }
}

if (!function_exists('dtid_format_manual')) {
    function dtid_format_manual(DateTimeInterface $d, bool $withDay = false, bool $withTime = true, bool $useWib = false): string
    {
        $days = dtid_days();
        $months = dtid_months();

        $dayName = $days[(int)$d->format('w')];
        $date = (int)$d->format('j');
        $monthName = $months[(int)$d->format('n')];
        $year = $d->format('Y');
        $time = $d->format('H:i');

        $result = $withDay
            ? "{$dayName}, {$date} {$monthName} {$year}"
            : "{$date} {$monthName} {$year}";

        if ($withTime) {
            $result .= $useWib ? ". {$time} WIB" : " {$time}";
        }

        return $result;
    }
}

if (!function_exists('format_datetime_id')) {
    /**
     * Format MySQL datetime ke format Indonesia yang rapi.
     * contoh:
     * - "4 November 2025 14:30"
     * - dengan $withDay=true => "Selasa, 4 November 2025 14:30"
     *
     * Asumsi input sudah merupakan waktu lokal Jakarta.
     */
    function format_datetime_id(?string $mysqlDt, bool $withDay = false, bool $withTime = true): string
    {
        $d = dtid_parse_local($mysqlDt);
        if (!$d) {
            return dtid_is_empty_mysql_datetime($mysqlDt) ? '' : (string)$mysqlDt;
        }

        $pattern = $withDay ? 'EEEE, d MMMM yyyy' : 'd MMMM yyyy';
        if ($withTime) {
            $pattern .= ' HH:mm';
        }

        $intl = dtid_format_with_intl($d, $pattern);
        if ($intl !== null) {
            return $intl;
        }

        return dtid_format_manual($d, $withDay, $withTime, false);
    }
}

if (!function_exists('format_datetime_indo')) {
    /**
     * Convert a DB datetime (assumed UTC or timezone-less UTC) into Jakarta time
     * and format like:
     * - "Senin, 10 November 2025. 10:19 WIB"
     */
    function format_datetime_indo(?string $mysqlDt, bool $withDay = true, bool $withTime = true): string
    {
        $d = dtid_parse_utc_to_jakarta($mysqlDt);
        if (!$d) {
            return dtid_is_empty_mysql_datetime($mysqlDt) ? '-' : (string)$mysqlDt;
        }

        $pattern = $withDay ? 'EEEE, d MMMM yyyy' : 'd MMMM yyyy';
        if ($withTime) {
            $pattern .= ". HH:mm 'WIB'";
        }

        $intl = dtid_format_with_intl($d, $pattern);
        if ($intl !== null) {
            return $intl;
        }

        return dtid_format_manual($d, $withDay, $withTime, true);
    }
}

if (!function_exists('format_date_ddmmyyyy')) {
    /**
     * Format MySQL datetime ke format dd-mm-yyyy (tanpa jam).
     * Contoh: "10-11-2025"
     *
     * Asumsi input sudah merupakan waktu lokal Jakarta.
     */
    function format_date_ddmmyyyy(?string $mysqlDt): string
    {
        $d = dtid_parse_local($mysqlDt);
        if (!$d) {
            return dtid_is_empty_mysql_datetime($mysqlDt) ? '' : (string)$mysqlDt;
        }

        return $d->format('d-m-Y');
    }
}

if (!function_exists('format_date_ddmmyyyy_time')) {
    /**
     * Format MySQL datetime ke format dd-mm-yyyy HH:mm
     * Contoh: "10-11-2025 14:30"
     *
     * Asumsi input sudah merupakan waktu lokal Jakarta.
     */
    function format_date_ddmmyyyy_time(?string $mysqlDt): string
    {
        $d = dtid_parse_local($mysqlDt);
        if (!$d) {
            return dtid_is_empty_mysql_datetime($mysqlDt) ? '' : (string)$mysqlDt;
        }

        return $d->format('d-m-Y H:i');
    }
}

if (!function_exists('format_date_ddmmyyyy_time_bracket')) {
    /**
     * Format MySQL datetime ke format dd/mm/yyyy (HH:mm)
     * Contoh: "10/11/2025 (14:30)"
     *
     * Asumsi input sudah merupakan waktu lokal Jakarta.
     */
    function format_date_ddmmyyyy_time_bracket(?string $mysqlDt): string
    {
        $d = dtid_parse_local($mysqlDt);
        if (!$d) {
            return dtid_is_empty_mysql_datetime($mysqlDt) ? '' : (string)$mysqlDt;
        }

        return $d->format('d/m/Y') . ' (' . $d->format('H:i') . ')';
    }
}