<?php
// /adiwira/admin/modal_img/save.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
}

// basic auth check — adapt to your auth system
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

// optional: CSRF verification if your app exposes a verifier function
if (isset($_POST['csrf_token']) && function_exists('verify_csrf_token')) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']); exit;
    }
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing id']); exit;
}

// sanitize fields (trim)
$title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
$alt = isset($_POST['alt']) ? trim((string)$_POST['alt']) : '';
$caption = isset($_POST['caption']) ? trim((string)$_POST['caption']) : '';
$credit = isset($_POST['credit']) ? trim((string)$_POST['credit']) : '';

// NEW: accept target fields
$target_url_raw = isset($_POST['target_url']) ? trim((string)$_POST['target_url']) : '';
$target_attribute_raw = isset($_POST['target_attribute']) ? trim((string)$_POST['target_attribute']) : '';

// validation
$errors = [];

$target_url = null;
if ($target_url_raw !== '') {
    if (!filter_var($target_url_raw, FILTER_VALIDATE_URL)) {
        $errors[] = 'Invalid target URL. Use full URL starting with http:// or https://';
    } else {
        $parts = parse_url($target_url_raw);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (!in_array($scheme, ['http','https'], true)) {
            $errors[] = 'Target URL must use http or https scheme';
        } else {
            $target_url = $target_url_raw;
        }
    }
}

$allowed = ['', '_self', '_blank', '_parent', '_top'];
$target_attribute = null;
if ($target_attribute_raw !== '') {
    if (!in_array($target_attribute_raw, $allowed, true)) {
        $errors[] = 'Invalid target attribute';
    } else {
        $target_attribute = $target_attribute_raw ?: null;
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Validation failed','errors'=>$errors]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "UPDATE media
           SET title = :title,
               alt = :alt,
               caption = :caption,
               credit = :credit,
               target_url = :target_url,
               target_attribute = :target_attribute,
               updated_at = NOW()
         WHERE id = :id"
    );

    // bind with possible NULLs
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':alt', $alt, PDO::PARAM_STR);
    $stmt->bindValue(':caption', $caption, PDO::PARAM_STR);
    $stmt->bindValue(':credit', $credit, PDO::PARAM_STR);
    if ($target_url === null) $stmt->bindValue(':target_url', null, PDO::PARAM_NULL);
    else $stmt->bindValue(':target_url', $target_url, PDO::PARAM_STR);
    if ($target_attribute === null) $stmt->bindValue(':target_attribute', null, PDO::PARAM_NULL);
    else $stmt->bindValue(':target_attribute', $target_attribute, PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

    $stmt->execute();

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'updated' => [
            'title' => $title,
            'alt' => $alt,
            'caption' => $caption,
            'credit' => $credit,
            'target_url' => $target_url,
            'target_attribute' => $target_attribute
        ]
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB error','detail'=>$e->getMessage()]);
    exit;
}
