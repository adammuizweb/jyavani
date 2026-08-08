<?php
declare(strict_types=1);
// Shared helpers for CMS update page + AJAX handlers

// --- Progress tracking ---
function _cms_progress_file(string $token): string {
    return dirname(DASH_PATH) . '/cfg/var/cms-progress-' . $token . '.json';
}

function _cms_write_progress(string $token, int $pct, string $status, bool $done = false, ?string $error = null): void {
    @file_put_contents(_cms_progress_file($token), json_encode([
        'percentage' => $pct,
        'status' => $status,
        'done' => $done,
        'error' => $error,
    ]), LOCK_EX);
}

function _cms_read_progress(string $token): ?array {
    $f = _cms_progress_file($token);
    if (!is_file($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function _cms_clear_progress(string $token): void {
    $f = _cms_progress_file($token);
    if (is_file($f)) @unlink($f);
}

// --- Helper: get preserve regex patterns ---
function _get_preserve_patterns(): array {
    return [
        '#^cfg/\.env$#',
        '#^cfg/var/#',
        '#^cfg/session_debug\.log$#',
        '#^cfg/php-noteloc\.ini$#',
        '#^cfg/site-router\.php$#',
        '#^private_files/#',
        '#^public/static/img/#',
        '#^public/static/files/#',
        '#^public/static/plugins/#',
        '#^public/sitemaps/#',
        '#^public/pdf/#',
        '#^public/views/themes/[^/]+/.+#',
        '#^plugins/[^/]+/.+#',
        '#^plugin-store/#',
        '#^app/controllers/DownloadController\.php$#',
        '#^dashboard/admin/community/#',
        '#^public/download/#',
        '#^public/static/community/#',
        '#^public/views/community/#',
        '#^public/views/member/#',
        '#^schema/community\.sql$#',
        '#^schema/migrations/008-dev-status-varchar\.sql$#',
        '#node_modules/#',
        '#\.git/#',
        '#^\.gitignore$#',
    ];
}

// --- Helper: load local manifest ---
function _get_local_manifest(): ?array {
    $f = dirname(DASH_PATH) . '/tools/cms-manifest.json';
    if (!is_file($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

// --- Helper: apply update from zip ---
function _apply_cms_update_from_zip(string $zipPath, array $remoteManifest, string $currentVer, string $progressToken = ''): array {
    $projectRoot = dirname(DASH_PATH);
    $backupDir = $projectRoot . '/cfg/var/backup-' . date('Ymd-His');

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['success' => false, 'message' => __('Failed to open ZIP package.')];
    }

    $totalFiles = $zip->numFiles;
    if ($totalFiles === 0) {
        $zip->close();
        return ['success' => false, 'message' => __('Update package is empty.')];
    }

    // Track file count for progress (only count matching files)
    $processable = 0;
    $remoteFiles = null;
    if (isset($remoteManifest['files']) && is_array($remoteManifest['files'])) {
        $remoteFiles = $remoteManifest['files'];
    }
    $preservePatterns = _get_preserve_patterns();

    // Refuse a partial update: version.json must never advance when a managed
    // file cannot be replaced by the PHP-FPM user.
    $writeErrors = [];
    for ($i = 0; $i < $totalFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        if ($filename === false || str_ends_with($filename, '/')) continue;
        $isPreserved = false;
        foreach ($preservePatterns as $pattern) {
            if (preg_match($pattern, $filename)) { $isPreserved = true; break; }
        }
        if ($isPreserved || ($remoteFiles !== null && !isset($remoteFiles[$filename]))) continue;

        $targetPath = $projectRoot . '/' . $filename;
        $remoteHash = is_string($remoteFiles[$filename] ?? null) ? $remoteFiles[$filename] : '';
        if ($remoteHash !== '' && is_file($targetPath) && hash_equals($remoteHash, (string)hash_file('sha256', $targetPath))) continue;
        if (file_exists($targetPath)) {
            if (!is_file($targetPath) || !is_writable($targetPath)) $writeErrors[] = $filename;
            continue;
        }
        $writePath = dirname($targetPath);
        while (!is_dir($writePath) && dirname($writePath) !== $writePath) {
            $writePath = dirname($writePath);
        }
        if (!is_writable($writePath)) $writeErrors[] = $filename;
    }
    if ($writeErrors) {
        $zip->close();
        return ['success' => false, 'message' => __('Update cannot write:') . ' ' . implode(', ', array_slice($writeErrors, 0, 5))];
    }

    // First pass: count processable files for accurate progress
    if ($progressToken !== '') {
        for ($i = 0; $i < $totalFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if ($filename === false || str_ends_with($filename, '/')) continue;
            $isPreserved = false;
            foreach ($preservePatterns as $pattern) {
                if (preg_match($pattern, $filename)) { $isPreserved = true; break; }
            }
            if ($isPreserved) continue;
            if ($remoteFiles !== null && !isset($remoteFiles[$filename])) continue;
            $targetPath = $projectRoot . '/' . $filename;
            $remoteHash = is_string($remoteFiles[$filename] ?? null) ? $remoteFiles[$filename] : '';
            if ($remoteHash !== '' && is_file($targetPath) && hash_equals($remoteHash, (string)hash_file('sha256', $targetPath))) continue;
            $processable++;
        }
        if ($processable < 1) $processable = 1;
    }

    // Create backup dir
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 1, __('Backing up existing files…'));
    }

    $updated = 0;
    $backedUp = 0;
    $errors = [];
    $processedIndex = 0;

    // Extract all files from zip
    for ($i = 0; $i < $totalFiles; $i++) {
        $filename = $zip->getNameIndex($i);
        if ($filename === false) continue;

        // Skip directories
        if (str_ends_with($filename, '/')) continue;

        // Skip preserved paths
        $isPreserved = false;
        foreach ($preservePatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                $isPreserved = true;
                break;
            }
        }
        if ($isPreserved) continue;

        // If manifest provides a file list, only update files listed in it
        if ($remoteFiles !== null && !isset($remoteFiles[$filename])) continue;

        $targetPath = $projectRoot . '/' . $filename;
        $remoteHash = is_string($remoteFiles[$filename] ?? null) ? $remoteFiles[$filename] : '';
        if ($remoteHash !== '' && is_file($targetPath) && hash_equals($remoteHash, (string)hash_file('sha256', $targetPath))) continue;
        $processedIndex++;

        // Backup existing file if it exists
        if (is_file($targetPath)) {
            $backupPath = $backupDir . '/' . $filename;
            $backupParent = dirname($backupPath);
            if (!is_dir($backupParent)) {
                @mkdir($backupParent, 0755, true);
            }
            if (@copy($targetPath, $backupPath)) {
                $backedUp++;
            }
        }

        // Ensure target parent dir exists
        $targetParent = dirname($targetPath);
        if (!is_dir($targetParent)) {
            @mkdir($targetParent, 0755, true);
        }

        // Extract file
        $extracted = @file_put_contents($targetPath, $zip->getFromIndex($i));
        if ($extracted === false) {
            $errors[] = __('Failed to write:') . ' ' . $filename;
        } else {
            $updated++;
        }

        // Write progress every ~5%
        if ($progressToken !== '' && $processable > 0) {
            $pct = 2 + (int)round(($processedIndex / $processable) * 73);
            if ($pct > 75) $pct = 75;
            if ($processedIndex % max(1, intdiv($processable, 20)) === 0 || $processedIndex === $processable) {
                _cms_write_progress($progressToken, $pct,
                    sprintf(__('Processing: %s (%d/%d)'), basename($filename), $processedIndex, $processable));
            }
        }
    }

    // Read version.json from zip before closing (must be before close in PHP 8.4+)
    $zipVersionJson = $zip->getFromName('version.json');
    $zip->close();

    if ($errors) {
        $message = __('Update stopped before cleanup and version change:') . ' ' . implode('; ', array_slice($errors, 0, 5));
        if ($progressToken !== '') _cms_write_progress($progressToken, 0, __('Update failed.'), true, $message);
        return ['success' => false, 'message' => $message . ' Backup: ' . basename($backupDir)];
    }

    // Delete files that exist locally but not in remote manifest
    $deleted = 0;
    if ($remoteFiles !== null) {
        $localManifest = _get_local_manifest();
        if ($localManifest) {
            $localFiles = $localManifest['files'] ?? [];
            $totalLocal = count($localFiles);
            $localIdx = 0;
            foreach ($localFiles as $localRelPath => $hash) {
                if (isset($remoteFiles[$localRelPath])) continue;
                $isPreserved = false;
                foreach ($preservePatterns as $pattern) {
                    if (preg_match($pattern, $localRelPath)) {
                        $isPreserved = true;
                        break;
                    }
                }
                if ($isPreserved) continue;
                $localPath = $projectRoot . '/' . $localRelPath;
                if (is_file($localPath)) {
                    $backupPath = $backupDir . '/' . $localRelPath;
                    $backupParent = dirname($backupPath);
                    if (!is_dir($backupParent)) @mkdir($backupParent, 0755, true);
                    @copy($localPath, $backupPath);
                    @unlink($localPath);
                    $deleted++;
                }

                if ($progressToken !== '' && $totalLocal > 0) {
                    $localIdx++;
                    $pct = 75 + (int)round(($localIdx / $totalLocal) * 10);
                    if ($pct > 85) $pct = 85;
                    if ($localIdx % max(1, intdiv($totalLocal, 10)) === 0) {
                        _cms_write_progress($progressToken, $pct, __('Removing obsolete files…'));
                    }
                }
            }
        }
    }

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 86, __('Updating version info…'));
    }

    // Update version.json — merge from remote manifest + zip version.json
    $newVersion = [
        'name' => $remoteManifest['name'] ?? 'Jyavani CMS',
        'version' => $remoteManifest['version'] ?? $currentVer,
        'build' => $remoteManifest['build'] ?? date('Y-m-d'),
        'edition' => $remoteManifest['edition'] ?? 'Phoenix',
        'php_required' => $remoteManifest['php_required'] ?? '8.1',
        'mysql_required' => $remoteManifest['mysql_required'] ?? '5.7',
    ];
    if ($zipVersionJson !== false) {
        $zipVersion = json_decode($zipVersionJson, true);
        if (is_array($zipVersion)) {
            $newVersion = array_merge($newVersion, $zipVersion);
        }
    }
    file_put_contents($projectRoot . '/version.json', json_encode($newVersion, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 91, __('Regenerating manifest…'));
    }

    // Regenerate local manifest
    if (is_file($projectRoot . '/tools/generate-manifest.php')) {
        @shell_exec('php ' . escapeshellarg($projectRoot . '/tools/generate-manifest.php') . ' 2>&1');
    }

    if ($progressToken !== '') {
        _cms_write_progress($progressToken, 97, __('Finalizing…'));
    }

    $msg = __('Update complete:') . ' ' . $updated . ' ' . __('files updated') . ', ' . $backedUp . ' ' . __('files backed up') . '.';
    if (!empty($errors)) {
        $msg .= ' Error: ' . implode('; ', array_slice($errors, 0, 5));
    }
    if (isset($deleted) && $deleted > 0) {
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
    $backupDir = $projectRoot . '/cfg/var/backup-' . date('Ymd-His');

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
