<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_POST['return_to'] ?? null, $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$rawIds = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
$ids = [];
foreach ($rawIds as $rawId) {
    if (!is_string($rawId) && !is_int($rawId)) continue;
    $validId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validId !== false) $ids[] = (int)$validId;
}
$ids = array_values(array_unique($ids));
if ($ids === []) {
    adiwira_redirect_with_flash($returnTo, 'error', __('No presets selected.'));
}

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$statuses = ['publish' => 'published', 'draft' => 'draft', 'private' => 'private'];
if (!isset($statuses[$action]) && $action !== 'delete') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Unknown bulk action.'));
}

try {
    $pdo->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $ownership = $role === 'admin' ? '' : ' AND created_by = ?';
    $params = $ids;
    if ($role !== 'admin') $params[] = $uid;

    $deletedIds = [];
    if ($action === 'delete') {
        $select = $pdo->prepare("SELECT id FROM posts WHERE id IN ($placeholders) AND type = 'sc_preset' AND is_deleted = 0$ownership FOR UPDATE");
        $select->execute($params);
        $deletedIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN, 0));

        foreach ($deletedIds as $deletedId) {
            // A listener failure aborts all source and dependent-row changes.
            shortcode_preset_before_delete($pdo, $deletedId);
        }

        $stmt = $pdo->prepare("UPDATE posts SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW() WHERE id IN ($placeholders) AND type = 'sc_preset' AND is_deleted = 0$ownership");
        $stmt->execute($params);
    } else {
        $status = $statuses[$action];
        $stmt = $pdo->prepare("UPDATE posts SET status = ?, updated_at = NOW() WHERE id IN ($placeholders) AND type = 'sc_preset' AND is_deleted = 0$ownership");
        $stmt->execute(array_merge([$status], $params));
    }

    $affected = $stmt->rowCount();
    if ($action === 'delete' && count($deletedIds) !== $affected) {
        throw new RuntimeException('Preset bulk delete count changed during transaction.');
    }
    $pdo->commit();

    if ($action === 'delete') {
        foreach ($deletedIds as $deletedId) {
            try {
                do_action('admin_shortcode_preset_after_delete', $deletedId, $pdo);
            } catch (Throwable $hookError) {
                error_log('admin_shortcode_preset_after_delete hook error: ' . $hookError->getMessage());
            }
        }
        adiwira_redirect_with_flash($returnTo, 'success', sprintf(__('%d preset(s) moved to trash.'), $affected));
    }
    adiwira_redirect_with_flash($returnTo, 'success', sprintf(__('%d preset(s) updated.'), $affected));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('shortcodes/bulk_action.php error: ' . $e->getMessage());
    $message = $e instanceof ShortcodePresetDeletionBlockedException
        ? $e->getMessage()
        : __('Bulk action failed.');
    adiwira_redirect_with_flash($returnTo, 'error', $message);
}
