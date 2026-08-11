<?php
declare(strict_types=1);

class PluginStoreController
{
    public static function checkUpdates(PDO $pdo): array
    {
        $transient = self::readTransient();
        $plugins = function_exists('plugins_all') ? plugins_all() : [];
        $updates = $transient['updates'] ?? [];
        $anyFetched = false;

        foreach ($plugins as $name => $manifest) {
            $store = $manifest['store'] ?? null;
            // Store packages published before manifest metadata existed can still
            // discover their first update from the official Jyavani store.
            if (!$store && str_starts_with((string)($manifest['plugin_uri'] ?? ''), 'https://jyavani.com/plugin/')) {
                $store = ['url' => 'https://jyavani.com/plugin-store'];
            }
            if (!$store) continue;
            $storeUrl = rtrim($store['url'] ?? 'https://jyavani.com/plugin-store/', '/');
            $currentVersion = $manifest['version'] ?? '0.0.0';

            $latest = self::fetchVersionInfo($storeUrl . '/' . $name . '/version.json');
            if ($latest === null) {
                continue;
            }
            $anyFetched = true;
            if (version_compare($latest['version'] ?? '0.0.0', $currentVersion, '>')) {
                $latestRequirements = is_array($latest['requires'] ?? null) ? $latest['requires'] : [];
                if (is_string($latest['jyavani_required'] ?? null) && $latest['jyavani_required'] !== '') {
                    $latestRequirements['jyavani'] = $latest['jyavani_required'];
                }
                if (is_string($latest['php_required'] ?? null) && $latest['php_required'] !== '') {
                    $latestRequirements['php'] = $latest['php_required'];
                }
                $requirementManifest = ['requires' => $latestRequirements];
                $compatibilityErrors = function_exists('plugin_requirement_errors')
                    ? plugin_requirement_errors($requirementManifest)
                    : [];
                $updates[$name] = [
                    'current_version' => $currentVersion,
                    'new_version' => $latest['version'],
                    'download_url' => $latest['download_url'] ?? ($storeUrl . '/download/' . $name . '/'),
                    'changelog' => $latest['changelog'] ?? '',
                    'zip_size' => $latest['zip_size'] ?? 0,
                    'php_required' => $latest['php_required'] ?? '',
                    'jyavani_required' => $latestRequirements['jyavani'] ?? '',
                    'requires' => $latestRequirements,
                    'compatible' => $compatibilityErrors === [],
                    'compatibility_errors' => $compatibilityErrors,
                    'checksum' => $latest['checksum'] ?? '',
                ];
            } else {
                unset($updates[$name]);
            }
        }

        $transient['last_check'] = time();
        $transient['updates'] = $anyFetched ? $updates : ($transient['updates'] ?? []);
        self::writeTransient($transient);

        return $updates;
    }

    public static function getCachedUpdates(): array
    {
        $transient = self::readTransient();
        $lastCheck = $transient['last_check'] ?? 0;
        if ((time() - $lastCheck) > 3600) return [];
        return $transient['updates'] ?? [];
    }

    public static function applyUpdate(PDO $pdo, string $name, string $progressToken = ''): array
    {
        if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) {
            return ['success' => false, 'error' => 'Invalid plugin name.'];
        }
        $updates = self::getCachedUpdates();
        if (!isset($updates[$name])) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, __('No updates available.'), true, __('No updates available. Run "Check for Updates" first.'));
            }
            return ['success' => false, 'error' => 'No update available for "' . $name . '". Run "Check for Updates" first.'];
        }

        $update = $updates[$name];
        if (($update['compatible'] ?? true) !== true) {
            $error = 'Plugin update requirements are not met: ' . implode('; ', (array)($update['compatibility_errors'] ?? [])) . '.';
            if ($progressToken !== '') self::writeProgress($progressToken, 0, $error, true, $error);
            return ['success' => false, 'error' => $error];
        }
        $pluginDir = (defined('PLUGIN_PATH') ? PLUGIN_PATH : __DIR__ . '/../../plugins') . '/' . $name;
        if (!is_dir($pluginDir)) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, 'Direktori plugin tidak ditemukan.', true, 'Direktori plugin tidak ditemukan.');
            }
            return ['success' => false, 'error' => 'Plugin directory not found.'];
        }
        $oldManifest = function_exists('plugin_manifest') ? plugin_manifest($name) : null;
        $oldStaticValue = $oldManifest['static']['copy'] ?? [];
        if (!is_array($oldStaticValue)) {
            return ['success' => false, 'error' => 'Installed plugin static.copy is invalid; update cannot safely track old destinations.'];
        }
        $oldStaticCopy = $oldStaticValue;

        $p = function ($pct, $status) use ($progressToken) {
            if ($progressToken !== '') self::writeProgress($progressToken, $pct, $status);
        };

        $p(3, 'Memulai update...');

        // Backup
        $p(8, 'Membackup plugin saat ini...');
        $backupVersion = preg_replace('/[^0-9A-Za-z._-]+/', '-', (string)$update['current_version']);
        $backupDir = dirname(self::transientFile()) . '/plugin-backups/' . $name . '-' . trim($backupVersion, '-');
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            return ['success' => false, 'error' => 'Failed to create plugin backup directory.'];
        }
        $backupFile = $backupDir . '/backup.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['success' => false, 'error' => 'Failed to create plugin backup.'];
        }
        $backupOk = true;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            $localPath = substr($file->getPathname(), strlen($pluginDir) + 1);
            if (!$zip->addFile($file->getPathname(), $localPath)) $backupOk = false;
        }
        if (!$zip->close() || !$backupOk || !is_file($backupFile)) {
            return ['success' => false, 'error' => 'Failed to create complete plugin backup.'];
        }
        $restoreBackup = static function () use ($pluginDir, $backupFile): bool {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $file) {
                    if ($file->isDir() && !$file->isLink()) @rmdir($file->getPathname());
                    else @unlink($file->getPathname());
                }
                $backup = new ZipArchive();
                if ($backup->open($backupFile) !== true) return false;
                $ok = $backup->extractTo($pluginDir);
                $backup->close();
                return $ok;
            } catch (Throwable $error) {
                error_log('plugin update restore failed: ' . $error->getMessage());
                return false;
            }
        };

        // Download update
        $tmpZip = self::downloadPackage((string)$update['download_url'], $p);
        if ($tmpZip === null) {
            self::writeProgress($progressToken, 0, 'Gagal mengunduh update.', true, 'Gagal mengunduh update dari store.');
            return ['success' => false, 'error' => 'Gagal mengunduh update dari store.'];
        }
        if (empty($update['checksum']) || !hash_equals(strtolower((string)$update['checksum']), hash_file('sha256', $tmpZip))) {
            @unlink($tmpZip);
            self::writeProgress($progressToken, 0, 'Paket update tidak valid.', true, 'Plugin package integrity verification failed.');
            return ['success' => false, 'error' => 'Plugin package integrity verification failed.'];
        }

        $p(35, 'Unduhan selesai. Memverifikasi paket...');

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            self::writeProgress($progressToken, 0, 'Paket update tidak valid.', true, 'Paket update tidak valid.');
            return ['success' => false, 'error' => 'Paket update tidak valid.'];
        }

        $manifestEntry = $zip->locateName('plugin.json', ZipArchive::FL_NOCASE) !== false
            ? 'plugin.json'
            : $name . '/plugin.json';
        $packageManifestRaw = $zip->getFromName($manifestEntry);
        $packageManifest = $packageManifestRaw !== false ? json_decode($packageManifestRaw, true) : null;
        if (!is_array($packageManifest) || ($packageManifest['name'] ?? '') !== $name
            || !is_string($packageManifest['version'] ?? null)
            || version_compare($packageManifest['version'], (string)$update['new_version'], '!=')) {
            $zip->close(); @unlink($tmpZip);
            return ['success' => false, 'error' => 'Update package plugin.json is invalid or does not match the advertised plugin version.'];
        }
        $packageRequirementError = function_exists('plugin_requirements_error_message')
            ? plugin_requirements_error_message($packageManifest)
            : '';
        if ($packageRequirementError !== '') {
            $zip->close(); @unlink($tmpZip);
            return ['success' => false, 'error' => $packageRequirementError];
        }
        if (function_exists('plugin_package_requirement_errors')) {
            $advertisedManifest = ['requires' => is_array($update['requires'] ?? null) ? $update['requires'] : []];
            $metadataErrors = plugin_package_requirement_errors($advertisedManifest, $packageManifest);
            if ($metadataErrors !== []) {
                $zip->close(); @unlink($tmpZip);
                return ['success' => false, 'error' => 'Update package requirements do not match store metadata: ' . implode('; ', $metadataErrors) . '.'];
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)($zip->statIndex($i)['name'] ?? '');
            if (str_ends_with($entry, '/')) continue;
            $relative = preg_replace('#^' . preg_quote($name, '#') . '/#', '', $entry, 1);
            if (!function_exists('plugin_safe_path') || plugin_safe_path($pluginDir, $relative) === null) {
                $zip->close(); unlink($tmpZip);
                return ['success' => false, 'error' => 'Update package contains an invalid file path.'];
            }
        }

        // Hapus files lama
        $p(45, 'Membersihkan file lama...');
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        $deleteFailed = false;
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($pluginDir) + 1);
            if ($rel === '.store.json' || str_starts_with($rel, '.git/')) continue;
            if ($f->isDir()) {
                if (!@rmdir($f->getPathname()) && is_dir($f->getPathname())) $deleteFailed = true;
            } elseif (!@unlink($f->getPathname()) && file_exists($f->getPathname())) {
                $deleteFailed = true;
            }
        }
        if ($deleteFailed) {
            $zip->close(); @unlink($tmpZip);
            $restored = $restoreBackup();
            return ['success' => false, 'error' => $restored ? 'Failed to replace old plugin files; backup restored.' : 'Failed to replace old plugin files and backup restoration failed.'];
        }

        // Extract update
        $p(55, 'Memasang file update...');
        $filesToExtract = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->statIndex($i)['name'] ?? '';
            if (!str_ends_with($filename, '/')) $filesToExtract[] = $filename;
        }
        $totalFiles = count($filesToExtract);
        $extractFailed = false;
        foreach ($filesToExtract as $i => $filename) {
            $relative = preg_replace('#^' . preg_quote($name, '#') . '/#', '', $filename, 1);
            $target = plugin_safe_path($pluginDir, $relative);
            if ($target === null) { $extractFailed = true; break; }
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) { $extractFailed = true; break; }
            $copied = @copy('zip://' . $tmpZip . '#' . $filename, $target);
            if (!$copied) {
                $extractFailed = true;
                break;
            }
            if ($progressToken !== '' && $totalFiles > 0 && (($i + 1) % max(1, intdiv($totalFiles, 20)) === 0 || $i + 1 === $totalFiles)) {
                $pct = 55 + (int)(30 * ($i + 1) / $totalFiles);
                $p($pct, 'Memasang file (' . ($i + 1) . '/' . $totalFiles . ')...');
            }
        }
        $zip->close();

        if ($extractFailed) {
            $p(0, 'Gagal! Mengembalikan backup...');
            $restored = $restoreBackup();
            unlink($tmpZip);
            self::writeProgress($progressToken, 0, 'Gagal mengekstrak update. Backup sudah dikembalikan.', true, 'Gagal mengekstrak update. Backup sudah dikembalikan.');
            return ['success' => false, 'error' => $restored ? 'Gagal mengekstrak update. Backup sudah dikembalikan.' : 'Gagal mengekstrak update dan pemulihan backup gagal.'];
        }

        unlink($tmpZip);

        // Re-read the package manifest; never manufacture compatibility metadata.
        $p(88, __('Verifying plugin manifest...'));
        $manifestPath = $pluginDir . '/plugin.json';
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
        if (!is_array($manifest) || ($manifest['name'] ?? '') !== $name
            || ($manifest['version'] ?? '') !== $update['new_version']
            || plugin_requirements_error_message($manifest) !== '') {
            $restored = $restoreBackup();
            return ['success' => false, 'error' => $restored ? 'Installed plugin manifest failed verification; backup restored.' : 'Installed plugin manifest failed verification and backup restoration failed.'];
        }

        // Copy static files (respects PUBLIC_PATH for public_html/public/etc)
        $staticCopy = $manifest['static']['copy'] ?? [];
        if (!is_array($staticCopy)) {
            $restored = $restoreBackup();
            return ['success' => false, 'error' => $restored ? 'Plugin static.copy is invalid; backup restored.' : 'Plugin static.copy is invalid and backup restoration failed.'];
        }
        if ($staticCopy !== [] || $oldStaticCopy !== []) {
            $p(90, __('Copying static files...'));
            $copyResult = plugin_static_copy($pluginDir, $staticCopy, $oldStaticCopy);
            if ($copyResult['failed'] > 0) {
                $restored = $restoreBackup();
                $staticRollbackFailed = ($copyResult['rollback_incomplete'] ?? false) === true;
                if ($staticRollbackFailed) {
                    return ['success' => false, 'error' => 'Declared static files failed and static asset rollback was incomplete. Manual recovery is required.'];
                }
                return ['success' => false, 'error' => $restored ? 'Declared static files failed to copy; backup restored.' : 'Declared static files failed to copy and backup restoration failed.'];
            }
        }

        // Delete the rollback archive only after manifest and static assets succeed.
        if (is_dir($backupDir)) {
            $dit = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($dit as $f) {
                if ($f->isDir()) @rmdir($f->getPathname());
                else @unlink($f->getPathname());
            }
            @rmdir($backupDir);
        }

        $p(95, 'Menyelesaikan...');
        $transient = self::readTransient();
        unset($transient['updates'][$name]);
        self::writeTransient($transient);

        self::writeProgress($progressToken, 100, 'Selesai!', true);

        return ['success' => true, 'new_version' => $update['new_version']];
    }

    public static function readProgress(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $file = self::progressFile($token);
        if (!is_file($file)) return null;
        if (time() - filemtime($file) > 3600) {
            @unlink($file);
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private static function transientFile(): string
    {
        $backend = defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg';
        return $backend . '/var/plugin-update-transient.json';
    }

    private static function readTransient(): array
    {
        $file = self::transientFile();
        if (!is_file($file)) return ['last_check' => 0, 'updates' => []];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : ['last_check' => 0, 'updates' => []];
    }

    private static function writeTransient(array $data): void
    {
        $file = self::transientFile();
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    public static function clearProgress(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
        $file = self::progressFile($token);
        if (is_file($file)) @unlink($file);
    }

    private static function progressFile(string $token): string
    {
        $backend = defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg';
        return $backend . '/var/update-progress-' . $token . '.json';
    }

    private static function writeProgress(string $token, int $percentage, string $status, bool $done = false, ?string $error = null): void
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) return;
        $file = self::progressFile($token);
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($file, json_encode([
            'percentage' => $percentage,
            'status' => $status,
            'done' => $done,
            'error' => $error,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private static function downloadPackage(string $url, callable $progress): ?string
    {
        $progress(18, 'Mengunduh paket update...');
        $tmp = tempnam(sys_get_temp_dir(), 'plugin-update-');
        if ($tmp === false) return null;

        if (function_exists('curl_init')) {
            $output = @fopen($tmp, 'wb');
            if ($output === false) { @unlink($tmp); return null; }
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_FILE => $output, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'JyavaniCMS-PluginUpdate']);
            $ok = curl_exec($curl) === true && (int)curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 200 && (int)curl_getinfo($curl, CURLINFO_HTTP_CODE) < 300;
            curl_close($curl);
            $ok = fclose($output) && $ok && filesize($tmp) > 0;
        } else {
            $ok = self::downloadPackageWithStream($url, $tmp, $progress);
        }
        if (!$ok) { @unlink($tmp); return null; }
        $progress(35, 'Unduhan selesai. Memverifikasi paket...');
        return $tmp;
    }

    private static function downloadPackageWithStream(string $url, string $tmp, callable $progress): bool
    {
        $ctx = stream_context_create(['http' => [
            'timeout' => 120,
            'user_agent' => 'JyavaniCMS-PluginUpdate',
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
        ]]);
        $input = @fopen($url, 'rb', false, $ctx);
        if ($input === false) return false;

        $headers = (array)(stream_get_meta_data($input)['wrapper_data'] ?? []);
        $status = 0;
        $length = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string)$header, $match)) $status = (int)$match[1];
            if (preg_match('/^Content-Length:\s*(\d+)/i', (string)$header, $match)) $length = (int)$match[1];
        }
        if ($status < 200 || $status >= 300) { fclose($input); return false; }

        $output = @fopen($tmp, 'wb');
        if ($output === false) { fclose($input); return false; }
        $downloaded = 0;
        $ok = true;
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) { $ok = false; break; }
            if ($chunk === '') continue;
            if (fwrite($output, $chunk) !== strlen($chunk)) { $ok = false; break; }
            $downloaded += strlen($chunk);
            if ($length > 0) $progress(18 + (int)floor(17 * min(1, $downloaded / $length)), 'Mengunduh paket update...');
        }
        fclose($input);
        return fclose($output) && $ok && $downloaded > 0;
    }

    private static function fetchVersionInfo(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'JyavaniCMS/2.0',
            ],
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    // ─── Store API endpoints (served by jyavani.com) ───

    public static function list(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $plugins = [];
        try {
            $stmt = $pdo->query(
                "SELECT p.name, p.title, p.description, p.excerpt, p.icon, p.banner,
                        p.php_required, p.homepage, p.github_url, p.total_downloads,
                        m.username, m.display_name,
                        pv.version, pv.changelog, pv.zip_file, pv.zip_size
                 FROM plugins p
                 LEFT JOIN members m ON p.member_id = m.id
                 LEFT JOIN plugin_versions pv ON pv.plugin_id = p.id AND pv.is_current = 1
                 WHERE p.status = 'approved' AND p.is_deleted = 0
                 ORDER BY p.total_downloads DESC, p.title ASC"
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
            foreach ($rows as $row) {
                $name = $row['name'];
                $packageRequirements = self::packageRequirements((string)($row['zip_file'] ?? ''), $name);
                $plugins[] = [
                    'name' => $name,
                    'title' => $row['title'] ?: $name,
                    'version' => $row['version'] ?: '1.0.0',
                    'php_required' => $row['php_required'] ?: '8.1',
                    'description' => $row['description'] ?: $row['excerpt'] ?: '',
                    'author' => $row['display_name'] ?: $row['username'] ?: 'Jyavani',
                    'icon' => self::storeStaticUrl($name, (string)$row['icon']),
                    'banner' => self::storeStaticUrl($name, (string)$row['banner']),
                    'plugin_uri' => $row['homepage'] ?: '',
                    'download_url' => self::storeDownloadUrl($name),
                    'jyavani_required' => $packageRequirements['jyavani'] ?? '',
                    'requires' => $packageRequirements,
                ];
            }
        } catch (Throwable $e) {
            error_log('PluginStore list error: ' . $e->getMessage());
            // Return empty list on DB error rather than 500
        }
        echo json_encode([
            'store_name' => 'Jyavani Plugin Store',
            'plugins' => $plugins,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function download(PDO $pdo, string $name): void
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($name === '') {
            http_response_code(400);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT p.id, pv.zip_file, pv.version
                 FROM plugins p
                 LEFT JOIN plugin_versions pv ON pv.plugin_id = p.id AND pv.is_current = 1
                 WHERE p.name = ? AND p.status = 'approved' AND p.is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([$name]);
            $row = $stmt->fetch();
            if (!$row || empty($row['zip_file'])) {
                http_response_code(404);
                exit;
            }

            $file = __DIR__ . '/../../' . $row['zip_file'];
            if (!is_file($file)) {
                http_response_code(404);
                exit;
            }

            $pdo->prepare("UPDATE plugins SET total_downloads = total_downloads + 1 WHERE id = ?")->execute([$row['id']]);

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '-' . $row['version'] . '.zip"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: no-store');
            readfile($file);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            exit;
        }
    }

    public static function versionInfo(PDO $pdo, string $name): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($name === '') {
            echo json_encode(['error' => 'Invalid plugin name.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT pv.version, pv.changelog, pv.zip_size, pv.php_required, pv.zip_file
                 FROM plugins p
                 LEFT JOIN plugin_versions pv ON pv.plugin_id = p.id AND pv.is_current = 1
                 WHERE p.name = ? AND p.status = 'approved' AND p.is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([$name]);
            $row = $stmt->fetch();
            if (!$row || empty($row['version'])) {
                echo json_encode(['error' => 'Plugin not found.']);
                exit;
            }
            $packageRequirements = self::packageRequirements((string)($row['zip_file'] ?? ''), $name);
            $packagePath = __DIR__ . '/../../' . (string)($row['zip_file'] ?? '');
            $checksum = is_file($packagePath) ? hash_file('sha256', $packagePath) : false;
            echo json_encode([
                'version' => $row['version'],
                'download_url' => self::storeDownloadUrl($name),
                'changelog' => $row['changelog'] ?: '',
                'zip_size' => (int)($row['zip_size'] ?: 0),
                'php_required' => $row['php_required'] ?: '8.1',
                'jyavani_required' => $packageRequirements['jyavani'] ?? '',
                'requires' => $packageRequirements,
                'checksum' => is_string($checksum) ? $checksum : '',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['error' => 'Failed to read version info.']);
            exit;
        }
    }

    public static function serveStatic(PDO $pdo, string $name, string $file): void
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($name === '' || $file === '') {
            http_response_code(400);
            exit;
        }
        // Prevent path traversal
        $file = basename($file);
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'];
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            http_response_code(403);
            exit;
        }
        $path = __DIR__ . '/../../plugin-store/plugins/' . $name . '/' . $file;
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }
        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    private static function storeDownloadUrl(string $name): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?: 'jyavani.com';
        return $scheme . '://' . $host . '/plugin-store/download/' . rawurlencode($name) . '/';
    }

    private static function packageRequirements(string $relativeZip, string $name): array
    {
        if ($relativeZip === '' || !class_exists('ZipArchive')) return [];
        $root = realpath(__DIR__ . '/../../');
        $path = $root ? realpath($root . '/' . ltrim($relativeZip, '/')) : false;
        if (!$root || !$path || ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR))) return [];
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];
        $raw = $zip->getFromName('plugin.json');
        if ($raw === false) $raw = $zip->getFromName($name . '/plugin.json');
        $zip->close();
        $manifest = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($manifest)) return [];
        if (function_exists('plugin_normalize_requirements')) {
            return plugin_normalize_requirements($manifest)['requires'];
        }
        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
        if (!isset($requires['jyavani']) && is_string($manifest['jyavani_required'] ?? null)) $requires['jyavani'] = trim($manifest['jyavani_required']);
        if (!isset($requires['php']) && is_string($manifest['php_required'] ?? null)) $requires['php'] = trim($manifest['php_required']);
        return $requires;
    }

    private static function storeStaticUrl(string $name, string $file): string
    {
        if ($file === '') return '';
        $file = basename($file);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?: 'jyavani.com';
        return $scheme . '://' . $host . '/plugin-store/static/' . rawurlencode($name) . '/' . rawurlencode($file);
    }
}
