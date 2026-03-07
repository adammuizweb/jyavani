<?php
declare(strict_types=1);

// /adiwira/admin/file/delete.php
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

$id  = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));

if ($id <= 0 && $url === '') {
    adiwira_json(['ok'=>false,'error'=>'Missing id or url'], 400);
}

// fetch row
$row = null;
try {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
        $stmt->execute([':id'=>$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE url = :url LIMIT 1");
        $stmt->execute([':url'=>$url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    adiwira_json(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()], 500);
}

// decide final values
$deleted_id = 0;
$final_url = $url;

if ($row) {
    $deleted_id = (int)($row['id'] ?? 0);
    $final_url = (string)($row['url'] ?? $url);
}

// delete physical file safely only under /static/files/
$path = parse_url((string)$final_url, PHP_URL_PATH) ?: '';
if (is_string($path) && strpos($path, '/static/files/') === 0) {
    $static_root = realpath(rtrim((string)PUBLIC_PATH, '/\\') . '/static');
    if ($static_root) {
        $rel = substr($path, strlen('/static')); // begins with '/files/...'
        $local = $static_root . $rel;
        $real_local = realpath($local);
        if ($real_local && strpos($real_local, $static_root) === 0 && is_file($real_local)) {
            @unlink($real_local);
        }
    }
}

// delete db row
try {
    if ($deleted_id > 0) {
        $pdo->prepare("DELETE FROM `file` WHERE id = :id")->execute([':id'=>$deleted_id]);
    } elseif ($final_url !== '') {
        $pdo->prepare("DELETE FROM `file` WHERE url = :url")->execute([':url'=>$final_url]);
    }

    adiwira_json([
        'ok' => true,
        'id' => $deleted_id,
        'deleted_ids' => ($deleted_id > 0 ? [$deleted_id] : []),
        'deleted_count' => ($deleted_id > 0 ? 1 : 0)
    ], 200);

} catch (Throwable $e) {
    adiwira_json(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()], 500);
}