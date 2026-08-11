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

$base = ADMIN_BASE_PATH;
$scopeInput = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : '';
$layoutScope = in_array($scopeInput, ['collection', 'section'], true) ? $scopeInput : 'collection';
$layoutsReturn = $base . '/?' . http_build_query([
    'page' => 'admin/shortcodes/index',
    'tab' => 'layouts',
    'scope' => $layoutScope,
]);
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_POST['return_to'] ?? null, $layoutsReturn)
    : $layoutsReturn;

if ($scopeInput !== $layoutScope) {
    adiwira_redirect_with_flash($return_to, 'error', __('Invalid layout selection.'));
}

$csrfInput = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrf = is_string($csrfInput) ? $csrfInput : '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash($return_to, 'error', __('CSRF invalid'));
}

$fileName = is_string($_POST['file'] ?? null) ? $_POST['file'] : '';

try {
    shortcode_layout_delete_files($pdo, $layoutScope, [$fileName]);
    adiwira_redirect_with_flash($return_to, 'success', __('Layout') . ' "' . $fileName . '" ' . __('removed to recoverable quarantine outside the public web root.'));
} catch (ShortcodeLayoutManagerException|ShortcodeCollectionLayoutLockException $e) {
    adiwira_redirect_with_flash($return_to, 'error', $e->getMessage());
} catch (Throwable $e) {
    error_log('shortcodes/delete_layout.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($return_to, 'error', __('Failed to delete layout file.'));
}
