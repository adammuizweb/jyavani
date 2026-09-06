<?php
declare(strict_types=1);

// /adiwira/admin/bin/category/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid] = adiwira_require_login($pdo, true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/category/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_category_bin_bulk')) {
    function respond_category_bin_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/bin/category/index';

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

        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond_category_bin_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_category_bin_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_category_bin_bulk(false, __('No categories selected.'), 400, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
sort($ids, SORT_NUMERIC);
if (empty($ids)) {
    respond_category_bin_bulk(false, __('Invalid category ID.'), 400, [], $returnTo);
}
if (count($ids) > 100) {
    respond_category_bin_bulk(false, __('You can select up to 100 items at a time.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_category_bin_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);
}
$requiredPermission = match ($action) {
    'restore' => 'core.categories.restore',
    'delete_permanent' => 'core.categories.purge',
    default => '',
};
if ($requiredPermission === '' || user_permission_scope($pdo, $uid, $requiredPermission) === null) {
    respond_category_bin_bulk(false, __('Access denied.'), 403, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Category actor permission lock failed.');
    $lockedRows = $pdo->query('SELECT id, parent_id, created_by, is_deleted FROM categories ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lockedById = [];
    foreach ($lockedRows as $lockedRow) $lockedById[(int)$lockedRow['id']] = $lockedRow;
    $selectedCategories = [];
    foreach ($ids as $selectedId) {
        if (isset($lockedById[$selectedId]) && (int)$lockedById[$selectedId]['is_deleted'] === 1) {
            $selectedCategories[] = $lockedById[$selectedId];
        }
    }
    if (count($selectedCategories) !== count($ids)) {
        $pdo->rollBack();
        respond_category_bin_bulk(false, __('Category not found in trash.'), 404, [], $returnTo);
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
            respond_category_bin_bulk(false, __('Access denied.'), 403, [], $returnTo);
        }
    }

    if ($action === 'restore') {
        $selectedIdMap = array_fill_keys($ids, true);
        foreach ($selectedCategories as $selectedCategory) {
            $parentId = (int)($selectedCategory['parent_id'] ?? 0);
            if ($parentId <= 0) continue;
            $parent = $lockedById[$parentId] ?? null;
            if (!$parent || ((int)$parent['is_deleted'] === 1 && !isset($selectedIdMap[$parentId]))) {
                $pdo->rollBack();
                respond_category_bin_bulk(false, __('Restore the parent category first.'), 409, [], $returnTo);
            }
        }
        $sql = "UPDATE categories
            SET is_deleted = 0,
                deleted_at = NULL,
                updated_at = NOW()
            WHERE id IN ($in)
              AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        foreach ($selectedCategories as $selectedCategory) {
            do_action('admin_category_before_restore_commit', (int)$selectedCategory['id'], $pdo);
        }

        $pdo->commit();
        respond_category_bin_bulk(true, "Successfully restored  {$affected} kategori.", 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'delete_permanent') {
        // blok kalau ada child, termasuk child yang ikut terpilih juga
        $chk = $pdo->prepare("
            SELECT parent_id, COUNT(*) AS cnt
            FROM categories
            WHERE parent_id IN ($in)
            GROUP BY parent_id
        ");
        $chk->execute($ids);
        $rows = $chk->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!empty($rows)) {
            $bad = array_map(static fn($r) => (int)$r['parent_id'], $rows);
            $pdo->rollBack();
            respond_category_bin_bulk(
                false,
                __('Failed: some categories still have subcategories. IDs: ') . implode(',', array_slice($bad, 0, 20)),
                400,
                ['blocked_ids' => $bad],
                $returnTo
            );
        }

        $pdo->prepare("DELETE FROM post_categories WHERE category_id IN ($in)")->execute($ids);

        foreach ($selectedCategories as $selectedCategory) {
            do_action('admin_category_before_purge_commit', (int)$selectedCategory['id'], $selectedCategory, $pdo);
        }

        $sql = "DELETE FROM categories WHERE id IN ($in) AND is_deleted = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond_category_bin_bulk(true, "Permanently deleted  {$affected} kategori.", 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond_category_bin_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/category/bulk_action.php error: ' . $e->getMessage());
    respond_category_bin_bulk(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}
