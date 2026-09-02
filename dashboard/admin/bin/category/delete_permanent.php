<?php
declare(strict_types=1);

// /adiwira/admin/bin/category/delete_permanent.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/category/index';
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
      AND is_deleted = 1
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$cat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cat) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Category not found in trash.'));
}

if (!user_can($pdo, $uid, 'core.categories.purge', ['owner_id' => (int)($cat['created_by'] ?? 0)])) {
    adiwira_redirect_with_flash($returnTo, 'error', __('Access denied.'));
}

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) {
        throw new DomainException('Category actor permission lock failed.');
    }
    $lockedRows = $pdo->query('SELECT id, parent_id, created_by, is_deleted FROM categories ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lockedCategory = null;
    $hasChildren = false;
    foreach ($lockedRows as $lockedRow) {
        if ((int)$lockedRow['id'] === $id && (int)$lockedRow['is_deleted'] === 1) $lockedCategory = $lockedRow;
        if ((int)($lockedRow['parent_id'] ?? 0) === $id) $hasChildren = true;
    }
    $ownerId = (int)($lockedCategory['created_by'] ?? 0);
    if (!$lockedCategory || !authorization_lock_owner_contexts($pdo, [$ownerId])
        || !user_can($pdo, $uid, 'core.categories.purge', ['owner_id' => $ownerId])) {
        throw new DomainException('Category purge permission changed.');
    }
    if ($hasChildren) {
        throw new DomainException(__('Cannot permanently delete: the category still has subcategories. Delete or move them first.'));
    }

    $pdo->prepare("DELETE FROM post_categories WHERE category_id = :id")
        ->execute([':id' => $id]);

    do_action('admin_category_before_purge_commit', $id, $lockedCategory, $pdo);

    $pdo->prepare("
        DELETE FROM categories
        WHERE id = :id
          AND is_deleted = 1
        LIMIT 1
    ")->execute([':id' => $id]);

    $pdo->commit();

    adiwira_redirect_with_flash($returnTo, 'success', __('Category permanently deleted.'));

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/category/delete_permanent.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($returnTo, 'error', __('Failed to permanently delete category.'));
}
