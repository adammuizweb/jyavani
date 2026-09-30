<?php
declare(strict_types=1);

function pondasi_demo_path_is_within(string $path, string $root): bool
{
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $root = rtrim($root, DIRECTORY_SEPARATOR);
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

function pondasi_demo_ensure_directory(string $directory, string $root, array &$createdDirectories): void
{
    if (!pondasi_demo_path_is_within($directory, $root)) {
        throw new RuntimeException('Tujuan aset demo berada di luar public root.');
    }
    if (is_dir($directory)) {
        if (is_link($directory)) throw new RuntimeException('Aset demo tidak boleh melewati symbolic link.');
        $real = realpath($directory);
        if (!$real || !pondasi_demo_path_is_within($real, $root)) {
            throw new RuntimeException('Direktori tujuan aset demo tidak valid.');
        }
        return;
    }
    if (file_exists($directory) || is_link($directory)) {
        throw new RuntimeException('Path tujuan aset demo bukan direktori.');
    }

    $parent = dirname($directory);
    pondasi_demo_ensure_directory($parent, $root, $createdDirectories);
    if (!mkdir($directory, 0755) || !is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('Direktori tujuan aset demo tidak dapat dibuat.');
    }
    $createdDirectories[] = $directory;
}

function pondasi_demo_assets_rollback(array $operation): void
{
    foreach (array_reverse((array)($operation['temporary_files'] ?? [])) as $path) {
        if (is_string($path) && is_file($path) && !is_link($path)) @unlink($path);
    }
    foreach (array_reverse((array)($operation['created_files'] ?? [])) as $entry) {
        if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['sha256'] ?? null)) continue;
        $path = $entry['path'];
        $stat = !is_link($path) ? @lstat($path) : false;
        $hash = is_file($path) && !is_link($path) ? @hash_file('sha256', $path) : false;
        if (is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000
            && (int)($stat['dev'] ?? -1) === (int)($entry['dev'] ?? -2)
            && (int)($stat['ino'] ?? -1) === (int)($entry['ino'] ?? -2)
            && is_string($hash) && hash_equals($entry['sha256'], $hash)) {
            @unlink($path);
        } elseif (file_exists($path) || is_link($path)) {
            error_log('Pondasi preserved a changed demo asset during rollback: ' . $path);
        }
    }
    foreach (array_reverse((array)($operation['created_directories'] ?? [])) as $path) {
        if (is_string($path) && is_dir($path) && !is_link($path)) @rmdir($path);
    }
}

/**
 * Publish demo assets without replacing unrelated site files. The returned
 * operation can be rolled back until the surrounding database transaction commits.
 */
function pondasi_demo_assets_publish(string $sourceDirectory, string $targetDirectory): array
{
    $source = !is_link($sourceDirectory) ? realpath($sourceDirectory) : false;
    $target = !is_link($targetDirectory) ? realpath($targetDirectory) : false;
    if (!$source || !is_dir($source)) throw new RuntimeException('Direktori aset demo wajib tidak ditemukan.');
    if (!$target || !is_dir($target) || !is_writable($target)) throw new RuntimeException('Public root tidak dapat menerima aset demo.');

    $operation = [
        'count' => 0,
        'created_files' => [],
        'created_directories' => [],
        'temporary_files' => [],
    ];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $sourcePrefix = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($iterator as $item) {
            if ($item->isLink()) throw new RuntimeException('Aset demo tidak boleh berisi symbolic link.');
            $sourcePath = $item->getPathname();
            $relative = substr($sourcePath, strlen($sourcePrefix));
            if ($relative === '' || str_contains($relative, "\0")
                || preg_match('#(?:^|[\\/])\.\.?(?:[\\/]|$)#', $relative)) {
                throw new RuntimeException('Path aset demo tidak valid.');
            }
            $destination = $target . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                pondasi_demo_ensure_directory($destination, $target, $operation['created_directories']);
                continue;
            }
            if (!$item->isFile()) throw new RuntimeException('Aset demo harus berupa file reguler.');
            $sourceReal = realpath($sourcePath);
            if (!$sourceReal || !pondasi_demo_path_is_within($sourceReal, $source)) {
                throw new RuntimeException('Aset demo keluar dari direktori sumber.');
            }
            $normalizedRelative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if (preg_match('#\Astatic/img/\d{4}/[a-zA-Z0-9._/-]+\.(?:jpe?g|png|gif|webp|avif)\z#i', $normalizedRelative) !== 1) {
                throw new RuntimeException('Aset demo hanya boleh berupa gambar bertanggal di static/img/.');
            }
            $basename = strtolower(basename($normalizedRelative));
            if (in_array($basename, ['.htaccess', '.user.ini', 'php.ini'], true)
                || preg_match('/\.(?:php\d*|phtml|pht|phar|cgi|pl|py|sh)(?:\.|$)/i', $basename) === 1) {
                throw new RuntimeException('Nama aset demo mengandung extension executable.');
            }
            $image = @getimagesize($sourceReal);
            $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $mimeByExtension = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
            ];
            if (!is_array($image) || !isset($mimeByExtension[$extension])
                || ($image['mime'] ?? '') !== $mimeByExtension[$extension]) {
                throw new RuntimeException('MIME aset demo tidak valid: ' . $relative);
            }

            pondasi_demo_ensure_directory(dirname($destination), $target, $operation['created_directories']);
            if (is_link($destination) || (file_exists($destination) && !is_file($destination))) {
                throw new RuntimeException('Tujuan aset demo bukan file reguler.');
            }
            if (is_file($destination)) {
                $sourceHash = hash_file('sha256', $sourceReal);
                $destinationHash = hash_file('sha256', $destination);
                if (!is_string($sourceHash) || !is_string($destinationHash) || !hash_equals($sourceHash, $destinationHash)) {
                    throw new RuntimeException('Aset demo akan menimpa file situs yang berbeda: ' . $relative);
                }
                $operation['count']++;
                continue;
            }

            $temporary = tempnam(dirname($destination), '.pondasi-demo-');
            if ($temporary === false) throw new RuntimeException('File sementara aset demo tidak dapat dibuat.');
            $operation['temporary_files'][] = $temporary;
            if (!copy($sourceReal, $temporary) || !chmod($temporary, 0644)) {
                throw new RuntimeException('Aset demo tidak dapat dipublikasikan: ' . $relative);
            }
            $sourceHash = hash_file('sha256', $sourceReal);
            $temporaryHash = hash_file('sha256', $temporary);
            if (!is_string($sourceHash) || !is_string($temporaryHash) || !hash_equals($sourceHash, $temporaryHash)
                || file_exists($destination) || is_link($destination) || !link($temporary, $destination)) {
                throw new RuntimeException('Aset demo tidak dapat dipublikasikan: ' . $relative);
            }
            $publishedStat = @lstat($destination);
            if (!is_array($publishedStat) || (($publishedStat['mode'] ?? 0) & 0170000) !== 0100000) {
                throw new RuntimeException('Identitas aset demo yang dipublikasikan tidak valid: ' . $relative);
            }
            $operation['created_files'][] = [
                'path' => $destination,
                'sha256' => $sourceHash,
                'dev' => (int)$publishedStat['dev'],
                'ino' => (int)$publishedStat['ino'],
            ];
            if (!unlink($temporary)) throw new RuntimeException('File sementara aset demo tidak dapat dibersihkan.');
            $operation['temporary_files'] = array_values(array_diff($operation['temporary_files'], [$temporary]));
            $operation['count']++;
        }
        if ($operation['count'] < 1) throw new RuntimeException('Paket aset demo kosong.');
        return $operation;
    } catch (Throwable $error) {
        pondasi_demo_assets_rollback($operation);
        throw $error;
    }
}
