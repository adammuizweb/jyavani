<?php
declare(strict_types=1);

$supported_locales = ['en', 'id', 'de'];
$default_locale = 'en';

function get_supported_locales(): array {
    global $supported_locales;
    return $supported_locales;
}

function default_locale(): string {
    global $default_locale;
    return $GLOBALS['__APP_DEFAULT_LOCALE'] ?? $default_locale;
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
