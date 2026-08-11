<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/_layout_manager.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_admin($pdo, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$scopeInput = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : '';
$layoutScope = in_array($scopeInput, ['collection', 'section'], true) ? $scopeInput : 'collection';
$defaultReturnTo = ADMIN_BASE_PATH . '/?' . http_build_query([
    'page' => 'admin/shortcodes/index',
    'tab' => 'layouts',
    'scope' => $layoutScope,
]);
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_POST['return_to'] ?? null, $defaultReturnTo)
    : $defaultReturnTo;

if ($scopeInput !== $layoutScope) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid layout selection.'));
}

$csrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}
if (!is_string($_POST['action'] ?? null) || $_POST['action'] !== 'delete') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Unknown bulk action.'));
}

$files = $_POST['files'] ?? null;
if (!is_array($files) || $files === []) {
    adiwira_redirect_with_flash($returnTo, 'error', __('No layouts selected.'));
}

try {
    $deleted = shortcode_layout_delete_files($pdo, $layoutScope, $files);
    adiwira_redirect_with_flash($returnTo, 'success', sprintf(__('%d layout(s) removed to recoverable quarantine outside the public web root.'), $deleted));
} catch (ShortcodeLayoutManagerException|ShortcodeCollectionLayoutLockException $error) {
    adiwira_redirect_with_flash($returnTo, 'error', $error->getMessage());
} catch (Throwable $error) {
    error_log('shortcodes/bulk_layout_action.php error: ' . $error->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete selected layouts.'));
}
