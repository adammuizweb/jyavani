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
$TEMP_OUTPUT = $OUTPUT . '.tmp-' . getmypid();

// Generate manifest first
echo "Generating manifest...\n";
$generateExitCode = 0;
passthru('php ' . escapeshellarg($ROOT . '/tools/generate-manifest.php'), $generateExitCode);
if ($generateExitCode !== 0) {
    echo "ERROR: Manifest generation failed with exit code {$generateExitCode}.\n";
    exit(1);
}

$manifestFile = $ROOT . '/tools/cms-manifest.json';
if (!is_file($manifestFile)) {
    echo "ERROR: Manifest generation failed.\n";
    exit(1);
}

$manifest = json_decode((string)file_get_contents($manifestFile), true);
$versionData = json_decode((string)file_get_contents($ROOT . '/version.json'), true);
$plainVersion = trim((string)file_get_contents($ROOT . '/VERSION'));
if (!is_array($manifest) || !is_array($manifest['files'] ?? null)
    || !is_array($versionData) || ($manifest['version'] ?? '') !== ($versionData['version'] ?? null)
    || $plainVersion !== ($versionData['version'] ?? null)) {
    echo "ERROR: Generated manifest is invalid or stale.\n";
    exit(1);
}
$version = $manifest['version'] ?? '0.0.0';
$files = $manifest['files'] ?? [];

echo "Building package v{$version} ({$manifest['total_files']} files)...\n";

$zip = new ZipArchive();
if ($zip->open($TEMP_OUTPUT, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "ERROR: Cannot create {$OUTPUT}\n";
    exit(1);
}

// The manifest itself is package metadata and is not hashed into itself.
$zip->addFile($manifestFile, 'cms-manifest.json');

// Exclude tool & preserve files from package
$excludeFromPackage = [
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

if (!$zip->close()) {
    @unlink($TEMP_OUTPUT);
    echo "ERROR: Cannot finalize package.\n";
    exit(1);
}

// Verify the completed temporary artifact before replacing the published ZIP.
$verify = new ZipArchive();
if ($verify->open($TEMP_OUTPUT) !== true) {
    @unlink($TEMP_OUTPUT);
    echo "ERROR: Built package cannot be reopened.\n";
    exit(1);
}
foreach ($files as $relative => $expectedHash) {
    if (in_array($relative, $excludeFromPackage, true)) continue;
    $contents = $verify->getFromName($relative);
    if ($contents === false || !hash_equals((string)$expectedHash, hash('sha256', $contents))) {
        $verify->close();
        @unlink($TEMP_OUTPUT);
        echo "ERROR: Package verification failed: {$relative}\n";
        exit(1);
    }
}
$expectedEntries = count($files) - count(array_intersect(array_keys($files), $excludeFromPackage)) + 1;
if ($verify->numFiles !== $expectedEntries) {
    $verify->close();
    @unlink($TEMP_OUTPUT);
    echo "ERROR: Package contains an unexpected number of entries.\n";
    exit(1);
}
$actualEntries = $verify->numFiles;
$verify->close();

if (!@rename($TEMP_OUTPUT, $OUTPUT)) {
    @unlink($TEMP_OUTPUT);
    echo "ERROR: Cannot publish {$OUTPUT}\n";
    exit(1);
}

$size = filesize($OUTPUT);

echo "\nDone.\n";
echo "Version: {$version}\n";
echo "Files in zip: {$actualEntries}\n";
echo "Output: {$OUTPUT}\n";
echo "Size: " . number_format($size) . " bytes (" . round($size / 1024 / 1024, 2) . " MB)\n";
echo "SHA-256: " . hash_file('sha256', $OUTPUT) . "\n";
