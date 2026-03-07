<?php
declare(strict_types=1);

// /adiwira/admin/upload_file.php
ob_start();
@ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_guard.php';

// ✅ KOSMETIK: kalau dibuka langsung via browser/tab, samarkan sebagai 404 HTML
if (adiwira_is_navigate_request()) {
  http_response_code(404);
  require __DIR__ . '/../../frontend_404.php';
  exit;
}

// Shutdown handler: kalau fatal error, balikin JSON (untuk request AJAX)
register_shutdown_function(function () {
  $sent = (bool)($GLOBALS['__ADIWIRA_JSON_SENT'] ?? false);
  if ($sent) return;

  $err = error_get_last();
  if (!$err) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array((int)$err['type'], $fatalTypes, true)) return;

  while (ob_get_level() > 0) { @ob_end_clean(); }

  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }

  echo json_encode([
    'success' => false,
    'ok'      => false,
    'error'   => 'Server error',
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

adiwira_require_admin(true);

// ✅ lebih “sunyi”: kalau bukan POST, balikin 404 (bukan 405)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'Not found'], 404);
}

// CSRF wajib
$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($csrf) || $csrf === '' || !adiwira_csrf_validate($csrf)) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'CSRF required'], 419);
}

// expected input name: "file"
if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'File not found'], 400);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'Upload error code: '.(int)$file['error']], 400);
}
if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'Invalid upload'], 400);
}

// limits: 30 MB
$maxBytes = 30 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxBytes) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'File too large (max 30MB)'], 413);
}

// resolve /static root using PUBLIC_PATH (lebih stabil dari DOCUMENT_ROOT)
$publicPath = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$static_root = rtrim($publicPath, '/\\') . '/static';
$upload_base_dir = $static_root . '/files';
$upload_base_url = '/static/files';

if (!is_dir($upload_base_dir) && !@mkdir($upload_base_dir, 0755, true)) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'Failed to create files dir'], 500);
}

/**
 * MIME detect robust:
 * - finfo
 * - mime_content_type
 * fallback: if octet-stream, use extension allowlist
 */
$tmp = (string)$file['tmp_name'];
$det = [
  'finfo' => null,
  'mime_content_type' => null,
  'fallback_ext' => null,
];

$mime = '';
if (class_exists('finfo')) {
  $fi = new finfo(FILEINFO_MIME_TYPE);
  $m = $fi->file($tmp);
  if (is_string($m) && $m !== '') {
    $det['finfo'] = $m;
    $mime = $m;
  }
}
if ($mime === '' && function_exists('mime_content_type')) {
  $m = @mime_content_type($tmp);
  if (is_string($m) && $m !== '') {
    $det['mime_content_type'] = $m;
    $mime = $m;
  }
}

$mime = strtolower(trim((string)$mime));

// normalisasi beberapa varian umum
$normalize = [
  'application/x-zip-compressed' => 'application/zip',
  'audio/x-wav' => 'audio/wav',
  'audio/wave'  => 'audio/wav',
  'audio/x-mpeg'=> 'audio/mpeg',
  'audio/mp3'   => 'audio/mpeg',
  'application/ogg' => 'audio/ogg',
];
if (isset($normalize[$mime])) $mime = $normalize[$mime];

// allowed mimes -> ext
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

  // archives
  'application/zip' => 'zip',

  // videos
  'video/mp4' => 'mp4',
  'video/webm' => 'webm',
  'video/quicktime' => 'mov',

  // ✅ audio
  'audio/mpeg' => 'mp3',
  'audio/wav'  => 'wav',
  'audio/ogg'  => 'ogg',
];

// extension fallback allowlist (only if mime unknown/octet-stream)
$allowed_ext_fallback = [
  'pdf','doc','docx','txt','rtf','xls','xlsx','ppt','pptx','zip','mp4','webm','mov','mp3','wav','ogg'
];

// pick ext from mime if possible
$ext = null;
if (isset($allowed_mimes[$mime])) {
  $ext = $allowed_mimes[$mime];
} else {
  // fallback if finfo returns octet-stream / empty
  $origExt = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
  if ($origExt !== '' && in_array($origExt, $allowed_ext_fallback, true)) {
    $det['fallback_ext'] = $origExt;
    $ext = $origExt;

    // set a reasonable mime for storage if unknown
    $rev = [
      'mp3' => 'audio/mpeg',
      'wav' => 'audio/wav',
      'ogg' => 'audio/ogg',
      'zip' => 'application/zip',
      'pdf' => 'application/pdf',
      'txt' => 'text/plain',
      'rtf' => 'application/rtf',
      'mp4' => 'video/mp4',
      'webm'=> 'video/webm',
      'mov' => 'video/quicktime',
      'doc' => 'application/msword',
      'docx'=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx'=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx'=> 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    if (!isset($allowed_mimes[$mime]) && isset($rev[$origExt])) {
      $mime = $rev[$origExt];
    }
  }
}

if ($ext === null) {
  adiwira_json([
    'success'=>false,
    'ok'=>false,
    'error'=>'File type not allowed',
    'detected_mime'=>$mime,
    'detectors'=>$det
  ], 415);
}

// create year/month dir
$year = date('Y');
$month = date('m');
$target_dir = $upload_base_dir . '/' . $year . '/' . $month;

if (!is_dir($target_dir) && !@mkdir($target_dir, 0755, true)) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'Failed make upload folder'], 500);
}

// unique filename (slug + random)
$original_name = (string)pathinfo((string)$file['name'], PATHINFO_FILENAME);
$slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($original_name, 'UTF-8'));
$slug = trim((string)$slug, '-');
if ($slug === '') $slug = bin2hex(random_bytes(4));

$rand = bin2hex(random_bytes(4));
$filename = $slug . '-' . $rand . '.' . $ext;
$target_path = $target_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
  adiwira_json(['success'=>false,'ok'=>false,'error'=>'Failed to save file'], 500);
}
@chmod($target_path, 0644);

$public_url = rtrim($upload_base_url, '/') . '/' . $year . '/' . $month . '/' . $filename;

$response = [
  'success' => true,
  'ok'      => true,
  'url'     => $public_url,
];

// Optional auto-save metadata to table `file`
$auto_save = !empty($_POST['auto_save']) && in_array((string)$_POST['auto_save'], ['1','true','on'], true);
if ($auto_save) {
  $title = trim((string)($_POST['title'] ?? $original_name));
  $caption = trim((string)($_POST['caption'] ?? ''));
  $credit = trim((string)($_POST['credit'] ?? ''));
  $file_size = @filesize($target_path) ?: 0;

  // media_type
  $media_type = 'file';
  if (strpos($mime, 'video/') === 0) $media_type = 'video';
  if (strpos($mime, 'audio/') === 0) $media_type = 'audio';
  if (strpos($mime, 'image/') === 0) $media_type = 'image';

  $duration = null;
  $thumb_url = null;

  try {
    $stmt = $pdo->prepare("
      INSERT INTO `file` (url, filename, mime, ext, size, title, caption, credit, user_id, media_type, duration, thumb_url, created_at)
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
      ':thumb_url' => $thumb_url,
    ]);

    $file_id = (int)$pdo->lastInsertId();
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
    $response['ok'] = false;
    $response['errors'] = ['DB insert failed: '.$e->getMessage()];
  }
}

adiwira_json($response, 200);