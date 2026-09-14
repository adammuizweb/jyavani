<?php
declare(strict_types=1);

require_once __DIR__ . '/core_integrity.php';
require_once __DIR__ . '/extension_release_manifest.php';

const SITE_HEALTH_SCHEMA = 1;
const SITE_HEALTH_MAX_ENTRIES = 200000;
const SITE_HEALTH_MAX_FINDINGS = 500;
const SITE_HEALTH_MAX_EXTENSION_ITEMS = 2000;
const SITE_HEALTH_SCAN_SECONDS = 30.0;
const SITE_HEALTH_EXTENSION_MAX_FILE_BYTES = 64 * 1024 * 1024;
const SITE_HEALTH_EXTENSION_MAX_TOTAL_BYTES = 512 * 1024 * 1024;
const SITE_HEALTH_STORE_METADATA_MAX_BYTES = 64 * 1024;

function site_health_add_finding(array &$result, string $path, string $status, string $reason): void
{
    $result['summary'][$status] = (int)($result['summary'][$status] ?? 0) + 1;
    if (count($result['findings']) >= SITE_HEALTH_MAX_FINDINGS) {
        $result['findings_truncated'] = true;
        return;
    }
    $result['findings'][] = ['path' => $path, 'status' => $status, 'reason' => $reason];
}

function site_health_inspect_tree(string $root, string $logicalPrefix, float $deadline, bool $contentRules = false, ?int &$remainingEntries = null, mixed $finfo = null): array
{
    if ($remainingEntries === null) $remainingEntries = SITE_HEALTH_MAX_ENTRIES;
    $result = [
        'complete' => true,
        'files_scanned' => 0,
        'bytes_observed' => 0,
        'summary' => ['unverified' => 0, 'contaminated' => 0, 'infected' => 0],
        'findings' => [],
        'findings_truncated' => false,
    ];
    clearstatcache(true, $root);
    $rootStat = @lstat($root);
    if (!is_array($rootStat)) return $result;
    if ((($rootStat['mode'] ?? 0) & 0170000) !== 0040000 || is_link($root) || !is_readable($root)) {
        site_health_add_finding($result, $logicalPrefix, 'unverified', 'scan_root_unreadable');
        $result['complete'] = false;
        return $result;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $prefixLength = strlen(rtrim($root, '/\\')) + 1;
        foreach ($iterator as $entry) {
            if ($remainingEntries-- <= 0 || microtime(true) >= $deadline) {
                site_health_add_finding($result, $logicalPrefix, 'unverified', 'scan_limit_reached');
                $result['complete'] = false;
                break;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), $prefixLength));
            $logical = $logicalPrefix . '/' . $relative;
            clearstatcache(true, $entry->getPathname());
            $stat = @lstat($entry->getPathname());
            if (!is_array($stat)) {
                site_health_add_finding($result, $logical, 'unverified', 'file_unreadable');
                $result['complete'] = false;
                continue;
            }
            $type = ($stat['mode'] ?? 0) & 0170000;
            if ($type === 0040000) continue;
            if ($type !== 0100000 || is_link($entry->getPathname())) {
                site_health_add_finding($result, $logical, 'contaminated', $type === 0120000 ? 'unexpected_symlink' : 'unexpected_special_file');
                continue;
            }
            $result['files_scanned']++;
            $result['bytes_observed'] += max(0, (int)($stat['size'] ?? 0));
            if (!$contentRules) continue;

            $basename = strtolower($entry->getBasename());
            if (in_array($basename, ['.htaccess', '.user.ini', 'php.ini'], true)
                || preg_match('/\.(?:php\d*|phtml|pht|phar|cgi|pl|py|sh)(?:\.|$)/i', $basename) === 1) {
                site_health_add_finding($result, $logical, 'contaminated', 'executable_upload');
                continue;
            }
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $imageMimes = [
                'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
                'gif' => ['image/gif'], 'webp' => ['image/webp'], 'avif' => ['image/avif'],
                'bmp' => ['image/bmp', 'image/x-ms-bmp'],
                'ico' => ['image/x-icon', 'image/vnd.microsoft.icon'],
            ];
            if (isset($imageMimes[$extension])) {
                if (!is_resource($finfo) && !($finfo instanceof finfo)) {
                    site_health_add_finding($result, $logical, 'unverified', 'mime_detector_unavailable');
                    $result['complete'] = false;
                    continue;
                }
                $prefix = site_health_read_regular_prefix($entry->getPathname(), 16384);
                if ($prefix === null) {
                    site_health_add_finding($result, $logical, 'unverified', 'file_unreadable');
                    $result['complete'] = false;
                    continue;
                }
                $mime = @finfo_buffer($finfo, $prefix);
                if (!is_string($mime) || $mime === '') {
                    site_health_add_finding($result, $logical, 'unverified', 'mime_detector_unavailable');
                    $result['complete'] = false;
                } elseif (!in_array(strtolower($mime), $imageMimes[$extension], true)) {
                    site_health_add_finding($result, $logical, 'contaminated', 'image_mime_mismatch');
                }
            }
        }
    } catch (Throwable $error) {
        site_health_add_finding($result, $logicalPrefix, 'unverified', 'inventory_incomplete');
        $result['complete'] = false;
    }
    return $result;
}

function site_health_read_regular_prefix(string $path, int $limit): ?string
{
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== 0100000
        || ($before['nlink'] ?? 0) !== 1 || is_link($path)) return null;
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return null;
    $descriptor = @fstat($handle);
    $prefix = @fread($handle, $limit);
    @fclose($handle);
    clearstatcache(true, $path);
    $after = @lstat($path);
    $safe = is_string($prefix) && is_array($descriptor) && is_array($after)
        && (($descriptor['mode'] ?? 0) & 0170000) === 0100000
        && ($descriptor['nlink'] ?? 0) === 1
        && ($before['dev'] ?? null) === ($descriptor['dev'] ?? null)
        && ($before['ino'] ?? null) === ($descriptor['ino'] ?? null)
        && ($descriptor['dev'] ?? null) === ($after['dev'] ?? null)
        && ($descriptor['ino'] ?? null) === ($after['ino'] ?? null)
        && !is_link($path);
    return $safe ? $prefix : null;
}

function site_health_extension_finding_path(string $logicalRoot, string $relative): string
{
    return extension_release_manifest_path_valid($relative)
        ? $logicalRoot . '/' . $relative
        : $logicalRoot . '/unsafe-path-' . substr(hash('sha256', $relative), 0, 16);
}

function site_health_scan_extension_tree(
    string $root,
    string $logicalRoot,
    array $manifest,
    float $deadline,
    int &$remainingEntries,
    int &$remainingBytes,
    bool $allowStoreMetadata = true
): array {
    $result = [
        'complete' => true,
        'files_scanned' => 0,
        'summary' => ['unverified' => 0, 'modified' => 0, 'contaminated' => 0, 'infected' => 0],
        'findings' => [],
        'findings_truncated' => false,
    ];
    $seen = [];
    clearstatcache(true, $root);
    $rootBefore = @lstat($root);
    if (!is_array($rootBefore)) {
        site_health_add_finding($result, $logicalRoot, 'unverified', 'extension_root_unreadable');
        $result['complete'] = false;
        return $result;
    }
    if ((($rootBefore['mode'] ?? 0) & 0170000) !== 0040000 || is_link($root)) {
        site_health_add_finding($result, $logicalRoot, 'contaminated', 'unsafe_extension_root');
        return $result;
    }
    if (!is_readable($root) || realpath($root) === false) {
        site_health_add_finding($result, $logicalRoot, 'unverified', 'extension_root_unreadable');
        $result['complete'] = false;
        return $result;
    }
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        clearstatcache(true, $root);
        $rootAfter = @lstat($root);
        if (!is_array($rootAfter) || (($rootAfter['mode'] ?? 0) & 0170000) !== 0040000
            || ($rootBefore['dev'] ?? null) !== ($rootAfter['dev'] ?? null)
            || ($rootBefore['ino'] ?? null) !== ($rootAfter['ino'] ?? null)
            || is_link($root)) {
            site_health_add_finding($result, $logicalRoot, 'contaminated', 'unsafe_extension_root');
            return $result;
        }
        if (!is_readable($root)) {
            site_health_add_finding($result, $logicalRoot, 'unverified', 'extension_root_unreadable');
            $result['complete'] = false;
            return $result;
        }
        $prefixLength = strlen(rtrim($root, '/\\')) + 1;
        foreach ($iterator as $entry) {
            if ($remainingEntries-- <= 0 || $remainingBytes <= 0 || microtime(true) >= $deadline) {
                site_health_add_finding($result, $logicalRoot, 'unverified', 'scan_limit_reached');
                $result['complete'] = false;
                break;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), $prefixLength));
            $logical = site_health_extension_finding_path($logicalRoot, $relative);
            clearstatcache(true, $entry->getPathname());
            $stat = @lstat($entry->getPathname());
            if (!is_array($stat)) {
                site_health_add_finding($result, $logical, 'unverified', 'file_unreadable');
                $result['complete'] = false;
                continue;
            }
            $type = ($stat['mode'] ?? 0) & 0170000;
            $relativeKey = strtolower($relative);
            if ($relativeKey === '.git' || str_starts_with($relativeKey, '.git/')) {
                site_health_add_finding($result, $logical, 'contaminated', 'prohibited_extension_metadata');
                continue;
            }
            if ($allowStoreMetadata && $relative === '.store.json') {
                $result['files_scanned']++;
                if ($type !== 0100000 || $entry->isLink()) {
                    site_health_add_finding($result, $logical, 'contaminated', 'unsafe_store_metadata');
                    continue;
                }
                $size = max(0, (int)($stat['size'] ?? 0));
                if ($size > $remainingBytes || microtime(true) >= $deadline) {
                    site_health_add_finding($result, $logical, 'unverified', 'scan_limit_reached');
                    $result['complete'] = false;
                    continue;
                }
                $metadataRaw = cms_manifest_read_bounded_regular_file($entry->getPathname(), SITE_HEALTH_STORE_METADATA_MAX_BYTES);
                $remainingBytes -= min($remainingBytes, $size);
                try {
                    $metadata = is_string($metadataRaw)
                        ? json_decode($metadataRaw, false, 16, JSON_THROW_ON_ERROR)
                        : null;
                } catch (JsonException $error) {
                    $metadata = null;
                }
                if (!$metadata instanceof stdClass) {
                    site_health_add_finding($result, $logical, 'unverified', 'store_metadata_invalid_or_unreadable');
                    $result['complete'] = false;
                }
                continue;
            }
            // Release manifests identify files only; ordinary empty directories do not affect identity.
            if ($type === 0040000 && !$entry->isLink()) continue;
            $result['files_scanned']++;
            if (!extension_release_manifest_path_valid($relative) || $type !== 0100000 || $entry->isLink()) {
                site_health_add_finding($result, $logical, 'contaminated', 'unsafe_extension_file');
                continue;
            }
            if (!isset($manifest['files'][$relative])) {
                site_health_add_finding($result, $logical, 'contaminated', 'unexpected_extension_file');
                continue;
            }
            $seen[$relative] = true;
            $observed = core_integrity_hash_regular_file(
                $entry->getPathname(),
                min(SITE_HEALTH_EXTENSION_MAX_FILE_BYTES, $remainingBytes),
                $deadline
            );
            $remainingBytes -= min($remainingBytes, (int)($observed['size'] ?? 0));
            if ($observed['status'] === 'ok') {
                if (!hash_equals($manifest['files'][$relative], (string)$observed['hash'])) {
                    site_health_add_finding($result, $logical, 'modified', 'extension_hash_mismatch');
                }
            } elseif ($observed['status'] === 'unsafe') {
                site_health_add_finding($result, $logical, 'contaminated', 'unsafe_extension_file');
            } else {
                site_health_add_finding($result, $logical, 'unverified', 'extension_file_unreadable_or_limited');
                $result['complete'] = false;
            }
        }
    } catch (Throwable $error) {
        site_health_add_finding($result, $logicalRoot, 'unverified', 'extension_inventory_incomplete');
        $result['complete'] = false;
    }
    clearstatcache(true, $root);
    $rootFinal = @lstat($root);
    if (!is_array($rootFinal) || (($rootFinal['mode'] ?? 0) & 0170000) !== 0040000
        || ($rootBefore['dev'] ?? null) !== ($rootFinal['dev'] ?? null)
        || ($rootBefore['ino'] ?? null) !== ($rootFinal['ino'] ?? null)
        || is_link($root)) {
        site_health_add_finding($result, $logicalRoot, 'contaminated', 'unsafe_extension_root');
        $result['complete'] = false;
    }
    if ($result['complete']) {
        foreach ($manifest['files'] as $relative => $_hash) {
            if (!isset($seen[$relative])) {
                site_health_add_finding($result, $logicalRoot . '/' . $relative, 'modified', 'extension_file_missing');
            }
        }
    }
    return $result;
}

function site_health_verify_plugin_static_copy(
    array &$tree,
    array $pluginManifest,
    array $releaseManifest,
    string $folder,
    string $publicRoot,
    float $deadline,
    int &$remainingEntries,
    int &$remainingBytes
): void {
    $entries = $pluginManifest['static']['copy'] ?? [];
    if (!is_array($entries) || array_is_list($entries) === false) {
        site_health_add_finding($tree, 'plugins/' . $folder . '/plugin.json', 'contaminated', 'static_copy_mapping_unsafe');
        return;
    }
    $staticFiles = [];
    $namespacePrefix = 'static/plugins/' . $folder . '/';
    foreach ($entries as $entry) {
        $from = is_array($entry) && is_string($entry['from'] ?? null) ? $entry['from'] : '';
        $to = is_array($entry) && is_string($entry['to'] ?? null) ? $entry['to'] : '';
        $logical = extension_release_manifest_path_valid($to) ? 'public/' . $to : 'plugins/' . $folder . '/plugin.json';
        if (!extension_release_manifest_path_valid($from) || !isset($releaseManifest['files'][$from])
            || !extension_release_manifest_path_valid($to)
            || !str_starts_with($to, $namespacePrefix)
            || isset($staticFiles[substr($to, strlen($namespacePrefix))])) {
            site_health_add_finding($tree, $logical, 'contaminated', 'static_copy_mapping_unsafe');
            continue;
        }
        $staticFiles[substr($to, strlen($namespacePrefix))] = $releaseManifest['files'][$from];
    }
    $namespace = rtrim($publicRoot, '/\\') . '/' . rtrim($namespacePrefix, '/');
    if (!file_exists($namespace) && !is_link($namespace)) {
        foreach ($staticFiles as $relative => $_hash) {
            site_health_add_finding($tree, 'public/' . $namespacePrefix . $relative, 'modified', 'static_copy_missing');
        }
        return;
    }
    $staticTree = site_health_scan_extension_tree(
        $namespace, 'public/' . rtrim($namespacePrefix, '/'), ['files' => $staticFiles],
        $deadline, $remainingEntries, $remainingBytes, false
    );
    $tree['complete'] = $tree['complete'] && $staticTree['complete'];
    $tree['files_scanned'] += $staticTree['files_scanned'];
    foreach (array_keys($tree['summary']) as $status) {
        $tree['summary'][$status] += $staticTree['summary'][$status];
    }
    foreach ($staticTree['findings'] as $finding) {
        if (count($tree['findings']) >= SITE_HEALTH_MAX_FINDINGS) {
            $tree['findings_truncated'] = true;
            break;
        }
        $tree['findings'][] = $finding;
    }
    if ($staticTree['findings_truncated']) $tree['findings_truncated'] = true;
}

function site_health_scan_extensions(
    string $projectRoot,
    string $publicRoot,
    float $deadline,
    ?int &$remainingEntries = null,
    ?callable $manifestProvider = null
): array
{
    if ($remainingEntries === null) $remainingEntries = SITE_HEALTH_MAX_ENTRIES;
    $result = [
        'status' => 'clean',
        'complete' => true,
        'summary' => ['total' => 0, 'clean' => 0, 'unverified' => 0, 'modified' => 0, 'contaminated' => 0, 'infected' => 0],
        'items' => [],
        'findings' => [],
        'findings_truncated' => false,
    ];
    $remainingBytes = SITE_HEALTH_EXTENSION_MAX_TOTAL_BYTES;
    $groups = [
        ['type' => 'plugin', 'root' => rtrim($projectRoot, '/\\') . '/plugins', 'manifest' => 'plugin.json', 'skip' => []],
        ['type' => 'theme', 'root' => rtrim($publicRoot, '/\\') . '/views/themes', 'manifest' => 'theme.json', 'skip' => ['default']],
    ];
    foreach ($groups as $group) {
        if (microtime(true) >= $deadline) {
            $result['complete'] = false;
            break;
        }
        clearstatcache(true, $group['root']);
        $groupStat = @lstat($group['root']);
        if (!is_array($groupStat)) {
            $logicalRoot = $group['type'] === 'plugin' ? 'plugins' : 'public/views/themes';
            $result['items'][] = ['type' => $group['type'], 'folder' => '(root)', 'name' => $logicalRoot, 'version' => '', 'status' => 'unverified', 'reason' => 'extension_root_unreadable', 'files_scanned' => 0, 'store_backed' => false, 'baseline' => 'none'];
            $result['summary']['total']++;
            $result['summary']['unverified']++;
            $result['findings'][] = ['path' => $logicalRoot, 'status' => 'unverified', 'reason' => 'scan_root_unreadable'];
            $result['complete'] = false;
            continue;
        }
        if ((($groupStat['mode'] ?? 0) & 0170000) !== 0040000 || is_link($group['root'])) {
            $logicalRoot = $group['type'] === 'plugin' ? 'plugins' : 'public/views/themes';
            $result['items'][] = ['type' => $group['type'], 'folder' => '(root)', 'name' => $logicalRoot, 'version' => '', 'status' => 'contaminated', 'reason' => 'unsafe_extension_artifact', 'files_scanned' => 0, 'store_backed' => false, 'baseline' => 'none'];
            $result['summary']['total']++;
            $result['summary']['contaminated']++;
            $result['findings'][] = ['path' => $logicalRoot, 'status' => 'contaminated', 'reason' => 'unsafe_extension_root'];
            continue;
        }
        try {
            $entries = new FilesystemIterator($group['root'], FilesystemIterator::SKIP_DOTS);
            foreach ($entries as $entry) {
                if ($remainingEntries-- <= 0 || microtime(true) >= $deadline
                    || $result['summary']['total'] >= SITE_HEALTH_MAX_EXTENSION_ITEMS - 1) {
                    $result['complete'] = false;
                    $result['items'][] = ['type' => $group['type'], 'folder' => '(limit)', 'name' => $group['type'] === 'plugin' ? 'plugins' : 'public/views/themes', 'version' => '', 'status' => 'unverified', 'reason' => 'extension_inventory_incomplete', 'files_scanned' => 0, 'store_backed' => false, 'baseline' => 'none'];
                    $result['summary']['total']++;
                    $result['summary']['unverified']++;
                    break 2;
                }
                $folder = $entry->getBasename();
                if ($folder === '' || strlen($folder) > 255 || in_array($folder, $group['skip'], true)) continue;
                if ($group['type'] === 'plugin' && str_starts_with($folder, '.')) continue;
                $logical = ($group['type'] === 'plugin' ? 'plugins/' : 'public/views/themes/') . $folder;
                clearstatcache(true, $entry->getPathname());
                $entryStat = @lstat($entry->getPathname());
                $entryType = is_array($entryStat) ? (($entryStat['mode'] ?? 0) & 0170000) : 0;
                if ($entryType === 0100000) {
                    if ($group['type'] === 'plugin' && $folder === 'index.php') continue;
                    $result['items'][] = ['type' => $group['type'], 'folder' => $folder, 'name' => $folder, 'version' => '', 'status' => 'contaminated', 'reason' => 'unsafe_extension_artifact', 'files_scanned' => 1, 'store_backed' => false, 'baseline' => 'none'];
                    $result['summary']['total']++;
                    site_health_add_finding($result, $logical, 'contaminated', 'unexpected_file');
                    continue;
                }
                if (!is_array($entryStat) || $entryType !== 0040000 || $entry->isLink()) {
                    $result['items'][] = ['type' => $group['type'], 'folder' => $folder, 'name' => $folder, 'version' => '', 'status' => 'contaminated', 'reason' => 'unsafe_extension_artifact', 'files_scanned' => 0, 'store_backed' => false, 'baseline' => 'none'];
                    $result['summary']['total']++;
                    site_health_add_finding($result, $logical, 'contaminated', 'unexpected_symlink');
                    continue;
                }
                $manifestData = null;
                $manifestRaw = cms_manifest_read_bounded_regular_file($entry->getPathname() . '/' . $group['manifest'], 512 * 1024);
                if (is_string($manifestRaw)) {
                    try {
                        $decoded = json_decode($manifestRaw, true, 32, JSON_THROW_ON_ERROR);
                        if (is_array($decoded) && !array_is_list($decoded)) $manifestData = $decoded;
                    } catch (JsonException $error) {
                        $manifestData = null;
                    }
                }
                $store = is_array($manifestData['store'] ?? null) ? $manifestData['store'] : [];
                $storeUrl = is_string($store['url'] ?? null) ? trim($store['url']) : '';
                $storeSlug = is_string($store['slug'] ?? null) ? trim($store['slug']) : '';
                $version = is_string($manifestData['version'] ?? null) ? trim($manifestData['version']) : '';
                $canonicalStore = extension_release_manifest_canonical_store($group['type'], $storeUrl);
                $storeBacked = $canonicalStore && extension_release_manifest_slug_valid($storeSlug);
                $baseline = null;
                $reason = 'extension_source_unverified';
                if ($manifestData === null || ($canonicalStore && (!$storeBacked || !extension_release_manifest_version_valid($version)))) {
                    $reason = 'extension_manifest_invalid';
                } elseif ($storeBacked && extension_release_manifest_version_valid($version)) {
                    $resolved = extension_release_manifest_fetch($group['type'], $storeSlug, $version, $deadline, $manifestProvider);
                    $baseline = $resolved['manifest'];
                    $reason = (string)($resolved['reason'] ?? '');
                }
                if (is_array($baseline)) {
                    $tree = site_health_scan_extension_tree(
                        $entry->getPathname(), $logical, $baseline, $deadline,
                        $remainingEntries, $remainingBytes
                    );
                    if ($group['type'] === 'plugin' && isset($baseline['files']['plugin.json'])) {
                        site_health_verify_plugin_static_copy(
                            $tree, $manifestData, $baseline, $folder, $publicRoot, $deadline, $remainingEntries, $remainingBytes
                        );
                    }
                    $itemStatus = $tree['summary']['infected'] > 0 ? 'infected'
                        : ($tree['summary']['contaminated'] > 0 ? 'contaminated'
                            : ($tree['summary']['modified'] > 0 ? 'modified'
                                : (!$tree['complete'] || $tree['summary']['unverified'] > 0 ? 'unverified' : 'clean')));
                    $reason = $itemStatus === 'clean' ? 'canonical_store_manifest_match'
                        : ($itemStatus === 'modified' ? 'extension_files_modified'
                            : ($itemStatus === 'contaminated' ? 'unexpected_or_unsafe_extension_file' : 'extension_scan_incomplete'));
                } else {
                    $tree = site_health_inspect_tree($entry->getPathname(), $logical, $deadline, false, $remainingEntries);
                    $tree['summary']['modified'] = 0;
                    $itemStatus = $tree['summary']['infected'] > 0 ? 'infected'
                        : ($tree['summary']['contaminated'] > 0 ? 'contaminated' : 'unverified');
                    if ($itemStatus === 'contaminated') $reason = 'unsafe_extension_artifact';
                }
                $result['items'][] = [
                    'type' => $group['type'],
                    'folder' => $folder,
                    'name' => is_string($manifestData['name'] ?? null) ? substr($manifestData['name'], 0, 200) : $folder,
                    'version' => substr($version, 0, 64),
                    'status' => $itemStatus,
                    'reason' => $reason,
                    'files_scanned' => $tree['files_scanned'],
                    'store_backed' => $storeBacked,
                    'baseline' => is_array($baseline) ? 'canonical_exact_https_store' : 'none',
                ];
                $result['summary']['total']++;
                $result['summary'][$itemStatus]++;
                foreach ($tree['findings'] as $finding) {
                    if (count($result['findings']) >= SITE_HEALTH_MAX_FINDINGS) {
                        $result['findings_truncated'] = true;
                        break;
                    }
                    $result['findings'][] = $finding;
                }
                if ($tree['findings_truncated']) $result['findings_truncated'] = true;
                if (!$tree['complete']) $result['complete'] = false;
            }
        } catch (Throwable $error) {
            $result['complete'] = false;
            $logicalRoot = $group['type'] === 'plugin' ? 'plugins' : 'public/views/themes';
            $result['items'][] = ['type' => $group['type'], 'folder' => '(root)', 'name' => $logicalRoot, 'version' => '', 'status' => 'unverified', 'reason' => 'extension_inventory_incomplete', 'files_scanned' => 0, 'store_backed' => false, 'baseline' => 'none'];
            $result['summary']['total']++;
            $result['summary']['unverified']++;
            if (count($result['findings']) < SITE_HEALTH_MAX_FINDINGS) $result['findings'][] = ['path' => $logicalRoot, 'status' => 'unverified', 'reason' => 'inventory_incomplete'];
            else $result['findings_truncated'] = true;
        }
    }
    usort($result['items'], static fn(array $left, array $right): int => strcmp($left['type'] . '/' . $left['folder'], $right['type'] . '/' . $right['folder']));
    if ($result['summary']['infected'] > 0) $result['status'] = 'infected';
    elseif ($result['summary']['contaminated'] > 0) $result['status'] = 'contaminated';
    elseif ($result['summary']['modified'] > 0) $result['status'] = 'modified';
    elseif (!$result['complete'] || $result['summary']['unverified'] > 0) $result['status'] = 'unverified';
    return $result;
}

function site_health_merge_content_tree(array &$result, array $tree): void
{
    $result['files_scanned'] += $tree['files_scanned'];
    $result['bytes_observed'] += $tree['bytes_observed'];
    foreach (['unverified', 'contaminated', 'infected'] as $status) $result['summary'][$status] += $tree['summary'][$status];
    foreach ($tree['findings'] as $finding) {
        if (count($result['findings']) >= SITE_HEALTH_MAX_FINDINGS) {
            $result['findings_truncated'] = true;
            break;
        }
        $result['findings'][] = $finding;
    }
    if ($tree['findings_truncated']) $result['findings_truncated'] = true;
    if (!$tree['complete']) $result['complete'] = false;
}

function site_health_scan_content(string $projectRoot, string $publicRoot, float $deadline, ?int &$remainingEntries = null): array
{
    if ($remainingEntries === null) $remainingEntries = SITE_HEALTH_MAX_ENTRIES;
    $result = [
        'status' => 'scanned',
        'complete' => true,
        'files_scanned' => 0,
        'bytes_observed' => 0,
        'summary' => ['unverified' => 0, 'contaminated' => 0, 'infected' => 0],
        'findings' => [],
        'findings_truncated' => false,
    ];
    $roots = [
        [rtrim($publicRoot, '/\\') . '/static/files', 'public/static/files'],
        [rtrim($projectRoot, '/\\') . '/private_files', 'private_files'],
    ];
    $finfo = function_exists('finfo_open') ? @finfo_open(FILEINFO_MIME_TYPE) : null;
    $imageRoot = rtrim($publicRoot, '/\\') . '/static/img';
    if (is_dir($imageRoot) && !is_link($imageRoot)) {
        try {
            foreach (new FilesystemIterator($imageRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($remainingEntries-- <= 0 || microtime(true) >= $deadline) {
                    site_health_add_finding($result, 'public/static/img', 'unverified', 'scan_limit_reached');
                    $result['complete'] = false;
                    break;
                }
                if ($entry->isDir() && !$entry->isLink() && preg_match('/\A\d{4}\z/D', $entry->getBasename()) === 1) {
                    $roots[] = [$entry->getPathname(), 'public/static/img/' . $entry->getBasename()];
                }
            }
        } catch (Throwable $error) {
            site_health_add_finding($result, 'public/static/img', 'unverified', 'inventory_incomplete');
            $result['complete'] = false;
        }
    }
    foreach ($roots as [$root, $logical]) {
        if (microtime(true) >= $deadline) {
            site_health_add_finding($result, $logical, 'unverified', 'scan_limit_reached');
            $result['complete'] = false;
            break;
        }
        site_health_merge_content_tree($result, site_health_inspect_tree($root, $logical, $deadline, true, $remainingEntries, $finfo));
    }
    if (is_resource($finfo) || $finfo instanceof finfo) @finfo_close($finfo);
    if ($result['summary']['infected'] > 0) $result['status'] = 'infected';
    elseif ($result['summary']['contaminated'] > 0) $result['status'] = 'contaminated';
    elseif (!$result['complete'] || $result['summary']['unverified'] > 0) $result['status'] = 'unverified';
    return $result;
}

function site_health_aggregate_status(array $components): string
{
    $statuses = array_map(static fn(array $component): string => (string)($component['status'] ?? 'unverified'), $components);
    foreach (['infected', 'contaminated', 'modified', 'unverified'] as $status) {
        if (in_array($status, $statuses, true)) return $status;
    }
    return 'clean';
}

function site_health_run(
    string $projectRoot,
    string $publicRoot,
    ?callable $coreProvider = null,
    ?callable $extensionManifestProvider = null
): array
{
    $startedAt = time();
    $started = microtime(true);
    $deadline = $started + SITE_HEALTH_SCAN_SECONDS;
    $locks = [];
    $alreadyLocked = function_exists('theme_operation_holds_lock') && defined('THEME_LIFECYCLE_LOCK_KEY')
        && theme_operation_holds_lock((string)THEME_LIFECYCLE_LOCK_KEY);
    try {
        if (!$alreadyLocked && function_exists('theme_operation_acquire') && defined('THEME_LIFECYCLE_LOCK_KEY')) {
            $locks = theme_operation_acquire([(string)THEME_LIFECYCLE_LOCK_KEY], LOCK_SH, microtime(true) + 2.0);
        }
        $remainingEntries = SITE_HEALTH_MAX_ENTRIES;
        $components = [
            'core' => core_integrity_run($projectRoot, $publicRoot, $coreProvider),
            'extensions' => site_health_scan_extensions($projectRoot, $publicRoot, $deadline, $remainingEntries, $extensionManifestProvider),
            'content' => site_health_scan_content($projectRoot, $publicRoot, $deadline, $remainingEntries),
        ];
        return [
            'schema' => SITE_HEALTH_SCHEMA,
            'status' => site_health_aggregate_status($components),
            'started_at' => $startedAt,
            'completed_at' => time(),
            'duration_ms' => max(0, (int)round((microtime(true) - $started) * 1000)),
            'components' => $components,
        ];
    } finally {
        if ($locks !== [] && function_exists('theme_operation_release')) theme_operation_release($locks);
    }
}

function site_health_report_path(): string
{
    return defined('BACKEND_PATH') ? rtrim((string)BACKEND_PATH, '/\\') . '/var/site-health-report.json' : '';
}

function site_health_run_and_store(
    string $projectRoot,
    string $publicRoot,
    ?callable $coreProvider = null,
    ?callable $extensionManifestProvider = null
): array
{
    $path = site_health_report_path();
    $directory = $path === '' ? '' : dirname($path);
    if ($directory === '' || ((!is_dir($directory) && !@mkdir($directory, 0750, true)) || is_link($directory))) {
        throw new RuntimeException('Site Health scan storage is unavailable.');
    }
    $stat = @lstat($directory);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000 || (($stat['mode'] ?? 0) & 0002) !== 0) {
        throw new RuntimeException('Site Health scan storage is unavailable.');
    }
    $lock = function_exists('update_operation_open_lock') ? update_operation_open_lock($directory . '/site-health-scan.lock') : null;
    if (!is_resource($lock)) throw new RuntimeException('Site Health scan lock is unavailable.');
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        throw new RuntimeException('Another Site Health scan is already running.');
    }
    try {
        $report = site_health_run($projectRoot, $publicRoot, $coreProvider, $extensionManifestProvider);
        if (!site_health_write_report($report)) throw new RuntimeException('Site Health report could not be saved.');
        return $report;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function site_health_report_valid(array $report): bool
{
    if (($report['schema'] ?? null) !== SITE_HEALTH_SCHEMA
        || !in_array($report['status'] ?? null, ['clean', 'unverified', 'modified', 'contaminated', 'infected'], true)
        || !is_int($report['started_at'] ?? null) || !is_int($report['completed_at'] ?? null)
        || !is_int($report['duration_ms'] ?? null) || $report['duration_ms'] < 0
        || $report['started_at'] < 0 || $report['completed_at'] < $report['started_at']
        || !is_array($report['components'] ?? null)) return false;
    foreach (['core', 'extensions', 'content'] as $component) if (!is_array($report['components'][$component] ?? null)) return false;
    if (!core_integrity_report_valid($report['components']['core'])) return false;
    $extensions = $report['components']['extensions'];
    $content = $report['components']['content'];
    if (!in_array($extensions['status'] ?? null, ['clean', 'unverified', 'modified', 'contaminated', 'infected'], true)
        || !is_bool($extensions['complete'] ?? null) || !is_bool($extensions['findings_truncated'] ?? null)
        || !is_array($extensions['summary'] ?? null)
        || !is_array($extensions['items'] ?? null) || count($extensions['items']) > 2000
        || !is_array($extensions['findings'] ?? null) || count($extensions['findings']) > SITE_HEALTH_MAX_FINDINGS
        || !in_array($content['status'] ?? null, ['scanned', 'unverified', 'contaminated', 'infected'], true)
        || !is_bool($content['complete'] ?? null) || !is_bool($content['findings_truncated'] ?? null)
        || !is_int($content['files_scanned'] ?? null) || $content['files_scanned'] < 0
        || !is_int($content['bytes_observed'] ?? null) || $content['bytes_observed'] < 0
        || !is_array($content['summary'] ?? null) || !is_array($content['findings'] ?? null)
        || count($content['findings']) > SITE_HEALTH_MAX_FINDINGS) return false;
    foreach (['total', 'clean', 'unverified', 'modified', 'contaminated', 'infected'] as $key) {
        if (!is_int($extensions['summary'][$key] ?? null) || $extensions['summary'][$key] < 0 || $extensions['summary'][$key] > SITE_HEALTH_MAX_ENTRIES) return false;
    }
    if ($extensions['summary']['total'] !== count($extensions['items'])
        || $extensions['summary']['total'] !== $extensions['summary']['clean'] + $extensions['summary']['unverified']
            + $extensions['summary']['modified'] + $extensions['summary']['contaminated'] + $extensions['summary']['infected']) return false;
    $itemCounts = ['clean' => 0, 'unverified' => 0, 'modified' => 0, 'contaminated' => 0, 'infected' => 0];
    foreach ($extensions['items'] as $item) {
        if (!is_array($item) || !in_array($item['type'] ?? null, ['plugin', 'theme'], true)
            || !is_string($item['folder'] ?? null) || strlen($item['folder']) > 255
            || !is_string($item['name'] ?? null) || strlen($item['name']) > 200
            || !is_string($item['version'] ?? null) || strlen($item['version']) > 64
            || !in_array($item['status'] ?? null, ['clean', 'unverified', 'modified', 'contaminated', 'infected'], true)
            || !is_string($item['reason'] ?? null) || strlen($item['reason']) > 64
            || !is_int($item['files_scanned'] ?? null)
            || $item['files_scanned'] < 0 || !is_bool($item['store_backed'] ?? null)
            || !in_array($item['baseline'] ?? null, ['none', 'canonical_exact_https_store'], true)
            || ($item['baseline'] === 'canonical_exact_https_store' && !$item['store_backed'])
            || (in_array($item['status'], ['clean', 'modified'], true)
                && $item['baseline'] !== 'canonical_exact_https_store')) return false;
        $itemCounts[$item['status']]++;
    }
    foreach ($itemCounts as $key => $count) if ($extensions['summary'][$key] !== $count) return false;
    $extensionStatus = $extensions['summary']['infected'] > 0 ? 'infected'
        : ($extensions['summary']['contaminated'] > 0 ? 'contaminated'
            : ($extensions['summary']['modified'] > 0 ? 'modified'
                : (!$extensions['complete'] || $extensions['summary']['unverified'] > 0 ? 'unverified' : 'clean')));
    if ($extensions['status'] !== $extensionStatus) return false;
    foreach (['unverified', 'contaminated', 'infected'] as $key) {
        if (!is_int($content['summary'][$key] ?? null) || $content['summary'][$key] < 0 || $content['summary'][$key] > SITE_HEALTH_MAX_ENTRIES) return false;
    }
    $contentStatus = $content['summary']['infected'] > 0 ? 'infected'
        : ($content['summary']['contaminated'] > 0 ? 'contaminated'
            : (!$content['complete'] || $content['summary']['unverified'] > 0 ? 'unverified' : 'scanned'));
    if ($content['status'] !== $contentStatus) return false;
    foreach ([[$extensions, ['unverified', 'modified', 'contaminated', 'infected']], [$content, ['unverified', 'contaminated', 'infected']]] as [$component, $findingStatuses]) {
        foreach ($component['findings'] as $finding) {
            if (!is_array($finding) || !is_string($finding['path'] ?? null)
                || cms_manifest_safe_relative_path($finding['path']) !== $finding['path']
                || !in_array($finding['status'] ?? null, $findingStatuses, true)
                || !is_string($finding['reason'] ?? null) || strlen($finding['reason']) > 64) return false;
        }
        if ($component['findings_truncated'] && count($component['findings']) !== SITE_HEALTH_MAX_FINDINGS) return false;
    }
    $extensionRank = ['clean' => 0, 'unverified' => 1, 'modified' => 2, 'contaminated' => 3, 'infected' => 4];
    foreach ($extensions['findings'] as $finding) {
        if ($extensionRank[$finding['status']] > $extensionRank[$extensions['status']]) return false;
    }
    $contentFindingCounts = ['unverified' => 0, 'contaminated' => 0, 'infected' => 0];
    foreach ($content['findings'] as $finding) $contentFindingCounts[$finding['status']]++;
    foreach ($contentFindingCounts as $key => $count) {
        if (($content['findings_truncated'] && $content['summary'][$key] < $count)
            || (!$content['findings_truncated'] && $content['summary'][$key] !== $count)) return false;
    }
    return $report['status'] === site_health_aggregate_status($report['components']);
}

function site_health_with_report_lock(callable $callback): mixed
{
    $path = site_health_report_path();
    if ($path === '') throw new RuntimeException('Site Health report storage is unavailable.');
    $directory = dirname($path);
    if ((!is_dir($directory) && !@mkdir($directory, 0750, true)) || is_link($directory)) throw new RuntimeException('Site Health report storage is unavailable.');
    $stat = @lstat($directory);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000 || (($stat['mode'] ?? 0) & 0002) !== 0) throw new RuntimeException('Site Health report storage is unavailable.');
    $lock = function_exists('update_operation_open_lock') ? update_operation_open_lock($path . '.lock') : null;
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) @fclose($lock);
        throw new RuntimeException('Site Health report storage is busy.');
    }
    try {
        return $callback($path);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function site_health_write_report(array $report): bool
{
    if (!site_health_report_valid($report)) return false;
    return site_health_with_report_lock(static function (string $path) use ($report): bool {
        try {
            $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        } catch (Throwable $error) {
            return false;
        }
        $existing = @lstat($path);
        if (strlen($json) > 2 * 1024 * 1024 || (is_array($existing)
            && ((($existing['mode'] ?? 0) & 0170000) !== 0100000 || ($existing['nlink'] ?? 0) !== 1 || is_link($path)))) return false;
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) return false;
        $ok = @chmod($temporary, 0640);
        $offset = 0;
        while ($ok && $offset < strlen($json)) {
            $written = @fwrite($handle, substr($json, $offset));
            if (!is_int($written) || $written < 1) $ok = false;
            else $offset += $written;
        }
        if ($ok) $ok = @fflush($handle);
        if ($ok && function_exists('fsync')) $ok = @fsync($handle);
        if (!@fclose($handle)) $ok = false;
        if ($ok) $ok = @rename($temporary, $path);
        if (!$ok) @unlink($temporary);
        return $ok;
    });
}

function site_health_read_report(): ?array
{
    try {
        return site_health_with_report_lock(static function (string $path): ?array {
            $json = cms_manifest_read_bounded_regular_file($path, 2 * 1024 * 1024);
            if (!is_string($json)) return null;
            try {
                $report = json_decode($json, true, 48, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                return null;
            }
            return is_array($report) && site_health_report_valid($report) ? $report : null;
        });
    } catch (Throwable $error) {
        return null;
    }
}
