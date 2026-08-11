<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/_layout_manager.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_admin($pdo, true);
$layoutScope = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : 'collection';
if (!in_array($layoutScope, ['collection', 'section'], true)) $layoutScope = 'collection';
$isSectionScope = $layoutScope === 'section';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrfInput = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrf = is_string($csrfInput) ? $csrfInput : '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'errors' => [__('CSRF invalid')]], 419);
    exit;
}

$requestedWith = is_string($_SERVER['HTTP_X_REQUESTED_WITH'] ?? null) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';
$accept = is_string($_SERVER['HTTP_ACCEPT'] ?? null) ? $_SERVER['HTTP_ACCEPT'] : '';
$wantsJson = strtolower($requestedWith) === 'xmlhttprequest'
    || str_contains(strtolower($accept), 'application/json');

$layoutsReturn = ADMIN_BASE_PATH . '/?' . http_build_query([
    'page' => 'admin/shortcodes/index',
    'tab' => 'layouts',
    'scope' => $layoutScope,
]);
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_POST['return_to'] ?? null, $layoutsReturn)
    : $layoutsReturn;

$save_nonce = is_string($_POST['save_nonce'] ?? null) ? $_POST['save_nonce'] : '';
$session_nonce = $_SESSION['sc_layout_nonce'] ?? null;
if (!$session_nonce || $save_nonce === '' || !hash_equals((string)$session_nonce, (string)$save_nonce)) {
    adiwira_json(['ok' => false, 'errors' => [__('Save token invalid. Reload the page.')]], 419);
    exit;
}

$existingFile = is_string($_POST['file'] ?? null) ? $_POST['file'] : '';
$isNew = $existingFile === '';
$newName = '';

if ($isNew) {
    $newName = is_string($_POST['layout_name'] ?? null) ? trim($_POST['layout_name']) : '';
    if ($newName === '') {
        adiwira_json(['ok' => false, 'errors' => [__('Layout name cannot be empty.')]], 400);
        exit;
    }
    if ($isSectionScope) $newName = strtolower($newName);
    if (($isSectionScope && (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid($newName)))
        || (!$isSectionScope && !shortcode_collection_layout_name_is_valid($newName))) {
        adiwira_json(['ok' => false, 'errors' => [__('Invalid layout name.')]], 400);
        exit;
    }
} else {
    $cleanName = shortcode_layout_file_is_valid($existingFile, $layoutScope) ? $existingFile : '';
    if ($cleanName === '') {
        adiwira_json(['ok' => false, 'errors' => [__('Invalid file path.')]], 400);
        exit;
    }
    if ($isSectionScope && (!function_exists('theme_section_name_is_valid') || !theme_section_name_is_valid(pathinfo($cleanName, PATHINFO_FILENAME)))) {
        adiwira_json(['ok' => false, 'errors' => [__('Invalid section name.')]], 400);
        exit;
    }
    $existingFile = $cleanName;
}

$content = is_string($_POST['content'] ?? null) ? $_POST['content'] : '';
if (trim($content) === '') {
    adiwira_json(['ok' => false, 'errors' => [__('Template content cannot be empty.')]], 400);
    exit;
}

try {
    $saved = shortcode_layout_atomic_save($pdo, $layoutScope, $existingFile, $newName, $content);

    unset($_SESSION['sc_layout_nonce']);

    if ($wantsJson) {
        $newNonce = bin2hex(random_bytes(12));
        $_SESSION['sc_layout_nonce'] = $newNonce;
        adiwira_json([
            'ok' => true,
            'message' => __('Layout') . ' "' . $saved['file'] . '" ' . __('saved successfully.'),
            'new_save_nonce' => $newNonce,
            'redirect' => $return_to,
        ], 200);
        exit;
    }

    adiwira_redirect_with_flash($return_to, 'success', __('Layout saved successfully.'));
} catch (ShortcodeLayoutManagerException|ShortcodeCollectionLayoutLockException $e) {
    error_log('shortcodes/save_layout.php rejected save: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'errors' => [$e->getMessage()]], 409);
    exit;
} catch (Throwable $e) {
    error_log('shortcodes/save_layout.php error: ' . $e->getMessage());
    adiwira_json(['ok' => false, 'errors' => [__('Failed to save layout file.')]], 500);
    exit;
}
