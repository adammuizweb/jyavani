<?php
declare(strict_types=1);

require_once __DIR__ . '/core_integrity.php';

const SITE_HEALTH_SCHEMA = 1;
const SITE_HEALTH_MAX_ENTRIES = 200000;
const SITE_HEALTH_MAX_FINDINGS = 500;
const SITE_HEALTH_SCAN_SECONDS = 30.0;

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

function site_health_scan_extensions(string $projectRoot, string $publicRoot, float $deadline, ?int &$remainingEntries = null): array
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
            $result['items'][] = ['type' => $group['type'], 'folder' => '(root)', 'name' => $logicalRoot, 'version' => '', 'status' => 'unverified', 'reason' => 'extension_root_unreadable', 'files_scanned' => 0, 'store_backed' => false];
            $result['summary']['total']++;
            $result['summary']['unverified']++;
            $result['findings'][] = ['path' => $logicalRoot, 'status' => 'unverified', 'reason' => 'scan_root_unreadable'];
            $result['complete'] = false;
            continue;
        }
        if ((($groupStat['mode'] ?? 0) & 0170000) !== 0040000 || is_link($group['root'])) {
            $logicalRoot = $group['type'] === 'plugin' ? 'plugins' : 'public/views/themes';
            $result['items'][] = ['type' => $group['type'], 'folder' => '(root)', 'name' => $logicalRoot, 'version' => '', 'status' => 'contaminated', 'reason' => 'unsafe_extension_artifact', 'files_scanned' => 0, 'store_backed' => false];
            $result['summary']['total']++;
            $result['summary']['contaminated']++;
            $result['findings'][] = ['path' => $logicalRoot, 'status' => 'contaminated', 'reason' => 'unsafe_extension_root'];
            continue;
        }
        try {
            $entries = new FilesystemIterator($group['root'], FilesystemIterator::SKIP_DOTS);
            foreach ($entries as $entry) {
                if (microtime(true) >= $deadline) {
                    $result['complete'] = false;
                    $result['items'][] = ['type' => $group['type'], 'folder' => '(limit)', 'name' => $group['type'] === 'plugin' ? 'plugins' : 'public/views/themes', 'version' => '', 'status' => 'unverified', 'reason' => 'extension_inventory_incomplete', 'files_scanned' => 0, 'store_backed' => false];
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
                    $result['items'][] = ['type' => $group['type'], 'folder' => $folder, 'name' => $folder, 'version' => '', 'status' => 'contaminated', 'reason' => 'unsafe_extension_artifact', 'files_scanned' => 1, 'store_backed' => false];
                    $result['summary']['total']++;
                    site_health_add_finding($result, $logical, 'contaminated', 'unexpected_file');
                    continue;
                }
                if (!is_array($entryStat) || $entryType !== 0040000 || $entry->isLink()) {
                    $result['items'][] = ['type' => $group['type'], 'folder' => $folder, 'name' => $folder, 'version' => '', 'status' => 'contaminated', 'reason' => 'unsafe_extension_artifact', 'files_scanned' => 0, 'store_backed' => false];
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
                $tree = site_health_inspect_tree($entry->getPathname(), $logical, $deadline, false, $remainingEntries);
                $itemStatus = $tree['summary']['infected'] > 0 ? 'infected'
                    : ($tree['summary']['contaminated'] > 0 ? 'contaminated' : 'unverified');
                $store = is_array($manifestData['store'] ?? null) ? $manifestData['store'] : [];
                $storeUrl = is_string($store['url'] ?? null) ? trim($store['url']) : '';
                $storeSlug = is_string($store['slug'] ?? null) ? trim($store['slug']) : '';
                $storeBacked = $storeUrl !== '' && strtolower((string)parse_url($storeUrl, PHP_URL_SCHEME)) === 'https' && $storeSlug !== '';
                $reason = $manifestData === null ? 'extension_manifest_invalid' : ($storeBacked ? 'store_file_manifest_unavailable' : 'extension_source_unverified');
                $result['items'][] = [
                    'type' => $group['type'],
                    'folder' => $folder,
                    'name' => is_string($manifestData['name'] ?? null) ? substr($manifestData['name'], 0, 200) : $folder,
                    'version' => is_string($manifestData['version'] ?? null) ? substr($manifestData['version'], 0, 64) : '',
                    'status' => $itemStatus,
                    'reason' => $itemStatus === 'contaminated' ? 'unsafe_extension_artifact' : $reason,
                    'files_scanned' => $tree['files_scanned'],
                    'store_backed' => $storeBacked,
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
            $result['items'][] = ['type' => $group['type'], 'folder' => '(root)', 'name' => $logicalRoot, 'version' => '', 'status' => 'unverified', 'reason' => 'extension_inventory_incomplete', 'files_scanned' => 0, 'store_backed' => false];
            $result['summary']['total']++;
            $result['summary']['unverified']++;
            if (count($result['findings']) < SITE_HEALTH_MAX_FINDINGS) $result['findings'][] = ['path' => $logicalRoot, 'status' => 'unverified', 'reason' => 'inventory_incomplete'];
            else $result['findings_truncated'] = true;
        }
    }
    usort($result['items'], static fn(array $left, array $right): int => strcmp($left['type'] . '/' . $left['folder'], $right['type'] . '/' . $right['folder']));
    if ($result['summary']['infected'] > 0) $result['status'] = 'infected';
    elseif ($result['summary']['contaminated'] > 0) $result['status'] = 'contaminated';
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

function site_health_run(string $projectRoot, string $publicRoot, ?callable $coreProvider = null): array
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
            'extensions' => site_health_scan_extensions($projectRoot, $publicRoot, $deadline, $remainingEntries),
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

function site_health_run_and_store(string $projectRoot, string $publicRoot, ?callable $coreProvider = null): array
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
        $report = site_health_run($projectRoot, $publicRoot, $coreProvider);
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
    if (!in_array($extensions['status'] ?? null, ['clean', 'unverified', 'contaminated', 'infected'], true)
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
            || !in_array($item['status'] ?? null, ['unverified', 'contaminated', 'infected'], true)
            || !is_string($item['reason'] ?? null) || !is_int($item['files_scanned'] ?? null)
            || $item['files_scanned'] < 0 || !is_bool($item['store_backed'] ?? null)) return false;
        $itemCounts[$item['status']]++;
    }
    foreach ($itemCounts as $key => $count) if ($extensions['summary'][$key] !== $count) return false;
    $extensionStatus = $extensions['summary']['infected'] > 0 ? 'infected'
        : ($extensions['summary']['contaminated'] > 0 ? 'contaminated'
            : (!$extensions['complete'] || $extensions['summary']['unverified'] > 0 ? 'unverified' : 'clean'));
    if ($extensions['status'] !== $extensionStatus) return false;
    foreach (['unverified', 'contaminated', 'infected'] as $key) {
        if (!is_int($content['summary'][$key] ?? null) || $content['summary'][$key] < 0 || $content['summary'][$key] > SITE_HEALTH_MAX_ENTRIES) return false;
    }
    $contentStatus = $content['summary']['infected'] > 0 ? 'infected'
        : ($content['summary']['contaminated'] > 0 ? 'contaminated'
            : (!$content['complete'] || $content['summary']['unverified'] > 0 ? 'unverified' : 'scanned'));
    if ($content['status'] !== $contentStatus) return false;
    foreach ([$extensions, $content] as $component) {
        foreach ($component['findings'] as $finding) {
            if (!is_array($finding) || !is_string($finding['path'] ?? null)
                || cms_manifest_safe_relative_path($finding['path']) !== $finding['path']
                || !in_array($finding['status'] ?? null, ['unverified', 'contaminated', 'infected'], true)
                || !is_string($finding['reason'] ?? null) || strlen($finding['reason']) > 64) return false;
        }
        if ($component['findings_truncated'] && count($component['findings']) !== SITE_HEALTH_MAX_FINDINGS) return false;
    }
    $extensionRank = ['clean' => 0, 'unverified' => 1, 'contaminated' => 2, 'infected' => 3];
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
