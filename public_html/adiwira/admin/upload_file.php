<?php
// /adiwira/admin/upload_file.php
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

// expected input name: "file"
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'File not found','ok'=>false,'errors'=>['File not found']]);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Upload error code: '.$file['error'],'ok'=>false,'errors'=>['Upload error']]);
    exit;
}

// limits: 30 MB
$maxBytes = 30 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'File too large (max 30MB)','ok'=>false,'errors'=>['File too large']]);
    exit;
}

// static root (document root based)
$static_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/static');
if ($static_root === false) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Static root not found','ok'=>false,'errors'=>['Static root not found']]);
    exit;
}

$upload_base_dir = $static_root . '/files';
$upload_base_url = '/static/files';
if (!is_dir($upload_base_dir) && !mkdir($upload_base_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed to create files dir','ok'=>false,'errors'=>['Failed to create files dir']]);
    exit;
}

// allowed mimes -> extension
$allowed_mimes = [
    // documents
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'text/plain' => 'txt',
    'application/rtf' => 'rtf',
    // excel
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    // powerpoint
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    // archives (optional)
    'application/zip' => 'zip',
    // videos
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/quicktime' => 'mov',
];

// detect mime securely
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// reject unknown mime
if (!isset($allowed_mimes[$mime])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'File type not allowed: '.$mime,'ok'=>false,'errors'=>['Invalid mime: '.$mime]]);
    exit;
}

$ext = $allowed_mimes[$mime];

// create year/month dir
$year = date('Y');
$month = date('m');
$target_dir = $upload_base_dir . '/' . $year . '/' . $month;

if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed make upload folder','ok'=>false,'errors'=>['Failed make upload folder']]);
    exit;
}

// unique filename (slug + random)
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

// Optional auto-save metadata to table `file`
$auto_save = !empty($_POST['auto_save']) && in_array($_POST['auto_save'], ['1','true','on'], true);
if ($auto_save) {
    $title = trim((string)($_POST['title'] ?? $original_name));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $credit = trim((string)($_POST['credit'] ?? ''));
    $file_size = @filesize($target_path) ?: 0;

    // compute media_type: image/file/video (we allow video types here)
    $media_type = 'file';
    if (strpos($mime, 'video/') === 0) $media_type = 'video';
    if (strpos($mime, 'image/') === 0) $media_type = 'image';

    // default duration/thumb null (optional post-processing with ffmpeg)
    $duration = null;
    $thumb_url = null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO file (url, filename, mime, ext, size, title, caption, credit, user_id, media_type, duration, thumb_url, created_at)
            VALUES (:url,:filename,:mime,:ext,:size,:title,:caption,:credit,:user_id,:media_type,:duration,:thumb_url,NOW())
        ");
        $stmt->execute([
            ':url' => $public_url,
            ':filename' => $filename,
            ':mime' => $mime,
            ':ext' => $ext,
            ':size' => $file_size,
            ':title' => $title ?: null,
            ':caption' => $caption ?: null,
            ':credit' => $credit ?: null,
            ':user_id' => (int)($_SESSION['user_id'] ?? 0),
            ':media_type' => $media_type,
            ':duration' => $duration,
            ':thumb_url' => $thumb_url
        ]);
        $file_id = (int)$pdo->lastInsertId();
        $response['ok'] = true;
        $response['file'] = [
            'id' => $file_id,
            'url' => $public_url,
            'filename' => $filename,
            'mime' => $mime,
            'ext' => $ext,
            'size' => $file_size,
            'title' => $title,
            'media_type' => $media_type
        ];
    } catch (Throwable $e) {
        // don't break upload, but report DB error
        $response['ok'] = false;
        $response['errors'] = ['DB insert failed: '.$e->getMessage()];
    }
} // end auto_save

echo json_encode($response);
exit;
