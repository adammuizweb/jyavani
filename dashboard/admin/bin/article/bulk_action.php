<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../_guard.php';
require_once __DIR__ . '/../../_notify.php';

adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_login($pdo, true);
if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }
}
$returnTo = adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), ADMIN_BASE_PATH . '/?page=admin/bin/article/index');
if (!function_exists('respond_article_bin_bulk')) {
    function respond_article_bin_bulk(bool $ok, string $message, int $code, array $extra, string $redirect): void {
        if (is_ajax_request()) adiwira_json(array_merge(['ok' => $ok, 'message' => $message, 'redirect' => $redirect], $extra), $code);
        adiwira_redirect_with_flash($redirect, $ok ? 'success' : 'error', $message);
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond_article_bin_bulk(false, __('Method Not Allowed'), 405, [], $returnTo);
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) respond_article_bin_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $id): bool => $id > 0)));
if ($ids === []) respond_article_bin_bulk(false, __('No articles selected.'), 400, [], $returnTo);
$action = (string)($_POST['action'] ?? '');
$permission = match ($action) {
    'restore' => 'core.posts.restore',
    'delete_permanent' => 'core.posts.purge',
    default => '',
};
if ($permission === '' || user_permission_scope($pdo, $uid, $permission) === null) {
    respond_article_bin_bulk(false, __('Access denied.'), 403, [], $returnTo);
}

try {
    $affected = shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $uid, $ids, $action, $permission): int {
        $pdo->beginTransaction();
        try {
            if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Article actor permission lock failed.');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $selectedStmt = $pdo->prepare("SELECT id, slug, status, created_by FROM posts WHERE id IN ($in) AND type = 'article' AND is_deleted = 1 FOR UPDATE");
            $selectedStmt->execute($ids);
            $posts = $selectedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($posts) !== count($ids)) throw new DomainException('Article selection changed.');
            if (!authorization_lock_owner_contexts($pdo, array_column($posts, 'created_by'))) throw new DomainException('Article owner context lock failed.');
            foreach ($posts as $post) {
                $context = ['owner_id' => (int)$post['created_by']];
                if (!user_can($pdo, $uid, $permission, $context)) throw new DomainException('Article permission changed.');
                if ($action === 'restore' && (string)$post['status'] !== 'draft' && !user_can($pdo, $uid, 'core.posts.publish', $context)) {
                    throw new DomainException('Article publish permission changed.');
                }
            }
            if ($action === 'restore') {
                $slugs = array_values(array_unique(array_map(static fn(array $post): string => (string)$post['slug'], $posts)));
                if (count($slugs) !== count($posts)) throw new InvalidArgumentException('Selected articles contain duplicate slugs.');
                $slugIn = implode(',', array_fill(0, count($slugs), '?'));
                $collision = $pdo->prepare("SELECT id FROM posts WHERE slug IN ($slugIn) AND type IN ('article', 'page', 'theme') AND is_deleted = 0 FOR UPDATE");
                $collision->execute($slugs);
                if ($collision->fetchColumn()) throw new InvalidArgumentException('One or more article slugs are already active.');
                $stmt = $pdo->prepare("UPDATE posts SET is_deleted = 0, deleted_at = NULL, updated_at = NOW(), updated_by = ? WHERE id IN ($in) AND type = 'article' AND is_deleted = 1");
                $stmt->execute(array_merge([$uid], $ids));
            } else {
                $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);
                $stmt = $pdo->prepare("DELETE FROM posts WHERE id IN ($in) AND type = 'article' AND is_deleted = 1");
                $stmt->execute($ids);
            }
            $count = $stmt->rowCount();
            $pdo->commit();
            return $count;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    });
    $message = $action === 'restore'
        ? sprintf(__('Successfully restored %d article(s).'), $affected)
        : sprintf(__('Permanently deleted %d article(s).'), $affected);
    respond_article_bin_bulk(true, $message, 200, ['count' => $affected], $returnTo);
} catch (InvalidArgumentException $e) {
    respond_article_bin_bulk(false, __($e->getMessage()), 400, [], $returnTo);
} catch (DomainException $e) {
    respond_article_bin_bulk(false, __('Access denied.'), 403, [], $returnTo);
} catch (Throwable $e) {
    error_log('bin/article/bulk_action.php error: ' . $e->getMessage());
    respond_article_bin_bulk(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}
