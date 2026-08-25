<?php
declare(strict_types=1);

if (defined('PACKAGE_ARCHIVE_HELPER_LOADED')) return;
define('PACKAGE_ARCHIVE_HELPER_LOADED', true);

const PACKAGE_MAX_BYTES = 50 * 1024 * 1024;
const PACKAGE_MAX_ENTRIES = 5000;
const PACKAGE_MAX_ENTRY_BYTES = 64 * 1024 * 1024;
const PACKAGE_MAX_UNCOMPRESSED_BYTES = 256 * 1024 * 1024;
const PACKAGE_MAX_COMPRESSION_RATIO = 200;

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
function package_archive_extract_files(ZipArchive $zip, array $files, string $directory): bool {
    $root = realpath($directory);
    if ($root === false || is_link($directory)) return false;
    $totalExtracted = 0;
    foreach ($files as $file) {
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
        }
        $flushed = $streamOk && $copied === $expectedBytes && fflush($output)
            && (!function_exists('fsync') || fsync($output));
        fclose($input);
        fclose($output);
        if (!$flushed) return false;
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

/** Exact regular-file tree identity, including paths, modes, hashes, and empty directories. */
function package_tree_identity(string $directory): ?array {
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
            if (!is_array($stat) || $entry->isLink() || (!$entry->isDir() && !$entry->isFile())) return null;
            if ($entry->isDir()) {
                $identity[$relative] = ['type' => 'dir', 'mode' => ($stat['mode'] ?? 0) & 0777];
            } else {
                $hash = hash_file('sha256', $path);
                if (!is_string($hash)) return null;
                $identity[$relative] = ['type' => 'file', 'mode' => ($stat['mode'] ?? 0) & 0777, 'size' => (int)$stat['size'], 'sha256' => $hash];
            }
        }
    } catch (Throwable $error) {
        return null;
    }
    ksort($identity, SORT_STRING);
    return $identity;
}

function package_tree_matches_identity(string $directory, array $identity): bool {
    $current = package_tree_identity($directory);
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

/** Bounded HTTP download streamed directly to an exclusive temporary file. */
function package_download(string $url, string $prefix, string $userAgent, ?callable $progress = null): ?string {
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false) return null;
    $output = @fopen($path, 'wb');
    if (!is_resource($output)) { @unlink($path); return null; }
    $downloaded = 0;
    $ok = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_FAILONERROR => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($output, &$downloaded, &$ok, $progress): int {
                $length = strlen($chunk);
                $downloaded += $length;
                if ($downloaded > PACKAGE_MAX_BYTES || fwrite($output, $chunk) !== $length) return 0;
                if ($progress !== null) $progress($downloaded, 0);
                return $length;
            },
        ]);
        $executed = curl_exec($curl) === true;
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $declared = (int)curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($curl);
        $ok = $executed && $status >= 200 && $status < 300 && $downloaded > 0
            && $downloaded <= PACKAGE_MAX_BYTES && ($declared <= 0 || $declared <= PACKAGE_MAX_BYTES);
    } else {
        $context = stream_context_create(['http' => [
            'timeout' => 120, 'user_agent' => $userAgent, 'follow_location' => 1,
            'max_redirects' => 5, 'ignore_errors' => true,
        ]]);
        $input = @fopen($url, 'rb', false, $context);
        if (is_resource($input)) {
            $status = 0;
            $declared = 0;
            foreach ((array)(stream_get_meta_data($input)['wrapper_data'] ?? []) as $header) {
                if (preg_match('#\AHTTP/\S+\s+(\d{3})#i', (string)$header, $match)) $status = (int)$match[1];
                if (preg_match('/\AContent-Length:\s*(\d+)/i', (string)$header, $match)) $declared = (int)$match[1];
            }
            if ($status >= 200 && $status < 300 && $declared <= PACKAGE_MAX_BYTES) {
                $ok = true;
                while (!feof($input)) {
                    $chunk = fread($input, 1024 * 1024);
                    if ($chunk === false) { $ok = false; break; }
                    if ($chunk === '') continue;
                    $length = strlen($chunk);
                    $downloaded += $length;
                    if ($downloaded > PACKAGE_MAX_BYTES || fwrite($output, $chunk) !== $length) { $ok = false; break; }
                    if ($progress !== null) $progress($downloaded, $declared);
                }
                $ok = $ok && $downloaded > 0;
            }
            fclose($input);
        }
    }
    $ok = fclose($output) && $ok;
    if (!$ok) { @unlink($path); return null; }
    return $path;
}
