<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jy-theme-package-' . bin2hex(random_bytes(6));
mkdir($fixture . '/public/views/themes', 0770, true);
define('PUBLIC_PATH', $fixture . '/public');
define('VIEWS_BASE', $fixture . '/public/views/themes');
require_once $root . '/cfg/helpers/theme_helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$makeZip = static function (string $path, string $entry, ?int $unixType = null): void {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('safe/theme.json', json_encode(['folder' => 'safe', 'name' => 'safe', 'version' => '1.0.0']));
    $zip->addFromString($entry, 'payload');
    if ($unixType !== null) $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, ($unixType | 0777) << 16);
    $zip->close();
};

try {
    $traversal = $fixture . '/traversal.zip';
    $makeZip($traversal, 'safe/../escape.php');
    $traversalResult = install_theme_from_zip(null, $traversal, false, null, 'safe');
    $check(($traversalResult['success'] ?? false) === false, 'theme installer rejects traversal entries');

    $symlink = $fixture . '/symlink.zip';
    $makeZip($symlink, 'safe/link.php', 0120000);
    $symlinkResult = install_theme_from_zip(null, $symlink, false, null, 'safe');
    $check(($symlinkResult['success'] ?? false) === false, 'theme installer rejects symlink entries');

    $fifo = $fixture . '/fifo.zip';
    $makeZip($fifo, 'safe/pipe', 0010000);
    $fifoResult = install_theme_from_zip(null, $fifo, false, null, 'safe');
    $check(($fifoResult['success'] ?? false) === false, 'theme installer rejects special filesystem entries');

    $identity = $fixture . '/identity.zip';
    $makeZip($identity, 'safe/header.php');
    $identityResult = install_theme_from_zip(null, $identity, false, null, 'different');
    $check(($identityResult['success'] ?? false) === false, 'Store theme installation binds the requested package identity');

    $manifestOnly = $fixture . '/manifest-only.zip';
    $zip = new ZipArchive();
    $zip->open($manifestOnly, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('theme.json', json_encode(['folder' => 'safe', 'name' => 'safe', 'version' => '1.0.0']));
    $zip->close();
    $manifestOnlyResult = install_theme_from_zip(null, $manifestOnly, false, null, 'safe');
    $check(($manifestOnlyResult['success'] ?? false) === false, 'theme installer rejects manifest-only packages');

    $missingManifest = $fixture . '/missing-manifest.zip';
    $zip = new ZipArchive();
    $zip->open($missingManifest, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('safe/header.php', '<?php echo "safe";');
    $zip->close();
    $missingManifestResult = install_theme_from_zip(null, $missingManifest, false, null, 'safe');
    $check(($missingManifestResult['success'] ?? false) === false, 'theme installer requires a valid manifest');

    $ratioBomb = $fixture . '/ratio.zip';
    $zip = new ZipArchive();
    $zip->open($ratioBomb, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('safe/theme.json', json_encode(['folder' => 'safe', 'name' => 'safe', 'version' => '1.0.0']));
    $zip->addFromString('safe/header.php', '<?php /*' . str_repeat('A', 2 * 1024 * 1024) . '*/');
    $zip->close();
    $ratioResult = install_theme_from_zip(null, $ratioBomb, false, null, 'safe');
    $check(($ratioResult['success'] ?? false) === false, 'theme installer rejects extreme compression ratios');
} finally {
    if (is_dir($fixture)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        @rmdir($fixture);
    }
}

if ($failures !== []) exit(1);
echo "RESULT: ALL PASS\n";
