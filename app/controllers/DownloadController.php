<?php
declare(strict_types=1);

class DownloadController
{
    private const VERSION_JSON = __DIR__ . '/../../version.json';
    private const MANIFEST_JSON = __DIR__ . '/../../tools/cms-manifest.json';
    private const GIT_DIR = __DIR__ . '/../../.git';

    public static function intro(PDO $pdo): void
    {
        $info = self::readVersionInfo();
        $version = $info['version'] ?? 'dev';

        $page_title = 'Download Jyavani CMS';
        $context_for_layout = 'download';

        $themeFolder = settings_get($pdo, 'active_theme', 'default') ?? 'default';
        $themeFile = PUBLIC_PATH . '/views/themes/' . $themeFolder . '/main/download-intro.php';
        $fallbackFile = PUBLIC_PATH . '/views/themes/default/main/download-intro.php';

        ob_start();
        if (is_file($themeFile)) {
            require $themeFile;
        } elseif (is_file($fallbackFile)) {
            require $fallbackFile;
        } else {
            echo '<section class="page-content" style="max-width:720px;margin:2rem auto;padding:0 1rem">';
            echo '<h1>Jyavani CMS</h1>';
            echo '<p>CMS native PHP tanpa framework.</p>';
            echo '<p><a href="/download/latest/" class="btn">Download v' . e($version) . '</a></p>';
            echo '</section>';
        }
        $content_html = (string)ob_get_clean();

        require __DIR__ . '/../layout.php';
        exit;
    }

    public static function latest(PDO $pdo): void
    {
        $info = self::readVersionInfo();

        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            self::serveVersionJson($info);
            return;
        }

        self::serveZip($info['version']);
    }

    private static function readVersionInfo(): array
    {
        $default = [
            'name'           => 'Jyavani CMS',
            'version'        => 'dev',
            'build'          => '',
            'edition'        => 'Hidden Admin',
            'php_required'   => '8.1',
            'mysql_required' => '5.7',
            'author'         => 'Adam Muiz',
            'homepage'       => 'https://jyavani.com',
        ];

        $file = realpath(self::VERSION_JSON);
        if ($file && is_file($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                return array_merge($default, $data);
            }
        }
        return $default;
    }

    private static function serveVersionJson(array $info): void
    {
        $totalFiles = 0;
        $manifestFile = realpath(self::MANIFEST_JSON);
        if ($manifestFile && is_file($manifestFile)) {
            $m = json_decode(file_get_contents($manifestFile), true);
            if (is_array($m) && isset($m['total_files'])) {
                $totalFiles = (int)$m['total_files'];
            }
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'jyavani.com';
        $base = $scheme . '://' . $host;

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');

        echo json_encode([
            'name'           => $info['name'] ?? 'Jyavani CMS',
            'version'        => $info['version'] ?? 'dev',
            'build'          => $info['build'] ?? '',
            'edition'        => $info['edition'] ?? 'Hidden Admin',
            'php_required'   => $info['php_required'] ?? '8.1',
            'mysql_required' => $info['mysql_required'] ?? '5.7',
            'total_files'    => $totalFiles,
            'download_url'   => $base . '/download/latest/',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function serveZip(string $version): void
    {
        $gitDir = realpath(self::GIT_DIR);
        if (!$gitDir || !is_dir($gitDir)) {
            http_response_code(500);
            echo 'Git repository not available.';
            exit;
        }

        $prefix = 'jyavani-cms-' . $version . '/';
        $filename = 'jyavani-cms-' . $version . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');

        $cmd = 'git --git-dir=' . escapeshellarg($gitDir)
            . ' archive --format=zip --prefix=' . escapeshellarg($prefix)
            . ' HEAD';

        putenv('GIT_DIR=' . $gitDir);
        passthru($cmd, $exitCode);
        exit;
    }
}
