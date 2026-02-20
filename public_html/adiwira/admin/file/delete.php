<?php
// /adiwira/admin/file/delete.php
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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$url = isset($_POST['url']) ? trim((string)$_POST['url']) : null;

$deleted_ids = [];

try {
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // attempt delete physical file
            $fsPath = realpath($_SERVER['DOCUMENT_ROOT'] . $row['url']);
            if ($fsPath && file_exists($fsPath)) {
                @unlink($fsPath);
            }
            // delete DB row
            $del = $pdo->prepare("DELETE FROM `file` WHERE id = :id");
            $del->execute([':id' => $id]);
            $deleted_ids[] = $id;
        }
    } elseif ($url) {
        // try match by url
        $stmt = $pdo->prepare("SELECT * FROM `file` WHERE url = :url LIMIT 1");
        $stmt->execute([':url' => $url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $fsPath = realpath($_SERVER['DOCUMENT_ROOT'] . $row['url']);
            if ($fsPath && file_exists($fsPath)) @unlink($fsPath);
            $del = $pdo->prepare("DELETE FROM `file` WHERE id = :id");
            $del->execute([':id' => $row['id']]);
            $deleted_ids[] = (int)$row['id'];
        } else {
            // not in DB, attempt deleting by url path directly
            $fsPath = realpath($_SERVER['DOCUMENT_ROOT'] . $url);
            if ($fsPath && file_exists($fsPath)) {
                @unlink($fsPath);
                // no db id to report
            }
        }
    } else {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Missing id or url']);
        exit;
    }

    echo json_encode(['ok'=>true,'deleted_ids'=>$deleted_ids,'deleted_count'=>count($deleted_ids)]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','errors'=>[$e->getMessage()]]);
    exit;
}
