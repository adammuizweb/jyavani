<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/_undo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed.')], 405);
}
$identity = adiwira_fetch_identity($pdo);
if (($identity['ok'] ?? false) !== true) {
    adiwira_json(['ok' => false, 'error' => __('Access denied.')], 403);
}
$uid = (int)($identity['uid'] ?? 0);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
    adiwira_json(['ok' => false, 'error' => __('Invalid CSRF token.')], 419);
}

$resource = (string)($_POST['resource'] ?? '');
$configs = [
    'article' => ['permission' => 'core.posts.restore', 'publish' => 'core.posts.publish', 'type' => 'article'],
    'page' => ['permission' => 'core.pages.restore', 'publish' => 'core.pages.publish', 'type' => 'page'],
    'theme' => ['permission' => 'core.theme_content.restore', 'publish' => null, 'type' => 'theme'],
    'category' => ['permission' => 'core.categories.restore', 'publish' => null, 'type' => null],
];
$config = $configs[$resource] ?? null;
if (!is_array($config)) {
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}

$undoToken = (string)($_POST['undo_token'] ?? '');
$undo = adiwira_undo_get($undoToken, 'bin.trash.' . $resource, $uid);
if ($undo === null || ($undo['state']['resource'] ?? null) !== $resource) {
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}
$items = adiwira_bin_parse_undo_items(
    $undo['state']['items'] ?? null,
    $resource === 'category',
    $resource === 'article' || $resource === 'page'
);
if ($items === []) {
    adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}
$ids = array_keys($items);

$performUndo = static function () use ($pdo, $uid, $resource, $config, $items, $ids): void {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) {
        throw new DomainException(__('Access denied.'));
    }

    if ($resource === 'category') {
        $lockedRows = $pdo->query('SELECT id, parent_id, created_by, is_deleted FROM categories ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lockedById = [];
        foreach ($lockedRows as $lockedRow) $lockedById[(int)$lockedRow['id']] = $lockedRow;
        $selected = [];
        foreach ($ids as $id) {
            $row = $lockedById[$id] ?? null;
            if (!$row || (int)$row['is_deleted'] !== 1
                || ($row['parent_id'] === null ? null : (int)$row['parent_id']) !== $items[$id]['parent_id']) {
                throw new DomainException(__('This action can no longer be undone.'));
            }
            $selected[] = $row;
        }
        if (!authorization_lock_owner_contexts($pdo, array_column($selected, 'created_by'))) {
            throw new DomainException(__('Access denied.'));
        }
        $selectedIdMap = array_fill_keys($ids, true);
        foreach ($selected as $row) {
            $id = (int)$row['id'];
            $parentId = (int)($row['parent_id'] ?? 0);
            if ($parentId > 0) {
                $parent = $lockedById[$parentId] ?? null;
                if (!$parent || ((int)$parent['is_deleted'] === 1 && !isset($selectedIdMap[$parentId]))) {
                    throw new DomainException(__('Restore the parent category first.'));
                }
            }
            if (!user_can($pdo, $uid, $config['permission'], ['owner_id' => (int)($row['created_by'] ?? 0)])) {
                throw new DomainException(__('Access denied.'));
            }
            if (adiwira_bin_latest_audit_id($pdo, $resource, $id) !== $items[$id]['audit_id']) {
                throw new DomainException(__('This action can no longer be undone.'));
            }
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $restore = $pdo->prepare("UPDATE categories SET is_deleted = 0, deleted_at = NULL, updated_at = NOW() WHERE id IN ($in) AND is_deleted = 1");
        $restore->execute($ids);
        if ($restore->rowCount() !== count($ids)) throw new RuntimeException('Category Undo selection changed.');
        foreach ($ids as $id) {
            do_action('admin_category_before_restore_commit', $id, $pdo);
            adiwira_bin_record_audit($pdo, $resource, $id, $uid, 'category.trash_undone', ['undo' => true]);
        }
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $type = (string)$config['type'];
        $selectedStmt = $pdo->prepare("SELECT id, slug, status, created_by, is_deleted FROM posts WHERE id IN ($in) AND type = ? ORDER BY id FOR UPDATE");
        $selectedStmt->execute(array_merge($ids, [$type]));
        $selected = $selectedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($selected) !== count($ids)) throw new DomainException(__('This action can no longer be undone.'));
        if (!authorization_lock_owner_contexts($pdo, array_column($selected, 'created_by'))) {
            throw new DomainException(__('Access denied.'));
        }
        $slugs = [];
        $categoryMap = ($resource === 'article' || $resource === 'page')
            ? adiwira_bin_post_category_map($pdo, $ids)
            : [];
        foreach ($selected as $row) {
            $id = (int)$row['id'];
            if ((int)$row['is_deleted'] !== 1
                || !user_can($pdo, $uid, $config['permission'], ['owner_id' => (int)($row['created_by'] ?? 0)])) {
                throw new DomainException(__('Access denied.'));
            }
            if (is_string($config['publish']) && (string)$row['status'] !== 'draft'
                && !user_can($pdo, $uid, $config['publish'], ['owner_id' => (int)($row['created_by'] ?? 0)])) {
                throw new DomainException(__('Access denied.'));
            }
            if (adiwira_bin_latest_audit_id($pdo, $resource, $id) !== $items[$id]['audit_id']) {
                throw new DomainException(__('This action can no longer be undone.'));
            }
            if (($resource === 'article' || $resource === 'page')
                && ($categoryMap[$id] ?? null) !== $items[$id]['category_ids']) {
                throw new DomainException(__('This action can no longer be undone.'));
            }
            $slugs[] = (string)$row['slug'];
        }
        if (count(array_unique($slugs)) !== count($slugs)) {
            throw new DomainException(__('One or more selected slugs are duplicated.'));
        }
        $slugIn = implode(',', array_fill(0, count($slugs), '?'));
        $collision = $pdo->prepare("SELECT id FROM posts WHERE slug IN ($slugIn) AND type IN ('article', 'page', 'theme') AND is_deleted = 0 LIMIT 1 FOR UPDATE");
        $collision->execute($slugs);
        if ($collision->fetchColumn()) throw new DomainException(__('One or more selected slugs are already active.'));

        $restore = $pdo->prepare("UPDATE posts SET is_deleted = 0, deleted_at = NULL, updated_at = NOW(), updated_by = ? WHERE id IN ($in) AND type = ? AND is_deleted = 1");
        $restore->execute(array_merge([$uid], $ids, [$type]));
        if ($restore->rowCount() !== count($ids)) throw new RuntimeException('Post Undo selection changed.');
        foreach ($ids as $id) {
            adiwira_bin_record_audit($pdo, $resource, $id, $uid, $resource . '.trash_undone', ['undo' => true]);
        }
    }

    $pdo->commit();
};

try {
    if ($resource !== 'category') {
        shortcode_collection_layout_content_mutation($pdo, $performUndo);
    } else {
        $performUndo();
    }
    try {
        adiwira_undo_consume($undoToken);
        adiwira_flash_push('success', __('Item restored successfully.'));
    } catch (Throwable $notifyError) {
        error_log('[bin/undo_trash] restore committed but notification failed: ' . $notifyError->getMessage());
    }
    adiwira_json(['ok' => true, 'reload' => true]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getMessage() === __('This action can no longer be undone.')) adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[bin/undo_trash] ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to undo move to trash.')], 500);
}
