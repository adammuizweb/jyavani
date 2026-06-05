<?php
declare(strict_types=1);

class ThemeStoreClient
{
    private const STORE_BASE = 'https://jyavani.com/theme-store';

    public static function checkUpdates(PDO $pdo): array
    {
        $transient = self::readTransient();
        $themes = self::scanThemes($pdo);
        $updates = $transient['updates'] ?? [];
        $anyFetched = false;

        foreach ($themes as $name => $manifest) {
            $store = $manifest['store'] ?? null;
            if (!$store) continue;
            $storeUrl = rtrim($store['url'] ?? self::STORE_BASE, '/');
            $currentVersion = $manifest['version'] ?? '0.0.0';

            $latest = self::fetchVersionInfo($storeUrl . '/' . $name . '/version.json');
            if ($latest === null) continue;
            $anyFetched = true;

            if (version_compare($latest['version'] ?? '0.0.0', $currentVersion, '>')) {
                $updates[$name] = [
                    'current_version' => $currentVersion,
                    'new_version' => $latest['version'],
                    'download_url' => $latest['download_url'] ?? ($storeUrl . '/download/' . $name . '/'),
                    'changelog' => $latest['changelog'] ?? '',
                    'zip_size' => $latest['zip_size'] ?? 0,
                    'php_required' => $latest['php_required'] ?? '',
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

    public static function applyUpdate(PDO $pdo, string $folderName, string $progressToken = ''): array
    {
        $updates = self::getCachedUpdates();
        $themeName = $folderName;

        if (!isset($updates[$themeName])) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, __('No update available.'), true, __('Run "Check for Updates" first.'));
            }
            return ['success' => false, 'error' => 'No update available for "' . $themeName . '". Run "Check for Updates" first.'];
        }

        $update = $updates[$themeName];
        $themeDir = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folderName;
        if (!is_dir($themeDir)) {
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
        $backupDir = dirname(self::transientFile()) . '/theme-backups/' . $folderName . '-' . $update['current_version'];
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        $backupFile = $backupDir . '/backup.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($themeDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                $localPath = substr($file->getPathname(), strlen($themeDir) + 1);
                $zip->addFile($file->getPathname(), $localPath);
            }
            $zip->close();
        }

        $p(18, __('Downloading update package...'));
        $zipContent = @file_get_contents($update['download_url']);
        if ($zipContent === false) {
            self::writeProgress($progressToken, 0, __('Failed to download update.'), true, __('Failed to download update from store.'));
            return ['success' => false, 'error' => __('Failed to download update from store.')];
        }

        $p(35, __('Download complete. Verifying package...'));
        $tmpZip = tempnam(sys_get_temp_dir(), 'update-') . '.zip';
        file_put_contents($tmpZip, $zipContent);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            self::writeProgress($progressToken, 0, __('Invalid update package.'), true, __('Invalid update package.'));
            return ['success' => false, 'error' => __('Invalid update package.')];
        }

        $manifestRaw = $zip->getFromName('theme.json');
        if ($manifestRaw === false) {
            $zip->close(); unlink($tmpZip);
            self::writeProgress($progressToken, 0, __('theme.json not found in package.'), true);
            return ['success' => false, 'error' => __('theme.json not found in package.')];
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || empty($manifest['name']) || $manifest['name'] !== $folderName) {
            $zip->close(); unlink($tmpZip);
            self::writeProgress($progressToken, 0, __('Invalid theme.json.'), true);
            return ['success' => false, 'error' => __('Invalid theme.json.')];
        }

        $p(45, __('Cleaning old files...'));
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($themeDir) + 1);
            if ($rel === '.store.json' || str_starts_with($rel, '.git/')) continue;
            if ($f->isDir()) @rmdir($f->getPathname());
            else @unlink($f->getPathname());
        }

        $p(55, __('Installing update files...'));
        $totalFiles = $zip->numFiles;
        $extractFailed = false;
        for ($i = 0; $i < $totalFiles; $i++) {
            $filename = $zip->statIndex($i)['name'] ?? '';
            if (str_ends_with($filename, '/')) continue;
            $relative = preg_replace('#^' . preg_quote($folderName, '#') . '/#', '', $filename, 1);
            $target = $themeDir . '/' . $relative;
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $copied = @copy('zip://' . $tmpZip . '#' . $filename, $target);
            if (!$copied) { $extractFailed = true; break; }
            if ($progressToken !== '' && $totalFiles > 0 && $i % max(1, intdiv($totalFiles, 10)) === 0) {
                $pct = 55 + (int)(30 * ($i + 1) / $totalFiles);
                $p($pct, __('Installing file') . ' (' . ($i + 1) . '/' . $totalFiles . ')...');
            }
        }
        $zip->close();

        if ($extractFailed) {
            $p(0, __('Failed! Restoring backup...'));
            if (is_file($backupFile)) {
                $bz = new ZipArchive();
                if ($bz->open($backupFile) === true) {
                    $bz->extractTo($themeDir);
                    $bz->close();
                }
            }
            unlink($tmpZip);
            self::writeProgress($progressToken, 0, __('Failed to extract update. Backup has been restored.'), true);
            return ['success' => false, 'error' => __('Failed to extract update. Backup has been restored.')];
        }

        unlink($tmpZip);

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

        $p(88, __('Updating theme manifest...'));
        $manifestPath = $themeDir . '/theme.json';
        $existingManifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
        if ($existingManifest) {
            $existingManifest['version'] = $update['new_version'];
            file_put_contents($manifestPath, json_encode($existingManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        register_theme_in_db($pdo, $folderName);

        $p(95, __('Finishing...'));
        $transient = self::readTransient();
        unset($transient['updates'][$themeName]);
        self::writeTransient($transient);

        self::writeProgress($progressToken, 100, __('Done!'), true);

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

            $name = $manifest['name'] ?? $folder;
            $themes[$name] = $manifest;
        }
        return $themes;
    }
}
