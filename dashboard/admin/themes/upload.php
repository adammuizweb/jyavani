<?php
declare(strict_types=1);

// /adiwira/admin/themes/upload.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../../../cfg/helpers/theme_helper.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_role($pdo, ['admin'], true);

if (!function_exists('theme_upload_is_ajax')) {
    function theme_upload_is_ajax(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/themes/assign';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('theme_upload_respond')) {
    function theme_upload_respond(bool $ok, string $message, int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/themes/assign';

        if (theme_upload_is_ajax()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge([
                'ok' => $ok,
                'message' => $message,
                'redirect' => $redirect,
            ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    theme_upload_respond(false, __('Method Not Allowed'), 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    theme_upload_respond(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

if (empty($_FILES['theme_zip']) || (int)($_FILES['theme_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    theme_upload_respond(false, __('File zip tidak ditemukan atau upload gagal.'), 400, [], $returnTo);
}

$file = $_FILES['theme_zip'];

$maxBytes = 50 * 1024 * 1024;
if ((int)($file['size'] ?? 0) > $maxBytes) {
    theme_upload_respond(false, __('File terlalu besar. Maksimal 50MB.'), 400, [], $returnTo);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($file['tmp_name']);
if ($mime !== 'application/zip' && $mime !== 'application/x-zip') {
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        theme_upload_respond(false, __('File harus berekstensi .zip.'), 400, [], $returnTo);
    }
}

$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "theme_upload_" . bin2hex(random_bytes(8)) . ".zip";
if (!move_uploaded_file($file['tmp_name'], $tmpZip)) {
    theme_upload_respond(false, __('Gagal memindahkan file upload.'), 500, [], $returnTo);
}

$activate = !empty($_POST['activate']) && (string)$_POST['activate'] === '1';

try {
    $res = install_theme_from_zip($pdo, $tmpZip, $activate, $uid);

    if (empty($res['success'])) {
        theme_upload_respond(false, 'Instalasi gagal: ' . (string)($res['message'] ?? 'unknown'), 400, [], $returnTo);
    }

    $folder = (string)($res['folder'] ?? '');
    $msg = 'Tema berhasil diinstall';
    if ($folder !== '') {
        $msg .= ': ' . $folder;
    }
    if (!empty($res['message'])) {
        $msg .= '. ' . (string)$res['message'];
    }

    theme_upload_respond(true, $msg, 200, ['folder' => $folder], $returnTo);

} catch (Throwable $e) {
    error_log('themes/upload.php error: ' . $e->getMessage());
    theme_upload_respond(false, __('Terjadi kesalahan saat instalasi theme.'), 500, [], $returnTo);
} finally {
    if (is_file($tmpZip)) {
        @unlink($tmpZip);
    }
}