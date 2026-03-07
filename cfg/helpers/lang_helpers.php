<?php
// /home/u528279701/jyavani-cfg/helpers/lang_helpers.php

// Supported locales dan default
$supported_locales = ['en','id'];
$default_locale = 'en';

function get_supported_locales() {
    global $supported_locales;
    return $supported_locales;
}

function default_locale() {
    global $default_locale;
    return $default_locale;
}

/**
 * Detect locale from URL segments.
 * Consume segment only if it's a non-default locale (e.g. 'id').
 *
 * @param array &$segments
 * @return string locale
 */
function detect_locale_from_segments(array &$segments) : string {
    $locales = get_supported_locales();
    $default = default_locale();
    if (isset($segments[0]) && in_array($segments[0], $locales, true)) {
        $first = $segments[0];
        if ($first !== $default) {
            array_shift($segments); // consume only non-default
            return $first;
        }
        // jika sama dengan default -> tidak di-consume
    }
    return $default;
}

function get_locale() {
    return $GLOBALS['__APP_LOCALE'] ?? default_locale();
}

function set_locale($locale) {
    $GLOBALS['__APP_LOCALE'] = $locale;
}

/**
 * t(scope, key, locale?, fallback?)
 * Resolve UI text from table `ui_translations`.
 * Uses $GLOBALS['pdo'] by default.
 *
 * Example scope: 'header', key: 'title'
 */
function t(string $scope, string $key, ?string $locale = null, ?string $fallback = null): string {
    $locale = $locale ?? get_locale();
    $fallback = $fallback ?? default_locale();

    static $cache = [];

    $cacheKey = "ui:{$scope}:{$key}:{$locale}";
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        $cache[$cacheKey] = '';
        return '';
    }

    $sql = "SELECT value FROM ui_translations WHERE scope = :scope AND `key` = :key AND locale = :locale LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':scope' => $scope, ':key' => $key, ':locale' => $locale]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['value'])) {
        $cache[$cacheKey] = $row['value'];
        return $row['value'];
    }

    // fallback to default locale (if different)
    if ($locale !== $fallback) {
        $fallbackKey = "ui:{$scope}:{$key}:{$fallback}";
        if (array_key_exists($fallbackKey, $cache)) return $cache[$fallbackKey];

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':scope' => $scope, ':key' => $key, ':locale' => $fallback]);
        $row2 = $stmt->fetch(PDO::FETCH_ASSOC);
        $val = $row2['value'] ?? '';
        $cache[$fallbackKey] = $val;
        return $val;
    }

    $cache[$cacheKey] = '';
    return '';
}

/**
 * Ambil post berdasarkan slug & locale.
 * Mengembalikan associative array gabungan posts + post_translations fields (pt.*)
 * Jika tidak ada pada requested locale, akan mencoba fallback jika $fallback diberikan (biasanya 'en').
 *
 * @param PDO $pdo
 * @param string $slug
 * @param string $locale
 * @param string|null $fallback
 * @return array|null
 */
function get_post_by_slug_and_locale($pdo, string $slug, string $locale, ?string $fallback = null): ?array {
    $fallback = $fallback ?? default_locale();

    $sql = "SELECT p.*, pt.locale AS pt_locale, pt.title AS title, pt.slug AS slug, pt.content AS content, pt.meta AS meta, pt.youtube AS youtube, pt.thumbnail AS thumbnail, pt.status AS pt_status
            FROM posts p
            JOIN post_translations pt ON pt.post_id = p.id
            WHERE pt.slug = :slug AND pt.locale = :locale AND p.is_deleted = 0
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':slug' => $slug, ':locale' => $locale]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    if ($fallback && $fallback !== $locale) {
        $stmt->execute([':slug' => $slug, ':locale' => $fallback]);
        $row2 = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row2 ?: null;
    }

    return null;
}

/**
 * Dapatkan translation record untuk post_id + locale (bisa null jika belum ada)
 */
function get_post_translation($pdo, int $post_id, string $locale): ?array {
    $stmt = $pdo->prepare("SELECT * FROM post_translations WHERE post_id = :post_id AND locale = :locale LIMIT 1");
    $stmt->execute([':post_id' => $post_id, ':locale' => $locale]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Helper kecil: build path with locale prefix only if locale != default
 * (If you already have url_helpers.php, you can remove this or keep as convenience.)
 */
function locale_prefix_for_url(?string $locale = null): string {
    $locale = $locale ?? get_locale();
    $default = default_locale();
    if ($locale === $default) return '';
    return '/' . $locale;
}
