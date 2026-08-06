<?php
declare(strict_types=1);

$supported_locales = ['en', 'id', 'de'];
$locale_presets = ['en' => 'English', 'id' => 'Indonesian', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'tr' => 'Turkish', 'ru' => 'Russian', 'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese', 'ar' => 'Arabic'];
$default_locale = 'en';

function get_supported_locales(): array {
    global $supported_locales, $locale_presets;
    $pdo = $GLOBALS['pdo'] ?? null;
    $custom = $pdo instanceof PDO && function_exists('settings_get') ? json_decode((string)settings_get($pdo, 'content_custom_locales', '[]'), true) : [];
    $codes = $supported_locales;
    foreach (is_array($custom) ? $custom : [] as $code) if (preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/', (string)$code)) $codes[] = (string)$code;
    return array_values(array_unique(array_merge($supported_locales, $codes)));
}

function content_locale_presets(): array { global $locale_presets; return $locale_presets; }

function register_content_locale(PDO $pdo, string $code): bool {
    $code = trim($code);
    if (!preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/', $code)) return false;
    $current = json_decode((string)settings_get($pdo, 'content_custom_locales', '[]'), true);
    $current = is_array($current) ? $current : [];
    if (!in_array($code, $current, true)) $current[] = $code;
    return settings_set($pdo, 'content_custom_locales', json_encode(array_values($current)));
}

function default_locale(): string {
    global $default_locale;
    return $GLOBALS['__APP_DEFAULT_LOCALE'] ?? $default_locale;
}

function admin_ui_locale(): string {
    return $GLOBALS['__APP_ADMIN_LOCALE'] ?? default_locale();
}

function content_default_locale(): string {
    $default = $GLOBALS['__APP_DEFAULT_LOCALE'] ?? default_locale();
    return apply_filters('content_default_locale', $default);
}

function get_locale(): string {
    return $GLOBALS['__APP_LOCALE'] ?? default_locale();
}

function set_locale(string $locale): void {
    $GLOBALS['__APP_LOCALE'] = $locale;
}

function locale_prefix_for_url(?string $locale = null): string {
    $locale ??= get_locale();
    return $locale === default_locale() ? '' : '/' . $locale;
}

function __(string $source, string $scope = 'default'): string {
    if ($source === '') return '';

    $locale = get_locale();

    if ($locale === 'en') return $source;

    static $cache = [];
    $cacheKey = $scope . "\0" . $locale . "\0" . md5($source);

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo) {
        $stmt = $pdo->prepare("SELECT value FROM ui_translations WHERE scope = ? AND source = ? AND locale = ? LIMIT 1");
        $stmt->execute([$scope, $source, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['value'] !== '') {
            $cache[$cacheKey] = $row['value'];
            return $row['value'];
        }
    }

    $cache[$cacheKey] = $source;
    return $source;
}

function _e(string $source, string $scope = 'default'): void {
    echo __($source, $scope);
}

/**
 * Split a multi-statement SQL file into individual statements.
 * Respects single/double quoted strings so semicolons inside values are preserved.
 */
function jy_split_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($inString) {
            if ($ch === $stringChar && $prev !== '\\') {
                $inString = false;
            }
            $current .= $ch;
        } elseif ($ch === "'" || $ch === '"') {
            $inString = true;
            $stringChar = $ch;
            $current .= $ch;
        } elseif ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
        } else {
            $current .= $ch;
        }
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * Ensure ui_translations seed data is loaded.
 *
 * Uses a hash of schema/translations.sql so that existing sites automatically
 * receive new strings whenever the seed file is updated, without overwriting
 * user-edited translations (INSERT IGNORE only inserts missing rows).
 *
 * Called once per request from bootstrap_core after the DB is ready.
 */
function ensure_ui_translations_seeded(PDO $pdo): bool {
    $seedFile = dirname(__DIR__, 2) . '/schema/translations.sql';
    if (!is_file($seedFile)) {
        return false;
    }

    $seedHash = hash_file('sha256', $seedFile);
    if ($seedHash === false) {
        return false;
    }

    $storedHash = function_exists('settings_get') ? (string) settings_get($pdo, 'ui_translations_seed_hash', '') : '';
    if ($storedHash === $seedHash) {
        return true;
    }

    $raw = file_get_contents($seedFile);
    if ($raw === false) {
        return false;
    }

    // Strip single-line comments
    $lines = explode("\n", $raw);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleanLines[] = $line;
    }

    $sql = implode("\n", $cleanLines);
    $statements = jy_split_sql_statements($sql);

    $anyFailed = false;
    $executed = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (Throwable $e) {
            error_log('ensure_ui_translations_seeded: statement failed: ' . $e->getMessage());
            $anyFailed = true;
        }
    }

    if ($anyFailed) {
        return false;
    }

    if (function_exists('settings_set')) {
        settings_set($pdo, 'ui_translations_seed_hash', $seedHash, 1);
    }

    return true;
}
