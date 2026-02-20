<?php
// /adiwira/admin/file/save.php
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Not authenticated','errors'=>['Not authenticated']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing id']);
    exit;
}

$title = trim((string)($_POST['title'] ?? ''));
$caption = trim((string)($_POST['caption'] ?? ''));
$credit = trim((string)($_POST['credit'] ?? ''));

try {
    $stmt = $pdo->prepare("UPDATE `file` SET title = :title, caption = :caption, credit = :credit, updated_at = NOW() WHERE id = :id");
    $stmt->execute([
        ':title' => $title ?: null,
        ':caption' => $caption ?: null,
        ':credit' => $credit ?: null,
        ':id' => $id
    ]);

    // fetch updated row
    $stmt2 = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
    $stmt2->execute([':id' => $id]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'file'=>$row]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB error','errors'=>[$e->getMessage()]]);
    exit;
}
