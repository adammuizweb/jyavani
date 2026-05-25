<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/delete.php
ob_start();

require_once __DIR__ . '/../_guard.php';

if (adiwira_is_navigate_request()) {
    http_response_code(404);
    require __DIR__ . '/../../../frontend_404.php';
    exit;
}

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['ok' => false, 'error' => 'CSRF invalid'], 419);
}

$id  = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));

if ($id <= 0 && $url === '') {
    adiwira_json(['ok' => false, 'error' => 'Missing id or url'], 400);
}

if (!function_exists('mdlib_starts_with')) {
    function mdlib_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('mdlib_local_path_from_url')) {
    function mdlib_local_path_from_url(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if ($path === '' || !mdlib_starts_with($path, '/static/files/')) {
            return null;
        }

        $staticRoot = realpath(rtrim((string)PUBLIC_PATH, '/\\') . '/static');
        if (!$staticRoot || !is_dir($staticRoot)) {
            return null;
        }

        $rel = substr($path, strlen('/static'));
        $local = $staticRoot . $rel;
        $realLocal = realpath($local);

        if ($realLocal && mdlib_starts_with($realLocal, $staticRoot) && is_file($realLocal)) {
            return $realLocal;
        }

        return null;
    }
}

if (!function_exists('mdlib_private_base_dir')) {
    function mdlib_private_base_dir(): string {
        $env = trim((string)(getenv('PRIVATE_FILES_PATH') ?: ''));
        if ($env !== '') return rtrim(str_replace('\\', '/', $env), '/');
        $appRoot = realpath(__DIR__ . '/../../..');
        if ($appRoot === false) $appRoot = dirname(__DIR__, 3);
        return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
    }
}

if (!function_exists('mdlib_safe_unlink')) {
    function mdlib_safe_unlink(?string $base, ?string $path): bool {
        $base = is_string($base) ? rtrim($base, '/\\') : '';
        $path = is_string($path) ? $path : '';
        if ($base === '' || $path === '') return false;
        $realBase = realpath($base);
        $realPath = realpath($path);
        if ($realBase === false || $realPath === false) return false;
        $realBase = rtrim(str_replace('\\', '/', $realBase), '/') . '/';
        $realPathNorm = str_replace('\\', '/', $realPath);
        if (strpos($realPathNorm, $realBase) !== 0) return false;
        return @unlink($realPath);
    }
}

try {
    $sql = "SELECT * FROM `file` WHERE ";
    $params = [];

    if ($id > 0) {
        $sql .= "id = :id";
        $params[':id'] = $id;
    } else {
        $sql .= "url = :url";
        $params[':url'] = $url;
    }

    if (!$isAdmin) {
        $sql .= " AND user_id = :uid";
        $params[':uid'] = $uid;
    }

    $sql .= " LIMIT 1";

    $q = $pdo->prepare($sql);
    $q->execute($params);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        adiwira_json(['ok' => false, 'error' => 'File not found'], 404);
    }

    $fileId = (int)$row['id'];
    $fileUrl = (string)($row['url'] ?? '');
    $visibility = strtolower((string)($row['visibility'] ?? 'public'));
    $disk = strtolower((string)($row['storage_disk'] ?? 'public'));
    $storagePath = trim((string)($row['storage_path'] ?? ''));

    $deletedPhysical = false;
    if ($disk === 'private' || $visibility === 'private') {
        $base = mdlib_private_base_dir() . '/files';
        $deletedPhysical = mdlib_safe_unlink($base, $base . '/' . ltrim($storagePath, '/\\'));
    } else {
        $publicPath = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        $urlPath = parse_url($fileUrl, PHP_URL_PATH);
        if (is_string($urlPath) && $urlPath !== '') {
            $deletedPhysical = mdlib_safe_unlink($publicPath, rtrim($publicPath, '/\\') . '/' . ltrim($urlPath, '/\\'));
        }
    }

    $pdo->prepare("DELETE FROM `file` WHERE id = :id LIMIT 1")->execute([':id' => $fileId]);

    adiwira_json([
        'ok'               => true,
        'id'               => $fileId,
        'deleted_ids'      => [$fileId],
        'deleted_count'    => 1,
        'url'              => $fileUrl,
        'deleted_urls'     => [$fileUrl],
        'physical_deleted' => $deletedPhysical,
        'warning'          => $deletedPhysical ? null : 'Metadata terhapus, tetapi file fisik tidak ditemukan atau tidak bisa dihapus.',
    ], 200);

} catch (Throwable $e) {
    error_log('modal_file/delete.php error: ' . $e->getMessage());
    adiwira_json([
        'ok'    => false,
        'error' => 'DB error',
    ], 500);
}
