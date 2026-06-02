<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Not found'], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash((string)($_POST['return_to'] ?? ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=layouts'), 'error', 'CSRF invalid.');
}

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $base . '/?page=admin/shortcodes/index&tab=layouts')
    : ($base . '/?page=admin/shortcodes/index&tab=layouts');

$layoutDir = realpath(__DIR__ . '/../../../views/partials/shortcodes/post_cat');
if (!$layoutDir || !is_dir($layoutDir)) {
    adiwira_redirect_with_flash($return_to, 'error', 'Direktori layout tidak ditemukan.');
}

$fileName = (string)($_POST['file'] ?? '');
$cleanName = preg_replace('/[^a-z0-9_\-\.]/i', '', basename($fileName));
if (!str_ends_with($cleanName, '.php')) {
    $cleanName .= '.php';
}

// Protect built-in layouts
$layoutName = pathinfo($cleanName, PATHINFO_FILENAME);
if (in_array($layoutName, ['cards', 'list', 'card2', 'sliderpage'], true)) {
    adiwira_redirect_with_flash($return_to, 'error', 'Layout bawaan tidak bisa dihapus.');
}

$filePath = $layoutDir . DIRECTORY_SEPARATOR . $cleanName;
$realPath = realpath($filePath);

if (!$realPath || strpos($realPath, $layoutDir) !== 0 || !is_file($realPath)) {
    adiwira_redirect_with_flash($return_to, 'error', 'File layout tidak ditemukan.');
}

try {
    if (!unlink($realPath)) {
        throw new RuntimeException('Gagal menghapus file.');
    }
    adiwira_redirect_with_flash($return_to, 'success', 'Layout "' . $cleanName . '" berhasil dihapus.');
} catch (Throwable $e) {
    error_log('shortcodes/delete_layout.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($return_to, 'error', 'Gagal menghapus file layout.');
}
