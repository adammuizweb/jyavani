<?php
declare(strict_types=1);

function ensure_writable_runtime(): void
{
    $varDir = defined('BACKEND_PATH') ? rtrim(BACKEND_PATH, '/\\') . '/var' : null;
    if ($varDir === null || !is_dir($varDir)) {
        return;
    }

    $dirMode = 02770;
    $fileMode = 0660;

    $currentDirPerms = fileperms($varDir) & 0777;
    if (($currentDirPerms & $dirMode) !== $dirMode) {
        @chmod($varDir, $dirMode);
    }

    $patternFiles = [
        $varDir . '/*.json',
    ];
    foreach ($patternFiles as $pattern) {
        foreach (glob($pattern) as $path) {
            if (!is_file($path) || is_writable($path)) {
                continue;
            }
            $mode = (fileperms($path) & 0777) | $fileMode;
            @chmod($path, $mode);
        }
    }

    $subDirs = [
        $varDir . '/terminal-tokens',
        $varDir . '/uploads',
        $varDir . '/plugin-backups',
        $varDir . '/theme-backups',
    ];
    foreach ($subDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $p = fileperms($dir) & 0777;
        if (($p & $dirMode) !== $dirMode) {
            @chmod($dir, $dirMode);
        }
    }
}
