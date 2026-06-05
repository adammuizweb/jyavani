<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/save.php
ob_start();

require_once __DIR__ . '/../_guard.php';

if (adiwira_is_navigate_request()) {
    http_response_code(404);
    require FRONTEND_404_PATH;
    exit;
}

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ($role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
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

function _mdlib_has_col(PDO $pdo, string $col): bool {
    try { $st = $pdo->prepare("SELECT {$col} FROM `file` LIMIT 0"); $st->execute(); return true; }
    catch (Throwable $e) { return false; }
}

try {
    $sql = "SELECT * FROM `file` WHERE id = :id";
    $params = [':id' => $id];

    if (!$isAdmin) {
        $sql .= " AND user_id = :uid";
        $params[':uid'] = $uid;
    }

    $sql .= " LIMIT 1";

    $check = $pdo->prepare($sql);
    $check->execute($params);
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

    if (_mdlib_has_col($pdo, 'access_scope') && _mdlib_has_col($pdo, 'is_downloadable')) {
        $visibility = strtolower((string)($row['visibility'] ?? 'public'));
        if ($visibility === 'public') $accessScope = 'public';
        if ($visibility === 'private' && $accessScope === 'public') $accessScope = 'editorial';

        $setClause .= ", access_scope = :access_scope, is_downloadable = :is_downloadable";
        $execParams[':access_scope'] = $accessScope;
        $execParams[':is_downloadable'] = $isDownloadable;
    }

    $stmt = $pdo->prepare("UPDATE `file` SET {$setClause} WHERE id = :id LIMIT 1");
    $stmt->execute($execParams);

    $sql2 = "SELECT * FROM `file` WHERE id = :id";
    $params2 = [':id' => $id];

    if (!$isAdmin) {
        $sql2 .= " AND user_id = :uid";
        $params2[':uid'] = $uid;
    }

    $sql2 .= " LIMIT 1";

    $q = $pdo->prepare($sql2);
    $q->execute($params2);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    adiwira_json([
        'ok'   => true,
        'id'   => $id,
        'file' => $row,
    ], 200);

} catch (Throwable $e) {
    error_log('modal_file/save.php error: ' . $e->getMessage());
    adiwira_json([
        'ok'    => false,
        'error' => __('DB error'),
    ], 500);
}
