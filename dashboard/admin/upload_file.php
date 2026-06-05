<?php
declare(strict_types=1);

// /adiwira/admin/upload_file.php
// Upload endpoint untuk File Library.
// v1 private file: mendukung public storage dan private storage di luar public_html.
ob_start();
@ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_guard.php';

if (adiwira_is_navigate_request()) {
    http_response_code(404);
    require FRONTEND_404_PATH;
    exit;
}

register_shutdown_function(function () {
    $sent = (bool)($GLOBALS['__ADIWIRA_JSON_SENT'] ?? false);
    if ($sent) return;

    $err = error_get_last();
    if (!$err) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) return;

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

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
        'error'   => __('Server error'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

[$uid, $role] = adiwira_require_editorial($pdo, true);
$isAdmin = ((string)$role === 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Not found')], 404);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($csrf) || $csrf === '' || !adiwira_csrf_validate($csrf)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('CSRF required')], 419);
}

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('File not found')], 400);
}

$file = $_FILES['file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    adiwira_json([
        'success' => false,
        'ok'      => false,
        'error'   => sprintf(__('Upload error code: %d'), (int)$file['error']),
    ], 400);
}

if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Invalid upload')], 400);
}

$auto_save = !empty($_POST['auto_save']) && in_array((string)$_POST['auto_save'], ['1', 'true', 'on'], true);

$maxBytes = 30 * 1024 * 1024;
if ((int)($file['size'] ?? 0) > $maxBytes) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('File too large (max 30MB)')], 413);
}

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(PDO $pdo, string $column): bool
    {
        try {
            $st = $pdo->prepare("SELECT {$column} FROM `file` LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$hasPrivateCols = isset($pdo) ? mdlib_has_column($pdo, 'visibility') : false;

if (!function_exists('adiwira_file_private_base_dir')) {
    function adiwira_file_private_base_dir(): string
    {
        $appRoot = realpath(__DIR__ . '/../..');
        if ($appRoot === false) {
            $appRoot = dirname(__DIR__, 2);
        }

        return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
    }
}

if (!function_exists('adiwira_file_normalize_choice')) {
    function adiwira_file_normalize_choice(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}

if (!function_exists('adiwira_file_slug')) {
    function adiwira_file_slug(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }

        $slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $value);
        $slug = trim((string)$slug, '-');
        return $slug;
    }
}

$publicPath = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$static_root = rtrim($publicPath, '/\\') . '/static';
$public_upload_base_dir = $static_root . '/files';
$public_upload_base_url = '/static/files';
$private_upload_base_dir = adiwira_file_private_base_dir();

$tmp = (string)$file['tmp_name'];
$det = [
    'finfo'             => null,
    'mime_content_type' => null,
    'fallback_ext'      => null,
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

$normalize = [
    'application/x-zip-compressed' => 'application/zip',
    'audio/x-wav'                  => 'audio/wav',
    'audio/wave'                   => 'audio/wav',
    'audio/x-mpeg'                 => 'audio/mpeg',
    'audio/mp3'                    => 'audio/mpeg',
    'application/ogg'              => 'audio/ogg',
];
if (isset($normalize[$mime])) {
    $mime = $normalize[$mime];
}

$allowed_mimes = [
    'application/pdf'                                                               => 'pdf',
    'application/msword'                                                            => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'       => 'docx',
    'text/plain'                                                                    => 'txt',
    'application/rtf'                                                               => 'rtf',
    'application/vnd.ms-excel'                                                      => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'             => 'xlsx',
    'application/vnd.ms-powerpoint'                                                 => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'     => 'pptx',
    'application/zip'                                                               => 'zip',
    'video/mp4'                                                                     => 'mp4',
    'video/webm'                                                                    => 'webm',
    'video/quicktime'                                                               => 'mov',
    'audio/mpeg'                                                                    => 'mp3',
    'audio/wav'                                                                     => 'wav',
    'audio/ogg'                                                                     => 'ogg',
];

$ext = null;

if (isset($allowed_mimes[$mime])) {
    $ext = $allowed_mimes[$mime];
}

if ($ext === null) {
    $response = [
        'success' => false,
        'ok'      => false,
        'error'   => __('File type not allowed'),
    ];
    if (function_exists('app_debug_enabled') && app_debug_enabled()) {
        $response['detected_mime'] = $mime;
        $response['detectors']     = $det;
    }
    adiwira_json($response, 415);
}

$visibilityInput = $hasPrivateCols
    ? adiwira_file_normalize_choice((string)($_POST['visibility'] ?? 'auto'), ['auto','public','private'], 'auto')
    : 'public';
$accessScopeInput = $hasPrivateCols
    ? adiwira_file_normalize_choice((string)($_POST['access_scope'] ?? 'editorial'), ['public','editorial','admin'], 'editorial')
    : 'public';

$visibility = $visibilityInput === 'auto' ? 'public' : $visibilityInput;

if (!$hasPrivateCols) {
    $visibility = 'public';
}

$storage_disk = $visibility === 'private' ? 'private' : 'public';
$access_scope = $visibility === 'private' ? $accessScopeInput : 'public';
if ($access_scope === 'public' && $visibility === 'private') {
    $access_scope = 'editorial';
}

if (array_key_exists('is_downloadable', $_POST)) {
    $is_downloadable = in_array((string)$_POST['is_downloadable'], ['1','true','on','yes'], true) ? 1 : 0;
} else {
    $is_downloadable = ($visibility === 'private') ? 0 : 1;
}

if ($visibility === 'private' && !$auto_save) {
    adiwira_json([
        'success' => false,
        'ok'      => false,
        'error'   => __('Private files require auto-save so a protected URL can be generated.'),
    ], 400);
}

$year = date('Y');
$month = date('m');
$relative_storage_path = $year . '/' . $month;

if ($storage_disk === 'private') {
    $target_base_dir = rtrim($private_upload_base_dir, '/\\') . '/files';
    $target_dir = $target_base_dir . '/' . $relative_storage_path;
    $dirMode = 0750;
} else {
    $target_base_dir = $public_upload_base_dir;
    $target_dir = $target_base_dir . '/' . $relative_storage_path;
    $dirMode = 0755;
}

if (!is_dir($target_dir) && !@mkdir($target_dir, $dirMode, true)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Failed to create upload folder')], 500);
}

$original_name = (string)pathinfo((string)$file['name'], PATHINFO_FILENAME);
$slug = adiwira_file_slug($original_name);
if ($slug === '') {
    $slug = bin2hex(random_bytes(4));
}

$rand = bin2hex(random_bytes(4));
$filename = $slug . '-' . $rand . '.' . $ext;
$target_path = $target_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Failed to save file')], 500);
}

@chmod($target_path, $storage_disk === 'private' ? 0640 : 0644);

$storage_path = $relative_storage_path . '/' . $filename;
$public_url = rtrim($public_upload_base_url, '/') . '/' . $storage_path;
$client_url = $storage_disk === 'private' ? '' : $public_url;

$response = [
    'success'       => true,
    'ok'            => true,
    'url'           => $client_url,
    'visibility'    => $visibility,
    'storage_disk'  => $storage_disk,
    'access_scope'  => $access_scope,
    'is_downloadable' => $is_downloadable,
];

if ($auto_save) {
    $title = trim((string)($_POST['title'] ?? $original_name));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $credit = trim((string)($_POST['credit'] ?? ''));
    $file_size = @filesize($target_path) ?: 0;

    $media_type = 'file';
    if (strpos($mime, 'video/') === 0) $media_type = 'video';
    if (strpos($mime, 'audio/') === 0) $media_type = 'audio';
    if (strpos($mime, 'image/') === 0) $media_type = 'image';

    $duration = null;
    $thumb_url = null;

    $db_url = $storage_disk === 'private' ? '/private/file/view/?id=0' : $public_url;

    try {
        $commonCols = 'url, filename, mime, ext, size, title, caption, credit, user_id, media_type, duration, thumb_url, created_at';
        $commonVals = ':url, :filename, :mime, :ext, :size, :title, :caption, :credit, :user_id, :media_type, :duration, :thumb_url, NOW()';

        $extraCols = '';
        $extraVals = '';
        $extraParams = [];
        if ($hasPrivateCols) {
            $extraCols = ', visibility, storage_disk, storage_path, access_scope, is_downloadable';
            $extraVals = ', :visibility, :storage_disk, :storage_path, :access_scope, :is_downloadable';
            $extraParams = [
                ':visibility'      => $visibility,
                ':storage_disk'    => $storage_disk,
                ':storage_path'    => $storage_path,
                ':access_scope'    => $access_scope,
                ':is_downloadable' => $is_downloadable,
            ];
        }

        $sql = "INSERT INTO `file` ({$commonCols}{$extraCols}) VALUES ({$commonVals}{$extraVals})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([
            ':url'             => $db_url,
            ':filename'        => $filename,
            ':mime'            => $mime,
            ':ext'             => $ext,
            ':size'            => $file_size,
            ':title'           => ($title !== '' ? $title : null),
            ':caption'         => ($caption !== '' ? $caption : null),
            ':credit'          => ($credit !== '' ? $credit : null),
            ':user_id'         => $uid,
            ':media_type'      => $media_type,
            ':duration'        => $duration,
            ':thumb_url'       => $thumb_url,
        ], $extraParams));

        $file_id = (int)$pdo->lastInsertId();

        if ($storage_disk === 'private') {
            $client_url = '/private/file/view/?id=' . $file_id;
            $up = $pdo->prepare("UPDATE `file` SET url = :url WHERE id = :id LIMIT 1");
            $up->execute([':url' => $client_url, ':id' => $file_id]);
            $response['url'] = $client_url;
        }

        $response['file'] = [
            'id'              => $file_id,
            'url'             => $client_url,
            'filename'        => $filename,
            'mime'            => $mime,
            'ext'             => $ext,
            'size'            => $file_size,
            'size_label'      => null,
            'title'           => $title,
            'media_type'      => $media_type,
            'user_id'         => $uid,
            'visibility'      => $visibility,
            'storage_disk'    => $storage_disk,
            'storage_path'    => $storage_path,
            'access_scope'    => $access_scope,
            'is_downloadable' => $is_downloadable,
        ];
    } catch (Throwable $e) {
        error_log('upload_file.php insert error: ' . $e->getMessage());
        @unlink($target_path);
        $response['success'] = false;
        $response['ok'] = false;
        $response['errors'] = [__('Database insert failed')];
        $response['error'] = __('Database insert failed');
        adiwira_json($response, 500);
    }
}

adiwira_json($response, 200);
