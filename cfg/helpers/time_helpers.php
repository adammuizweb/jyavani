<?php
declare(strict_types=1);

if (!function_exists('app_timezone_default_id')) {
    function app_timezone_default_id(): string
    {
        return 'Asia/Jakarta';
    }
}

if (!function_exists('app_timezone_identifiers')) {
    function app_timezone_identifiers(): array
    {
        static $identifiers = null;
        if ($identifiers === null) {
            $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
            if (!in_array('UTC', $identifiers, true)) array_unshift($identifiers, 'UTC');
        }
        return $identifiers;
    }
}

if (!function_exists('app_timezone_is_valid')) {
    function app_timezone_is_valid(string $timezoneId): bool
    {
        $timezoneId = trim($timezoneId);
        return $timezoneId !== '' && in_array($timezoneId, app_timezone_identifiers(), true);
    }
}

if (!function_exists('app_timezone_id')) {
    function app_timezone_id(): string
    {
        $configured = $GLOBALS['__APP_TIMEZONE_ID'] ?? null;
        return is_string($configured) && app_timezone_is_valid($configured)
            ? $configured
            : app_timezone_default_id();
    }
}

if (!function_exists('app_timezone')) {
    function app_timezone(): DateTimeZone
    {
        static $cache = [];
        $timezoneId = app_timezone_id();
        return $cache[$timezoneId] ??= new DateTimeZone($timezoneId);
    }
}

if (!function_exists('app_utc_timezone')) {
    function app_utc_timezone(): DateTimeZone
    {
        static $utc = null;
        return $utc ??= new DateTimeZone('UTC');
    }
}

if (!function_exists('app_now')) {
    function app_now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', app_timezone());
    }
}

if (!function_exists('app_now_utc')) {
    function app_now_utc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', app_utc_timezone());
    }
}

if (!function_exists('app_mysql_datetime')) {
    function app_mysql_datetime(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}

if (!function_exists('app_now_wall_mysql')) {
    function app_now_wall_mysql(): string
    {
        return app_mysql_datetime(app_now());
    }
}

if (!function_exists('app_now_utc_mysql')) {
    function app_now_utc_mysql(): string
    {
        return app_mysql_datetime(app_now_utc());
    }
}

if (!function_exists('app_parse_exact_datetime')) {
    function app_parse_exact_datetime(?string $value, string $format, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return null;
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
        return $date->format($format) === $value ? $date : null;
    }
}

if (!function_exists('app_parse_wall_mysql')) {
    function app_parse_wall_mysql(?string $value): ?DateTimeImmutable
    {
        return app_parse_exact_datetime($value, 'Y-m-d H:i:s', app_timezone());
    }
}

if (!function_exists('app_parse_utc_mysql')) {
    function app_parse_utc_mysql(?string $value): ?DateTimeImmutable
    {
        return app_parse_exact_datetime($value, 'Y-m-d H:i:s', app_utc_timezone());
    }
}

if (!function_exists('app_parse_site_datetime_local')) {
    function app_parse_site_datetime_local(?string $value): ?DateTimeImmutable
    {
        return app_parse_exact_datetime($value, 'Y-m-d\\TH:i', app_timezone());
    }
}

if (!function_exists('app_wall_mysql_to_datetime_local')) {
    function app_wall_mysql_to_datetime_local(?string $value): ?string
    {
        $date = app_parse_wall_mysql($value);
        return $date?->format('Y-m-d\\TH:i');
    }
}

if (!function_exists('app_utc_mysql_to_site')) {
    function app_utc_mysql_to_site(?string $value): ?DateTimeImmutable
    {
        return app_parse_utc_mysql($value)?->setTimezone(app_timezone());
    }
}

if (!function_exists('app_site_datetime_to_utc_mysql')) {
    function app_site_datetime_to_utc_mysql(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(app_utc_timezone())->format('Y-m-d H:i:s');
    }
}

if (!function_exists('app_db_set_session_timezone')) {
    function app_db_set_session_timezone(PDO $pdo, string $timezoneId): string
    {
        if (!app_timezone_is_valid($timezoneId)) throw new InvalidArgumentException('Invalid site timezone.');
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver !== 'mysql') return $timezoneId;

        $apply = static function (string $value) use ($pdo): void {
            $pdo->exec('SET SESSION time_zone = ' . $pdo->quote($value));
        };
        try {
            $apply($timezoneId);
            $applied = $timezoneId;
        } catch (PDOException) {
            $applied = (new DateTimeImmutable('now', new DateTimeZone($timezoneId)))->format('P');
            $apply($applied);
        }
        $actual = (string)$pdo->query('SELECT @@SESSION.time_zone')->fetchColumn();
        if ($actual !== $applied) throw new RuntimeException('Database session timezone verification failed.');
        return $applied;
    }
}

if (!function_exists('app_time_bootstrap')) {
    function app_time_bootstrap(PDO $pdo): void
    {
        $timezoneId = app_timezone_default_id();
        try {
            $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = 'site_timezone' LIMIT 1");
            $stmt->execute();
            $stored = $stmt->fetchColumn();
            if (is_string($stored) && trim($stored) !== '') $timezoneId = trim($stored);
        } catch (PDOException $error) {
            $driverCode = (int)($error->errorInfo[1] ?? 0);
            if ($driverCode !== 1146 && $error->getCode() !== '42S02') throw $error;
        }
        if (!app_timezone_is_valid($timezoneId)) {
            error_log('[time] Invalid site_timezone setting; using ' . app_timezone_default_id() . '.');
            $timezoneId = app_timezone_default_id();
        }
        $GLOBALS['__APP_TIMEZONE_ID'] = $timezoneId;
        date_default_timezone_set($timezoneId);
        $GLOBALS['__APP_DB_TIMEZONE'] = app_db_set_session_timezone($pdo, $timezoneId);
    }
}

if (!function_exists('app_date_format_default')) {
    function app_date_format_default(): string
    {
        return 'F j, Y';
    }
}

if (!function_exists('app_time_format_default')) {
    function app_time_format_default(): string
    {
        return 'H:i';
    }
}

if (!function_exists('app_date_format_presets')) {
    function app_date_format_presets(): array
    {
        return ['F j, Y', 'Y-m-d', 'm/d/Y', 'd/m/Y', 'd.m.Y'];
    }
}

if (!function_exists('app_time_format_presets')) {
    function app_time_format_presets(): array
    {
        return ['g:i a', 'g:i A', 'H:i'];
    }
}

if (!function_exists('app_display_format_is_valid')) {
    function app_display_format_is_valid(string $format, string $kind): bool
    {
        if ($format === '' || strlen($format) > 64 || preg_match('/[^\x20-\x7E]/', $format)) return false;
        $tokens = $kind === 'date' ? 'djmnFMYylD' : ($kind === 'time' ? 'HGhgisaATP' : '');
        if ($tokens === '') return false;
        $hasToken = false;
        $separators = " -.,/:()";
        foreach (str_split($format) as $character) {
            if (str_contains($tokens, $character)) {
                $hasToken = true;
                continue;
            }
            if (!str_contains($separators, $character)) return false;
        }
        return $hasToken;
    }
}

if (!function_exists('app_display_format_validation_error')) {
    function app_display_format_validation_error(string $format, string $kind): ?string
    {
        if (app_display_format_is_valid($format, $kind)) return null;
        return $kind === 'date' ? 'Invalid date format.' : 'Invalid time format.';
    }
}

if (!function_exists('app_display_format_setting')) {
    function app_display_format_setting(string $key): string
    {
        $isDate = $key === 'date_format';
        $default = $isDate ? app_date_format_default() : app_time_format_default();
        $pdo = $GLOBALS['pdo'] ?? null;
        $value = function_exists('settings_get') && $pdo instanceof PDO
            ? (string)(settings_get($pdo, $key, $default) ?? $default)
            : $default;
        return app_display_format_is_valid($value, $isDate ? 'date' : 'time') ? $value : $default;
    }
}

if (!function_exists('app_date_format')) {
    function app_date_format(): string
    {
        return app_display_format_setting('date_format');
    }
}

if (!function_exists('app_time_format')) {
    function app_time_format(): string
    {
        return app_display_format_setting('time_format');
    }
}

if (!function_exists('app_display_format')) {
    function app_display_format(DateTimeInterface $value, string $format): string
    {
        static $formatters = [];
        $date = DateTimeImmutable::createFromInterface($value)->setTimezone(app_timezone());
        $locale = function_exists('get_locale') ? get_locale() : 'en';
        $localizedPatterns = [
            'F' => 'MMMM',
            'M' => 'MMM',
            'l' => 'EEEE',
            'D' => 'EEE',
            'a' => 'a',
            'A' => 'a',
        ];
        $output = '';
        foreach (str_split($format) as $character) {
            if (!isset($localizedPatterns[$character]) || !class_exists('IntlDateFormatter')) {
                $output .= str_contains('djmnFMYylDHGhgisaATP', $character)
                    ? $date->format($character)
                    : $character;
                continue;
            }
            $formatterKey = $locale . "\0" . app_timezone_id() . "\0" . $localizedPatterns[$character];
            if (!array_key_exists($formatterKey, $formatters)) {
                $formatters[$formatterKey] = IntlDateFormatter::create(
                    $locale,
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    app_timezone_id(),
                    IntlDateFormatter::GREGORIAN,
                    $localizedPatterns[$character]
                );
            }
            $formatter = $formatters[$formatterKey];
            $part = $formatter ? $formatter->format($date) : false;
            $part = $part === false ? $date->format($character) : (string)$part;
            if ($character === 'a') $part = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
            if ($character === 'A') $part = function_exists('mb_strtoupper') ? mb_strtoupper($part, 'UTF-8') : strtoupper($part);
            $output .= $part;
        }
        return $output;
    }
}

if (!function_exists('app_display_wall_value')) {
    function app_display_wall_value(DateTimeInterface|string|null $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(app_timezone());
        }
        return app_parse_wall_mysql($value);
    }
}

if (!function_exists('app_display_date')) {
    function app_display_date(DateTimeInterface|string|null $value, ?string $format = null): string
    {
        $date = app_display_wall_value($value);
        return $date ? app_display_format($date, $format ?? app_date_format()) : '';
    }
}

if (!function_exists('app_display_time')) {
    function app_display_time(DateTimeInterface|string|null $value, ?string $format = null): string
    {
        $date = app_display_wall_value($value);
        return $date ? app_display_format($date, $format ?? app_time_format()) : '';
    }
}

if (!function_exists('app_display_datetime')) {
    function app_display_datetime(DateTimeInterface|string|null $value, ?string $dateFormat = null, ?string $timeFormat = null): string
    {
        $date = app_display_wall_value($value);
        if (!$date) return '';
        return app_display_format($date, $dateFormat ?? app_date_format())
            . ' ' . app_display_format($date, $timeFormat ?? app_time_format());
    }
}
// Legacy formatting helpers. Stored Core timestamps remain site-local wall time.
// - tanpa strftime()
// - pakai IntlDateFormatter jika tersedia
// - fallback manual jika intl tidak aktif

if (!function_exists('dtid_jakarta_tz')) {
    function dtid_jakarta_tz(): DateTimeZone
    {
        return app_timezone();
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
     * Parse a legacy datetime stored as site-local wall time.
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
     * Parse an explicitly UTC datetime and convert it to the site timezone.
     */
    function dtid_parse_utc_to_jakarta(?string $mysqlDt): ?DateTimeImmutable
    {
        if (dtid_is_empty_mysql_datetime($mysqlDt)) {
            return null;
        }

        try {
            $d = new DateTimeImmutable((string)$mysqlDt, app_utc_timezone());
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
            app_timezone_id(),
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
            $result .= $useWib ? '. ' . $time . ' ' . $d->format('T') : " {$time}";
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
     * The input is a legacy site-local wall-clock value.
     */
    function format_datetime_id(?string $mysqlDt, bool $withDay = false, bool $withTime = true): string
    {
        $dateFormat = app_date_format();
        if ($withDay && !str_contains($dateFormat, 'l') && !str_contains($dateFormat, 'D')) $dateFormat = 'l, ' . $dateFormat;
        return $withTime
            ? app_display_datetime($mysqlDt, $dateFormat)
            : app_display_date($mysqlDt, $dateFormat);
    }
}

if (!function_exists('format_datetime_indo')) {
    /**
     * Format a legacy site-local wall-clock value with its timezone abbreviation.
     */
    function format_datetime_indo(?string $mysqlDt, bool $withDay = true, bool $withTime = true): string
    {
        $dateFormat = app_date_format();
        if ($withDay && !str_contains($dateFormat, 'l') && !str_contains($dateFormat, 'D')) $dateFormat = 'l, ' . $dateFormat;
        if (!$withTime) return app_display_date($mysqlDt, $dateFormat);
        $formatted = app_display_datetime($mysqlDt, $dateFormat);
        $date = app_display_wall_value($mysqlDt);
        return $formatted === '' || !$date ? '-' : $formatted . ' ' . $date->format('T');
    }
}

if (!function_exists('format_date_ddmmyyyy')) {
    /**
     * Format MySQL datetime ke format dd-mm-yyyy (tanpa jam).
     * Contoh: "10-11-2025"
     *
     * The input is a legacy site-local wall-clock value.
     */
    function format_date_ddmmyyyy(?string $mysqlDt): string
    {
        return app_display_date($mysqlDt);
    }
}

if (!function_exists('format_date_ddmmyyyy_time')) {
    /**
     * Format MySQL datetime ke format dd-mm-yyyy HH:mm
     * Contoh: "10-11-2025 14:30"
     *
     * The input is a legacy site-local wall-clock value.
     */
    function format_date_ddmmyyyy_time(?string $mysqlDt): string
    {
        return app_display_datetime($mysqlDt);
    }
}

if (!function_exists('format_date_ddmmyyyy_time_bracket')) {
    /**
     * Format MySQL datetime ke format dd/mm/yyyy (HH:mm)
     * Contoh: "10/11/2025 (14:30)"
     *
     * The input is a legacy site-local wall-clock value.
     */
    function format_date_ddmmyyyy_time_bracket(?string $mysqlDt): string
    {
        $date = app_display_wall_value($mysqlDt);
        if (!$date) return '';
        return app_display_format($date, app_date_format()) . ' (' . app_display_format($date, app_time_format()) . ')';
    }
}
