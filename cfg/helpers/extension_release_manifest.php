<?php
declare(strict_types=1);

require_once __DIR__ . '/cms_manifest.php';
require_once __DIR__ . '/update_metadata_http.php';

const EXTENSION_RELEASE_MANIFEST_SCHEMA = 1;
const EXTENSION_RELEASE_MANIFEST_MAX_BYTES = 1024 * 1024;
const EXTENSION_RELEASE_MANIFEST_MAX_FILES = 5000;
const EXTENSION_RELEASE_MANIFEST_MAX_PATH_BYTES = 1024;
const EXTENSION_RELEASE_MANIFEST_MAX_ZIP_BYTES = 50 * 1024 * 1024;

function extension_release_manifest_slug_valid(string $slug): bool
{
    return preg_match('/\A[a-z0-9][a-z0-9_-]{0,99}\z/D', $slug) === 1;
}

function extension_release_manifest_version_valid(string $version): bool
{
    return $version !== '' && strlen($version) <= 64
        && preg_match('/\A[0-9A-Za-z][0-9A-Za-z._+-]*\z/D', $version) === 1;
}

function extension_release_manifest_path_valid(string $path): bool
{
    if (strlen($path) > EXTENSION_RELEASE_MANIFEST_MAX_PATH_BYTES
        || cms_manifest_safe_relative_path($path) !== $path
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        || preg_match('/\A[A-Za-z]:/', $path) === 1) return false;
    foreach (explode('/', $path) as $segment) if (strlen($segment) > 255) return false;
    return true;
}

function extension_release_manifest_url(string $type, string $slug, string $version): ?string
{
    if (!in_array($type, ['plugin', 'theme'], true)
        || !extension_release_manifest_slug_valid($slug)
        || !extension_release_manifest_version_valid($version)) return null;
    $store = $type === 'plugin' ? 'plugin-store' : 'theme-store';
    return 'https://jyavani.com/' . $store . '/' . rawurlencode($slug)
        . '/releases/' . rawurlencode($version) . '/manifest.json';
}

function extension_release_manifest_canonical_store(string $type, string $url): bool
{
    $expected = $type === 'plugin'
        ? 'https://jyavani.com/plugin-store'
        : ($type === 'theme' ? 'https://jyavani.com/theme-store' : '');
    $url = trim($url);
    return $expected !== '' && ($url === $expected || $url === $expected . '/');
}

function extension_release_manifest_validate(array $manifest, string $type, string $slug, string $version): array
{
    if (array_is_list($manifest)) throw new RuntimeException('Invalid extension release manifest.');
    $keys = array_keys($manifest);
    sort($keys, SORT_STRING);
    $expectedKeys = ['files', 'name', 'package_sha256', 'schema_version', 'total_files', 'type', 'version', 'zip_size'];
    sort($expectedKeys, SORT_STRING);
    if ($keys !== $expectedKeys
        || ($manifest['schema_version'] ?? null) !== EXTENSION_RELEASE_MANIFEST_SCHEMA
        || ($manifest['type'] ?? null) !== $type
        || !is_string($manifest['name'] ?? null) || !hash_equals($slug, $manifest['name'])
        || !extension_release_manifest_slug_valid($manifest['name'])
        || !is_string($manifest['version'] ?? null) || !hash_equals($version, $manifest['version'])
        || !extension_release_manifest_version_valid($manifest['version'])
        || !is_string($manifest['package_sha256'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $manifest['package_sha256']) !== 1
        || !is_int($manifest['zip_size'] ?? null) || $manifest['zip_size'] < 1
        || $manifest['zip_size'] > EXTENSION_RELEASE_MANIFEST_MAX_ZIP_BYTES
        || !is_int($manifest['total_files'] ?? null)
        || !is_array($manifest['files'] ?? null) || array_is_list($manifest['files'])
        || $manifest['files'] === [] || count($manifest['files']) > EXTENSION_RELEASE_MANIFEST_MAX_FILES
        || $manifest['total_files'] !== count($manifest['files'])) {
        throw new RuntimeException('Invalid extension release manifest.');
    }

    $paths = array_keys($manifest['files']);
    $sorted = $paths;
    sort($sorted, SORT_STRING);
    if ($paths !== $sorted) throw new RuntimeException('Invalid extension release manifest.');
    $mapBytes = 0;
    $caseInsensitivePaths = [];
    $caseInsensitiveSegments = [];
    $directoryPrefixes = [];
    foreach ($manifest['files'] as $path => $hash) {
        $pathKey = is_string($path) ? strtolower($path) : '';
        if (!is_string($path) || !extension_release_manifest_path_valid($path)
            || $pathKey === '.store.json' || $pathKey === '.git' || str_starts_with($pathKey, '.git/')
            || isset($caseInsensitivePaths[$pathKey]) || isset($directoryPrefixes[$pathKey])
            || !is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new RuntimeException('Invalid extension release manifest.');
        }
        $caseInsensitivePaths[$pathKey] = true;
        $segments = explode('/', $path);
        for ($position = 0; $position < count($segments); $position++) {
            $prefix = implode('/', array_slice($segments, 0, $position + 1));
            $prefixKey = strtolower($prefix);
            if (isset($caseInsensitiveSegments[$prefixKey])
                && $caseInsensitiveSegments[$prefixKey] !== $prefix) {
                throw new RuntimeException('Invalid extension release manifest.');
            }
            $caseInsensitiveSegments[$prefixKey] = $prefix;
            if ($position < count($segments) - 1 && isset($caseInsensitivePaths[$prefixKey])) {
                throw new RuntimeException('Invalid extension release manifest.');
            }
            if ($position < count($segments) - 1) $directoryPrefixes[$prefixKey] = true;
        }
        $mapBytes += strlen($path) + 72;
        if ($mapBytes > EXTENSION_RELEASE_MANIFEST_MAX_BYTES) {
            throw new RuntimeException('Invalid extension release manifest.');
        }
    }
    return $manifest;
}

function extension_release_manifest_fetch(
    string $type,
    string $slug,
    string $version,
    float $deadline,
    ?callable $provider = null
): array {
    $url = extension_release_manifest_url($type, $slug, $version);
    if ($url === null || microtime(true) >= $deadline) {
        return ['manifest' => null, 'url' => $url ?? '', 'reason' => 'store_release_manifest_invalid'];
    }
    try {
        if ($provider !== null) {
            $data = $provider($url, $type, $slug, $version, $deadline);
        } else {
            $response = update_metadata_stream_request_once(
                $url,
                'JyavaniCMS-SiteHealth-Extension/' . $version,
                min($deadline, microtime(true) + UPDATE_METADATA_REQUEST_SECONDS)
            );
            if (!is_array($response) || $response['status'] < 200 || $response['status'] >= 300
                || !is_string($response['body']) || $response['body'] === ''
                || strlen($response['body']) > EXTENSION_RELEASE_MANIFEST_MAX_BYTES) {
                return ['manifest' => null, 'url' => $url, 'reason' => 'store_release_manifest_unavailable'];
            }
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($data)) return ['manifest' => null, 'url' => $url, 'reason' => 'store_release_manifest_unavailable'];
        return ['manifest' => extension_release_manifest_validate($data, $type, $slug, $version), 'url' => $url, 'reason' => null];
    } catch (Throwable $error) {
        return ['manifest' => null, 'url' => $url, 'reason' => 'store_release_manifest_invalid'];
    }
}
