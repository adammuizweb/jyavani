<?php
// /adiwira/admin/file/delete_bulk.php
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}

$ids = [];
if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_map('intval', $_POST['ids']);
} elseif (!empty($_POST['ids']) && is_string($_POST['ids'])) {
    // if sent as comma separated
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'])));
}

if (count($ids) === 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'No ids provided']);
    exit;
}

$deleted = [];
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT * FROM `file` WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // delete files and collect ids
    foreach ($rows as $r) {
        $fsPath = realpath($_SERVER['DOCUMENT_ROOT'] . $r['url']);
        if ($fsPath && file_exists($fsPath)) @unlink($fsPath);
        $deleted[] = (int)$r['id'];
    }

    // delete db rows
    $del = $pdo->prepare("DELETE FROM `file` WHERE id IN (" . implode(',', array_fill(0, count($deleted), '?')) . ")");
    if (count($deleted)) $del->execute($deleted);

    $pdo->commit();
    echo json_encode(['ok'=>true,'deleted_count'=>count($deleted),'deleted_ids'=>$deleted]);
    exit;
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','errors'=>[$e->getMessage()]]);
    exit;
}
