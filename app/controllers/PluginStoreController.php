<?php
declare(strict_types=1);

class PluginStoreController
{
    private const STORE_DIR = __DIR__ . '/../../plugin-store';

    public static function list(PDO $pdo): void
    {
        $plugins = self::scanPlugins();

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'jyavani.com';
        $base = $scheme . '://' . $host . '/plugin-store';

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'store_name' => 'Jyavani Plugin Store',
            'store_url' => $base . '/',
            'plugins' => $plugins,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function download(PDO $pdo, string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            http_response_code(400);
            echo 'Invalid plugin name.';
            exit;
        }

        $pluginDir = realpath(self::STORE_DIR . '/plugins/' . $name);
        $storePlugins = realpath(self::STORE_DIR . '/plugins');
        if (!$pluginDir || !is_dir($pluginDir) || strpos($pluginDir, $storePlugins) !== 0) {
            http_response_code(404);
            echo 'Plugin not found.';
            exit;
        }

        $manifest = [];
        if (is_file($pluginDir . '/plugin.json')) {
            $m = json_decode(file_get_contents($pluginDir . '/plugin.json'), true);
            if (is_array($m)) $manifest = $m;
        }
        $version = $manifest['version'] ?? '0.0.0';

        $filename = $name . '-' . $version . '.zip';
        $tmpFile = tempnam(sys_get_temp_dir(), 'plugin-');
        if ($tmpFile === false) {
            http_response_code(500);
            echo 'Failed to create temp file.';
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            http_response_code(500);
            echo 'Failed to create zip.';
            exit;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($pluginDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    }

    private static function scanPlugins(): array
    {
        $pluginsDir = realpath(self::STORE_DIR . '/plugins');
        if (!$pluginsDir || !is_dir($pluginsDir)) return [];

        $result = [];
        $host = $_SERVER['HTTP_HOST'] ?? 'jyavani.com';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $host . '/plugin-store';

        foreach (glob($pluginsDir . '/*/plugin.json') as $manifestFile) {
            $data = json_decode(file_get_contents($manifestFile), true);
            if (!is_array($data) || empty($data['name'])) continue;

            $result[] = [
                'name' => $data['name'],
                'title' => $data['title'] ?? $data['name'],
                'version' => $data['version'] ?? '0.0.0',
                'description' => $data['description'] ?? '',
                'author' => $data['author'] ?? '',
                'homepage' => $data['homepage'] ?? '',
                'php_required' => $data['php_required'] ?? '8.1',
                'checksum' => $data['checksum'] ?? '',
                'download_url' => $base . '/download/' . $data['name'] . '/',
            ];
        }

        return $result;
    }
}
