<?php
declare(strict_types=1);
/**
 * CMS Package Builder — Build update zip for distribution
 * Usage: php tools/build-package.php [output-path]
 * 
 * Creates a zip containing ONLY CMS core files (excludes preserve items).
 * The zip includes cms-manifest.json and version.json at root.
 */

$ROOT = dirname(__DIR__);
$OUTPUT = $argv[1] ?? $ROOT . '/jyavani-cms-update.zip';

// Generate manifest first
echo "Generating manifest...\n";
passthru('php ' . escapeshellarg($ROOT . '/tools/generate-manifest.php'));

$manifestFile = $ROOT . '/tools/cms-manifest.json';
if (!is_file($manifestFile)) {
    echo "ERROR: Manifest generation failed.\n";
    exit(1);
}

$manifest = json_decode(file_get_contents($manifestFile), true);
$version = $manifest['version'] ?? '0.0.0';
$files = $manifest['files'] ?? [];

echo "Building package v{$version} ({$manifest['total_files']} files)...\n";

$zip = new ZipArchive();
if ($zip->open($OUTPUT, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "ERROR: Cannot create {$OUTPUT}\n";
    exit(1);
}

// Add root files
foreach (['version.json', 'cms-manifest.json'] as $rootFile) {
    $src = $ROOT . '/' . $rootFile;
    if (is_file($src)) {
        $zip->addFile($src, $rootFile);
    }
}
$zip->addFile($manifestFile, 'cms-manifest.json');

// Exclude tool & preserve files from package
$excludeFromPackage = [
    'tools/generate-manifest.php',
    'tools/build-package.php',
    'tools/cms-manifest.json',
    'cfg/.gitignore',
    'cfg/var/.gitignore',
];

// Add all core files from manifest
foreach ($files as $relative => $hash) {
    if (in_array($relative, $excludeFromPackage, true)) continue;
    $source = $ROOT . '/' . $relative;
    if (is_file($source)) {
        $zip->addFile($source, $relative);
    }
}

$zip->close();

$size = filesize($OUTPUT);
$fileCount = $zip->numFiles;

echo "\nDone.\n";
echo "Version: {$version}\n";
echo "Files in zip: {$manifest['total_files']}\n";
echo "Output: {$OUTPUT}\n";
echo "Size: " . number_format($size) . " bytes (" . round($size / 1024 / 1024, 2) . " MB)\n";
