<?php
declare(strict_types=1);

function cms_manifest_preserve_patterns(): array
{
    return [
        '#^cfg/\.env$#',
        '#^cfg/site-files\.json$#',
        '#^cfg/var/#',
        '#^cfg/session_debug\.log$#',
        '#^cfg/php-noteloc\.ini$#',
        '#^cfg/\.env\.old$#',
        '#^cfg/site-router\.php$#',
        '#^\.git/#',
        '#^\.gitignore$#',
        '#^\.gitattributes$#',
        '#^AGENTS\.md$#',
        '#^README\.md$#',
        '#^INSTALL\.md$#',
        '#^SERVER_SETUP\.md$#',
        '#^public/static/img/\d{4}/#',
        '#^public/static/files/#',
        '#^public/sitemaps/#',
        '#^private_files/#',
        '#^public/views/themes/(?!default(?:/|$))[^/]+$#',
        '#^public/views/themes/(?!default(?:/|$))[^/]+/.+#',
        '#^plugins/[^/]+/.+#',
        '#^public/static/plugins/#',
        '#^plugin-store/#',
        '#^theme-store/#',
        '#^tools/dev-user\.php$#',
        '#^tools/data/#',
        '#^public/static/vendor/xterm/#',
        '#^public/static/vendor/jyavani-builder/#',
        '#^public/static/js/photo_canvas\.js$#',
        '#node_modules/#',
        '#^tools/cms-manifest\.json$#',
        '#^var/#',
        '#\.DS_Store$#',
        '#Thumbs\.db$#',
        '#^public/pdf/#',
    ];
}

function cms_manifest_site_files(string $projectRoot): array
{
    $path = rtrim($projectRoot, '/\\') . '/cfg/site-files.json';
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) return ['files' => [], 'reason' => null];
    $raw = cms_manifest_read_bounded_regular_file($path, 4 * 1024 * 1024);
    if (!is_string($raw)) return ['files' => [], 'reason' => 'site_manifest_unreadable'];
    try {
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return ['files' => [], 'reason' => 'site_manifest_invalid'];
    }
    if (!is_array($manifest) || array_is_list($manifest) || ($manifest['schema'] ?? null) !== 1
        || !is_array($manifest['files'] ?? null) || array_is_list($manifest['files'])
        || count($manifest['files']) > 100000) {
        return ['files' => [], 'reason' => 'site_manifest_invalid'];
    }
    $files = [];
    foreach ($manifest['files'] as $logical => $hash) {
        if (!is_string($logical) || cms_manifest_safe_relative_path($logical) !== $logical
            || !cms_manifest_site_file_allowed($logical) || cms_manifest_is_preserved($logical)
            || !is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            return ['files' => [], 'reason' => 'site_manifest_invalid'];
        }
        $files[$logical] = $hash;
    }
    ksort($files, SORT_STRING);
    return ['files' => $files, 'reason' => null];
}

function cms_manifest_site_file_allowed(string $path): bool
{
    if (str_starts_with($path, 'tools/')) return true;
    if (!str_starts_with($path, 'public/')) return false;
    $basename = strtolower(basename($path));
    return !in_array($basename, ['.htaccess', '.user.ini', 'php.ini'], true)
        && preg_match('/\.(?:php\d*|phtml|pht|phar|cgi|pl|py|sh)(?:\.|$)/i', $basename) !== 1;
}

function cms_manifest_is_preserved(string $path, ?array $patterns = null): bool
{
    foreach ($patterns ?? cms_manifest_preserve_patterns() as $pattern) {
        if (is_string($pattern) && preg_match($pattern, $path) === 1) return true;
    }
    return false;
}

function cms_manifest_should_prune_directory(string $path): bool
{
    return cms_manifest_is_preserved($path)
        || cms_manifest_is_preserved(rtrim($path, '/') . '/')
        || cms_manifest_is_preserved(rtrim($path, '/') . '/.site-health-probe');
}

function cms_manifest_allowed_directories(): array
{
    return ['app', 'cfg', 'dashboard', 'plugins', 'public', 'schema', 'tools'];
}

function cms_manifest_allowed_root_files(): array
{
    return ['version.json', 'router.php', 'VERSION', '.gitattributes', 'LICENSE'];
}

function cms_manifest_is_managed_candidate(string $path): bool
{
    $safe = cms_manifest_safe_relative_path($path);
    if ($safe === null || $safe !== $path || cms_manifest_is_preserved($path)) return false;
    return cms_manifest_path_in_core_namespace($path);
}

function cms_manifest_path_in_core_namespace(string $path): bool
{
    $safe = cms_manifest_safe_relative_path($path);
    if ($safe === null || $safe !== $path) return false;
    $parts = explode('/', $path, 2);
    return count($parts) === 1
        ? in_array($path, cms_manifest_allowed_root_files(), true)
        : in_array($parts[0], cms_manifest_allowed_directories(), true);
}

function cms_manifest_safe_relative_path(string $path): ?string
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) return null;
    $segments = explode('/', rtrim($path, '/'));
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') return null;
    }
    return implode('/', $segments);
}

function cms_manifest_target_path(string $logicalPath, string $projectRoot, string $publicRoot): ?string
{
    $safe = cms_manifest_safe_relative_path($logicalPath);
    if ($safe === null || $safe !== $logicalPath) return null;

    if ($logicalPath === 'public' || str_starts_with($logicalPath, 'public/')) {
        $root = $publicRoot;
        $relative = $logicalPath === 'public' ? '' : substr($logicalPath, 7);
    } else {
        $root = $projectRoot;
        $relative = $logicalPath;
    }
    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal) || is_link($root)) return null;
    $rootReal = rtrim($rootReal, '/\\');
    $target = $rootReal;
    $segments = $relative === '' ? [] : explode('/', $relative);
    foreach ($segments as $index => $segment) {
        $target .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($target)) return null;
        if (file_exists($target)) {
            $real = realpath($target);
            if ($real === false || ($real !== $rootReal && !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR))) return null;
            if ($index < count($segments) - 1 && !is_dir($target)) return null;
        }
    }
    return $target;
}

function cms_manifest_read_bounded_regular_file(string $path, int $maxBytes): ?string
{
    if ($maxBytes < 1) return null;
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== 0100000
        || ($before['nlink'] ?? 0) !== 1 || ($before['size'] ?? -1) < 1
        || ($before['size'] ?? 0) > $maxBytes || is_link($path)) return null;
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return null;
    $descriptor = @fstat($handle);
    $contents = is_array($descriptor) && ($descriptor['size'] ?? 0) <= $maxBytes
        ? stream_get_contents($handle, $maxBytes + 1)
        : false;
    @fclose($handle);
    clearstatcache(true, $path);
    $after = @lstat($path);
    $safe = is_string($contents) && strlen($contents) <= $maxBytes
        && is_array($descriptor) && is_array($after)
        && (($descriptor['mode'] ?? 0) & 0170000) === 0100000
        && ($descriptor['nlink'] ?? 0) === 1
        && ($before['dev'] ?? null) === ($descriptor['dev'] ?? null)
        && ($before['ino'] ?? null) === ($descriptor['ino'] ?? null)
        && ($before['size'] ?? null) === ($descriptor['size'] ?? null)
        && ($descriptor['dev'] ?? null) === ($after['dev'] ?? null)
        && ($descriptor['ino'] ?? null) === ($after['ino'] ?? null)
        && ($descriptor['size'] ?? null) === ($after['size'] ?? null)
        && !is_link($path);
    return $safe ? $contents : null;
}

function cms_manifest_validate(array $manifest, string $label = 'CMS manifest', bool $allowPreserved = false): array
{
    if (array_is_list($manifest)) throw new RuntimeException('Invalid ' . $label . ': expected a JSON object.');
    $version = $manifest['version'] ?? null;
    $files = $manifest['files'] ?? null;
    $total = $manifest['total_files'] ?? null;
    if (!is_string($version) || trim($version) === '' || strlen($version) > 64) {
        throw new RuntimeException('Invalid ' . $label . ': invalid version.');
    }
    if (!is_array($files) || $files === [] || array_is_list($files) || count($files) > 100000) {
        throw new RuntimeException('Invalid ' . $label . ': invalid file map.');
    }
    if (!is_int($total) || $total !== count($files)) {
        throw new RuntimeException('Invalid ' . $label . ': file count mismatch.');
    }
    $normalized = [];
    foreach ($files as $path => $hash) {
        $preserved = is_string($path) && cms_manifest_is_preserved($path);
        if (!is_string($path) || cms_manifest_safe_relative_path($path) !== $path
            || $path === 'tools/cms-manifest.json'
            || (!$preserved && !cms_manifest_path_in_core_namespace($path))
            || (!$allowPreserved && $preserved)
            || !is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new RuntimeException('Invalid ' . $label . ': invalid path or hash.');
        }
        $normalized[$path] = $hash;
    }
    ksort($normalized, SORT_STRING);
    $manifest['version'] = trim($version);
    $manifest['files'] = $normalized;
    $manifest['total_files'] = count($normalized);
    return $manifest;
}

function cms_manifest_decode(string $json, string $label = 'CMS manifest', bool $allowPreserved = false): array
{
    if ($json === '' || strlen($json) > 4 * 1024 * 1024) {
        throw new RuntimeException('Invalid ' . $label . ': invalid size.');
    }
    try {
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Invalid ' . $label . ': malformed JSON.', 0, $error);
    }
    if (!is_array($decoded)) throw new RuntimeException('Invalid ' . $label . ': expected a JSON object.');
    return cms_manifest_validate($decoded, $label, $allowPreserved);
}
