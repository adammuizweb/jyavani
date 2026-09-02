<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets';
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to($_POST['return_to'] ?? null, $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
}

$csrfInput = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrf = is_string($csrfInput) ? $csrfInput : '';
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash($return_to, 'error', __('Invalid CSRF token.'));
}

$id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

if ($id <= 0) {
    adiwira_redirect_with_flash($return_to, 'error', __('Invalid ID.'));
}

try {
    $pdo->beginTransaction();
    $sql = "SELECT id FROM posts WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
    $params = [':id' => $id];
    if ($role !== 'admin') {
        $sql .= " AND created_by = :uid";
        $params[':uid'] = $uid;
    }
    $sql .= " LIMIT 1 FOR UPDATE";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) {
        $pdo->rollBack();
        adiwira_redirect_with_flash($return_to, 'error', __('Preset not found.'));
    }

    // Pre-delete listeners share this transaction and may abort source deletion.
    shortcode_preset_before_delete($pdo, $id);

    $stmt = $pdo->prepare("UPDATE posts SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW(), updated_by = :updated_by WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0 LIMIT 1");
    $stmt->execute([':id' => $id, ':updated_by' => $uid]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Preset delete did not affect one row.');
    $pdo->commit();

    try {
        do_action('admin_shortcode_preset_after_delete', $id, $pdo);
    } catch (Throwable $hookError) {
        error_log('admin_shortcode_preset_after_delete hook error: ' . $hookError->getMessage());
    }
    adiwira_redirect_with_flash($return_to, 'success', __('Preset moved to trash successfully.'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('shortcodes/delete.php error: ' . $e->getMessage());
    $message = $e instanceof ShortcodePresetDeletionBlockedException
        ? $e->getMessage()
        : __('Failed to delete preset.');
    adiwira_redirect_with_flash($return_to, 'error', $message);
}
