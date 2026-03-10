<?php
declare(strict_types=1);

// /adiwira/admin/file/save.php
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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_json(['ok' => false, 'error' => 'Missing id'], 400);
}

$title   = trim((string)($_POST['title'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
$credit  = trim((string)($_POST['credit'] ?? ''));

try {
    $checkSql = "SELECT id FROM `file` WHERE id = :id";
    $checkParams = [':id' => $id];

    if (!$isAdmin) {
        $checkSql .= " AND user_id = :uid";
        $checkParams[':uid'] = $uid;
    }

    $checkSql .= " LIMIT 1";

    $check = $pdo->prepare($checkSql);
    $check->execute($checkParams);

    if (!$check->fetchColumn()) {
        adiwira_json(['ok' => false, 'error' => 'File not found'], 404);
    }

    $stmt = $pdo->prepare("
        UPDATE `file`
           SET title      = :title,
               caption    = :caption,
               credit     = :credit,
               updated_at = NOW()
         WHERE id = :id
         LIMIT 1
    ");
    $stmt->execute([
        ':title'   => ($title !== '' ? $title : null),
        ':caption' => ($caption !== '' ? $caption : null),
        ':credit'  => ($credit !== '' ? $credit : null),
        ':id'      => $id
    ]);

    $rowSql = "SELECT * FROM `file` WHERE id = :id";
    $rowParams = [':id' => $id];

    if (!$isAdmin) {
        $rowSql .= " AND user_id = :uid";
        $rowParams[':uid'] = $uid;
    }

    $rowSql .= " LIMIT 1";

    $stmt2 = $pdo->prepare($rowSql);
    $stmt2->execute($rowParams);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);

    adiwira_json([
        'ok'   => true,
        'id'   => $id,
        'file' => $row,
    ], 200);

} catch (Throwable $e) {
    error_log('file/save.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => 'DB error'], 500);
}