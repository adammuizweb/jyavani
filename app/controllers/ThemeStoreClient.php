<?php
declare(strict_types=1);

class ThemeStoreClient
{
    private const STORE_BASE = 'https://jyavani.com/theme-store';
    private const UPDATE_TTL = 3600;

    public static function checkUpdates(PDO $pdo): array
    {
        return self::checkUpdatesDetailed($pdo)['updates'];
    }

    public static function checkUpdatesDetailed(PDO $pdo): array
    {
        $transient = self::readTransient();
        $themes = self::scanThemes($pdo);
        $updates = $transient['updates'] ?? [];
        $eligible = [];
        $fetched = 0;
        $failed = [];
        $now = time();

        foreach ($themes as $folder => $manifest) {
            $store = $manifest['store'] ?? null;
            if (!$store) continue;
            $eligible[$folder] = true;
            $storeUrl = rtrim($store['url'] ?? self::STORE_BASE, '/');
            $storeSlug = $store['slug'] ?? $folder;
            $currentVersion = $manifest['version'] ?? '0.0.0';

            $latest = self::fetchVersionInfo($storeUrl . '/' . $storeSlug . '/version.json');
            if ($latest === null || !is_string($latest['version'] ?? null) || trim($latest['version']) === '') {
                $failed[] = $folder;
                if (isset($updates[$folder]) && is_array($updates[$folder])) $updates[$folder]['actionable'] = false;
                continue;
            }
            if (!is_string($latest['checksum'] ?? null) || preg_match('/^[a-f0-9]{64}$/i', $latest['checksum']) !== 1) {
                $failed[] = $folder;
                if (isset($updates[$folder]) && is_array($updates[$folder])) $updates[$folder]['actionable'] = false;
                continue;
            }
            $fetched++;

            if (version_compare($latest['version'] ?? '0.0.0', $currentVersion, '>')) {
                $updates[$folder] = [
                    'current_version' => $currentVersion,
                    'new_version' => $latest['version'],
                    'download_url' => $latest['download_url'] ?? ($storeUrl . '/download/' . $storeSlug . '/'),
                    'changelog' => $latest['changelog'] ?? '',
                    'zip_size' => $latest['zip_size'] ?? 0,
                    'php_required' => $latest['php_required'] ?? '',
                    'checksum' => $latest['checksum'] ?? '',
                    'checked_at' => $now,
                    'actionable' => true,
                ];
            } else {
                unset($updates[$folder]);
            }
        }

        $updates = array_intersect_key($updates, $eligible);
        $state = $failed === [] ? 'ok' : ($fetched > 0 ? 'partial' : 'error');
        if ($eligible === []) $state = 'ok';
        $transient['last_attempt'] = $now;
        if ($state !== 'error') $transient['last_check'] = $now;
        $transient['state'] = $state;
        $transient['errors'] = $failed;
        $transient['updates'] = $updates;
        self::writeTransient($transient);

        return ['state' => $state, 'updates' => $updates, 'errors' => $failed];
    }

    public static function getCachedUpdates(): array
    {
        $transient = self::readTransient();
        $updates = is_array($transient['updates'] ?? null) ? $transient['updates'] : [];
        $freshAfter = time() - self::UPDATE_TTL;
        return array_filter($updates, static fn(mixed $update): bool => is_array($update)
            && ($update['actionable'] ?? false) === true
            && (int)($update['checked_at'] ?? 0) >= $freshAfter);
    }

    public static function applyUpdate(PDO $pdo, string $folderName, string $progressToken = ''): array
    {
        if (preg_match('/\A[a-zA-Z0-9_-][a-zA-Z0-9._-]*\z/', $folderName) !== 1 || in_array($folderName, ['.', '..'], true)) {
            return ['success' => false, 'error' => 'Invalid theme name.'];
        }
        require_once __DIR__ . '/UpdateStatusController.php';
        if (!UpdateStatusController::isUpdateActionable('themes', $folderName)) {
            $error = 'No update available for "' . $folderName . '". Run "Check for Updates" first.';
            if ($progressToken !== '') self::writeProgress($progressToken, 0, __('No update available.'), true, $error);
            return ['success' => false, 'error' => $error];
        }
        $updates = self::getCachedUpdates();
        $themeName = $folderName;

        if (!isset($updates[$themeName])) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, __('No update available.'), true, __('Run "Check for Updates" first.'));
            }
            return ['success' => false, 'error' => 'No update available for "' . $themeName . '". Run "Check for Updates" first.'];
        }

        $update = $updates[$themeName];
        $themesRoot = realpath(rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR));
        $themeCandidate = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folderName;
        $themeDir = realpath($themeCandidate);
        if ($themesRoot === false || $themeDir === false || is_link($themeCandidate)
            || ($themeDir !== $themesRoot && !str_starts_with($themeDir, $themesRoot . DIRECTORY_SEPARATOR))) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, __('Theme directory not found.'), true, __('Theme directory not found.'));
            }
            return ['success' => false, 'error' => 'Theme directory not found.'];
        }

        $p = function ($pct, $status) use ($progressToken) {
            if ($progressToken !== '') self::writeProgress($progressToken, $pct, $status);
        };

        $p(3, __('Starting update...'));

        $p(8, __('Backing up current theme...'));
        $backupVersion = preg_replace('/[^0-9A-Za-z._-]+/', '-', (string)$update['current_version']);
        $backupVersion = trim((string)$backupVersion, '-');
        if ($backupVersion === '') $backupVersion = 'unknown';
        $backupDir = dirname(self::transientFile()) . '/theme-backups/' . $folderName . '-' . $backupVersion;
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            return ['success' => false, 'error' => __('Failed to create theme backup directory.')];
        }
        $backupFile = $backupDir . '/backup.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::removeTree($backupDir);
            return ['success' => false, 'error' => __('Failed to create theme backup.')];
        }
        $backupOk = true;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if ($file->isLink() || !$file->isFile()) {
                $backupOk = false;
                break;
            }
            $localPath = substr($file->getPathname(), strlen($themeDir) + 1);
            if (!$zip->addFile($file->getPathname(), $localPath)) {
                $backupOk = false;
                break;
            }
        }
        if (!$zip->close() || !$backupOk || !is_file($backupFile)) {
            self::removeTree($backupDir);
            return ['success' => false, 'error' => __('Failed to create complete theme backup.')];
        }

        $tmpZip = self::downloadPackage((string)$update['download_url'], $p);
        if ($tmpZip === null) {
            self::removeTree($backupDir);
            self::writeProgress($progressToken, 0, __('Failed to download update.'), true, __('Failed to download update from store.'));
            return ['success' => false, 'error' => __('Failed to download update from store.')];
        }
        if (!is_string($update['checksum'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/i', (string)$update['checksum']) !== 1
            || !hash_equals(strtolower((string)$update['checksum']), hash_file('sha256', $tmpZip))) {
            @unlink($tmpZip);
            self::removeTree($backupDir);
            self::writeProgress($progressToken, 0, __('Invalid update package.'), true, __('Invalid update package.'));
            return ['success' => false, 'error' => __('Invalid update package.')];
        }

        $p(35, __('Download complete. Verifying package...'));

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            self::removeTree($backupDir);
            self::writeProgress($progressToken, 0, __('Invalid update package.'), true, __('Invalid update package.'));
            return ['success' => false, 'error' => __('Invalid update package.')];
        }

        $manifestRaw = $zip->getFromName('theme.json');
        if ($manifestRaw === false) {
            $zip->close(); unlink($tmpZip);
            self::removeTree($backupDir);
            self::writeProgress($progressToken, 0, __('theme.json not found in package.'), true);
            return ['success' => false, 'error' => __('theme.json not found in package.')];
        }

        $manifest = json_decode($manifestRaw, true);
        $manifestFolder = is_array($manifest) ? (string)($manifest['folder'] ?? $manifest['name'] ?? '') : '';
        if (!is_array($manifest) || $manifestFolder !== $folderName
            || !is_string($manifest['version'] ?? null)
            || version_compare($manifest['version'], (string)$update['new_version'], '!=')) {
            $zip->close(); unlink($tmpZip);
            self::removeTree($backupDir);
            self::writeProgress($progressToken, 0, __('Invalid theme.json.'), true);
            return ['success' => false, 'error' => __('Invalid theme.json.')];
        }

        $filesToExtract = [];
        $logicalTargets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = str_replace('\\', '/', (string)($zip->statIndex($i)['name'] ?? ''));
            $segments = explode('/', rtrim($filename, '/'));
            if ($filename === '' || str_contains($filename, "\0") || str_starts_with($filename, '/')
                || preg_match('/^[A-Za-z]:/', $filename) === 1 || in_array('', $segments, true)
                || in_array('.', $segments, true) || in_array('..', $segments, true)
                || self::zipEntryIsSymlink($zip, $i)) {
                $zip->close(); unlink($tmpZip);
                self::removeTree($backupDir);
                self::writeProgress($progressToken, 0, __('Invalid update package.'), true);
                return ['success' => false, 'error' => __('Invalid update package.')];
            }
            if (str_ends_with($filename, '/')) continue;
            $relative = preg_replace('#^' . preg_quote($folderName, '#') . '/#', '', $filename, 1);
            if (!is_string($relative) || $relative === '' || $relative === '.store.json'
                || $relative === '.git' || str_starts_with($relative, '.git/') || isset($logicalTargets[$relative])) {
                $zip->close(); unlink($tmpZip);
                self::removeTree($backupDir);
                self::writeProgress($progressToken, 0, __('Invalid update package.'), true);
                return ['success' => false, 'error' => __('Invalid update package.')];
            }
            $logicalTargets[$relative] = true;
            $filesToExtract[] = ['source' => $filename, 'relative' => $relative];
        }

        $p(45, __('Cleaning old files...'));
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($themeDir) + 1);
            if ($rel === '.store.json' || $rel === '.git' || str_starts_with($rel, '.git/')) continue;
            $removed = $f->isLink() || !$f->isDir() ? @unlink($f->getPathname()) : @rmdir($f->getPathname());
            if (!$removed && file_exists($f->getPathname())) {
                $zip->close(); unlink($tmpZip);
                self::restoreBackup($themeDir, $backupFile);
                self::writeProgress($progressToken, 0, __('Invalid update package.'), true);
                return ['success' => false, 'error' => __('Invalid update package.')];
            }
        }

        $p(55, __('Installing update files...'));
        $totalFiles = count($filesToExtract);
        $extractFailed = false;
        foreach ($filesToExtract as $i => $file) {
            $filename = $file['source'];
            $relative = $file['relative'];
            $target = self::safeThemeTarget($themeDir, $relative);
            if ($target === null) { $extractFailed = true; break; }
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $extractFailed = true;
                break;
            }
            if (self::safeThemeTarget($themeDir, $relative) !== $target) { $extractFailed = true; break; }
            $copied = @copy('zip://' . $tmpZip . '#' . $filename, $target);
            if (!$copied) { $extractFailed = true; break; }
            if ($progressToken !== '' && $totalFiles > 0 && (($i + 1) % max(1, intdiv($totalFiles, 20)) === 0 || $i + 1 === $totalFiles)) {
                $pct = 55 + (int)(30 * ($i + 1) / $totalFiles);
                $p($pct, __('Installing file') . ' (' . ($i + 1) . '/' . $totalFiles . ')...');
            }
        }
        $zip->close();

        if ($extractFailed) {
            $p(0, __('Failed! Restoring backup...'));
            $restored = self::restoreBackup($themeDir, $backupFile);
            unlink($tmpZip);
            $error = $restored ? __('Failed to extract update. Backup has been restored.') : __('Invalid update package.');
            self::writeProgress($progressToken, 0, $error, true);
            return ['success' => false, 'error' => $error];
        }

        unlink($tmpZip);

        $installedManifestPath = $themeDir . '/theme.json';
        $installedManifest = is_file($installedManifestPath) ? json_decode((string)file_get_contents($installedManifestPath), true) : null;
        $installedFolder = is_array($installedManifest) ? (string)($installedManifest['folder'] ?? $installedManifest['name'] ?? '') : '';
        if (!is_array($installedManifest) || $installedFolder !== $folderName
            || !is_string($installedManifest['version'] ?? null)
            || version_compare($installedManifest['version'], (string)$update['new_version'], '!=')) {
            $restored = self::restoreBackup($themeDir, $backupFile);
            $error = $restored ? __('Failed to extract update. Backup has been restored.') : __('Invalid update package.');
            self::writeProgress($progressToken, 0, $error, true);
            return ['success' => false, 'error' => $error];
        }

        $p(88, __('Updating theme manifest...'));
        try {
            if (!register_theme_in_db($pdo, $folderName, $installedManifest)) {
                throw new RuntimeException('Theme registration failed.');
            }
        } catch (Throwable $error) {
            error_log('[theme-update] Unable to register updated theme: ' . $error->getMessage());
            $restored = self::restoreBackup($themeDir, $backupFile);
            if ($restored) {
                $restoredManifest = json_decode((string)@file_get_contents($themeDir . '/theme.json'), true);
                if (is_array($restoredManifest)) {
                    try {
                        register_theme_in_db($pdo, $folderName, $restoredManifest);
                    } catch (Throwable $restoreError) {
                        error_log('[theme-update] Unable to restore theme database metadata: ' . $restoreError->getMessage());
                    }
                }
            }
            $message = $restored ? __('Failed to extract update. Backup has been restored.') : __('Invalid update package.');
            self::writeProgress($progressToken, 0, $message, true);
            return ['success' => false, 'error' => $message];
        }
        self::removeTree($backupDir);

        $p(95, __('Finishing...'));
        $transient = self::readTransient();
        unset($transient['updates'][$themeName]);
        self::writeTransient($transient);

        self::writeProgress($progressToken, 100, __('Done!'), true);

        return ['success' => true, 'new_version' => $update['new_version']];
    }

    private static function safeThemeTarget(string $root, string $relative): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) return null;
        $parts = explode('/', $relative);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) return null;
        $base = realpath($root);
        if ($base === false) return null;
        $parentPath = dirname($base . '/' . $relative);
        while (!file_exists($parentPath) && dirname($parentPath) !== $parentPath) $parentPath = dirname($parentPath);
        $parent = realpath($parentPath);
        if ($parent === false || ($parent !== $base && !str_starts_with($parent, $base . DIRECTORY_SEPARATOR))) return null;
        return $base . '/' . $relative;
    }

    private static function zipEntryIsSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;
        return $zip->getExternalAttributesIndex($index, $opsys, $attributes)
            && (($attributes >> 16) & 0170000) === 0120000;
    }

    private static function restoreBackup(string $themeDir, string $backupFile): bool
    {
        if (!is_file($backupFile) || !self::removeTreeContents($themeDir)) return false;
        $zip = new ZipArchive();
        if ($zip->open($backupFile) !== true) return false;
        $restored = $zip->extractTo($themeDir);
        $closed = $zip->close();
        return $restored && $closed;
    }

    private static function removeTreeContents(string $directory): bool
    {
        if (!is_dir($directory)) return false;
        $ok = true;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $removed = $file->isLink() || !$file->isDir() ? @unlink($file->getPathname()) : @rmdir($file->getPathname());
            if (!$removed && file_exists($file->getPathname())) $ok = false;
        }
        return $ok;
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        self::removeTreeContents($directory);
        @rmdir($directory);
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

    public static function clearProgress(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return;
        $file = self::progressFile($token);
        if (is_file($file)) @unlink($file);
    }

    private static function transientFile(): string
    {
        $backend = defined('BACKEND_PATH') ? BACKEND_PATH : __DIR__ . '/../../cfg';
        return $backend . '/var/theme-update-transient.json';
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
        $progress(18, __('Downloading update package...'));
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'JyavaniCMS-ThemeUpdate']]);
        $input = @fopen($url, 'rb', false, $ctx);
        if ($input === false) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'theme-update-') . '.zip';
        $output = @fopen($tmp, 'wb');
        if ($output === false) { fclose($input); return null; }

        $length = 0;
        foreach ((array)(stream_get_meta_data($input)['wrapper_data'] ?? []) as $header) {
            if (preg_match('/^Content-Length:\s*(\d+)/i', (string)$header, $match)) $length = (int)$match[1];
        }
        $downloaded = 0;
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) { fclose($input); fclose($output); @unlink($tmp); return null; }
            if ($chunk === '') continue;
            if (fwrite($output, $chunk) !== strlen($chunk)) { fclose($input); fclose($output); @unlink($tmp); return null; }
            $downloaded += strlen($chunk);
            if ($length > 0) $progress(18 + (int)floor(17 * min(1, $downloaded / $length)), __('Downloading update package...'));
        }
        fclose($input);
        fclose($output);
        return $tmp;
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

    private static function scanThemes(PDO $pdo): array
    {
        $themes = [];
        $registered = get_registered_themes($pdo);
        foreach ($registered as $t) {
            $folder = $t['folder_name'] ?? '';
            if ($folder === '') continue;

            $manifest = [];
            try {
                $manifest = read_theme_manifest(path_candidate(VIEWS_BASE, $folder, ''));
            } catch (Throwable $e) {
                $manifest = [];
            }

            $themes[$folder] = $manifest;
        }
        return $themes;
    }
}
