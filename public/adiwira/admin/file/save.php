<?php
declare(strict_types=1);

// /adiwira/admin/file/save.php
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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_json(['ok'=>false,'error'=>'Missing id'], 400);
}

$title   = trim((string)($_POST['title'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
$credit  = trim((string)($_POST['credit'] ?? ''));

try {
    $stmt = $pdo->prepare("UPDATE `file`
                              SET title=:title,
                                  caption=:caption,
                                  credit=:credit,
                                  updated_at=NOW()
                            WHERE id=:id");
    $stmt->execute([
        ':title'   => ($title !== '' ? $title : null),
        ':caption' => ($caption !== '' ? $caption : null),
        ':credit'  => ($credit !== '' ? $credit : null),
        ':id'      => $id
    ]);

    $stmt2 = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
    $stmt2->execute([':id' => $id]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);

    adiwira_json(['ok'=>true,'file'=>$row], 200);

} catch (Throwable $e) {
    adiwira_json(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()], 500);
}