<?php
// /adiwira/admin/media/delete_bulk.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || count($ids) === 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'No ids provided']);
    exit;
}

// sanitize ids to ints and unique
$ids = array_values(array_unique(array_map('intval', $ids)));
if (count($ids) === 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid ids']);
    exit;
}

try {
    // fetch urls for these ids
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, url FROM media WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $deleted_ids = [];
    $errors = [];

    // static root resolution
    $static_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/static');

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $url = $row['url'];
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        // only delete physical file if under /static/
        if (strpos($path, '/static/') === 0 && $static_root) {
            $rel = substr($path, strlen('/static')); // begins with '/'
            $local = $static_root . $rel;
            $real_local = realpath($local) ?: $local;
            if (is_file($real_local) && strpos($real_local, $static_root) === 0) {
                if (!@unlink($real_local)) {
                    // don't stop; record error
                    $errors[] = "Failed to unlink file for id {$id}";
                }
            }
        }
        // delete DB row
        $del = $pdo->prepare("DELETE FROM media WHERE id=:id");
        $del->execute([':id'=>$id]);
        $deleted_ids[] = $id;
    }

    echo json_encode(['ok'=>true,'deleted_count'=>count($deleted_ids),'deleted_ids'=>$deleted_ids,'errors'=>$errors]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
    exit;
}
