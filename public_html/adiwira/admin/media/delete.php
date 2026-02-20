<?php
// /adiwira/admin/media/delete.php
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

$id = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));

if ($id <= 0 && $url === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing id or url']);
    exit;
}

// If id given, fetch url
if ($id > 0) {
    $q = $pdo->prepare("SELECT url FROM media WHERE id = :id LIMIT 1");
    $q->execute([':id'=>$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r) $url = $r['url'];
}

$path = parse_url($url, PHP_URL_PATH) ?: '';

// only allow deletion of files under /static/
if (strpos($path, '/static/') === 0) {
    $static_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/static');
    // build local path safely
    $rel = substr($path, strlen('/static')); // starts with '/'
    $local_path = $static_root . $rel;
    // normalize path
    $local_path = realpath($local_path) ?: $local_path;

    // ensure the file is inside static root
    if ($static_root && strpos($local_path, $static_root) === 0 && is_file($local_path)) {
        @unlink($local_path);
    }
}

// delete DB row if any
// delete DB row if any
try {

    // Tentukan ID yang benar-benar dihapus
    $deleted_id = $id;

    // Jika id tidak dikirim, cari id berdasarkan url
    if ($deleted_id <= 0 && $url !== '') {
        $q = $pdo->prepare("SELECT id FROM media WHERE url = :url LIMIT 1");
        $q->execute([':url'=>$url]);
        $found = $q->fetch(PDO::FETCH_ASSOC);
        if ($found) $deleted_id = (int)$found['id'];
    }

    // Lakukan penghapusan
    if ($deleted_id > 0) {
        $pdo->prepare("DELETE FROM media WHERE id = :id")->execute([':id'=>$deleted_id]);
    } else {
        $pdo->prepare("DELETE FROM media WHERE url = :url")->execute([':url'=>$url]);
    }

    echo json_encode([
        'ok' => true,
        'id' => $deleted_id
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()]);
    exit;
}
