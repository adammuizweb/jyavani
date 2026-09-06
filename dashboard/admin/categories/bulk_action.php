<?php
declare(strict_types=1);

// /adiwira/admin/categories/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

adiwira_cosmetic_404_on_direct_open();

[$uid] = adiwira_require_login($pdo, true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/categories/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_categories_bulk')) {
    function respond_categories_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/categories/index';

        if (is_ajax_request()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge([
                'ok' => $ok,
                'message' => $message,
                'redirect' => $redirect,
            ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message, 302, $extra);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_categories_bulk(false, __('Method Not Allowed'), 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_categories_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_categories_bulk(false, __('No categories selected.'), 400, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
if (empty($ids)) {
    respond_categories_bulk(false, __('Invalid category ID.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_categories_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);
}
$requiredPermission = match ($action) {
    'delete' => 'core.categories.trash',
    'change_parent' => 'core.categories.update',
    default => '',
};
if ($requiredPermission === '' || user_permission_scope($pdo, $uid, $requiredPermission) === null) {
    respond_categories_bulk(false, __('Access denied.'), 403, [], $returnTo);
}
if ($action === 'delete' && count($ids) > 100) {
    respond_categories_bulk(false, __('You can select up to 100 items at a time.'), 400, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Category actor permission lock failed.');

    $activeCategories = $pdo->query("
        SELECT id, parent_id, created_by
        FROM categories
        WHERE is_deleted = 0
        ORDER BY id
        FOR UPDATE
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $activeById = [];
    foreach ($activeCategories as $activeCategory) {
        $activeById[(int)$activeCategory['id']] = $activeCategory;
    }
    $selectedCategories = [];
    foreach ($ids as $selectedId) {
        if (isset($activeById[$selectedId])) {
            $selectedCategories[] = $activeById[$selectedId];
        }
    }
    if (count($selectedCategories) !== count($ids)) {
        $pdo->rollBack();
        respond_categories_bulk(false, __('Category not found.'), 404, [], $returnTo);
    }
    if (!authorization_lock_owner_contexts($pdo, array_column($selectedCategories, 'created_by'))) {
        throw new DomainException('Category owner context lock failed.');
    }
    foreach ($selectedCategories as $selectedCategory) {
        if (!user_can(
            $pdo,
            $uid,
            $requiredPermission,
            ['owner_id' => (int)($selectedCategory['created_by'] ?? 0)]
        )) {
            $pdo->rollBack();
            respond_categories_bulk(false, __('Access denied.'), 403, [], $returnTo);
        }
    }

    if ($action === 'delete') {
        $chk = $pdo->prepare("
            SELECT DISTINCT parent_id
            FROM categories
            WHERE parent_id IN ($in)
              AND is_deleted = 0
        ");
        $chk->execute($ids);
        $badParents = $chk->fetchAll(PDO::FETCH_COLUMN, 0);

        if (!empty($badParents)) {
            $pdo->rollBack();
            respond_categories_bulk(
                false,
                __('Failed: some categories still have active subcategories. IDs: ') . implode(',', array_slice(array_map('intval', $badParents), 0, 30)),
                400,
                [],
                $returnTo
            );
        }

        $stmt = $pdo->prepare("
            UPDATE categories
            SET is_deleted = 1,
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE id IN ($in)
              AND is_deleted = 0
        ");
        $stmt->execute($ids);
        $affected = $stmt->rowCount();
        if ($affected !== count($ids)) {
            throw new RuntimeException('Category bulk trash did not affect the complete selection.');
        }

        foreach ($selectedCategories as $selectedCategory) {
            do_action('admin_category_before_trash_commit', (int)$selectedCategory['id'], $pdo);
        }

        $expectedIds = $ids;
        sort($expectedIds, SORT_NUMERIC);
        $newlyTrashedIds = [];
        $deletedRows = $pdo->query('SELECT id FROM categories WHERE is_deleted = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        foreach ($deletedRows as $deletedId) {
            $deletedId = (int)$deletedId;
            if (isset($activeById[$deletedId])) $newlyTrashedIds[] = $deletedId;
        }
        if ($newlyTrashedIds !== $expectedIds) {
            throw new RuntimeException('Category bulk trash mutation affected an unexpected row set.');
        }

        $undoItems = [];
        foreach ($expectedIds as $trashedId) {
            $parentId = $activeById[$trashedId]['parent_id'] === null
                ? null
                : (int)$activeById[$trashedId]['parent_id'];
            $auditId = adiwira_bin_record_audit(
                $pdo,
                'category',
                $trashedId,
                $uid,
                'category.trashed',
                ['bulk' => true, 'parent_id' => $parentId]
            );
            $undoItems[] = ['id' => $trashedId, 'audit_id' => $auditId, 'parent_id' => $parentId];
        }

        $successMessage = sprintf(__('%d category(ies) moved to trash.'), $affected);
        $pdo->commit();
        try {
            $extra = ['count' => $affected];
            $undoAction = adiwira_bin_issue_trash_undo($pdo, 'category', $uid, $undoItems);
            if ($undoAction !== null) $extra['action'] = $undoAction;
            respond_categories_bulk(true, $successMessage, 200, $extra, $returnTo);
        } catch (Throwable $notifyError) {
            error_log('[categories/bulk_action] trash committed but notification failed: ' . $notifyError->getMessage());
            if (is_ajax_request()) {
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'message' => $successMessage,
                    'count' => $affected,
                    'redirect' => $returnTo,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            header('Location: ' . $returnTo, true, 302);
            exit;
        }
    }

    if ($action === 'change_parent') {
        $parentRaw = $_POST['parent_id'] ?? null;
        if ($parentRaw === null || $parentRaw === '') {
            $pdo->rollBack();
            respond_categories_bulk(false, __('Parent is required (or select No Parent).'), 400, [], $returnTo);
        }

        $parent = (int)$parentRaw;

        if ($parent > 0 && in_array($parent, $ids, true)) {
            $pdo->rollBack();
            respond_categories_bulk(false, __('Parent cannot be among the selected categories.'), 400, [], $returnTo);
        }

        if ($parent !== 0) {
            $parentCategory = $activeById[$parent] ?? null;
            if (!$parentCategory
                || !user_can($pdo, $uid, 'core.categories.read', ['owner_id' => (int)($parentCategory['created_by'] ?? 0)])) {
                $pdo->rollBack();
                respond_categories_bulk(false, __('Parent category not found.'), 400, [], $returnTo);
            }

            $children = [];
            foreach ($activeCategories as $c) {
                $pid = $c['parent_id'] === null ? 0 : (int)$c['parent_id'];
                $children[$pid][] = (int)$c['id'];
            }

            $collectDesc = function(int $start, array &$out) use (&$children, &$collectDesc): void {
                if (empty($children[$start])) return;
                foreach ($children[$start] as $cid) {
                    if (isset($out[$cid])) continue;
                    $out[$cid] = true;
                    $collectDesc($cid, $out);
                }
            };

            foreach ($ids as $cid) {
                $desc = [];
                $collectDesc($cid, $desc);
                if (isset($desc[$parent])) {
                    $pdo->rollBack();
                    respond_categories_bulk(false, __('Invalid parent: would create a category hierarchy loop.'), 400, [], $returnTo);
                }
            }
        }

        if ($parent === 0) {
            $stmt = $pdo->prepare("
                UPDATE categories
                SET parent_id = NULL,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND is_deleted = 0
            ");
            $stmt->execute($ids);
        } else {
            $stmt = $pdo->prepare("
                UPDATE categories
                SET parent_id = ?,
                    updated_at = NOW()
                WHERE id IN ($in)
                  AND is_deleted = 0
            ");
            $stmt->execute(array_merge([$parent], $ids));
        }

        $affected = $stmt->rowCount();
        foreach ($selectedCategories as $selectedCategory) {
            do_action('admin_category_before_edit_commit', (int)$selectedCategory['id'], $pdo, [
                'parent_id' => $parent === 0 ? null : $parent,
            ]);
        }
        $pdo->commit();
        respond_categories_bulk(true, __('Parent changed for') . " {$affected} " . __('categories.'), 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_categories_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('categories/bulk_action.php error: ' . $e->getMessage());
    respond_categories_bulk(false, __('An error occurred during bulk processing.'), 500, [], $returnTo);
}
