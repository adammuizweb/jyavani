<?php
declare(strict_types=1);

require_once __DIR__ . '/cms_manifest.php';
require_once __DIR__ . '/update_metadata_http.php';

const CORE_INTEGRITY_SCHEMA = 1;
const CORE_INTEGRITY_MAX_FINDINGS = 500;
const CORE_INTEGRITY_BASELINE_URL = 'https://jyavani.com/download/latest/?format=json&version=';
const CORE_INTEGRITY_MAX_FILE_BYTES = 64 * 1024 * 1024;
const CORE_INTEGRITY_MAX_TOTAL_BYTES = 512 * 1024 * 1024;
const CORE_INTEGRITY_MAX_INVENTORY_ENTRIES = 200000;
const CORE_INTEGRITY_SCAN_SECONDS = 20.0;

function core_integrity_local_version(string $projectRoot): string
{
    $path = rtrim($projectRoot, '/\\') . '/version.json';
    $raw = cms_manifest_read_bounded_regular_file($path, 65536);
    if (!is_string($raw)) return '';
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return '';
    }
    return is_array($data) && is_string($data['version'] ?? null) ? trim($data['version']) : '';
}

function core_integrity_resolve_baseline(string $projectRoot, ?callable $provider = null): array
{
    $version = core_integrity_local_version($projectRoot);
    $url = CORE_INTEGRITY_BASELINE_URL . rawurlencode($version);
    $remote = null;
    try {
        $remote = $provider !== null
            ? $provider($url, $version)
            : update_metadata_fetch_json($url, 'JyavaniCMS-SiteHealth/' . ($version ?: 'unknown'), microtime(true) + 4.0);
        if (is_array($remote)) {
            $remote = cms_manifest_validate($remote, 'canonical Core manifest');
            if ($version !== '' && hash_equals($version, (string)$remote['version'])) {
                return ['manifest' => $remote, 'trusted' => true, 'source' => 'canonical_https', 'reason' => null];
            }
        }
    } catch (Throwable $error) {
        $remote = null;
    }

    $path = rtrim($projectRoot, '/\\') . '/tools/cms-manifest.json';
    try {
        $raw = cms_manifest_read_bounded_regular_file($path, 4 * 1024 * 1024);
        $local = is_string($raw) ? cms_manifest_decode($raw, 'installed Core manifest', true) : null;
        if (is_array($local) && $version !== '' && hash_equals($version, (string)$local['version'])) {
            $local['files'] = array_filter(
                $local['files'],
                static fn(string $hash, string $logical): bool => !cms_manifest_is_preserved($logical),
                ARRAY_FILTER_USE_BOTH
            );
            $local['total_files'] = count($local['files']);
            return [
                'manifest' => $local,
                'trusted' => false,
                'source' => 'installed_local',
                'reason' => is_array($remote) ? 'canonical_version_mismatch' : 'canonical_unavailable',
            ];
        }
    } catch (Throwable $error) {
        // The normalized error below intentionally avoids exposing paths or parser details.
    }
    return ['manifest' => null, 'trusted' => false, 'source' => 'none', 'reason' => 'baseline_unavailable'];
}

function core_integrity_hash_regular_file(string $path, int $maxBytes = CORE_INTEGRITY_MAX_FILE_BYTES, ?float $deadline = null): array
{
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (!is_array($before)) return ['status' => 'missing', 'hash' => null, 'size' => 0];
    if ((($before['mode'] ?? 0) & 0170000) !== 0100000 || is_link($path) || ($before['nlink'] ?? 0) !== 1) {
        return ['status' => 'unsafe', 'hash' => null, 'size' => 0];
    }
    $size = (int)($before['size'] ?? -1);
    if ($size < 0 || $size > $maxBytes) return ['status' => 'too_large', 'hash' => null, 'size' => max(0, $size)];
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return ['status' => 'unreadable', 'hash' => null, 'size' => max(0, $size)];
    $descriptor = @fstat($handle);
    clearstatcache(true, $path);
    $opened = @lstat($path);
    $same = is_array($descriptor) && is_array($opened)
        && (($descriptor['mode'] ?? 0) & 0170000) === 0100000
        && ($descriptor['nlink'] ?? 0) === 1
        && ($before['dev'] ?? null) === ($descriptor['dev'] ?? null)
        && ($before['ino'] ?? null) === ($descriptor['ino'] ?? null)
        && ($descriptor['dev'] ?? null) === ($opened['dev'] ?? null)
        && ($descriptor['ino'] ?? null) === ($opened['ino'] ?? null)
        && !is_link($path);
    if (!$same) {
        @fclose($handle);
        return ['status' => 'unsafe', 'hash' => null, 'size' => max(0, $size)];
    }
    $size = (int)($descriptor['size'] ?? -1);
    if ($size < 0 || $size > $maxBytes) {
        @fclose($handle);
        return ['status' => 'too_large', 'hash' => null, 'size' => max(0, $size)];
    }
    $context = hash_init('sha256');
    $hashed = 0;
    while ($hashed < $size) {
        if ($deadline !== null && microtime(true) >= $deadline) {
            @fclose($handle);
            return ['status' => 'limit', 'hash' => null, 'size' => $size];
        }
        $chunk = @fread($handle, min(1024 * 1024, $size - $hashed));
        if (!is_string($chunk) || $chunk === '') {
            @fclose($handle);
            return ['status' => 'unreadable', 'hash' => null, 'size' => $size];
        }
        hash_update($context, $chunk);
        $hashed += strlen($chunk);
    }
    $afterDescriptor = @fstat($handle);
    @fclose($handle);
    clearstatcache(true, $path);
    $after = @lstat($path);
    $stable = is_array($afterDescriptor) && is_array($after)
        && ($descriptor['dev'] ?? null) === ($after['dev'] ?? null)
        && ($descriptor['ino'] ?? null) === ($after['ino'] ?? null)
        && ($descriptor['size'] ?? null) === ($afterDescriptor['size'] ?? null)
        && ($descriptor['mtime'] ?? null) === ($afterDescriptor['mtime'] ?? null)
        && !is_link($path);
    return $stable
        ? ['status' => 'ok', 'hash' => hash_final($context), 'size' => max(0, $size)]
        : ['status' => 'unreadable', 'hash' => null, 'size' => max(0, $size)];
}

function core_integrity_add_finding(array &$report, string $path, string $status, string $reason, ?string $expected = null, ?string $observed = null): void
{
    $report['summary'][$status]++;
    if (count($report['findings']) >= CORE_INTEGRITY_MAX_FINDINGS) {
        $report['findings_truncated'] = true;
        return;
    }
    $finding = ['path' => $path, 'status' => $status, 'reason' => $reason];
    if ($expected !== null) $finding['expected_sha256'] = $expected;
    if ($observed !== null) $finding['observed_sha256'] = $observed;
    $report['findings'][] = $finding;
}

function core_integrity_inventory_root(array &$report, string $physicalRoot, string $logicalPrefix, array $expected, ?float $deadline = null, array $siteFiles = [], ?int &$hashedBytes = null): void
{
    if ($hashedBytes === null) $hashedBytes = 0;
    clearstatcache(true, $physicalRoot);
    $rootStat = @lstat($physicalRoot);
    if (!is_array($rootStat)) return;
    if ((($rootStat['mode'] ?? 0) & 0170000) !== 0040000 || is_link($physicalRoot) || !is_readable($physicalRoot)) {
        core_integrity_add_finding($report, $logicalPrefix, 'unverified', 'inventory_unreadable');
        $report['complete'] = false;
        return;
    }
    try {
        $directory = new RecursiveDirectoryIterator($physicalRoot, FilesystemIterator::SKIP_DOTS);
        $prefixLength = strlen(rtrim($physicalRoot, '/\\')) + 1;
        $filtered = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $entry) use ($prefixLength, $logicalPrefix): bool {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), $prefixLength));
            $logical = $logicalPrefix === '' ? $relative : $logicalPrefix . '/' . $relative;
            return !cms_manifest_is_preserved($logical)
                && !(!$entry->isLink() && $entry->isDir() && cms_manifest_should_prune_directory($logical));
        });
        $iterator = new RecursiveIteratorIterator(
            $filtered,
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (Throwable $error) {
        core_integrity_add_finding($report, $logicalPrefix, 'unverified', 'inventory_unreadable');
        $report['complete'] = false;
        return;
    }
    try {
        foreach ($iterator as $entry) {
            $report['_inventory_entries'] = (int)($report['_inventory_entries'] ?? 0) + 1;
            if ($report['_inventory_entries'] > CORE_INTEGRITY_MAX_INVENTORY_ENTRIES
                || ($deadline !== null && microtime(true) >= $deadline)) {
                core_integrity_add_finding($report, $logicalPrefix, 'unverified', 'inventory_limit_reached');
                $report['complete'] = false;
                return;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), $prefixLength));
            $logical = $logicalPrefix === '' ? $relative : $logicalPrefix . '/' . $relative;
            if (!cms_manifest_is_managed_candidate($logical) || isset($expected[$logical])) continue;
            clearstatcache(true, $entry->getPathname());
            $stat = @lstat($entry->getPathname());
            if (!is_array($stat)) {
                core_integrity_add_finding($report, $logical, 'unverified', 'inventory_unreadable');
                $report['complete'] = false;
                continue;
            }
            $type = ($stat['mode'] ?? 0) & 0170000;
            if ($type === 0040000) continue;
            if ($type === 0100000 && isset($siteFiles[$logical])) {
                $remainingBytes = CORE_INTEGRITY_MAX_TOTAL_BYTES - $hashedBytes;
                if ($remainingBytes <= 0) {
                    core_integrity_add_finding($report, $logical, 'unverified', 'site_file_scan_limit');
                    $report['complete'] = false;
                    continue;
                }
                $observed = core_integrity_hash_regular_file($entry->getPathname(), min(CORE_INTEGRITY_MAX_FILE_BYTES, $remainingBytes), $deadline);
                $hashedBytes += (int)($observed['size'] ?? 0);
                if ($observed['status'] === 'ok' && hash_equals($siteFiles[$logical], (string)$observed['hash'])) continue;
                if ($observed['status'] !== 'ok') {
                    $reason = in_array($observed['status'], ['too_large', 'limit'], true) ? 'site_file_scan_limit' : 'site_file_unreadable';
                    core_integrity_add_finding($report, $logical, 'unverified', $reason);
                    $report['complete'] = false;
                    continue;
                }
                core_integrity_add_finding($report, $logical, 'contaminated', 'site_file_hash_mismatch', $siteFiles[$logical], (string)$observed['hash']);
                continue;
            }
            core_integrity_add_finding(
                $report,
                $logical,
                'contaminated',
                $type === 0120000 ? 'unexpected_symlink' : ($type === 0100000 ? 'unexpected_file' : 'unexpected_special_file')
            );
        }
    } catch (Throwable $error) {
        core_integrity_add_finding($report, $logicalPrefix, 'unverified', 'inventory_incomplete');
        $report['complete'] = false;
    }
}

function core_integrity_scan(array $baseline, string $projectRoot, string $publicRoot): array
{
    $started = microtime(true);
    $deadline = $started + CORE_INTEGRITY_SCAN_SECONDS;
    $manifest = cms_manifest_validate($baseline['manifest'], 'Core integrity baseline');
    $trusted = ($baseline['trusted'] ?? false) === true;
    $report = [
        'schema' => CORE_INTEGRITY_SCHEMA,
        'status' => $trusted ? 'clean' : 'unverified',
        'complete' => true,
        'baseline' => [
            'version' => (string)$manifest['version'],
            'source' => (string)($baseline['source'] ?? 'unknown'),
            'trusted' => $trusted,
            'reason' => is_string($baseline['reason'] ?? null) ? $baseline['reason'] : null,
            'total_files' => count($manifest['files']),
        ],
        'started_at' => time(),
        'completed_at' => 0,
        'duration_ms' => 0,
        'summary' => ['expected' => count($manifest['files']), 'clean' => 0, 'unverified' => 0, 'modified' => 0, 'contaminated' => 0, 'infected' => 0],
        'findings' => [],
        'findings_truncated' => false,
    ];

    $hashedBytes = 0;
    foreach ($manifest['files'] as $logical => $expectedHash) {
        if (microtime(true) >= $deadline || $hashedBytes >= CORE_INTEGRITY_MAX_TOTAL_BYTES) {
            core_integrity_add_finding($report, $logical, 'unverified', 'scan_limit_reached', $expectedHash);
            $report['complete'] = false;
            break;
        }
        $target = cms_manifest_target_path($logical, $projectRoot, $publicRoot);
        if ($target === null) {
            core_integrity_add_finding($report, $logical, 'contaminated', 'unsafe_path', $expectedHash);
            continue;
        }
        $result = core_integrity_hash_regular_file($target, min(CORE_INTEGRITY_MAX_FILE_BYTES, CORE_INTEGRITY_MAX_TOTAL_BYTES - $hashedBytes), $deadline);
        $hashedBytes += (int)($result['size'] ?? 0);
        if ($result['status'] === 'ok') {
            if (hash_equals($expectedHash, (string)$result['hash'])) {
                $report['summary']['clean']++;
            } else {
                core_integrity_add_finding($report, $logical, 'modified', 'hash_mismatch', $expectedHash, (string)$result['hash']);
            }
        } elseif ($result['status'] === 'missing') {
            core_integrity_add_finding($report, $logical, 'modified', 'missing_file', $expectedHash);
        } elseif ($result['status'] === 'unsafe') {
            core_integrity_add_finding($report, $logical, 'contaminated', 'unsafe_file_type', $expectedHash);
        } else {
            $reason = $result['status'] === 'too_large' ? 'file_size_limit' : ($result['status'] === 'limit' ? 'scan_limit_reached' : 'file_unreadable');
            core_integrity_add_finding($report, $logical, 'unverified', $reason, $expectedHash);
            $report['complete'] = false;
        }
    }

    $expected = array_fill_keys(array_keys($manifest['files']), true);
    $siteManifest = cms_manifest_site_files($projectRoot);
    $siteFiles = array_diff_key($siteManifest['files'], $expected);
    if ($siteManifest['reason'] !== null) {
        core_integrity_add_finding($report, 'cfg/site-files.json', 'unverified', $siteManifest['reason']);
        $report['complete'] = false;
    }
    foreach (array_diff(cms_manifest_allowed_directories(), ['public']) as $directory) {
        core_integrity_inventory_root($report, rtrim($projectRoot, '/\\') . '/' . $directory, $directory, $expected, $deadline, $siteFiles, $hashedBytes);
    }
    core_integrity_inventory_root($report, $publicRoot, 'public', $expected, $deadline, $siteFiles, $hashedBytes);
    unset($report['_inventory_entries']);
    usort($report['findings'], static fn(array $left, array $right): int => strcmp((string)$left['path'], (string)$right['path']));

    if ($report['summary']['infected'] > 0) $report['status'] = 'infected';
    elseif (!$trusted) $report['status'] = 'unverified';
    elseif ($report['summary']['contaminated'] > 0) $report['status'] = 'contaminated';
    elseif ($trusted && $report['summary']['modified'] > 0) $report['status'] = 'modified';
    elseif (!$report['complete'] || $report['summary']['unverified'] > 0) $report['status'] = 'unverified';
    else $report['status'] = 'clean';
    $report['completed_at'] = time();
    $report['duration_ms'] = max(0, (int)round((microtime(true) - $started) * 1000));
    return $report;
}

function core_integrity_run(string $projectRoot, string $publicRoot, ?callable $provider = null): array
{
    $baseline = core_integrity_resolve_baseline($projectRoot, $provider);
    if (!is_array($baseline['manifest'] ?? null)) {
        $now = time();
        return [
            'schema' => CORE_INTEGRITY_SCHEMA,
            'status' => 'unverified',
            'complete' => false,
            'baseline' => ['version' => core_integrity_local_version($projectRoot), 'source' => 'none', 'trusted' => false, 'reason' => $baseline['reason'], 'total_files' => 0],
            'started_at' => $now,
            'completed_at' => $now,
            'duration_ms' => 0,
            'summary' => ['expected' => 0, 'clean' => 0, 'unverified' => 1, 'modified' => 0, 'contaminated' => 0, 'infected' => 0],
            'findings' => [['path' => 'tools/cms-manifest.json', 'status' => 'unverified', 'reason' => 'baseline_unavailable']],
            'findings_truncated' => false,
        ];
    }

    $locks = [];
    $alreadyLocked = function_exists('theme_operation_holds_lock')
        && defined('THEME_LIFECYCLE_LOCK_KEY')
        && theme_operation_holds_lock((string)THEME_LIFECYCLE_LOCK_KEY);
    try {
        if (!$alreadyLocked && function_exists('theme_operation_acquire') && defined('THEME_LIFECYCLE_LOCK_KEY')) {
            $locks = theme_operation_acquire([(string)THEME_LIFECYCLE_LOCK_KEY], LOCK_SH, microtime(true) + 2.0);
        }
        return core_integrity_scan($baseline, $projectRoot, $publicRoot);
    } finally {
        if ($locks !== [] && function_exists('theme_operation_release')) theme_operation_release($locks);
    }
}

function core_integrity_report_path(): string
{
    return defined('BACKEND_PATH') ? rtrim((string)BACKEND_PATH, '/\\') . '/var/site-health-core-integrity.json' : '';
}

function core_integrity_report_valid(array $report): bool
{
    if (($report['schema'] ?? null) !== CORE_INTEGRITY_SCHEMA
        || !in_array($report['status'] ?? null, ['clean', 'unverified', 'modified', 'contaminated', 'infected'], true)
        || !is_bool($report['complete'] ?? null) || !is_bool($report['findings_truncated'] ?? null)
        || !is_array($report['baseline'] ?? null) || !is_array($report['summary'] ?? null)
        || !is_array($report['findings'] ?? null) || count($report['findings']) > CORE_INTEGRITY_MAX_FINDINGS
        || !is_int($report['started_at'] ?? null) || !is_int($report['completed_at'] ?? null)
        || !is_int($report['duration_ms'] ?? null) || $report['started_at'] < 0
        || $report['completed_at'] < $report['started_at'] || $report['duration_ms'] < 0) return false;

    $baseline = $report['baseline'];
    if (!is_string($baseline['version'] ?? null) || strlen($baseline['version']) > 64
        || !in_array($baseline['source'] ?? null, ['canonical_https', 'installed_local', 'none'], true)
        || !is_bool($baseline['trusted'] ?? null)
        || (!is_null($baseline['reason'] ?? null) && !is_string($baseline['reason']))
        || !is_int($baseline['total_files'] ?? null) || $baseline['total_files'] < 0 || $baseline['total_files'] > 100000) return false;
    if (($baseline['source'] === 'canonical_https') !== $baseline['trusted']) return false;
    if ($baseline['trusted'] && $baseline['reason'] !== null) return false;
    if (!$baseline['trusted'] && (!is_string($baseline['reason']) || $baseline['reason'] === '')) return false;
    if ($baseline['source'] === 'none' && $baseline['total_files'] !== 0) return false;
    if ($baseline['source'] !== 'none' && ($baseline['version'] === '' || $baseline['total_files'] < 1)) return false;

    foreach (['expected', 'clean', 'unverified', 'modified', 'contaminated', 'infected'] as $key) {
        if (!is_int($report['summary'][$key] ?? null) || $report['summary'][$key] < 0 || $report['summary'][$key] > 200000) return false;
    }
    if ($report['summary']['expected'] !== $baseline['total_files'] || $report['summary']['clean'] > $report['summary']['expected']) return false;
    if ($report['complete'] && $report['summary']['unverified'] !== 0) return false;
    $findingCounts = ['unverified' => 0, 'modified' => 0, 'contaminated' => 0, 'infected' => 0];
    foreach ($report['findings'] as $finding) {
        if (!is_array($finding) || !is_string($finding['path'] ?? null)
            || cms_manifest_safe_relative_path($finding['path']) !== $finding['path']
            || !in_array($finding['status'] ?? null, ['unverified', 'modified', 'contaminated', 'infected'], true)
            || !is_string($finding['reason'] ?? null) || strlen($finding['reason']) > 64) return false;
        foreach (['expected_sha256', 'observed_sha256'] as $hashKey) {
            if (isset($finding[$hashKey]) && (!is_string($finding[$hashKey]) || preg_match('/\A[a-f0-9]{64}\z/D', $finding[$hashKey]) !== 1)) return false;
        }
        $findingCounts[$finding['status']]++;
    }
    foreach ($findingCounts as $key => $count) {
        if (($report['findings_truncated'] && $report['summary'][$key] < $count)
            || (!$report['findings_truncated'] && $report['summary'][$key] !== $count)) return false;
    }
    if ($report['findings_truncated'] && count($report['findings']) !== CORE_INTEGRITY_MAX_FINDINGS) return false;
    $expectedStatus = $report['summary']['infected'] > 0 ? 'infected'
        : (!$baseline['trusted'] ? 'unverified'
            : ($report['summary']['contaminated'] > 0 ? 'contaminated'
                : ($report['summary']['modified'] > 0 ? 'modified'
                    : (!$report['complete'] || $report['summary']['unverified'] > 0 ? 'unverified' : 'clean'))));
    if ($report['status'] !== $expectedStatus) return false;
    return $report['status'] !== 'clean'
        || ($report['complete'] && $baseline['trusted'] && $report['summary']['clean'] === $report['summary']['expected']);
}

function core_integrity_with_report_lock(callable $callback): mixed
{
    $path = core_integrity_report_path();
    if ($path === '') throw new RuntimeException('Site Health report storage is unavailable.');
    $directory = dirname($path);
    if ((!is_dir($directory) && !@mkdir($directory, 0750, true)) || is_link($directory)) {
        throw new RuntimeException('Site Health report storage is unavailable.');
    }
    $stat = @lstat($directory);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000 || (($stat['mode'] ?? 0) & 0002) !== 0) {
        throw new RuntimeException('Site Health report storage is unavailable.');
    }
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

function core_integrity_write_report(array $report): bool
{
    if (!core_integrity_report_valid($report)) return false;
    return core_integrity_with_report_lock(static function (string $path) use ($report): bool {
        $existing = @lstat($path);
        if (is_array($existing) && ((($existing['mode'] ?? 0) & 0170000) !== 0100000 || is_link($path) || ($existing['nlink'] ?? 0) !== 1)) return false;
        try {
            $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        } catch (Throwable $error) {
            return false;
        }
        if (strlen($json) > 1024 * 1024) return false;
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
        if ($ok) $ok = @rename($temporary, $path) && @chmod($path, 0640);
        if (!$ok) @unlink($temporary);
        return $ok;
    });
}

function core_integrity_read_report(): ?array
{
    try {
        return core_integrity_with_report_lock(static function (string $path): ?array {
            $stat = @lstat($path);
            if (!is_array($stat)) return null;
            if ((($stat['mode'] ?? 0) & 0170000) !== 0100000 || is_link($path) || ($stat['nlink'] ?? 0) !== 1
                || ($stat['size'] ?? 0) < 2 || ($stat['size'] ?? 0) > 1024 * 1024) return null;
            $json = cms_manifest_read_bounded_regular_file($path, 1024 * 1024);
            if (!is_string($json)) return null;
            try {
                $report = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                return null;
            }
            return is_array($report) && core_integrity_report_valid($report) ? $report : null;
        });
    } catch (Throwable $error) {
        return null;
    }
}
