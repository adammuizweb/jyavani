<?php
// /adiwira/admin/modal_img/delete.php
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

// optional: CSRF verification if available
if (isset($_POST['csrf_token']) && function_exists('verify_csrf_token')) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']); exit;
    }
}

$id = (int)($_POST['id'] ?? 0);
$url = trim((string)($_POST['url'] ?? ''));

if ($id <= 0 && $url === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing id or url']);
    exit;
}

// If id given, fetch url for optional file deletion
if ($id > 0) {
    $q = $pdo->prepare("SELECT url FROM media WHERE id = :id LIMIT 1");
    $q->execute([':id'=>$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if ($r) $url = $r['url'];
}

// determine path component
$path = parse_url($url, PHP_URL_PATH) ?: '';

// Only delete filesystem files inside /static/ (safety)
if ($path && strpos($path, '/static/') === 0) {
    $static_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/static');
    if ($static_root) {
        // Build local path from the path component
        $rel = substr($path, strlen('/static')); // leading '/'
        $local_path = $static_root . $rel;
        // normalize
        $local_real = realpath($local_path) ?: $local_path;

        if (is_file($local_real) && strpos($local_real, $static_root) === 0) {
            @unlink($local_real);
        }
    }
}

// Delete DB row if any
try {
    $deleted_id = $id;

    if ($deleted_id <= 0 && $url !== '') {
        $q = $pdo->prepare("SELECT id FROM media WHERE url = :url LIMIT 1");
        $q->execute([':url'=>$url]);
        $found = $q->fetch(PDO::FETCH_ASSOC);
        if ($found) $deleted_id = (int)$found['id'];
    }

    if ($deleted_id > 0) {
        $pdo->prepare("DELETE FROM media WHERE id = :id")->execute([':id'=>$deleted_id]);
    } else {
        // fallback: try delete by url
        $pdo->prepare("DELETE FROM media WHERE url = :url")->execute([':url'=>$url]);
    }

    echo json_encode([
        'ok' => true,
        'id' => $deleted_id,
        'url' => $url
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()]);
    exit;
}
