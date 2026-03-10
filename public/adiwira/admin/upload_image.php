<?php
declare(strict_types=1);

// /adiwira/admin/upload_image.php

ob_start();
@ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/_guard.php';

adiwira_cosmetic_404_on_direct_open();

/**
 * Jika fatal error terjadi sebelum sempat output JSON,
 * paksa balikin JSON supaya frontend tidak dapat body kosong.
 */
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
        'error'   => 'Server error',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

[$uid, $role] = adiwira_require_editorial($pdo, true);

// lebih sunyi
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'Not found'], 404);
}

// CSRF
$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!adiwira_csrf_validate(is_string($csrf) ? $csrf : '')) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'CSRF invalid'], 419);
}

// file guard
if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'File not found'], 400);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'Upload error code: ' . (int)$file['error']], 400);
}
if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'Invalid upload'], 400);
}

// limits
$maxBytes = 5 * 1024 * 1024;
if (($file['size'] ?? 0) > $maxBytes) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'File too large (max 5MB)'], 413);
}

// lokasi /static/img
$static_root = rtrim((string)PUBLIC_PATH, '/\\') . '/static';
$upload_base_dir = $static_root . '/img';
$upload_base_url = '/static/img';

if (!is_dir($upload_base_dir) && !mkdir($upload_base_dir, 0755, true)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'Failed to create img dir'], 500);
}

/**
 * MIME detection yang lebih robust:
 * - getimagesize
 * - exif_imagetype
 * - finfo
 * - mime_content_type
 */
$tmp = (string)$file['tmp_name'];
$det = [
    'getimagesize'      => null,
    'exif'              => null,
    'finfo'             => null,
    'mime_content_type' => null,
];

$mime = '';

// 1) getimagesize
$g = @getimagesize($tmp);
if (is_array($g) && !empty($g['mime'])) {
    $det['getimagesize'] = (string)$g['mime'];
    $mime = (string)$g['mime'];
}

// 2) exif_imagetype
if ($mime === '' && function_exists('exif_imagetype')) {
    $t = @exif_imagetype($tmp);
    if ($t) {
        $map = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG  => 'image/png',
        ];
        if (defined('IMAGETYPE_WEBP')) {
            $map[IMAGETYPE_WEBP] = 'image/webp';
        }

        if (isset($map[$t])) {
            $det['exif'] = $map[$t];
            $mime = $map[$t];
        } else {
            $det['exif'] = 'type=' . (string)$t;
        }
    }
}

// 3) finfo
if ($mime === '' && class_exists('finfo')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $m = $fi->file($tmp);
    if (is_string($m) && $m !== '') {
        $det['finfo'] = $m;
        $mime = $m;
    }
}

// 4) mime_content_type
if ($mime === '' && function_exists('mime_content_type')) {
    $m = @mime_content_type($tmp);
    if (is_string($m) && $m !== '') {
        $det['mime_content_type'] = $m;
        $mime = $m;
    }
}

$mime = strtolower(trim((string)$mime));

// normalisasi
$normalize = [
    'image/pjpeg' => 'image/jpeg',
    'image/jpg'   => 'image/jpeg',
    'image/x-png' => 'image/png',
];
if (isset($normalize[$mime])) {
    $mime = $normalize[$mime];
}

$allowed = [
    'image/webp' => 'webp',
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
];

if (!isset($allowed[$mime])) {
    adiwira_json([
        'success'       => false,
        'ok'            => false,
        'error'         => 'Only webp/png/jpg/jpeg allowed',
        'detected_mime' => $mime,
        'detectors'     => $det,
    ], 415);
}

$ext = $allowed[$mime];

// folder per tahun/bulan
$year = date('Y');
$month = date('m');

$target_dir = $upload_base_dir . '/' . $year . '/' . $month;
if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true)) {
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'Failed make upload folder'], 500);
}

// unique filename
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
    adiwira_json(['success' => false, 'ok' => false, 'error' => 'Failed to save file'], 500);
}
@chmod($target_path, 0644);

$public_url = rtrim($upload_base_url, '/') . '/' . $year . '/' . $month . '/' . $filename;

$response = [
    'success' => true,
    'ok'      => true,
    'url'     => $public_url,
];

// auto-save metadata
$auto_save = !empty($_POST['auto_save']) && in_array((string)$_POST['auto_save'], ['1', 'true', 'on'], true);
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
        $stmt = $pdo->prepare("
            INSERT INTO media
                (url, filename, mime, ext, size, width, height, title, alt, caption, credit, user_id, created_at)
            VALUES
                (:url, :filename, :mime, :ext, :size, :width, :height, :title, :alt, :caption, :credit, :user_id, NOW())
        ");
        $stmt->execute([
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
        ]);

        $media_id = (int)$pdo->lastInsertId();
        $response['media'] = [
            'id'       => $media_id,
            'url'      => $public_url,
            'filename' => $filename,
            'mime'     => $mime,
            'ext'      => $ext,
            'size'     => $size,
            'width'    => $width,
            'height'   => $height,
            'title'    => $title,
        ];
    } catch (Throwable $e) {
        @unlink($target_path);
        error_log('upload_image.php DB insert failed: ' . $e->getMessage());
        adiwira_json([
            'success' => false,
            'ok'      => false,
            'error'   => 'DB insert failed',
        ], 500);
    }
}

adiwira_json($response, 200);