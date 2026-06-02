<?php
declare(strict_types=1);

// /adiwira/admin/media/delete.php
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

function media_static_root(): ?string {
    $root = realpath(rtrim((string)PUBLIC_PATH, '/\\') . '/static');
    return ($root && is_dir($root)) ? $root : null;
}

function media_local_path_from_url(string $url): ?string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    if (!is_string($path) || !str_starts_with($path, '/static/')) {
        return null;
    }

    $static_root = media_static_root();
    if (!$static_root) return null;

    $rel = substr($path, strlen('/static'));
    $local = $static_root . $rel;
    $realLocal = realpath($local);

    if ($realLocal && str_starts_with($realLocal, $static_root) && is_file($realLocal)) {
        return $realLocal;
    }

    return null;
}

function media_private_base_dir(): string {
    $appRoot = realpath(__DIR__ . '/../../..');
    if ($appRoot === false) $appRoot = dirname(__DIR__, 3);
    return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
}

function media_safe_unlink(?string $base, ?string $path): bool {
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

function media_has_private_cols(PDO $pdo): bool {
    try {
        $st = $pdo->prepare("SELECT storage_disk, storage_path FROM media LIMIT 0");
        $st->execute();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    if ($id > 0) {
        $sql = "SELECT * FROM media WHERE id = :id";
        $params = [':id' => $id];

        if (!$isAdmin) {
            $sql .= " AND user_id = :uid";
            $params[':uid'] = $uid;
        }

        $sql .= " LIMIT 1";

        $q = $pdo->prepare($sql);
        $q->execute($params);
        $row = $q->fetch(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT * FROM media WHERE url = :url";
        $params = [':url' => $url];

        if (!$isAdmin) {
            $sql .= " AND user_id = :uid";
            $params[':uid'] = $uid;
        }

        $sql .= " LIMIT 1";

        $q = $pdo->prepare($sql);
        $q->execute($params);
        $row = $q->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        adiwira_json(['ok' => false, 'error' => 'Media not found'], 404);
    }

    $deleted_id = (int)$row['id'];
    $url = (string)($row['url'] ?? '');
    $warning = null;
    $deletedPhysical = false;

    if (media_has_private_cols($pdo)) {
        $disk = strtolower((string)($row['storage_disk'] ?? 'public'));
        $storagePath = trim((string)($row['storage_path'] ?? ''));
        if (($disk === 'private') && $storagePath !== '') {
            $base = media_private_base_dir() . '/media';
            $fullPath = $base . '/' . ltrim($storagePath, '/\\');
            $deletedPhysical = media_safe_unlink($base, $fullPath);
            if (!$deletedPhysical) {
                $warning = 'File private gagal dihapus, tetapi record database tetap dihapus.';
            }
        }
    }

    if (!$deletedPhysical) {
        $localFile = media_local_path_from_url($url);
        if ($localFile && is_file($localFile)) {
            if (!@unlink($localFile)) {
                $warning = 'File fisik gagal dihapus, tetapi record database tetap dihapus.';
            }
        }
    }

    $pdo->prepare("DELETE FROM media WHERE id = :id LIMIT 1")->execute([':id' => $deleted_id]);

    adiwira_json([
        'ok'               => true,
        'id'               => $deleted_id,
        'url'              => $url,
        'warning'          => $warning,
        'physical_deleted' => $deletedPhysical,
    ], 200);

} catch (Throwable $e) {
    error_log('media/delete.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => 'Server error'], 500);
}