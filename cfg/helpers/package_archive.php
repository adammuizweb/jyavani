<?php
declare(strict_types=1);

if (defined('PACKAGE_ARCHIVE_HELPER_LOADED')) return;
define('PACKAGE_ARCHIVE_HELPER_LOADED', true);

const PACKAGE_MAX_BYTES = 50 * 1024 * 1024;
const PACKAGE_MAX_ENTRIES = 5000;
const PACKAGE_MAX_ENTRY_BYTES = 64 * 1024 * 1024;
const PACKAGE_MAX_UNCOMPRESSED_BYTES = 256 * 1024 * 1024;
const PACKAGE_MAX_COMPRESSION_RATIO = 200;
const PACKAGE_DOWNLOAD_DEFAULT_TIMEOUT_SECONDS = 240;
const PACKAGE_DOWNLOAD_DEFAULT_ATTEMPTS = 2;

/** Validate archive structure before any entry is extracted. */
function package_archive_validate(ZipArchive $zip, bool $caseInsensitiveTargets = true): array {
    if ($zip->numFiles <= 0 || $zip->numFiles > PACKAGE_MAX_ENTRIES) {
        return ['success' => false, 'error' => 'Package contains too many entries.', 'entries' => []];
    }

    $entries = [];
    $targets = [];
    $files = [];
    $total = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        $source = is_array($stat) ? (string)($stat['name'] ?? '') : '';
        $directory = str_ends_with($source, '/');
        $logical = rtrim($source, '/');
        $segments = explode('/', $logical);
        $size = max(0, (int)($stat['size'] ?? 0));
        $compressed = max(0, (int)($stat['comp_size'] ?? 0));
        $total += $size;
        $ratio = $compressed > 0 ? $size / $compressed : ($size > 0 ? INF : 1);
        $opsys = 0;
        $attributes = 0;
        $hasAttributes = $zip->getExternalAttributesIndex($index, $opsys, $attributes);
        $type = $hasAttributes ? (($attributes >> 16) & 0170000) : 0;
        $safeType = $type === 0 || (!$directory && $type === 0100000) || ($directory && $type === 0040000);
        $key = $caseInsensitiveTargets ? strtolower($logical) : $logical;

        if ($source === '' || $logical === '' || str_contains($source, "\0") || str_contains($source, '\\')
            || str_starts_with($source, '/') || preg_match('/\A[A-Za-z]:/', $source) === 1
            || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)
            || !$safeType || isset($targets[$key]) || $size > PACKAGE_MAX_ENTRY_BYTES
            || $total > PACKAGE_MAX_UNCOMPRESSED_BYTES
            || ($size > 1024 * 1024 && $ratio > PACKAGE_MAX_COMPRESSION_RATIO)) {
            return ['success' => false, 'error' => 'Package contains an unsafe, duplicate, or oversized entry.', 'entries' => []];
        }
        foreach ($segments as $position => $_segment) {
            if ($position === count($segments) - 1) break;
            $ancestor = implode('/', array_slice($segments, 0, $position + 1));
            $ancestorKey = $caseInsensitiveTargets ? strtolower($ancestor) : $ancestor;
            if (isset($files[$ancestorKey])) {
                return ['success' => false, 'error' => 'Package contains conflicting file targets.', 'entries' => []];
            }
        }
        if (!$directory) {
            foreach (array_keys($targets) as $existingTarget) {
                if (str_starts_with($existingTarget, $key . '/')) {
                    return ['success' => false, 'error' => 'Package contains conflicting file targets.', 'entries' => []];
                }
            }
        }
        $targets[$key] = true;
        if (!$directory) $files[$key] = true;
        $entries[] = ['source' => $source, 'path' => $logical, 'directory' => $directory, 'size' => $size];
    }
    return ['success' => true, 'error' => '', 'entries' => $entries];
}

function package_safe_relative_path(string $relative): bool {
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
        || str_starts_with($relative, '/') || preg_match('/\A[A-Za-z]:/', $relative) === 1) return false;
    $segments = explode('/', $relative);
    return !in_array('', $segments, true) && !in_array('.', $segments, true) && !in_array('..', $segments, true);
}

/** Extract an already validated source-to-relative-file map without extractTo(). */
function package_archive_extract_files(ZipArchive $zip, array $files, string $directory, ?callable $checkpoint = null): bool {
    $root = realpath($directory);
    if ($root === false || is_link($directory)) return false;
    $totalExtracted = 0;
    foreach ($files as $file) {
        if ($checkpoint !== null && $checkpoint() === false) return false;
        $source = is_array($file) && is_string($file['source'] ?? null) ? $file['source'] : '';
        $relative = is_array($file) && is_string($file['relative'] ?? null) ? $file['relative'] : '';
        if ($source === '' || !package_safe_relative_path($relative)) return false;
        $sourceStat = $zip->statName($source);
        $expectedBytes = is_array($sourceStat) ? (int)($sourceStat['size'] ?? -1) : -1;
        if ($expectedBytes < 0 || $expectedBytes > PACKAGE_MAX_ENTRY_BYTES) return false;
        $target = $root . '/' . $relative;
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) return false;
        $parentReal = realpath($parent);
        if ($parentReal === false || ($parentReal !== $root && !str_starts_with($parentReal, $root . DIRECTORY_SEPARATOR))
            || is_link($parent)) return false;
        $input = $zip->getStream($source);
        $output = @fopen($target, 'x+b');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            return false;
        }
        $copied = 0;
        $streamOk = true;
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) { $streamOk = false; break; }
            if ($chunk === '') continue;
            $length = strlen($chunk);
            $copied += $length;
            $totalExtracted += $length;
            if ($copied > $expectedBytes || $copied > PACKAGE_MAX_ENTRY_BYTES
                || $totalExtracted > PACKAGE_MAX_UNCOMPRESSED_BYTES || fwrite($output, $chunk) !== $length) {
                $streamOk = false;
                break;
            }
            if ($checkpoint !== null && $checkpoint() === false) {
                $streamOk = false;
                break;
            }
        }
        $flushed = $streamOk && $copied === $expectedBytes && fflush($output)
            && (!function_exists('fsync') || fsync($output));
        fclose($input);
        fclose($output);
        if (!$flushed) {
            @unlink($target);
            return false;
        }
    }
    return package_tree_identity($root) !== null;
}

function package_private_directory(string $parent, string $label): ?string {
    $parentReal = realpath($parent);
    if ($parentReal === false || is_link($parent) || !is_dir($parent)) return null;
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $directory = $parentReal . '/.' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $label) . '-' . bin2hex(random_bytes(12));
        if (@mkdir($directory, 0700)) return $directory;
    }
    return null;
}

function package_remove_tree(string $path): bool {
    if (is_link($path) || is_file($path)) return @unlink($path) || !file_exists($path);
    if (!is_dir($path)) return true;
    @chmod($path, 0700);
    $ok = true;
    try {
        $prepare = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($prepare as $entry) @chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $removed = $entry->isLink() || !$entry->isDir() ? @unlink($entry->getPathname()) : @rmdir($entry->getPathname());
            if (!$removed && file_exists($entry->getPathname())) $ok = false;
        }
    } catch (Throwable $error) {
        return false;
    }
    return (@rmdir($path) || !is_dir($path)) && $ok;
}

function package_copy_path(string $source, string $destination): bool {
    $stat = @lstat($source);
    if (!is_array($stat) || is_link($source)) return false;
    $type = ($stat['mode'] ?? 0) & 0170000;
    if ($type === 0100000) {
        if (file_exists($destination) || is_link($destination)) return false;
        $parent = dirname($destination);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) return false;
        return @copy($source, $destination) && @chmod($destination, ($stat['mode'] ?? 0644) & 0777);
    }
    if ($type !== 0040000 || !@mkdir($destination, 0700)) return false;
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!package_copy_path($source . '/' . $entry, $destination . '/' . $entry)) return false;
    }
    return @chmod($destination, ($stat['mode'] ?? 0755) & 0777);
}

function package_copy_preserved_paths(string $oldTree, string $stage, array $names): bool {
    foreach ($names as $name) {
        if (!is_string($name) || !package_safe_relative_path($name)) return false;
        $source = $oldTree . '/' . $name;
        if (!file_exists($source) && !is_link($source)) continue;
        if (!package_copy_path($source, $stage . '/' . $name)) return false;
    }
    return true;
}

function package_chmod_tree(string $directory, int $directoryMode = 0755, int $fileMode = 0644): bool {
    if (!is_dir($directory) || is_link($directory) || !@chmod($directory, $directoryMode)) return false;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())
            || !@chmod($entry->getPathname(), $entry->isDir() ? $directoryMode : $fileMode)) return false;
    }
    return true;
}

function package_sync_directory(string $directory): void {
    if (!function_exists('fsync')) return;
    $handle = @fopen($directory, 'rb');
    if (!is_resource($handle)) return;
    @fsync($handle);
    fclose($handle);
}

/** Exact tree identity, including paths, modes, hashes, empty directories, and optionally links. */
function package_tree_identity(string $directory, bool $allowSymlinks = false): ?array {
    if (!is_dir($directory) || is_link($directory)) return null;
    $identity = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen(rtrim($directory, '/')) + 1);
            $stat = @lstat($path);
            if (!is_array($stat)) return null;
            if ($entry->isLink()) {
                if (!$allowSymlinks) return null;
                $target = @readlink($path);
                if (!is_string($target)) return null;
                $identity[$relative] = ['type' => 'link', 'target' => $target];
            } elseif ($entry->isDir()) {
                $identity[$relative] = ['type' => 'dir', 'mode' => ($stat['mode'] ?? 0) & 0777];
            } elseif ($entry->isFile()) {
                $hash = hash_file('sha256', $path);
                if (!is_string($hash)) return null;
                $identity[$relative] = ['type' => 'file', 'mode' => ($stat['mode'] ?? 0) & 0777, 'size' => (int)$stat['size'], 'sha256' => $hash];
            } else {
                return null;
            }
        }
    } catch (Throwable $error) {
        return null;
    }
    ksort($identity, SORT_STRING);
    return $identity;
}

function package_tree_matches_identity(string $directory, array $identity): bool {
    $allowSymlinks = array_filter($identity, static fn(mixed $entry): bool => is_array($entry) && ($entry['type'] ?? '') === 'link') !== [];
    $current = package_tree_identity($directory, $allowSymlinks);
    return is_array($current) && $current === $identity;
}

function package_lifecycle_exclusive_lock_owned(): bool {
    $key = defined('THEME_LIFECYCLE_LOCK_KEY') ? (string)THEME_LIFECYCLE_LOCK_KEY : '0-theme-lifecycle';
    if (function_exists('theme_operation_holds_lock')) return theme_operation_holds_lock($key, LOCK_EX);
    $resourceId = $GLOBALS['_theme_operation_held_keys'][$key] ?? null;
    return is_int($resourceId) && ($GLOBALS['_theme_operation_lock_modes'][$resourceId] ?? null) === LOCK_EX;
}

function package_publication_recovery_prefix(string $target): ?string {
    $parent = realpath(dirname($target));
    $name = basename($target);
    if ($parent === false || $name === '' || in_array($name, ['.', '..'], true)) return null;
    $label = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name), '-');
    if ($label === '') $label = 'tree';
    return $parent . '/.package-publication-recovery-' . substr($label, 0, 48)
        . '-' . substr(hash('sha256', $name), 0, 12) . '-';
}

/** Named residuals are never removed automatically by a later operation. */
function package_publication_recovery_paths(string $target): array {
    $prefix = package_publication_recovery_prefix($target);
    if ($prefix === null) return [];
    $paths = glob($prefix . '*') ?: [];
    sort($paths, SORT_STRING);
    return $paths;
}

function package_unique_publication_recovery_path(string $target, string $role): ?string {
    $prefix = package_publication_recovery_prefix($target);
    if ($prefix === null || preg_match('/\A[a-z]+\z/', $role) !== 1) return null;
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $path = $prefix . bin2hex(random_bytes(12)) . '-' . $role;
        if (!file_exists($path) && !is_link($path)) return $path;
    }
    return null;
}

function package_publication_result(bool $success, string $error = '', ?string $rollbackPath = null, bool $restored = false): array {
    return [
        'success' => $success,
        'error' => $error,
        'rollback_path' => $rollbackPath,
        'restored' => $restored,
        'recovery_paths' => $rollbackPath !== null && (file_exists($rollbackPath) || is_link($rollbackPath)) ? [$rollbackPath] : [],
    ];
}

/**
 * Publish a complete same-parent stage while the caller owns the global exclusive lock.
 * The old tree remains at rollback_path until the caller finalizes or rolls back.
 */
function package_guarded_publish(string $stage, string $target, array $oldIdentity, array $newIdentity): array {
    if (!package_lifecycle_exclusive_lock_owned()) {
        return package_publication_result(false, 'Global lifecycle exclusive lock is not owned by this request.');
    }
    $parent = realpath(dirname($target));
    if ($parent === false || realpath(dirname($stage)) !== $parent
        || dirname($target) !== $parent || dirname($stage) !== $parent) {
        return package_publication_result(false, 'Publication trees or identities are invalid.');
    }
    $residuals = package_publication_recovery_paths($target);
    if ($residuals !== []) {
        return package_publication_result(false, 'A prior package publication recovery path requires manual resolution.', $residuals[0]);
    }
    if (is_link($target) || is_link($stage)
        || !package_tree_matches_identity($target, $oldIdentity)
        || !package_tree_matches_identity($stage, $newIdentity)) {
        return package_publication_result(false, 'Publication trees or identities are invalid.');
    }
    $rollbackPath = package_unique_publication_recovery_path($target, 'old');
    if ($rollbackPath === null || !@rename($target, $rollbackPath)) {
        return package_publication_result(false, 'Unable to preserve the installed tree for publication rollback.');
    }
    if (file_exists($target) || is_link($target)
        || !package_tree_matches_identity($rollbackPath, $oldIdentity)
        || !package_tree_matches_identity($stage, $newIdentity)) {
        $restored = !file_exists($target) && !is_link($target)
            && package_tree_matches_identity($rollbackPath, $oldIdentity)
            && @rename($rollbackPath, $target)
            && package_tree_matches_identity($target, $oldIdentity);
        return package_publication_result(false, 'Publication state changed after preserving the installed tree.', $restored ? null : $rollbackPath, $restored);
    }
    if (!@rename($stage, $target)) {
        $restored = @rename($rollbackPath, $target)
            && package_tree_matches_identity($target, $oldIdentity)
            && package_tree_matches_identity($stage, $newIdentity);
        return package_publication_result(false, 'Unable to publish the staged tree; exact old-tree restoration was attempted.', $restored ? null : $rollbackPath, $restored);
    }
    if (!package_tree_matches_identity($target, $newIdentity)
        || !package_tree_matches_identity($rollbackPath, $oldIdentity)
        || file_exists($stage) || is_link($stage)) {
        $rollback = package_guarded_rollback($target, $rollbackPath, $oldIdentity);
        return package_publication_result(
            false,
            'Published tree verification failed; exact rollback was attempted.',
            $rollback['restored'] ? null : $rollbackPath,
            $rollback['restored']
        );
    }
    return package_publication_result(true, '', $rollbackPath);
}

/** Restore the exact old tree with the same guarded two-rename strategy. */
function package_guarded_rollback(string $target, string $rollbackPath, array $oldIdentity): array {
    $result = ['restored' => false, 'cleanup_complete' => false, 'error' => '', 'recovery_paths' => []];
    if (!package_lifecycle_exclusive_lock_owned()) {
        $result['error'] = 'Global lifecycle exclusive lock is not owned by this request.';
        return $result;
    }
    $parent = realpath(dirname($target));
    $failedIdentity = package_tree_identity($target);
    if ($parent === false || realpath(dirname($rollbackPath)) !== $parent
        || dirname($target) !== $parent || dirname($rollbackPath) !== $parent
        || !is_array($failedIdentity) || is_link($target) || is_link($rollbackPath)
        || !package_tree_matches_identity($rollbackPath, $oldIdentity)) {
        $result['error'] = 'Rollback trees or identities are invalid.';
        $result['recovery_paths'] = package_publication_recovery_paths($target);
        return $result;
    }
    $residuals = array_values(array_filter(
        package_publication_recovery_paths($target),
        static fn(string $path): bool => $path !== $rollbackPath
    ));
    if ($residuals !== []) {
        $result['error'] = 'Another package publication recovery path requires manual resolution.';
        $result['recovery_paths'] = array_merge([$rollbackPath], $residuals);
        return $result;
    }
    $failedPath = package_unique_publication_recovery_path($target, 'failed');
    if ($failedPath === null || !@rename($target, $failedPath)) {
        $result['error'] = 'Unable to preserve the failed tree before rollback.';
        $result['recovery_paths'] = package_publication_recovery_paths($target);
        return $result;
    }
    if (file_exists($target) || is_link($target)
        || !package_tree_matches_identity($failedPath, $failedIdentity)
        || !package_tree_matches_identity($rollbackPath, $oldIdentity)) {
        $failedRestored = false;
        if (!file_exists($target) && !is_link($target) && package_tree_matches_identity($failedPath, $failedIdentity)) {
            $failedRestored = @rename($failedPath, $target)
                && package_tree_matches_identity($target, $failedIdentity)
                && package_tree_matches_identity($rollbackPath, $oldIdentity);
        }
        $result['error'] = $failedRestored
            ? 'Rollback state changed; the exact failed tree was restored and the exact old tree was retained.'
            : 'Rollback state changed and failed-tree restoration could not be verified.';
        $result['recovery_paths'] = package_publication_recovery_paths($target);
        return $result;
    }
    if (!@rename($rollbackPath, $target)) {
        $failedRestored = @rename($failedPath, $target)
            && package_tree_matches_identity($target, $failedIdentity)
            && package_tree_matches_identity($rollbackPath, $oldIdentity);
        $result['error'] = $failedRestored
            ? 'Unable to restore the old tree; the exact failed tree was restored and the exact old tree was retained.'
            : 'Unable to restore the old tree or verify failed-tree restoration.';
        $result['recovery_paths'] = package_publication_recovery_paths($target);
        return $result;
    }
    if (!package_tree_matches_identity($target, $oldIdentity)
        || !package_tree_matches_identity($failedPath, $failedIdentity)
        || file_exists($rollbackPath) || is_link($rollbackPath)) {
        $result['error'] = 'Restored old-tree verification failed.';
        $result['recovery_paths'] = package_publication_recovery_paths($target);
        return $result;
    }
    $result['restored'] = true;
    $result['cleanup_complete'] = package_remove_tree($failedPath);
    if (!$result['cleanup_complete']) {
        $result['error'] = 'The old tree was restored, but failed-tree cleanup requires manual attention.';
        $result['recovery_paths'] = [$failedPath];
    }
    return $result;
}

/** Remove the retained exact old tree only after all post-publication work succeeds. */
function package_guarded_finalize(string $target, string $rollbackPath, array $oldIdentity): bool {
    if (!package_lifecycle_exclusive_lock_owned()
        || !in_array($rollbackPath, package_publication_recovery_paths($target), true)
        || !package_tree_matches_identity($rollbackPath, $oldIdentity)) return false;
    return package_remove_tree($rollbackPath);
}

function package_download_configured_int(string $name, int $default, int $minimum, int $maximum): int {
    $configured = getenv($name);
    $value = is_string($configured) && ctype_digit($configured) ? (int)$configured : $default;
    return max($minimum, min($maximum, $value));
}

function package_download_last_error_code(): string {
    return (string)($GLOBALS['__package_download_last_error_code'] ?? '');
}

function package_download_retryable(string $code): bool {
    if (str_starts_with($code, 'http-')) {
        return in_array((int)substr($code, 5), [408, 425, 429, 500, 502, 503, 504], true);
    }
    if ($code === 'stream-open' || $code === 'stream-timeout' || $code === 'stream-read') return true;
    if (!str_starts_with($code, 'curl-')) return false;
    return in_array((int)substr($code, 5), [5, 6, 7, 16, 18, 28, 35, 52, 55, 56, 92, 95], true);
}

/** Execute one bounded attempt and return a temporary path or safe diagnostic state. */
function package_download_attempt(string $url, string $prefix, string $userAgent, ?callable $progress, bool $followRedirects, int $timeout): array {
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false) return ['path' => null, 'code' => 'temp-create', 'detail' => '', 'cancelled' => false];
    $output = @fopen($path, 'wb');
    if (!is_resource($output)) {
        @unlink($path);
        return ['path' => null, 'code' => 'temp-open', 'detail' => '', 'cancelled' => false];
    }
    $downloaded = 0;
    $ok = false;
    $cancelled = false;
    $code = 'transport';
    $detail = '';
    $curl = null;
    $input = null;
    $closed = false;
    try {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                $code = 'curl-init';
            } else {
                $writeFailure = '';
                $initialScheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                curl_setopt_array($curl, [
                    CURLOPT_FOLLOWLOCATION => $followRedirects,
                    CURLOPT_MAXREDIRS => $followRedirects ? 5 : 0,
                    CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_USERAGENT => $userAgent,
                    CURLOPT_FAILONERROR => false,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => $initialScheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_PROGRESSFUNCTION => static function ($handle, float $total, float $current) use ($progress, &$cancelled): int {
                        if ($progress !== null && $progress((int)$current, (int)$total) === false) {
                            $cancelled = true;
                            return 1;
                        }
                        return 0;
                    },
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($output, &$downloaded, &$writeFailure): int {
                        $length = strlen($chunk);
                        $downloaded += $length;
                        if ($downloaded > PACKAGE_MAX_BYTES) {
                            $writeFailure = 'size-limit';
                            return 0;
                        }
                        if (fwrite($output, $chunk) !== $length) {
                            $writeFailure = 'write-failed';
                            return 0;
                        }
                        return $length;
                    },
                ]);
                $executed = curl_exec($curl) === true;
                $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $declared = (int)curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                $ok = !$cancelled && $executed && $status >= 200 && $status < 300 && $downloaded > 0
                    && $downloaded <= PACKAGE_MAX_BYTES && ($declared <= 0 || $declared <= PACKAGE_MAX_BYTES);
                if (!$ok) {
                    $curlError = curl_errno($curl);
                    $detail = curl_error($curl);
                    if ($cancelled) $code = 'cancelled';
                    elseif ($writeFailure !== '') $code = $writeFailure;
                    elseif ($curlError > 0) $code = 'curl-' . $curlError;
                    elseif ($status < 200 || $status >= 300) $code = 'http-' . $status;
                    elseif ($declared > PACKAGE_MAX_BYTES) $code = 'size-limit';
                    elseif ($downloaded === 0) $code = 'empty-response';
                }
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'user_agent' => $userAgent,
                    'follow_location' => $followRedirects ? 1 : 0,
                    'max_redirects' => $followRedirects ? 5 : 0,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $input = @fopen($url, 'rb', false, $context);
            if (!is_resource($input)) {
                $code = 'stream-open';
            } else {
                $status = 0;
                $declared = 0;
                foreach ((array)(stream_get_meta_data($input)['wrapper_data'] ?? []) as $header) {
                    if (preg_match('#\AHTTP/\S+\s+(\d{3})#i', (string)$header, $match)) $status = (int)$match[1];
                    if (preg_match('/\AContent-Length:\s*(\d+)/i', (string)$header, $match)) $declared = (int)$match[1];
                }
                if ($status < 200 || $status >= 300) {
                    $code = 'http-' . $status;
                } elseif ($declared > PACKAGE_MAX_BYTES) {
                    $code = 'size-limit';
                } else {
                    $ok = true;
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false) {
                            $ok = false;
                            $code = !empty(stream_get_meta_data($input)['timed_out']) ? 'stream-timeout' : 'stream-read';
                            break;
                        }
                        if ($chunk === '') {
                            if (!empty(stream_get_meta_data($input)['timed_out'])) {
                                $ok = false;
                                $code = 'stream-timeout';
                                break;
                            }
                            continue;
                        }
                        $length = strlen($chunk);
                        $downloaded += $length;
                        if ($downloaded > PACKAGE_MAX_BYTES) {
                            $ok = false;
                            $code = 'size-limit';
                            break;
                        }
                        if (fwrite($output, $chunk) !== $length) {
                            $ok = false;
                            $code = 'write-failed';
                            break;
                        }
                        if ($progress !== null && $progress($downloaded, $declared) === false) {
                            $cancelled = true;
                            $ok = false;
                            $code = 'cancelled';
                            break;
                        }
                    }
                    if ($ok && $downloaded === 0) {
                        $ok = false;
                        $code = 'empty-response';
                    }
                }
            }
        }
    } finally {
        if (isset($curl) && is_object($curl)) curl_close($curl);
        if (isset($input) && is_resource($input)) fclose($input);
        $closed = fclose($output);
    }
    if ($ok && $closed) return ['path' => $path, 'code' => '', 'detail' => '', 'cancelled' => false];
    if (!$closed && $code === 'transport') $code = 'write-close';
    @unlink($path);
    return ['path' => null, 'code' => $code, 'detail' => $detail, 'cancelled' => $cancelled];
}

/** Bounded HTTP download with transient retries, streamed to an exclusive temporary file. */
function package_download(string $url, string $prefix, string $userAgent, ?callable $progress = null, bool $followRedirects = true): ?string {
    $GLOBALS['__package_download_last_error_code'] = '';
    $parts = parse_url($url);
    $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
    $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        $GLOBALS['__package_download_last_error_code'] = 'invalid-url';
        return null;
    }

    $timeout = package_download_configured_int('PACKAGE_DOWNLOAD_TIMEOUT_SECONDS', PACKAGE_DOWNLOAD_DEFAULT_TIMEOUT_SECONDS, 30, 600);
    $attempts = package_download_configured_int('PACKAGE_DOWNLOAD_ATTEMPTS', PACKAGE_DOWNLOAD_DEFAULT_ATTEMPTS, 1, 3);
    $deadline = microtime(true) + $timeout;
    $result = ['path' => null, 'code' => 'transport', 'detail' => '', 'cancelled' => false];
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $remaining = (int)ceil($deadline - microtime(true));
        if ($remaining < 1) {
            $result = ['path' => null, 'code' => 'timeout', 'detail' => '', 'cancelled' => false];
            break;
        }
        $result = package_download_attempt($url, $prefix, $userAgent, $progress, $followRedirects, $remaining);
        if (is_string($result['path'])) {
            $GLOBALS['__package_download_last_error_code'] = '';
            return $result['path'];
        }
        if (!empty($result['cancelled']) || !package_download_retryable((string)$result['code']) || $attempt === $attempts) break;
        usleep(250000);
    }

    $code = preg_replace('/[^a-z0-9-]/', '', strtolower((string)$result['code'])) ?: 'transport';
    $GLOBALS['__package_download_last_error_code'] = $code;
    if ($code !== 'cancelled') {
        $detail = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$result['detail']) ?? '');
        error_log('[package-download] ' . $code . ' for ' . $host . ($detail !== '' ? ': ' . substr($detail, 0, 240) : ''));
    }
    return null;
}
