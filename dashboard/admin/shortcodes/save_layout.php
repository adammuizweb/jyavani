<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'errors' => ['CSRF invalid']], 419);
    exit;
}

if (!function_exists('slugify_sc')) {
    function slugify_sc(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        return trim((string)$text, '-') ?: bin2hex(random_bytes(4));
    }
}

$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=layouts')
    : ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=layouts';

$save_nonce = (string)($_POST['save_nonce'] ?? '');
$session_nonce = $_SESSION['sc_layout_nonce'] ?? null;
if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
    adiwira_json(['ok' => false, 'errors' => [__('Save token invalid. Reload the page.')]], 419);
    exit;
}

$layoutDir = (defined('PUBLIC_PATH') ? realpath(PUBLIC_PATH . '/views/partials/shortcodes/post_cat') : realpath(__DIR__ . '/../../../public/views/partials/shortcodes/post_cat'));
if (!$layoutDir || !is_dir($layoutDir)) {
    adiwira_json(['ok' => false, 'errors' => [__('Layout directory not found.')]], 500);
    exit;
}

$existingFile = (string)($_POST['file'] ?? '');
$isNew = $existingFile === '';
$newName = '';

if ($isNew) {
    $newName = trim((string)($_POST['layout_name'] ?? ''));
    if ($newName === '') {
        adiwira_json(['ok' => false, 'errors' => ['Nama layout tidak boleh kosong.']], 400);
        exit;
    }
    $newName = slugify_sc($newName);
    if ($newName === '') {
        adiwira_json(['ok' => false, 'errors' => [__('Invalid layout name.')]], 400);
        exit;
    }
    $fileName = $newName . '.php';
    $filePath = $layoutDir . DIRECTORY_SEPARATOR . $fileName;

    if (is_file($filePath)) {
        adiwira_json(['ok' => false, 'errors' => ['File layout "' . $fileName . '" sudah ada.']], 409);
        exit;
    }
} else {
    $cleanName = preg_replace('/[^a-z0-9_\-\.]/i', '', basename($existingFile));
    if (!str_ends_with($cleanName, '.php')) {
        $cleanName .= '.php';
    }
    $filePath = $layoutDir . DIRECTORY_SEPARATOR . $cleanName;
    $realPath = realpath($filePath);
    if (!$realPath || strpos($realPath, $layoutDir) !== 0) {
        adiwira_json(['ok' => false, 'errors' => [__('Invalid file path.')]], 400);
        exit;
    }
    $filePath = $realPath;
}

$content = (string)($_POST['content'] ?? '');
if (trim($content) === '') {
    adiwira_json(['ok' => false, 'errors' => [__('Template content cannot be empty.')]], 400);
    exit;
}

try {
    $written = file_put_contents($filePath, $content, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException(__('Failed to write file.'));
    }

    unset($_SESSION['sc_layout_nonce']);

    if (function_exists('adiwira_request_wants_json') && adiwira_request_wants_json()) {
        $newNonce = bin2hex(random_bytes(12));
        $_SESSION['sc_layout_nonce'] = $newNonce;
        adiwira_json([
            'ok' => true,
            'message' => __('Layout') . ' "' . ($isNew ? $newName : basename($filePath)) . '" ' . __('saved successfully.'),
            'new_save_nonce' => $newNonce,
            'redirect' => $return_to,
        ], 200);
        exit;
    }

    adiwira_redirect_with_flash($return_to, 'success', __('Layout saved successfully.'));
} catch (Throwable $e) {
    error_log('shortcodes/save_layout.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'errors' => [__('Failed to save layout file.')]], 500);
    exit;
}
