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

$base = ADMIN_BASE_PATH;
$layoutsReturn = $base . '/?' . http_build_query([
    'page' => 'admin/shortcodes/index',
    'tab' => 'layouts',
    'scope' => $layoutScope,
]);
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $layoutsReturn)
    : $layoutsReturn;

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash($return_to, 'error', __('CSRF invalid'));
}

$layoutDir = $isSectionScope
    ? (function_exists('theme_section_theme_directory') ? theme_section_theme_directory($pdo) : null)
    : (defined('PUBLIC_PATH') ? realpath(PUBLIC_PATH . '/views/partials/shortcodes/post_cat') : realpath(__DIR__ . '/../../../public/views/partials/shortcodes/post_cat'));
if (!$layoutDir || !is_dir($layoutDir)) {
    adiwira_redirect_with_flash($return_to, 'error', __('Layout directory not found.'));
}

$fileName = (string)($_POST['file'] ?? '');
$cleanName = preg_replace('/[^a-z0-9_\-\.]/i', '', basename($fileName));
if (!str_ends_with($cleanName, '.php')) {
    $cleanName .= '.php';
}
if ($isSectionScope && (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid(pathinfo($cleanName, PATHINFO_FILENAME)))) {
    adiwira_redirect_with_flash($return_to, 'error', __('Invalid section name.'));
}

// Protect built-in layouts
$layoutName = pathinfo($cleanName, PATHINFO_FILENAME);
if (!$isSectionScope && in_array($layoutName, ['cards', 'list', 'card2', 'sliderpage'], true)) {
    adiwira_redirect_with_flash($return_to, 'error', __('Default layout cannot be deleted.'));
}

$filePath = $layoutDir . DIRECTORY_SEPARATOR . $cleanName;
$realPath = realpath($filePath);

if (!$realPath || !theme_section_path_is_within($realPath, $layoutDir) || !is_file($realPath)) {
    adiwira_redirect_with_flash($return_to, 'error', __('Layout file not found:') . ' ' . $cleanName);
}

try {
    if (!unlink($realPath)) {
        throw new RuntimeException(__('Failed to delete file.'));
    }
    adiwira_redirect_with_flash($return_to, 'success', __('Layout') . ' "' . $cleanName . '" ' . __('deleted successfully.'));
} catch (Throwable $e) {
    error_log('shortcodes/delete_layout.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($return_to, 'error', __('Failed to delete layout file.'));
}
