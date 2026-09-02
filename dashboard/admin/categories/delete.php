<?php
declare(strict_types=1);

// /adiwira/admin/categories/delete.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/categories/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_redirect_with_flash($returnTo, 'error', __('Method not allowed.'));
}

[$uid] = adiwira_require_login($pdo, false);

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid CSRF token.'));
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Invalid ID.'));
}

$stmt = $pdo->prepare("
    SELECT id, created_by
    FROM categories
    WHERE id = :id
      AND is_deleted = 0
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Category not found.'));
}
if (!user_can($pdo, $uid, 'core.categories.trash', ['owner_id' => (int)($category['created_by'] ?? 0)])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) {
        throw new DomainException('Category actor permission lock failed.');
    }
    $lockedRows = $pdo->query('SELECT id, parent_id, created_by FROM categories WHERE is_deleted = 0 ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lockedCategory = null;
    $hasChildren = false;
    foreach ($lockedRows as $lockedRow) {
        if ((int)$lockedRow['id'] === $id) $lockedCategory = $lockedRow;
        if ((int)($lockedRow['parent_id'] ?? 0) === $id) $hasChildren = true;
    }
    if (!$lockedCategory) throw new DomainException(__('Category not found.'));
    $ownerId = (int)($lockedCategory['created_by'] ?? 0);
    if (!authorization_lock_owner_contexts($pdo, [$ownerId])
        || !user_can($pdo, $uid, 'core.categories.trash', ['owner_id' => $ownerId])) {
        throw new DomainException(__('Access denied.'));
    }
    if ($hasChildren) throw new DomainException(__('Category still has active subcategories. Move/delete them first.'));

    $pdo->prepare("
        UPDATE categories
        SET is_deleted = 1,
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND is_deleted = 0
        LIMIT 1
    ")->execute([':id' => $id]);

    do_action('admin_category_before_trash_commit', $id, $pdo);
    $pdo->commit();
    adiwira_redirect_with_flash($returnTo, 'success', __('Category moved to trash successfully.'));

} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    adiwira_redirect_with_flash($returnTo, 'error', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('categories/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to delete category.'));
}
