<?php
// url_helpers.php (atau di i18n_helpers.php)

function url_for(string $path = '', ?string $locale = null) : string {
    // path seharusnya tanpa leading slash atau bisa menerima juga '/foo' -> kita normalisasi
    $path = (string)$path;
    if ($path === '' || $path === '/') $path = '/';
    // Ensure single leading slash
    $path = '/' . ltrim($path, '/');

    $locale = $locale ?? get_locale();
    $default = default_locale();

    // If locale is default, do NOT prefix. Otherwise, prefix like '/id'
    if ($locale === $default) {
        return $path;
    }
    // Avoid double slashes: '/id/' + '/slug' -> '/id/slug'
    return '/' . $locale . rtrim($path, '/');
}

/** Append a raw query string before any fragment without creating a second question mark. */
function url_append_query_string(string $url, string $query): string {
    if ($query === '') return $url;
    if (preg_match('/[\r\n\0]/', $url)) return '';

    [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
    $separator = str_contains($base, '?')
        ? (str_ends_with($base, '?') || str_ends_with($base, '&') ? '' : '&')
        : '?';
    return $base . $separator . $query . ($fragment !== null ? '#' . $fragment : '');
}

/** File-like request paths must not receive collection-style trailing slashes. */
function url_path_is_file_like(string $path): bool {
    $path = parse_url($path, PHP_URL_PATH);
    if (!is_string($path)) return false;
    return preg_match('#(?:^|/)[^/]+\.(?:[a-z0-9]{1,10}|webmanifest)$#i', $path) === 1;
}
