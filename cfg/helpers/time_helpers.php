<?php
// menambahkan fungsi waktu

if (!function_exists('format_datetime_id')) {
    /**
     * Format MySQL datetime ke format Indonesia yang rapi.
     * contoh: "4 November 2025 14:30" atau jika $withDay true => "Selasa, 4 November 2025 14:30"
     */
    function format_datetime_id(?string $mysqlDt, bool $withDay = false, bool $withTime = true): string {
        if (empty($mysqlDt)) return '';
        try {
            $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
        } catch (Exception $e) {
            return $mysqlDt;
        }

        // Gunakan IntlDateFormatter jika tersedia (lebih andal)
        if (class_exists('IntlDateFormatter')) {
            $pattern = $withDay ? "EEEE, d MMMM yyyy" : "d MMMM yyyy";
            if ($withTime) $pattern .= " HH:mm";
            $fmt = new IntlDateFormatter('id_ID', IntlDateFormatter::FULL, IntlDateFormatter::SHORT, 'Asia/Jakarta', IntlDateFormatter::GREGORIAN, $pattern);
            return $fmt->format($d);
        }

        // fallback: gunakan strftime (tergantung setlocale)
        $format = $withDay ? '%A, %e %B %Y' : '%e %B %Y';
        if ($withTime) $format .= ' %H:%M';
        $str = strftime($format, $d->getTimestamp());
        return trim($str);
    }
}

if (!function_exists('format_datetime_indo')) {
    /**
     * Convert a DB datetime (assumed UTC or timezone-less) into Jakarta time
     * and format like: "Senin, 10 November 2025. 10:19 WIB"
     */
    function format_datetime_indo(?string $mysqlDt, bool $withDay = true, bool $withTime = true): string {
        if (empty($mysqlDt)) return '-';
        try {
            // first parse as UTC to avoid misinterpreting DB-stored UTC timestamps
            $d = new DateTime($mysqlDt, new DateTimeZone('UTC'));
            // convert to Jakarta time
            $d->setTimezone(new DateTimeZone('Asia/Jakarta'));
        } catch (Exception $e) {
            return $mysqlDt;
        }

        $days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        $months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

        $dayName = $days[(int)$d->format('w')];
        $date = (int)$d->format('j');
        $monthName = $months[(int)$d->format('n')];
        $year = $d->format('Y');
        $time = $d->format('H:i');

        if ($withDay && $withTime) return "$dayName, $date $monthName $year. $time WIB";
        if ($withDay) return "$dayName, $date $monthName $year";
        if ($withTime) return "$date $monthName $year. $time WIB";
        return "$date $monthName $year";
    }
}
if (!function_exists('format_date_ddmmyyyy')) {
    /**
     * Format MySQL datetime ke format dd-mm-yyyy (tanpa jam).
     * Contoh: "10-11-2025"
     */
    function format_date_ddmmyyyy(?string $mysqlDt): string {
        if (empty($mysqlDt)) return '';
        try {
            $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
        } catch (Exception $e) {
            return $mysqlDt;
        }
        return $d->format('d-m-Y');
    }
}
if (!function_exists('format_date_ddmmyyyy_time')) {
    /**
     * Format MySQL datetime ke format dd-mm-yyyy HH:MM (dengan jam).
     * Contoh: "10-11-2025 14:30"
     */
    function format_date_ddmmyyyy_time(?string $mysqlDt): string {
        if (empty($mysqlDt)) return '';
        try {
            $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
        } catch (Exception $e) {
            return $mysqlDt;
        }
        return $d->format('d-m-Y H:i');
    }
}
if (!function_exists('format_date_ddmmyyyy_time_bracket')) {
    /**
     * Format MySQL datetime ke format dd/mm/yyyy (HH:MM)
     * Contoh: "10/11/2025 (14:30)"
     */
    function format_date_ddmmyyyy_time_bracket(?string $mysqlDt): string {
        if (empty($mysqlDt)) return '';
        try {
            $d = new DateTime($mysqlDt, new DateTimeZone('Asia/Jakarta'));
        } catch (Exception $e) {
            return $mysqlDt;
        }
        return $d->format('d/m/Y') . ' (' . $d->format('H:i') . ')';
    }
}

