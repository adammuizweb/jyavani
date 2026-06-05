<?php
declare(strict_types=1);

// /adiwira/admin/file/save.php
ob_start();
require_once __DIR__ . '/../_guard.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_json(['ok' => false, 'error' => __('Missing id')], 400);
}

$title   = trim((string)($_POST['title'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
$credit  = trim((string)($_POST['credit'] ?? ''));
$accessScope = strtolower(trim((string)($_POST['access_scope'] ?? '')));
if (!in_array($accessScope, ['public','editorial','admin'], true)) $accessScope = 'editorial';
$isDownloadable = !empty($_POST['is_downloadable']) ? 1 : 0;

function _filesave_has_col(PDO $pdo, string $col): bool {
    try { $st = $pdo->prepare("SELECT {$col} FROM `file` LIMIT 0"); $st->execute(); return true; }
    catch (Throwable $e) { return false; }
}

try {
    $checkSql = "SELECT * FROM `file` WHERE id = :id";
    $checkParams = [':id' => $id];

    if (!$isAdmin) {
        $checkSql .= " AND user_id = :uid";
        $checkParams[':uid'] = $uid;
    }

    $checkSql .= " LIMIT 1";

    $check = $pdo->prepare($checkSql);
    $check->execute($checkParams);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        adiwira_json(['ok' => false, 'error' => __('File not found')], 404);
    }

    $setClause = "title = :title, caption = :caption, credit = :credit, updated_at = NOW()";
    $execParams = [
        ':title'   => ($title !== '' ? $title : null),
        ':caption' => ($caption !== '' ? $caption : null),
        ':credit'  => ($credit !== '' ? $credit : null),
        ':id'      => $id,
    ];

    if (_filesave_has_col($pdo, 'access_scope') && _filesave_has_col($pdo, 'is_downloadable')) {
        $visibility = strtolower((string)($row['visibility'] ?? 'public'));
        if ($visibility === 'public') $accessScope = 'public';
        if ($visibility === 'private' && $accessScope === 'public') $accessScope = 'editorial';

        $setClause .= ", access_scope = :access_scope, is_downloadable = :is_downloadable";
        $execParams[':access_scope'] = $accessScope;
        $execParams[':is_downloadable'] = $isDownloadable;
    }

    $stmt = $pdo->prepare("UPDATE `file` SET {$setClause} WHERE id = :id LIMIT 1");
    $stmt->execute($execParams);

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
    adiwira_json(['ok' => false, 'error' => __('DB error')], 500);
}