<?php
declare(strict_types=1);

// /adiwira/admin/media/delete_bulk.php
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

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || count($ids) === 0) {
    adiwira_json(['ok' => false, 'error' => 'No ids provided'], 400);
}

$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_values(array_filter($ids, function($x){
    return (int)$x > 0;
}));

if (count($ids) === 0) {
    adiwira_json(['ok' => false, 'error' => 'Invalid ids'], 400);
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

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT id, url FROM media WHERE id IN ($placeholders)");
        $stmt->execute($ids);
    } else {
        $stmt = $pdo->prepare("SELECT id, url FROM media WHERE id IN ($placeholders) AND user_id = ?");
        $stmt->execute(array_merge($ids, [$uid]));
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($rows)) {
        adiwira_json(['ok' => false, 'error' => 'Media not found'], 404);
    }

    $deleted_ids = [];
    $warnings = [];

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $url = (string)($row['url'] ?? '');

        $localFile = media_local_path_from_url($url);
        if ($localFile && is_file($localFile)) {
            if (!@unlink($localFile)) {
                $warnings[] = "Failed to unlink file for id {$id}";
            }
        }

        $pdo->prepare("DELETE FROM media WHERE id = :id LIMIT 1")->execute([':id' => $id]);
        $deleted_ids[] = $id;
    }

    adiwira_json([
        'ok'            => true,
        'deleted_count' => count($deleted_ids),
        'deleted_ids'   => $deleted_ids,
        'warnings'      => $warnings
    ], 200);

} catch (Throwable $e) {
    error_log('media/delete_bulk.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => 'Server error'], 500);
}