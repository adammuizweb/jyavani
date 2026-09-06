<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
require_once __DIR__ . '/../bin/_undo.php';

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
    'article' => ['update' => 'core.posts.update', 'publish' => 'core.posts.publish', 'hook' => 'admin_posts_bulk_before_mutation'],
    'page' => ['update' => 'core.pages.update', 'publish' => 'core.pages.publish', 'hook' => 'admin_pages_bulk_before_mutation'],
    'theme' => ['update' => 'core.theme_content.update', 'publish' => null, 'hook' => null],
];
$config = $configs[$resource] ?? null;
if (!is_array($config)) {
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}

$undoToken = (string)($_POST['undo_token'] ?? '');
$undo = adiwira_undo_get($undoToken, 'content.bulk_status.' . $resource, $uid);
if ($undo === null || ($undo['state']['resource'] ?? null) !== $resource) {
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}
$items = adiwira_content_parse_status_undo_items($undo['state']['items'] ?? null);
if ($items === []) {
    adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => __('This action can no longer be undone.')], 409);
}
$ids = array_keys($items);

$performUndo = static function () use ($pdo, $uid, $resource, $config, $items, $ids): void {
    $pdo->beginTransaction();
    try {
        if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException(__('Access denied.'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, status, status_revision, created_by, is_deleted FROM posts WHERE id IN ($in) AND type = ? ORDER BY id FOR UPDATE");
        $stmt->execute(array_merge($ids, [$resource]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== count($ids)) throw new UnexpectedValueException(__('This action can no longer be undone.'));
        if (!authorization_lock_owner_contexts($pdo, array_column($rows, 'created_by'))) {
            throw new DomainException(__('Access denied.'));
        }

        $groups = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $item = $items[$id] ?? null;
            $context = ['owner_id' => (int)($row['created_by'] ?? 0)];
            if (!$item || (int)$row['is_deleted'] !== 0 || (string)$row['status'] !== $item['changed_status']
                || (int)$row['status_revision'] !== $item['status_revision']
                || adiwira_bin_latest_audit_id($pdo, $resource, $id) !== $item['audit_id']) {
                throw new UnexpectedValueException(__('This action can no longer be undone.'));
            }
            if (!user_can($pdo, $uid, $config['update'], $context)) throw new DomainException(__('Access denied.'));
            if (is_string($config['publish'])
                && ($item['previous_status'] !== 'draft' || $item['changed_status'] !== 'draft')
                && !user_can($pdo, $uid, $config['publish'], $context)) {
                throw new DomainException(__('Access denied.'));
            }
            if ($resource === 'article'
                && apply_filters('admin_post_editor_status', (string)$row['status'], $row, $pdo) !== (string)$row['status']) {
                throw new UnexpectedValueException(__('This action can no longer be undone.'));
            }
            if ($resource === 'page'
                && apply_filters('admin_page_editor_status', (string)$row['status'], $row, $pdo) !== (string)$row['status']) {
                throw new UnexpectedValueException(__('This action can no longer be undone.'));
            }
            $groupKey = $item['previous_status'] . ':' . $item['changed_status'];
            $groups[$groupKey]['previous_status'] = $item['previous_status'];
            $groups[$groupKey]['changed_status'] = $item['changed_status'];
            $groups[$groupKey]['ids'][] = $id;
            $groups[$groupKey]['rows'][] = $row;
        }

        foreach ($groups as $group) {
            if (is_string($config['hook'])) {
                do_action($config['hook'], 'change_status', $group['rows'], $pdo, [
                    'status' => $group['previous_status'],
                    'ids' => $group['ids'],
                    'undo' => true,
                ]);
            }
            if (is_string($config['hook'])) {
                foreach ($group['rows'] as $row) {
                    $previousRow = array_replace($row, ['status' => $group['previous_status']]);
                    $editorStatus = $resource === 'article'
                        ? apply_filters('admin_post_editor_status', $group['previous_status'], $previousRow, $pdo)
                        : apply_filters('admin_page_editor_status', $group['previous_status'], $previousRow, $pdo);
                    if ($editorStatus !== $group['previous_status']) {
                        throw new UnexpectedValueException(__('This action can no longer be undone.'));
                    }
                }
            }
            $groupIn = implode(',', array_fill(0, count($group['ids']), '?'));
            $update = $pdo->prepare("UPDATE posts SET status = ?, status_revision = status_revision + 1, updated_at = NOW(), updated_by = ? WHERE id IN ($groupIn) AND type = ? AND is_deleted = 0 AND status = ?");
            $update->execute(array_merge(
                [$group['previous_status'], $uid],
                $group['ids'],
                [$resource, $group['changed_status']]
            ));
            if ($update->rowCount() !== count($group['ids'])) {
                throw new UnexpectedValueException(__('This action can no longer be undone.'));
            }
        }

        foreach ($ids as $id) {
            adiwira_bin_record_audit($pdo, $resource, $id, $uid, $resource . '.status_change_undone', [
                'from' => $items[$id]['changed_status'],
                'to' => $items[$id]['previous_status'],
                'bulk' => true,
                'undo' => true,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
};

try {
    $performUndo();
    try {
        adiwira_undo_consume($undoToken);
        adiwira_flash_push('success', sprintf(__('%d item(s) status restored.'), count($ids)));
    } catch (Throwable $notifyError) {
        error_log('[content/undo_bulk_status] status Undo committed but notification failed: ' . $notifyError->getMessage());
    }
    adiwira_json(['ok' => true, 'reload' => true]);
} catch (UnexpectedValueException $e) {
    adiwira_undo_consume($undoToken);
    adiwira_json(['ok' => false, 'error' => $e->getMessage()], 409);
} catch (DomainException $e) {
    adiwira_json(['ok' => false, 'error' => $e->getMessage()], 403);
} catch (Throwable $e) {
    error_log('[content/undo_bulk_status] ' . $e->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to undo status change.')], 500);
}
