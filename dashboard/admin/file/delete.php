<?php
declare(strict_types=1);

// /adiwira/admin/file/delete.php
ob_start();
require_once __DIR__ . '/../_guard.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => 'CSRF invalid'], 419);
}

$id  = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));

if ($id <= 0 && $url === '') {
    adiwira_json(['ok' => false, 'error' => 'Missing id or url'], 400);
}

if (!function_exists('file_static_root')) {
    function file_static_root(): ?string {
        $root = realpath(rtrim((string)PUBLIC_PATH, '/\\') . '/static');
        return ($root && is_dir($root)) ? $root : null;
    }
}

if (!function_exists('file_local_path_from_url')) {
    function file_local_path_from_url(string $url): ?string {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (!is_string($path) || !str_starts_with($path, '/static/files/')) {
            return null;
        }

        $static_root = file_static_root();
        if (!$static_root) return null;

        $rel = substr($path, strlen('/static'));
        $local = $static_root . $rel;
        $realLocal = realpath($local);

        if ($realLocal && str_starts_with($realLocal, $static_root) && is_file($realLocal)) {
            return $realLocal;
        }

        return null;
    }
}

if (!function_exists('file_private_path')) {
    function file_private_path(): string {
        $appRoot = realpath(__DIR__ . '/../../..');
        if ($appRoot === false) $appRoot = dirname(__DIR__, 3);
        return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
    }
}

try {
    if ($id > 0) {
        $sql = "SELECT id, url, storage_path, storage_disk FROM `file` WHERE id = :id";
        $params = [':id' => $id];
    } else {
        $sql = "SELECT id, url, storage_path, storage_disk FROM `file` WHERE url = :url";
        $params = [':url' => $url];
    }

    if (!$isAdmin) {
        $sql .= " AND user_id = :uid";
        $params[':uid'] = $uid;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        adiwira_json(['ok' => false, 'error' => 'File not found'], 404);
    }

    $deleted_id = (int)($row['id'] ?? 0);
    $final_url = (string)($row['url'] ?? '');
    $storageDisk = strtolower((string)($row['storage_disk'] ?? 'public'));
    $storagePath = (string)($row['storage_path'] ?? '');

    $warning = null;

    if ($storageDisk === 'private' && $storagePath !== '') {
        $privateRoot = file_private_path();
        if ($privateRoot) {
            $privateFile = realpath($privateRoot . '/' . ltrim($storagePath, '/\\'));
            if ($privateFile && str_starts_with($privateFile, $privateRoot) && is_file($privateFile)) {
                if (!@unlink($privateFile)) {
                    $warning = 'File fisik gagal dihapus, tetapi record database tetap dihapus.';
                }
            }
        }
    } else {
        $localFile = file_local_path_from_url($final_url);
        if ($localFile && is_file($localFile)) {
            if (!@unlink($localFile)) {
                $warning = 'File fisik gagal dihapus, tetapi record database tetap dihapus.';
            }
        }
    }

    $pdo->prepare("DELETE FROM `file` WHERE id = :id LIMIT 1")->execute([':id' => $deleted_id]);

    adiwira_json([
        'ok'            => true,
        'id'            => $deleted_id,
        'deleted_ids'   => [$deleted_id],
        'deleted_count' => 1,
        'warning'       => $warning,
    ], 200);

} catch (Throwable $e) {
    error_log('file/delete.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => 'Server error'], 500);
}