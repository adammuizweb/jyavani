<?php
declare(strict_types=1);
// Shared helpers for CMS update page + AJAX handlers

// --- Progress tracking ---
function _cms_progress_file(string $token): string {
    return dirname(DASH_PATH) . '/cfg/var/cms-progress-' . $token . '.json';
}

function _cms_write_progress(string $token, int $pct, string $status, bool $done = false, ?string $error = null): void {
    try {
        $json = json_encode([
            'percentage' => $pct,
            'status' => $status,
            'done' => $done,
            'error' => $error,
        ], JSON_THROW_ON_ERROR);
        @file_put_contents(_cms_progress_file($token), $json, LOCK_EX);
    } catch (JsonException $ignored) {
    }
}

function _cms_read_progress(string $token): ?array {
    $f = _cms_progress_file($token);
    if (!is_file($f)) return null;
    try {
        $d = json_decode((string)file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
        return is_array($d) ? $d : null;
    } catch (JsonException $error) {
        return null;
    }
}

function _cms_clear_progress(string $token): void {
    $f = _cms_progress_file($token);
    if (is_file($f)) @unlink($f);
}

function _cms_update_failure(string $token, string $message): array {
    if ($token !== '') {
        _cms_write_progress($token, 0, __('Update failed.'), true, $message);
    }
    return ['success' => false, 'message' => $message];
}

function _cms_is_preserved(string $path, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $path)) return true;
    }
    return false;
}

function _cms_track_target(string $logicalPath, string $targetPath, string $backupDir, array &$backupFiles, array &$createdFiles): bool {
    if (isset($backupFiles[$logicalPath]) || isset($createdFiles[$logicalPath])) return true;
    if (!file_exists($targetPath) && !is_link($targetPath)) {
        $createdFiles[$logicalPath] = $targetPath;
        return true;
    }
    if (!is_file($targetPath) || is_link($targetPath)) return false;

    $backupPath = $backupDir . '/' . $logicalPath;
    $backupParent = dirname($backupPath);
    if (!is_dir($backupParent) && !@mkdir($backupParent, 0755, true)) return false;
    if (!@copy($targetPath, $backupPath)) return false;
    $backupFiles[$logicalPath] = ['target' => $targetPath, 'backup' => $backupPath];
    return true;
}

function _cms_rollback_files(array $backupFiles, array $createdFiles, string $projectRoot): array {
    $errors = [];
    foreach ($backupFiles as $logicalPath => $record) {
        $targetPath = (string)($record['target'] ?? '');
        $backupPath = (string)($record['backup'] ?? '');
        if (_cms_target_path((string)$logicalPath, $projectRoot) !== $targetPath) {
            $errors[] = (string)$logicalPath;
            continue;
        }
        $targetParent = dirname($targetPath);
        if (!is_dir($targetParent)) @mkdir($targetParent, 0755, true);
        if (!is_file($backupPath) || !@copy($backupPath, $targetPath)) $errors[] = $targetPath;
    }
    foreach ($createdFiles as $logicalPath => $createdFile) {
        if (_cms_target_path((string)$logicalPath, $projectRoot) !== $createdFile) {
            $errors[] = (string)$logicalPath;
            continue;
        }
        if (is_file($createdFile) && !@unlink($createdFile)) $errors[] = $createdFile;
    }
    return $errors;
}

function _cms_new_backup_dir(string $projectRoot): string {
    $base = $projectRoot . '/cfg/var/backup-' . date('Ymd-His');
    if (!file_exists($base)) return $base;
    do {
        $candidate = $base . '-' . bin2hex(random_bytes(3));
    } while (file_exists($candidate));
    return $candidate;
}

// --- Helper: get preserve regex patterns ---
function _get_preserve_patterns(): array {
    return [
        '#^cfg/\.env$#',
        '#^cfg/var/#',
        '#^cfg/session_debug\.log$#',
        '#^cfg/php-noteloc\.ini$#',
        '#^cfg/site-router\.php$#',
        '#^cfg/community-i18n\.php$#',
        '#^private_files/#',
        '#^public/static/img/\d{4}/#',
        '#^public/static/files/#',
        '#^public/static/plugins/#',
        '#^public/sitemaps/#',
        '#^public/pdf/#',
        '#^public/views/themes/(?!default(?:/|$))[^/]+/.+#',
        '#^plugins/[^/]+/.+#',
        '#^plugin-store/#',
        '#^theme-store/#',
        '#^app/controllers/DownloadController\.php$#',
        '#^dashboard/admin/community/#',
        '#^public/download/#',
        '#^public/static/community/#',
        '#^public/views/community/#',
        '#^public/views/member/#',
        '#^schema/community\.sql$#',
        '#^schema/migrations/008-dev-status-varchar\.sql$#',
        '#^tools/import_core_demo_multilingual\.php$#',
        '#^tools/localize_community\.php$#',
        '#^tools/data/#',
        '#node_modules/#',
        '#\.git/#',
        '#^\.gitignore$#',
    ];
}

// --- Helper: load local manifest ---
function _get_local_manifest(): ?array {
    $f = dirname(DASH_PATH) . '/tools/cms-manifest.json';
    if (!is_file($f)) return null;
    return _cms_decode_json_array((string)file_get_contents($f), 'tools/cms-manifest.json');
}

function _cms_decode_json_array(string $json, string $label): array {
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Invalid ' . $label . ': ' . $error->getMessage(), 0, $error);
    }
    if (!is_array($decoded)) throw new RuntimeException('Invalid ' . $label . ': expected a JSON object.');
    return $decoded;
}

function _cms_safe_relative_path(string $path): ?string {
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) return null;
    $segments = explode('/', rtrim($path, '/'));
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') return null;
    }
    return implode('/', $segments);
}

function _cms_target_path(string $logicalPath, string $projectRoot): ?string {
    $safe = _cms_safe_relative_path($logicalPath);
    if ($safe === null || $safe !== $logicalPath) return null;

    if ($logicalPath === 'public' || str_starts_with($logicalPath, 'public/')) {
        $root = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : $projectRoot . '/public';
        $relative = $logicalPath === 'public' ? '' : substr($logicalPath, 7);
    } else {
        $root = $projectRoot;
        $relative = $logicalPath;
    }

    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal)) return null;
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

function _cms_zip_entry_is_symlink(ZipArchive $zip, int $index): bool {
    $opsys = 0;
    $attributes = 0;
    if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) return false;
    return (($attributes >> 16) & 0170000) === 0120000;
}

// --- Helper: apply update from zip ---
function _apply_cms_update_from_zip(string $zipPath, array $remoteManifest, string $currentVer, string $progressToken = ''): array {
    $projectRoot = realpath(dirname(DASH_PATH)) ?: dirname(DASH_PATH);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return _cms_update_failure($progressToken, __('Failed to open ZIP package.'));
    }

    $totalFiles = $zip->numFiles;
    if ($totalFiles === 0) {
        $zip->close();
        return _cms_update_failure($progressToken, __('Update package is empty.'));
    }

    $remoteFiles = is_array($remoteManifest['files'] ?? null) ? $remoteManifest['files'] : null;
    $preservePatterns = _get_preserve_patterns();
    if ($remoteFiles === null || $remoteFiles === []) {
        $zip->close();
        return _cms_update_failure($progressToken, __('Update package manifest is missing or invalid.'));
    }

    foreach ($remoteFiles as $filename => $expectedHash) {
        if (!is_string($filename) || _cms_safe_relative_path($filename) !== $filename
            || $filename === 'tools/cms-manifest.json'
            || !is_string($expectedHash) || $expectedHash !== strtolower(trim($expectedHash))
            || !preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Update package manifest contains an invalid path or hash.'));
        }
        $remoteFiles[$filename] = strtolower(trim($expectedHash));
    }

    // Validate every archive entry and every managed hash before mutating disk.
    $seenEntries = [];
    $verifiedFiles = [];
    for ($i = 0; $i < $totalFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false) continue;
        if (_cms_zip_entry_is_symlink($zip, $i)) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Update package contains a symbolic link.'));
        }
        $isDirectory = str_ends_with($entry, '/');
        $filename = _cms_safe_relative_path($entry);
        if ($filename === null) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Update package contains an invalid file path.'));
        }
        if ($isDirectory) continue;
        if (isset($seenEntries[$filename])) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Update package contains duplicate files.'));
        }
        $seenEntries[$filename] = true;

        if (!isset($remoteFiles[$filename])) continue;
        $contents = $zip->getFromIndex($i);
        if ($contents === false || !hash_equals($remoteFiles[$filename], hash('sha256', $contents))) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Update package integrity verification failed:') . ' ' . $filename);
        }
        $verifiedFiles[$filename] = $i;
    }

    foreach ($remoteFiles as $filename => $_expectedHash) {
        if (!isset($verifiedFiles[$filename])) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Update package is missing:') . ' ' . $filename);
        }
    }

    $packageVersion = null;
    $zipVersionJson = $zip->getFromName('version.json');
    if ($zipVersionJson !== false) {
        try {
            $packageVersion = _cms_decode_json_array($zipVersionJson, 'package version.json');
        } catch (Throwable $error) {
            $zip->close();
            return _cms_update_failure($progressToken, $error->getMessage());
        }
    }

    try {
        $localManifest = _get_local_manifest();
    } catch (Throwable $error) {
        $zip->close();
        return _cms_update_failure($progressToken, $error->getMessage());
    }
    $localFiles = is_array($localManifest['files'] ?? null) ? $localManifest['files'] : [];
    foreach ($localFiles as $filename => $_hash) {
        if (!is_string($filename) || _cms_safe_relative_path($filename) !== $filename) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Local manifest contains an invalid path.'));
        }
    }

    $changed = [];
    $obsolete = [];
    $writeErrors = [];
    foreach ($remoteFiles as $filename => $remoteHash) {
        if (_cms_is_preserved($filename, $preservePatterns)) continue;
        $targetPath = _cms_target_path($filename, $projectRoot);
        if ($targetPath === null) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Unsafe update target:') . ' ' . $filename);
        }
        if (is_file($targetPath) && hash_equals($remoteHash, (string)hash_file('sha256', $targetPath))) continue;
        $changed[$filename] = $targetPath;
    }
    foreach ($localFiles as $filename => $_hash) {
        if (isset($remoteFiles[$filename]) || _cms_is_preserved($filename, $preservePatterns)) continue;
        $targetPath = _cms_target_path($filename, $projectRoot);
        if ($targetPath === null) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Unsafe obsolete target:') . ' ' . $filename);
        }
        if (is_file($targetPath)) $obsolete[$filename] = $targetPath;
    }

    $manifestLogicalPath = 'tools/cms-manifest.json';
    $manifestPath = _cms_target_path($manifestLogicalPath, $projectRoot);
    if ($manifestPath === null) {
        $zip->close();
        return _cms_update_failure($progressToken, __('Unsafe update target:') . ' ' . $manifestLogicalPath);
    }

    $versionContents = null;
    if (!isset($remoteFiles['version.json'])) {
        $newVersion = [
            'name' => $remoteManifest['name'] ?? 'Jyavani CMS',
            'version' => $remoteManifest['version'] ?? $currentVer,
            'build' => $remoteManifest['build'] ?? date('Y-m-d'),
            'edition' => $remoteManifest['edition'] ?? 'Phoenix',
            'php_required' => $remoteManifest['php_required'] ?? '8.1',
            'mysql_required' => $remoteManifest['mysql_required'] ?? '5.7',
        ];
        if ($packageVersion !== null) $newVersion = array_merge($newVersion, $packageVersion);
        try {
            $versionContents = json_encode($newVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $error) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Failed to encode version.json:') . ' ' . $error->getMessage());
        }
        $versionPath = _cms_target_path('version.json', $projectRoot);
        if ($versionPath === null) {
            $zip->close();
            return _cms_update_failure($progressToken, __('Unsafe update target:') . ' version.json');
        }
        if (!is_file($versionPath) || !hash_equals(hash('sha256', $versionContents), (string)hash_file('sha256', $versionPath))) {
            $changed['version.json'] = $versionPath;
        }
    }

    foreach ($changed + $obsolete + [$manifestLogicalPath => $manifestPath] as $filename => $targetPath) {
        if (file_exists($targetPath)) {
            if (!is_file($targetPath) || is_link($targetPath) || !is_writable($targetPath)) $writeErrors[] = $filename;
            continue;
        }
        $writePath = dirname($targetPath);
        while (!is_dir($writePath) && dirname($writePath) !== $writePath) $writePath = dirname($writePath);
        if (!is_writable($writePath)) $writeErrors[] = $filename;
    }
    if ($writeErrors) {
        $zip->close();
        return _cms_update_failure($progressToken, __('Update cannot write:') . ' ' . implode(', ', array_slice($writeErrors, 0, 5)));
    }

    $backupDir = _cms_new_backup_dir($projectRoot);
    $backupLogicalPath = ltrim(substr($backupDir, strlen($projectRoot)), '/\\');
    if (_cms_target_path($backupLogicalPath, $projectRoot) !== $backupDir) {
        $zip->close();
        return _cms_update_failure($progressToken, __('Unsafe backup directory.'));
    }
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
        $zip->close();
        return _cms_update_failure($progressToken, __('Failed to create backup directory.'));
    }

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 1, __('Backing up existing files…'));
    }

    $processable = max(1, count($changed));
    $updated = 0;
    $backedUp = 0;
    $errors = [];
    $backupFiles = [];
    $createdFiles = [];
    $processedIndex = 0;

    foreach ($changed as $filename => $targetPath) {
        $processedIndex++;
        $backupCount = count($backupFiles);
        if (!_cms_track_target($filename, $targetPath, $backupDir, $backupFiles, $createdFiles)) {
            $errors[] = __('Failed to back up:') . ' ' . $filename;
            break;
        }
        if (count($backupFiles) > $backupCount) $backedUp++;
        $targetParent = dirname($targetPath);
        if (!is_dir($targetParent) && !@mkdir($targetParent, 0755, true)) {
            $errors[] = __('Failed to create directory:') . ' ' . $filename;
            break;
        }
        if (_cms_target_path($filename, $projectRoot) !== $targetPath) {
            $errors[] = __('Unsafe update target:') . ' ' . $filename;
            break;
        }
        $contents = $filename === 'version.json' && !isset($remoteFiles['version.json'])
            ? $versionContents
            : $zip->getFromIndex($verifiedFiles[$filename]);
        if (!is_string($contents) || @file_put_contents($targetPath, $contents, LOCK_EX) === false) {
            $errors[] = __('Failed to write:') . ' ' . $filename;
            break;
        } else {
            $updated++;
        }
        if ($progressToken !== '' && $processable > 0) {
            $pct = 2 + (int)round(($processedIndex / $processable) * 73);
            if ($pct > 75) $pct = 75;
            if ($processedIndex % max(1, intdiv($processable, 20)) === 0 || $processedIndex === $processable) {
                _cms_write_progress($progressToken, $pct,
                    sprintf(__('Processing: %s (%d/%d)'), basename($filename), $processedIndex, $processable));
            }
        }
    }
    $zip->close();

    if ($errors) {
        $rollbackErrors = _cms_rollback_files($backupFiles, $createdFiles, $projectRoot);
        $message = __('Update failed. Existing files were restored.') . ' ' . implode('; ', array_slice($errors, 0, 5));
        if ($rollbackErrors) $message .= ' ' . __('Rollback incomplete:') . ' ' . implode(', ', array_slice($rollbackErrors, 0, 5));
        return _cms_update_failure($progressToken, $message);
    }

    $deleted = 0;
    $obsoleteIndex = 0;
    foreach ($obsolete as $filename => $targetPath) {
        $backupCount = count($backupFiles);
        if (!_cms_track_target($filename, $targetPath, $backupDir, $backupFiles, $createdFiles)) {
            $errors[] = __('Failed to back up:') . ' ' . $filename;
            break;
        }
        if (count($backupFiles) > $backupCount) $backedUp++;
        if (_cms_target_path($filename, $projectRoot) !== $targetPath || !@unlink($targetPath)) {
            $errors[] = __('Failed to remove obsolete file:') . ' ' . $filename;
            break;
        }
        $deleted++;
        $obsoleteIndex++;
        if ($progressToken !== '' && $obsolete !== []) {
            $pct = 75 + (int)round(($obsoleteIndex / count($obsolete)) * 10);
            _cms_write_progress($progressToken, min(85, $pct), __('Removing obsolete files…'));
        }
    }

    if ($errors) {
        $rollbackErrors = _cms_rollback_files($backupFiles, $createdFiles, $projectRoot);
        $message = __('Update failed. Existing files were restored.') . ' ' . implode('; ', array_slice($errors, 0, 5));
        if ($rollbackErrors) $message .= ' ' . __('Rollback incomplete:') . ' ' . implode(', ', array_slice($rollbackErrors, 0, 5));
        return _cms_update_failure($progressToken, $message);
    }

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 91, __('Verifying installed files…'));
    }

    foreach ($remoteFiles as $filename => $expectedHash) {
        if (_cms_is_preserved($filename, $preservePatterns)) continue;
        $targetPath = _cms_target_path($filename, $projectRoot);
        if ($targetPath === null || !is_file($targetPath)
            || !hash_equals((string)$expectedHash, (string)hash_file('sha256', $targetPath))) {
            $errors[] = __('Final file verification failed:') . ' ' . $filename;
            break;
        }
    }

    if ($errors) {
        $rollbackErrors = _cms_rollback_files($backupFiles, $createdFiles, $projectRoot);
        $message = __('Update failed. Existing files were restored.') . ' ' . implode('; ', array_slice($errors, 0, 5));
        if ($rollbackErrors) $message .= ' ' . __('Rollback incomplete:') . ' ' . implode(', ', array_slice($rollbackErrors, 0, 5));
        return _cms_update_failure($progressToken, $message);
    }

    if ($progressToken !== '') _cms_write_progress($progressToken, 95, __('Installing verified manifest…'));
    try {
        $manifestContents = json_encode($remoteManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } catch (JsonException $error) {
        $errors[] = __('Failed to encode update manifest:') . ' ' . $error->getMessage();
        $manifestContents = '';
    }
    if (!$errors) {
        $backupCount = count($backupFiles);
        if (!_cms_track_target($manifestLogicalPath, $manifestPath, $backupDir, $backupFiles, $createdFiles)) {
            $errors[] = __('Failed to back up:') . ' ' . $manifestLogicalPath;
        } else {
            if (count($backupFiles) > $backupCount) $backedUp++;
            try {
                do {
                    $manifestTemp = $manifestPath . '.tmp-' . bin2hex(random_bytes(6));
                } while (file_exists($manifestTemp) || is_link($manifestTemp));
                if (@file_put_contents($manifestTemp, $manifestContents, LOCK_EX) === false
                    || _cms_target_path($manifestLogicalPath, $projectRoot) !== $manifestPath
                    || !@rename($manifestTemp, $manifestPath)) {
                    @unlink($manifestTemp);
                    $errors[] = __('Failed to install local manifest.');
                }
            } catch (Throwable $error) {
                if (isset($manifestTemp)) @unlink($manifestTemp);
                $errors[] = __('Failed to install local manifest:') . ' ' . $error->getMessage();
            }
        }
    }

    if ($errors) {
        $rollbackErrors = _cms_rollback_files($backupFiles, $createdFiles, $projectRoot);
        $message = __('Update failed. Existing files were restored.') . ' ' . implode('; ', array_slice($errors, 0, 5));
        if ($rollbackErrors) $message .= ' ' . __('Rollback incomplete:') . ' ' . implode(', ', array_slice($rollbackErrors, 0, 5));
        return _cms_update_failure($progressToken, $message);
    }

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 97, __('Finalizing…'));
    }

    $msg = __('Update complete:') . ' ' . $updated . ' ' . __('files updated') . ', ' . $backedUp . ' ' . __('files backed up') . '.';
    if ($deleted > 0) {
        $msg .= ' ' . $deleted . ' ' . __('obsolete files removed') . '.';
    }
    $msg .= ' Backup: ' . basename($backupDir);

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 100, __('Complete!'), true);
    }

    return ['success' => true, 'message' => $msg];
}

// --- Helper: remote download + apply ---
function _apply_cms_update(array $remoteManifest, string $baseUrl, string $currentVer, string $progressToken = ''): array {
    $projectRoot = dirname(DASH_PATH);

    try {
        // Determine download URL from manifest or from base URL
        $downloadUrl = $remoteManifest['download_url'] ?? '';
        if ($downloadUrl === '') {
            // Remove trailing ?format=json or similar, use base URL directly
            $downloadUrl = $baseUrl;
        }

        if ($progressToken !== '') {
            _cms_write_progress($progressToken, 0, __('Downloading update package…'));
        }

        $tmpZip = sys_get_temp_dir() . '/cms-update-' . bin2hex(random_bytes(8)) . '.zip';

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'user_agent' => 'JyavaniCMS-Update/' . $currentVer,
            ],
        ]);

        $input = @fopen($downloadUrl, 'rb', false, $ctx);
        $output = $input === false ? false : @fopen($tmpZip, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($tmpZip);
            if ($progressToken !== '') {
                _cms_write_progress($progressToken, 0, __('Download failed.'), true, __('Failed to download update package.'));
            }
            return ['success' => false, 'message' => __('Failed to download update package.')];
        }

        $length = 0;
        foreach ((array)(stream_get_meta_data($input)['wrapper_data'] ?? []) as $header) {
            if (preg_match('/^Content-Length:\s*(\d+)/i', (string)$header, $match)) $length = (int)$match[1];
        }
        $downloaded = 0;
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
                fclose($input);
                fclose($output);
                @unlink($tmpZip);
                if ($progressToken !== '') _cms_write_progress($progressToken, 0, __('Download failed.'), true, __('Failed to download update package.'));
                return ['success' => false, 'message' => __('Failed to download update package.')];
            }
            $downloaded += strlen($chunk);
            if ($progressToken !== '' && $length > 0) {
                _cms_write_progress($progressToken, (int)floor(5 * min(1, $downloaded / $length)), __('Downloading update package…'));
            }
        }
        fclose($input);
        fclose($output);

        $expectedPackageHash = strtolower(trim((string)($remoteManifest['package_sha256'] ?? '')));
        if ($expectedPackageHash !== '' && (!preg_match('/^[a-f0-9]{64}$/', $expectedPackageHash)
            || !hash_equals($expectedPackageHash, (string)hash_file('sha256', $tmpZip)))) {
            @unlink($tmpZip);
            return _cms_update_failure($progressToken, __('Package checksum verification failed.'));
        }

        if ($progressToken !== '') {
            _cms_write_progress($progressToken, 5, __('Download complete. Extracting…'));
        }

        $result = _apply_cms_update_from_zip($tmpZip, $remoteManifest, $currentVer, $progressToken);
        @unlink($tmpZip);
        return $result;

    } catch (Throwable $e) {
        if ($progressToken !== '') {
            _cms_write_progress($progressToken, 0, __('Error.'), true, $e->getMessage());
        }
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}
