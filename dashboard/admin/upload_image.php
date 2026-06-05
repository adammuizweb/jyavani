<?php
declare(strict_types=1);

// /adiwira/admin/upload_image.php

ob_start();
@ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_guard.php';

adiwira_cosmetic_404_on_direct_open();

register_shutdown_function(function () {
    $sent = (bool)($GLOBALS['__ADIWIRA_JSON_SENT'] ?? false);
    if ($sent) return;

    $err = error_get_last();
    if (!$err) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) return;

    error_log('upload_image.php fatal: ' . ($err['message'] ?? 'unknown') . ' in ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'));

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Not found')], 404);
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('CSRF invalid')], 419);
}

if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('File not found')], 400);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => sprintf(__('Upload error code: %d'), (int)$file['error'])], 400);
}
if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Invalid upload')], 400);
}

$maxBytes = 20 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxBytes) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('File too large (max 5MB)')], 413);
}

$auto_save = !empty($_POST['auto_save']) && in_array((string)$_POST['auto_save'], ['1', 'true', 'on'], true);

if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(PDO $pdo, string $column): bool
    {
        try {
            $st = $pdo->prepare("SELECT {$column} FROM media LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$hasPrivateCols = isset($pdo) ? mdlib_has_column($pdo, 'visibility') : false;

if (!function_exists('adiwira_media_private_base_dir')) {
    function adiwira_media_private_base_dir(): string
    {
        $appRoot = realpath(__DIR__ . '/../..');
        if ($appRoot === false) {
            $appRoot = dirname(__DIR__, 2);
        }

        return rtrim(str_replace('\\', '/', $appRoot), '/') . '/private_files';
    }
}

if (!function_exists('adiwira_media_normalize_choice')) {
    function adiwira_media_normalize_choice(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}

// public
$publicPath = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$static_root = rtrim($publicPath, '/\\') . '/static';
$upload_base_dir = $static_root . '/img';
$upload_base_url = '/static/img';

// private
$private_upload_base_dir = adiwira_media_private_base_dir();

if (!is_dir($upload_base_dir) && !mkdir($upload_base_dir, 0755, true)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Failed to create image directory')], 500);
}

$tmp = (string)$file['tmp_name'];
$det = [
    'getimagesize'      => null,
    'exif'              => null,
    'finfo'             => null,
    'mime_content_type' => null,
    'signature'         => null,
];

$mime = '';

$g = @getimagesize($tmp);
if (is_array($g) && !empty($g['mime'])) {
    $det['getimagesize'] = (string)$g['mime'];
    $mime = (string)$g['mime'];
}

if ($mime === '' && function_exists('exif_imagetype')) {
    $t = @exif_imagetype($tmp);
    if ($t) {
        $map = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
        ];
        if (defined('IMAGETYPE_WEBP')) $map[IMAGETYPE_WEBP] = 'image/webp';
        if (defined('IMAGETYPE_AVIF')) $map[IMAGETYPE_AVIF] = 'image/avif';
        if (isset($map[$t])) {
            $det['exif'] = $map[$t];
            $mime = $map[$t];
        } else {
            $det['exif'] = 'type=' . (string)$t;
        }
    }
}

if ($mime === '' && class_exists('finfo')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $m = $fi->file($tmp);
    if (is_string($m) && $m !== '') {
        $det['finfo'] = $m;
        $mime = $m;
    }
}

if (($mime === '' || $mime === 'application/octet-stream') && function_exists('mime_content_type')) {
    $m = @mime_content_type($tmp);
    if (is_string($m) && $m !== '') {
        $det['mime_content_type'] = $m;
        $mime = $m;
    }
}

if ($mime === '' || $mime === 'application/octet-stream') {
    $head = @file_get_contents($tmp, false, null, 0, 64);
    if (is_string($head) && preg_match('/ftyp(?:avif|avis)/i', $head)) {
        $det['signature'] = 'image/avif';
        $mime = 'image/avif';
    }
}

$mime = strtolower(trim((string)$mime));

$normalize = [
    'image/pjpeg'         => 'image/jpeg',
    'image/jpg'           => 'image/jpeg',
    'image/x-png'         => 'image/png',
    'image/avif-sequence' => 'image/avif',
];
if (isset($normalize[$mime])) {
    $mime = $normalize[$mime];
}

$allowed = [
    'image/avif' => 'avif',
    'image/webp' => 'webp',
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
];

if (!isset($allowed[$mime])) {
    $response = [
        'success' => false,
        'ok'      => false,
        'error'   => __('Only avif/webp/png/jpg/jpeg allowed'),
    ];
    if (function_exists('app_debug_enabled') && app_debug_enabled()) {
        $response['detected_mime'] = $mime;
        $response['detectors'] = $det;
    }
    adiwira_json($response, 415);
}

$ext = $allowed[$mime];

// visibility logic
$visibilityInput = $hasPrivateCols
    ? adiwira_media_normalize_choice((string)($_POST['visibility'] ?? 'auto'), ['auto','public','private'], 'auto')
    : 'public';
$accessScopeInput = $hasPrivateCols
    ? adiwira_media_normalize_choice((string)($_POST['access_scope'] ?? 'editorial'), ['public','editorial','admin'], 'editorial')
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

$year = date('Y');
$month = date('m');
$relative_storage_path = $year . '/' . $month;

if ($storage_disk === 'private') {
    $target_base_dir = rtrim($private_upload_base_dir, '/\\') . '/media';
    $target_dir = $target_base_dir . '/' . $relative_storage_path;
    $dirMode = 0750;
} else {
    $target_dir = $upload_base_dir . '/' . $relative_storage_path;
    $dirMode = 0755;
}

if (!is_dir($target_dir) && !@mkdir($target_dir, $dirMode, true)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Failed to create upload folder')], 500);
}

$original_name = pathinfo((string)$file['name'], PATHINFO_FILENAME);
$slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', mb_strtolower($original_name, 'UTF-8'));
$slug = trim((string)$slug, '-');
if ($slug === '') {
    $slug = bin2hex(random_bytes(4));
}

$rand = bin2hex(random_bytes(4));
$filename = $slug . '-' . $rand . '.' . $ext;
$target_path = $target_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => __('Failed to save file')], 500);
}

file_put_contents('/tmp/upload_debug.log', date('H:i:s') . ' file moved OK' . PHP_EOL, FILE_APPEND);

@chmod($target_path, $storage_disk === 'private' ? 0640 : 0644);

$storage_path = $relative_storage_path . '/' . $filename;
$public_url = rtrim($upload_base_url, '/') . '/' . $storage_path;
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
    $title   = trim((string)($_POST['title'] ?? $original_name));
    $alt     = trim((string)($_POST['alt'] ?? ''));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $credit  = trim((string)($_POST['credit'] ?? ''));

    $size = @filesize($target_path) ?: 0;
    $g2 = @getimagesize($target_path);
    $width = $height = null;
    if ($g2) {
        $width = $g2[0];
        $height = $g2[1];
    }

    try {
        $commonCols = 'url, filename, mime, ext, size, width, height, title, alt, caption, credit, user_id, created_at';
        $commonVals = ':url, :filename, :mime, :ext, :size, :width, :height, :title, :alt, :caption, :credit, :user_id, NOW()';

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

        $sql = "INSERT INTO media ({$commonCols}{$extraCols}) VALUES ({$commonVals}{$extraVals})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([
            ':url'      => $public_url,
            ':filename' => $filename,
            ':mime'     => $mime,
            ':ext'      => $ext,
            ':size'     => $size,
            ':width'    => $width,
            ':height'   => $height,
            ':title'    => $title,
            ':alt'      => $alt ?: null,
            ':caption'  => $caption ?: null,
            ':credit'   => $credit ?: null,
            ':user_id'  => $uid,
        ], $extraParams));

        $media_id = (int)$pdo->lastInsertId();

        if ($storage_disk === 'private') {
            $client_url = '/private/media/view/?id=' . $media_id;
            $up = $pdo->prepare("UPDATE media SET url = :url WHERE id = :id LIMIT 1");
            $up->execute([':url' => $client_url, ':id' => $media_id]);
            $response['url'] = $client_url;
        }

        $response['media'] = [
            'id'             => $media_id,
            'url'            => $client_url,
            'filename'       => $filename,
            'mime'           => $mime,
            'ext'            => $ext,
            'size'           => $size,
            'width'          => $width,
            'height'         => $height,
            'title'          => $title,
            'visibility'     => $visibility,
            'storage_disk'   => $storage_disk,
            'storage_path'   => $storage_path,
            'access_scope'   => $access_scope,
            'is_downloadable'=> $is_downloadable,
        ];
    } catch (Throwable $e) {
        @unlink($target_path);
        error_log('upload_image.php DB insert failed: ' . $e->getMessage());
        adiwira_json([
            'success' => false,
            'ok'      => false,
            'error'   => __('Database insert failed'),
        ], 500);
    }
}

adiwira_json($response, 200);
