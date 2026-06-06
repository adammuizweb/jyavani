<?php
declare(strict_types=1);

$projectRoot = realpath(__DIR__ . '/..');
if (!$projectRoot) {
    fwrite(STDERR, "Cannot determine project root.\n");
    exit(1);
}

$currentUser = trim(shell_exec('whoami') ?? '');
$isRoot = ($currentUser === 'root');

$fixDir = function (string $dir) use ($projectRoot) {
    $absDir = $projectRoot . '/' . ltrim($dir, '/');
    if (!is_dir($absDir)) {
        echo "  [SKIP]  {$dir} (not found)\n";
        return;
    }
    $p = fileperms($absDir) & 0777;
    if (($p & 02770) !== 02770) {
        @chmod($absDir, 02770);
        echo "  [FIX]   {$dir} → 2770\n";
    } else {
        echo "  [OK]    {$dir} (2770)\n";
    }
};

$fixFilePerms = function (string $path) use ($projectRoot) {
    $absPath = $projectRoot . '/' . ltrim($path, '/');
    if (!is_file($absPath)) {
        return;
    }
    $p = fileperms($absPath) & 0777;
    if (($p & 0660) !== 0660) {
        @chmod($absPath, $p | 0660);
        echo "  [FIX]   {$path} → " . substr(sprintf('%o', $p | 0660), -4) . "\n";
    }
};

echo "=== Jyavani CMS Permission Fix ===\n";
echo "User: {$currentUser}\n\n";

$dirs = [
    'cfg/var',
    'cfg/var/sessions',
    'cfg/var/terminal-tokens',
    'cfg/var/uploads',
    'cfg/var/plugin-backups',
    'cfg/var/theme-backups',
];
echo "Directories (2770):\n";
foreach ($dirs as $dir) {
    $fixDir($dir);
}

$jsonPatterns = [
    'cfg/var/*.json',
];
echo "\nJSON files (0660):\n";
foreach ($jsonPatterns as $pattern) {
    foreach (glob($projectRoot . '/' . $pattern) as $path) {
        $relPath = substr($path, strlen($projectRoot) + 1);
        $fixFilePerms($relPath);
    }
}

echo "\n=== Summary ===\n";
echo "Runtime data in cfg/var/ should now be writable by www-data.\n";
echo "If files show [FIX] but still not working, run as root:\n";
echo "  # chown -R www-data:www-data {$projectRoot}/cfg/var\n\n";
