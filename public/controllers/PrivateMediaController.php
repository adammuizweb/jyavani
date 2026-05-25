<?php
declare(strict_types=1);

// public/controllers/PrivateMediaController.php
// Protected image stream untuk media (gambar) private.

class PrivateMediaController
{
    private static function abort(int $code = 404, string $message = ''): void
    {
        http_response_code($code);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $text = $message !== '' ? $message : ($code === 403 ? 'Forbidden' : 'Not found');
        echo '<!doctype html><meta charset="utf-8"><title>' . ($code === 403 ? 'Forbidden' : 'Not found') . '</title><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
        exit;
    }

    private static function mediaId(): int
    {
        return max(0, (int)($_GET['id'] ?? 0));
    }

    private static function privateBaseDir(): string
    {
        $env = trim((string)(getenv('PRIVATE_FILES_PATH') ?: ''));
        if ($env !== '') {
            return rtrim(str_replace('\\', '/', $env), '/');
        }

        $appRoot = realpath(__DIR__ . '/../..');
        if ($appRoot === false) {
            $appRoot = dirname(__DIR__, 2);
        }

        return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
    }

    private static function safePrivatePath(string $relative): ?string
    {
        $base = self::privateBaseDir() . '/media';
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $realBase = realpath($base);
        $candidate = $base . '/' . $relative;
        $realFile = realpath($candidate);

        if ($realBase === false || $realFile === false || !is_file($realFile)) {
            return null;
        }

        $realBase = rtrim(str_replace('\\', '/', $realBase), '/') . '/';
        $realFileNorm = str_replace('\\', '/', $realFile);

        if (strpos($realFileNorm, $realBase) !== 0) {
            return null;
        }

        return $realFile;
    }

    private static function adminOk(PDO $pdo): bool
    {
        try {
            if (function_exists('adiwira_fetch_identity')) {
                $identity = adiwira_fetch_identity($pdo);
                if (($identity['ok'] ?? false) === true) {
                    $role = strtolower((string)($identity['role'] ?? ''));
                    return in_array($role, ['admin', 'editor', 'author'], true);
                }
            }

            if (function_exists('is_logged_in') && is_logged_in()) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('[PrivateMediaController] adminOk error: ' . $e->getMessage());
        }

        return false;
    }

    private static function fetchMedia(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;

        $stmt = $pdo->prepare("SELECT * FROM media WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function canAccess(PDO $pdo, array $media): bool
    {
        $visibility = strtolower((string)($media['visibility'] ?? 'public'));
        $disk = strtolower((string)($media['storage_disk'] ?? 'public'));
        $scope = strtolower((string)($media['access_scope'] ?? 'public'));

        if ($visibility === 'public' && $disk === 'public' && $scope === 'public') {
            return true;
        }

        return self::adminOk($pdo);
    }

    public static function view(PDO $pdo): void
    {
        $id = self::mediaId();
        $media = self::fetchMedia($pdo, $id);
        if (!$media) {
            self::abort(404);
        }

        if (!self::canAccess($pdo, $media)) {
            self::abort(403, 'Gambar ini hanya untuk pengguna yang berwenang.');
        }

        $visibility = strtolower((string)($media['visibility'] ?? 'public'));
        $disk = strtolower((string)($media['storage_disk'] ?? 'public'));

        if ($disk === 'public' && $visibility === 'public') {
            $url = trim((string)($media['url'] ?? ''));
            if ($url === '') {
                self::abort(404);
            }
            header('Location: ' . $url, true, 302);
            exit;
        }

        $storagePath = trim((string)($media['storage_path'] ?? ''));
        $realFile = self::safePrivatePath($storagePath);
        if ($realFile === null) {
            self::abort(404);
        }

        $mime = trim((string)($media['mime'] ?? ''));
        if ($mime === '' || strpos($mime, 'image/') !== 0) {
            $mime = 'application/octet-stream';
        }

        $filename = (string)($media['filename'] ?? basename($realFile));
        $filename = str_replace(["\r", "\n", '"'], ['', '', ''], $filename);

        $size = (int)@filesize($realFile);

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $size);

        $fp = @fopen($realFile, 'rb');
        if (!$fp) {
            self::abort(404);
        }

        $remaining = $size;
        while ($remaining > 0 && !feof($fp)) {
            $chunkSize = min(8192, $remaining);
            $buffer = fread($fp, $chunkSize);
            if ($buffer === false || $buffer === '') break;
            echo $buffer;
            $remaining -= strlen($buffer);
            flush();
        }

        fclose($fp);
        exit;
    }
}
