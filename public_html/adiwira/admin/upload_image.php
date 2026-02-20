<?php
// /adiwira/admin/upload_image.php
// Upload file fisik. Optional: auto-save metadata jika client kirim auto_save=1.
// Will return both 'success' (old) and 'ok'/'media' (new) keys.

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method not allowed']);
    exit;
}

// ensure session
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Not authenticated','ok'=>false,'errors'=>['Not authenticated']]);
    exit;
}

if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'File not found','ok'=>false,'errors'=>['File not found']]);
    exit;
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Upload error code: '.$file['error'],'ok'=>false,'errors'=>['Upload error']]);
    exit;
}

// limits
$maxBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'File too large (max 5MB)','ok'=>false,'errors'=>['File too large']]);
    exit;
}

// static root (document root based)
$static_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/static');
if ($static_root === false) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Static root not found','ok'=>false,'errors'=>['Static root not found']]);
    exit;
}

$upload_base_dir = $static_root . '/img';
$upload_base_url = '/static/img';
if (!is_dir($upload_base_dir) && !mkdir($upload_base_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed to create img dir','ok'=>false,'errors'=>['Failed to create img dir']]);
    exit;
}

// allowed mimes
$allowed_mimes = [
    'image/webp' => 'webp',
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed_mimes[$mime])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Only webp/png/jpg allowed','ok'=>false,'errors'=>['Invalid mime']]);
    exit;
}

$ext = $allowed_mimes[$mime];
$year = date('Y');
$month = date('m');
$target_dir = $upload_base_dir . '/' . $year . '/' . $month;

if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed make upload folder','ok'=>false,'errors'=>['Failed make upload folder']]);
    exit;
}

// unique filename
$original_name = pathinfo($file['name'], PATHINFO_FILENAME);
$slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($original_name));
$slug = trim($slug, '-');
if ($slug === '') $slug = bin2hex(random_bytes(4));
$rand = bin2hex(random_bytes(4));
$filename = $slug . '-' . $rand . '.' . $ext;
$target_path = $target_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed to save file','ok'=>false,'errors'=>['Failed to save file']]);
    exit;
}
@chmod($target_path, 0644);

$public_url = rtrim($upload_base_url, '/') . '/' . $year . '/' . $month . '/' . $filename;

// Build response base
$response = [
    'success' => true,
    'url' => $public_url,
];

// Optional auto-save metadata
$auto_save = !empty($_POST['auto_save']) && in_array($_POST['auto_save'], ['1','true','on'], true);
if ($auto_save) {
    // Collect optional metadata from request
    $title = trim((string)($_POST['title'] ?? $original_name));
    $alt = trim((string)($_POST['alt'] ?? ''));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $credit = trim((string)($_POST['credit'] ?? ''));

    // try to get image info
    $size = @filesize($target_path) ?: 0;
    $g = @getimagesize($target_path);
    $width = $height = null;
    if ($g) { $width = $g[0]; $height = $g[1]; }

    // insert into DB (use $pdo from bootstrap)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO media (url, filename, mime, ext, size, width, height, title, alt, caption, credit, user_id, created_at)
            VALUES (:url,:filename,:mime,:ext,:size,:width,:height,:title,:alt,:caption,:credit,:user_id,NOW())
        ");
        $stmt->execute([
            ':url' => $public_url,
            ':filename' => $filename,
            ':mime' => $mime,
            ':ext' => $ext,
            ':size' => $size,
            ':width' => $width,
            ':height' => $height,
            ':title' => $title,
            ':alt' => $alt ?: null,
            ':caption' => $caption ?: null,
            ':credit' => $credit ?: null,
            ':user_id' => (int)($_SESSION['user_id'] ?? 0)
        ]);
        $media_id = (int)$pdo->lastInsertId();
        $response['ok'] = true;
        $response['media'] = [
            'id' => $media_id,
            'url' => $public_url,
            'filename' => $filename,
            'mime' => $mime,
            'ext' => $ext,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'title' => $title
        ];
    } catch (Throwable $e) {
        // don't break upload, but report DB error
        $response['ok'] = false;
        $response['errors'] = ['DB insert failed: '.$e->getMessage()];
    }
} // end auto_save

echo json_encode($response);
exit;
