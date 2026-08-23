<?php
declare(strict_types=1);
/**
 * CMS Manifest Generator
 * Usage: php tools/generate-manifest.php
 * Output: tools/cms-manifest.json (core file hashes for update system)
 * 
 * Scans the project and generates SHA256 hashes for all CMS core files.
 * Files matching PRESERVE patterns are EXCLUDED (not part of CMS core).
 */

$ROOT = dirname(__DIR__);

// Allow re-use from build-package.php (skip re-run if already defined)
if (!defined('GENERATE_MANIFEST_RUNNING')) {
    define('GENERATE_MANIFEST_RUNNING', true);
}

// Patterns that are SITE-SPECIFIC (preserved during update)
const PRESERVE_PATTERNS = [
    // Config
    '#^cfg/\.env$#',
    '#^cfg/var/#',
    '#^cfg/session_debug\.log$#',
    '#^cfg/php-noteloc\.ini$#',
    '#^cfg/\.env\.old$#',
    '#^cfg/site-router\.php$#',
    '#^cfg/community-i18n\.php$#',

    // Git
    '#^\.git/#',
    '#^\.gitignore$#',
    '#^\.gitattributes$#',

    // Docs
    '#^AGENTS\.md$#',
    '#^README\.md$#',
    '#^INSTALL\.md$#',
    '#^SERVER_SETUP\.md$#',

    // Uploaded content. Root image assets are bundled Core branding; dated
    // directories hold site uploads and must survive updates.
    '#^public/static/img/\d{4}/#',
    '#^public/static/files/#',
    '#^public/sitemaps/#',
    '#^private_files/#',

    // User-installed themes are preserved; only the default system theme ships with Core.
    '#^public/views/themes/(?!default(?:/|$))[^/]+$#',
    '#^public/views/themes/(?!default(?:/|$))[^/]+/.+#',

    // Plugins
    '#^plugins/[^/]+/.+#',
    '#^public/static/plugins/#',

    // Community/Store extensions maintained outside the Core package
    '#^app/controllers/DownloadController\.php$#',
    '#^dashboard/admin/community/#',
    '#^public/download/#',
    '#^public/static/community/#',
    '#^public/views/community/#',
    '#^public/views/member/#',
    '#^schema/community\.sql$#',
    '#^schema/migrations/008-dev-status-varchar\.sql$#',
    '#^theme-store/#',
    '#^tools/import_core_demo_multilingual\.php$#',
    '#^tools/localize_community\.php$#',
    '#^tools/dev-user\.php$#',
    '#^tools/data/#',

    // Plugin-installed vendor assets
    '#^public/static/vendor/xterm/#',
    '#^public/static/vendor/jyavani-builder/#',
    '#^public/static/js/photo_canvas\.js$#',

    // Node modules
    '#node_modules/#',

    // Generated / runtime
    '#^tools/cms-manifest\.json$#',
    '#^var/#',
    '#\.DS_Store$#',
    '#Thumbs\.db$#',

    // PWA files (adammuiz.com specific, preserved)
    '#^public/pdf/#',
];

function isPreserved(string $relative): bool {
    foreach (PRESERVE_PATTERNS as $pattern) {
        if (preg_match($pattern, $relative)) return true;
    }
    return false;
}

echo "Scanning {$ROOT}...\n";

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($ROOT, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
    RecursiveIteratorIterator::CATCH_GET_CHILD
);

foreach ($it as $fileinfo) {
    $realPath = $fileinfo->getRealPath();
    if (!$realPath || !$fileinfo->isFile()) continue;

    $relative = substr($realPath, strlen($ROOT) + 1);

    // Skip preserved paths
    if (isPreserved($relative)) continue;

    // Only include certain top-level dirs (CMS core)
    $parts = explode('/', $relative, 2);
    $topDir = $parts[0];

    $allowedDirs = ['app', 'cfg', 'dashboard', 'plugins', 'public', 'schema', 'tools'];
    $allowedRootFiles = ['version.json', 'router.php', 'VERSION', '.gitattributes', 'LICENSE'];

    if ($topDir === $relative) {
        if (!in_array($relative, $allowedRootFiles, true)) continue;
    } elseif (!in_array($topDir, $allowedDirs, true)) {
        continue;
    }

    $hash = hash_file('sha256', $realPath);
    $files[$relative] = $hash;

    if (count($files) % 100 === 0) {
        echo "  ... {$relative}\n";
    }
}

ksort($files);

try {
    $versionJson = file_get_contents($ROOT . '/version.json');
    if ($versionJson === false) throw new RuntimeException('Cannot read version.json.');
    $version = json_decode($versionJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($version)) throw new RuntimeException('version.json must contain a JSON object.');
} catch (Throwable $error) {
    fwrite(STDERR, "ERROR: Invalid version.json: {$error->getMessage()}\n");
    exit(1);
}
$manifest = [
    'name' => $version['name'] ?? 'Jyavani CMS',
    'version' => $version['version'] ?? '0.0.0',
    'build' => $version['build'] ?? date('Y-m-d'),
    'generated' => date('c'),
    'edition' => $version['edition'] ?? '',
    'php_required' => $version['php_required'] ?? '8.1',
    'mysql_required' => $version['mysql_required'] ?? '5.7',
    'total_files' => count($files),
    'files' => $files,
];

$outPath = $ROOT . '/tools/cms-manifest.json';
try {
    $encodedManifest = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (JsonException $error) {
    fwrite(STDERR, "ERROR: Cannot encode manifest: {$error->getMessage()}\n");
    exit(1);
}
if (file_put_contents($outPath, $encodedManifest) === false) {
    fwrite(STDERR, "ERROR: Cannot write manifest: {$outPath}\n");
    exit(1);
}

echo "\nDone. {$manifest['total_files']} files hashed.\n";
echo "Manifest: {$outPath}\n";
echo "Size: " . number_format(filesize($outPath)) . " bytes\n";
