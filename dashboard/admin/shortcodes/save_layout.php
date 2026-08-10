<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_admin($pdo, true);
$layoutScope = (string)($_POST['scope'] ?? 'collection');
if (!in_array($layoutScope, ['collection', 'section'], true)) $layoutScope = 'collection';
$isSectionScope = $layoutScope === 'section';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'errors' => [__('CSRF invalid')]], 419);
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

$wantsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

$layoutsReturn = ADMIN_BASE_PATH . '/?' . http_build_query([
    'page' => 'admin/shortcodes/index',
    'tab' => 'layouts',
    'scope' => $layoutScope,
]);
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $layoutsReturn)
    : $layoutsReturn;

$save_nonce = (string)($_POST['save_nonce'] ?? '');
$session_nonce = $_SESSION['sc_layout_nonce'] ?? null;
if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
    adiwira_json(['ok' => false, 'errors' => [__('Save token invalid. Reload the page.')]], 419);
    exit;
}

$layoutDir = $isSectionScope
    ? (function_exists('theme_section_theme_directory') ? theme_section_theme_directory($pdo, true) : null)
    : (defined('PUBLIC_PATH') ? realpath(PUBLIC_PATH . '/views/partials/shortcodes/post_cat') : realpath(__DIR__ . '/../../../public/views/partials/shortcodes/post_cat'));
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
        adiwira_json(['ok' => false, 'errors' => [__('Layout name cannot be empty.')]], 400);
        exit;
    }
    $newName = $isSectionScope ? strtolower($newName) : slugify_sc($newName);
    if ($newName === '' || ($isSectionScope && (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid($newName)))) {
        adiwira_json(['ok' => false, 'errors' => [__('Invalid layout name.')]], 400);
        exit;
    }
    $fileName = $newName . '.php';
    $filePath = $layoutDir . DIRECTORY_SEPARATOR . $fileName;

    if (file_exists($filePath) || is_link($filePath)) {
        adiwira_json(['ok' => false, 'errors' => [sprintf(__('Layout file "%s" already exists.'), $fileName)]], 409);
        exit;
    }
} else {
    $cleanName = preg_replace('/[^a-z0-9_\-\.]/i', '', basename($existingFile));
    if (!str_ends_with($cleanName, '.php')) {
        $cleanName .= '.php';
    }
    if ($isSectionScope && (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid(pathinfo($cleanName, PATHINFO_FILENAME)))) {
        adiwira_json(['ok' => false, 'errors' => [__('Invalid section name.')]], 400);
        exit;
    }
    $filePath = $layoutDir . DIRECTORY_SEPARATOR . $cleanName;
    $realPath = realpath($filePath);
    if (!$realPath || !theme_section_path_is_within($realPath, $layoutDir)) {
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

    if ($wantsJson) {
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
