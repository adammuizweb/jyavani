<?php
declare(strict_types=1);

class DownloadController
{
    private const VERSION_FILE = __DIR__ . '/../../VERSION';
    private const GIT_DIR = __DIR__ . '/../../.git';
    // Files to exclude are defined in .gitattributes (export-ignore)

    public static function intro(PDO $pdo): void
    {
        $version = self::readVersion();

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
            echo '<p>CMS native PHP tanpa framework. Cepat, ringan, dan mudah dikustomisasi.</p>';
            echo '<p><a href="/download/latest/" class="btn">Download v' . e($version) . '</a></p>';
            echo '</section>';
        }
        $content_html = (string)ob_get_clean();

        require __DIR__ . '/../layout.php';
        exit;
    }

    public static function latest(PDO $pdo): void
    {
        $version = self::readVersion();

        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            self::serveVersionJson($version);
            return;
        }

        self::serveZip($version);
    }

    private static function readVersion(): string
    {
        $file = realpath(self::VERSION_FILE);
        if ($file && is_file($file)) {
            $v = trim(file_get_contents($file));
            if ($v !== '') {
                return $v;
            }
        }
        return 'dev';
    }

    private static function serveVersionJson(string $version): void
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'jyavani.com';
        $base = $scheme . '://' . $host;

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');

        echo json_encode([
            'version'      => $version,
            'download_url' => $base . '/download/latest/',
            'release_date' => self::getReleaseDate(),
            'name'         => 'Jyavani CMS',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function getReleaseDate(): string
    {
        $gitDir = realpath(self::GIT_DIR);
        if ($gitDir && is_dir($gitDir)) {
            $head = @file_get_contents($gitDir . '/HEAD');
            if ($head && preg_match('#^ref:\s+(refs/heads/\S+)#m', $head, $m)) {
                $refPath = $gitDir . '/' . $m[1];
                if (is_file($refPath)) {
                    $log = $gitDir . '/logs/' . $m[1];
                    if (is_file($log)) {
                        $lines = file($log);
                        $last = trim(end($lines));
                        if ($last && preg_match('/^[\d]+\s+[\d]+\s+([\d.+\-: ]+)/', $last, $dm)) {
                            return date('c', (int)strtotime($dm[1]));
                        }
                    }
                }
            }
        }
        return date('c');
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
