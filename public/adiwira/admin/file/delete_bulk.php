<?php
declare(strict_types=1);

// /adiwira/admin/file/delete_bulk.php
ob_start();
require_once __DIR__ . '/../_guard.php';

// 1) Kalau orang buka URL ini langsung di browser: samarkan total sebagai 404 HTML
if (adiwira_is_navigate_request()) {
  http_response_code(404);
  require __DIR__ . '/../../../frontend_404.php';
  exit;
}

// 2) Untuk request programmatic (AJAX/fetch): pakai JSON
adiwira_require_admin(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['ok'=>false,'error'=>'CSRF invalid'], 419);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || count($ids) === 0) {
    adiwira_json(['ok'=>false,'error'=>'No ids provided'], 400);
}

// sanitize
$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_values(array_filter($ids, function($x){ return (int)$x > 0; }));
if (count($ids) === 0) {
    adiwira_json(['ok'=>false,'error'=>'Invalid ids'], 400);
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, url FROM `file` WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deleted_ids = [];
    $errors = [];

    $static_root = realpath(rtrim((string)PUBLIC_PATH, '/\\') . '/static');

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $url = (string)($row['url'] ?? '');
        if ($id <= 0) continue;

        // unlink safely (only /static/files/)
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if ($static_root && is_string($path) && strpos($path, '/static/files/') === 0) {
            $rel = substr($path, strlen('/static')); // begins with '/files/...'
            $local = $static_root . $rel;
            $real_local = realpath($local);
            if ($real_local && strpos($real_local, $static_root) === 0 && is_file($real_local)) {
                if (!@unlink($real_local)) {
                    $errors[] = "Failed to unlink file for id {$id}";
                }
            }
        }

        $pdo->prepare("DELETE FROM `file` WHERE id=:id")->execute([':id'=>$id]);
        $deleted_ids[] = $id;
    }

    adiwira_json([
        'ok'=>true,
        'deleted_count'=>count($deleted_ids),
        'deleted_ids'=>$deleted_ids,
        'errors'=>$errors
    ], 200);

} catch (Throwable $e) {
    adiwira_json(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()], 500);
}