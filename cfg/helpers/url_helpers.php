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

/** Return the configured site origin instead of trusting an arbitrary Host header. */
function app_site_origin(?PDO $pdo = null): string {
    $pdo ??= ($GLOBALS['pdo'] ?? null);
    $configured = '';
    if ($pdo instanceof PDO && function_exists('settings_get')) {
        $configured = trim((string)settings_get($pdo, 'site_url', ''));
    }

    $candidate = $configured;
    if ($candidate === '' && $pdo instanceof PDO && function_exists('settings_get')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $configuredHost = trim((string)settings_get($pdo, 'site_host', ''));
        $candidate = $configuredHost !== '' ? $scheme . '://' . $configuredHost : '';
    }
    if ($candidate === '') {
        $candidate = 'http://localhost';
    }

    try {
        $parts = parse_url($candidate);
    } catch (ValueError) {
        $parts = false;
    }
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        return 'http://localhost';
    }

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower(trim($parts['host']));
    $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
        || $host === 'localhost'
        || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $host) === 1;
    if (!$validHost) return 'http://localhost';

    $authority = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    if (isset($parts['port'])) {
        $port = (int)$parts['port'];
        if ($port < 1 || $port > 65535) return 'http://localhost';
        $authority .= ':' . $port;
    }
    return $scheme . '://' . $authority;
}
