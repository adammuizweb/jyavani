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
            if (!$store) continue;
            $storeUrl = rtrim($store['url'] ?? 'https://jyavani.com/plugin-store/', '/');
            $currentVersion = $manifest['version'] ?? '0.0.0';

            $latest = self::fetchVersionInfo($storeUrl . '/' . $name . '/version.json');
            if ($latest === null) {
                continue;
            }
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

    public static function applyUpdate(PDO $pdo, string $name, string $progressToken = ''): array
    {
        $updates = self::getCachedUpdates();
        if (!isset($updates[$name])) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, 'Tidak ada update tersedia.', true, 'Tidak ada update tersedia. Jalankan "Check for Updates" terlebih dahulu.');
            }
            return ['success' => false, 'error' => 'No update available for "' . $name . '". Run "Check for Updates" first.'];
        }

        $update = $updates[$name];
        $pluginDir = (defined('PLUGIN_PATH') ? PLUGIN_PATH : __DIR__ . '/../../plugins') . '/' . $name;
        if (!is_dir($pluginDir)) {
            if ($progressToken !== '') {
                self::writeProgress($progressToken, 0, 'Direktori plugin tidak ditemukan.', true, 'Direktori plugin tidak ditemukan.');
            }
            return ['success' => false, 'error' => 'Plugin directory not found.'];
        }

        $p = function ($pct, $status) use ($progressToken) {
            if ($progressToken !== '') self::writeProgress($progressToken, $pct, $status);
        };

        $p(3, 'Memulai update...');

        // Backup
        $p(8, 'Membackup plugin saat ini...');
        $backupDir = dirname(self::transientFile()) . '/plugin-backups/' . $name . '-' . $update['current_version'];
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        $backupFile = $backupDir . '/backup.zip';
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                $localPath = substr($file->getPathname(), strlen($pluginDir) + 1);
                $zip->addFile($file->getPathname(), $localPath);
            }
            $zip->close();
        }

        // Download update
        $p(18, 'Mengunduh paket update...');
        $zipContent = @file_get_contents($update['download_url']);
        if ($zipContent === false) {
            self::writeProgress($progressToken, 0, 'Gagal mengunduh update.', true, 'Gagal mengunduh update dari store.');
            return ['success' => false, 'error' => 'Gagal mengunduh update dari store.'];
        }

        $p(35, 'Unduhan selesai. Memverifikasi paket...');
        $tmpZip = tempnam(sys_get_temp_dir(), 'update-') . '.zip';
        file_put_contents($tmpZip, $zipContent);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            self::writeProgress($progressToken, 0, 'Paket update tidak valid.', true, 'Paket update tidak valid.');
            return ['success' => false, 'error' => 'Paket update tidak valid.'];
        }

        // Hapus files lama
        $p(45, 'Membersihkan file lama...');
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($pluginDir) + 1);
            if ($rel === '.store.json' || str_starts_with($rel, '.git/')) continue;
            if ($f->isDir()) @rmdir($f->getPathname());
            else @unlink($f->getPathname());
        }

        // Extract update
        $p(55, 'Memasang file update...');
        $totalFiles = $zip->numFiles;
        $extractFailed = false;
        for ($i = 0; $i < $totalFiles; $i++) {
            $filename = $zip->statIndex($i)['name'] ?? '';
            if (str_ends_with($filename, '/')) continue;
            $relative = preg_replace('#^' . preg_quote($name, '#') . '/#', '', $filename, 1);
            $target = $pluginDir . '/' . $relative;
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            $copied = @copy('zip://' . $tmpZip . '#' . $filename, $target);
            if (!$copied) {
                $extractFailed = true;
                break;
            }
            if ($progressToken !== '' && $totalFiles > 0 && $i % max(1, intdiv($totalFiles, 10)) === 0) {
                $pct = 55 + (int)(30 * ($i + 1) / $totalFiles);
                $p($pct, 'Memasang file (' . ($i + 1) . '/' . $totalFiles . ')...');
            }
        }
        $zip->close();

        if ($extractFailed) {
            $p(0, 'Gagal! Mengembalikan backup...');
            if (is_file($backupFile)) {
                $bz = new ZipArchive();
                if ($bz->open($backupFile) === true) {
                    $bz->extractTo($pluginDir);
                    $bz->close();
                }
            }
            unlink($tmpZip);
            self::writeProgress($progressToken, 0, 'Gagal mengekstrak update. Backup sudah dikembalikan.', true, 'Gagal mengekstrak update. Backup sudah dikembalikan.');
            return ['success' => false, 'error' => 'Gagal mengekstrak update. Backup sudah dikembalikan.'];
        }

        unlink($tmpZip);

        // Hapus backup setelah update sukses
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

        // Update plugin.json version
        $p(88, 'Memperbarui manifest plugin...');
        $manifestPath = $pluginDir . '/plugin.json';
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
        if ($manifest) {
            $manifest['version'] = $update['new_version'];
            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
}
