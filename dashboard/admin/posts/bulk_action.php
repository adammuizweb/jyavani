<?php
declare(strict_types=1);

// /adiwira/admin/posts/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid] = adiwira_require_login($pdo, true);

if (!function_exists('is_ajax_request')) {
    function is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/posts/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond')) {
    function respond(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/posts/index';

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
    respond(false, __('Method Not Allowed'), 405, [], $returnTo);
}

if (!function_exists('parse_datetime_local')) {
    function parse_datetime_local(string $value): ?DateTimeImmutable {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}(?::\\d{2})?$/', $value)) {
            return null;
        }
        $normalized = str_replace('T', ' ', $value);
        if (strlen($normalized) === 16) {
            $normalized .= ':00';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $normalized, $m)) {
            return null;
        }
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        if ((int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized);
        if (!$dt) {
            return null;
        }
        return $dt;
    }
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond(false, __('No articles selected.'), 400, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
if (empty($ids)) {
    respond(false, __('Invalid article ID.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond(false, __('Unknown bulk action.'), 400, [], $returnTo);
}
$requiredPermission = match ($action) {
    'delete' => 'core.posts.trash',
    'change_status', 'change_categories' => 'core.posts.update',
    'change_author' => 'core.posts.change_owner',
    'change_date' => 'core.posts.change_dates',
    default => '',
};
if ($requiredPermission === '' || user_permission_scope($pdo, $uid, $requiredPermission) === null) {
    respond(false, __('Access denied.'), 403, [], $returnTo);
}

try {
    $pdo->beginTransaction();
    if (!authorization_lock_actor_permissions($pdo, $uid)) {
        $pdo->rollBack();
        respond(false, __('Access denied.'), 403, [], $returnTo);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $selectedStmt = $pdo->prepare("SELECT id, status, created_by FROM posts WHERE id IN ($in) AND type = 'article' AND is_deleted = 0 FOR UPDATE");
    $selectedStmt->execute($ids);
    $selectedPosts = $selectedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($selectedPosts) !== count($ids)) {
        $pdo->rollBack();
        respond(false, __('Article not found.'), 404, [], $returnTo);
    }
    if (!authorization_lock_owner_contexts($pdo, array_column($selectedPosts, 'created_by'))) {
        $pdo->rollBack();
        respond(false, __('Access denied.'), 403, [], $returnTo);
    }
    foreach ($selectedPosts as &$selectedPost) {
        $editorStatus = apply_filters('admin_post_editor_status', (string)($selectedPost['status'] ?? 'draft'), $selectedPost, $pdo);
        if (!is_string($editorStatus) || !in_array($editorStatus, ['draft', 'published', 'private'], true)) {
            throw new DomainException('Post editor status is invalid.');
        }
        $selectedPost['status'] = $editorStatus;
    }
    unset($selectedPost);
    foreach ($selectedPosts as $selectedPost) {
        if (!user_can($pdo, $uid, $requiredPermission, ['owner_id' => (int)($selectedPost['created_by'] ?? 0)])) {
            $pdo->rollBack();
            respond(false, __('Access denied.'), 403, [], $returnTo);
        }
    }
    if ($action === 'change_categories') {
        foreach ($selectedPosts as $selectedPost) {
            if ((string)($selectedPost['status'] ?? 'draft') !== 'draft'
                && !user_can($pdo, $uid, 'core.posts.publish', ['owner_id' => (int)($selectedPost['created_by'] ?? 0)])) {
                $pdo->rollBack();
                respond(false, __('Access denied.'), 403, [], $returnTo);
            }
        }
    }

    if ($action === 'delete') {
        do_action('admin_posts_bulk_before_mutation', $action, $selectedPosts, $pdo, $_POST);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $sql = "UPDATE posts
                SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW()
                WHERE id IN ($in) AND type = 'article' AND is_deleted = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $affected = $stmt->rowCount();

        $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);

        $pdo->commit();
        respond(true, sprintf(__('%d article(s) deleted.'), $affected), 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_status') {
        $new_status = (string)($_POST['status'] ?? '');
        $allowed = ['draft', 'published', 'private'];

        if (!in_array($new_status, $allowed, true)) {
            $pdo->rollBack();
            respond(false, __('Invalid status.'), 400, [], $returnTo);
        }
        if ($new_status !== 'draft' || array_filter($selectedPosts, static fn(array $post): bool => (string)($post['status'] ?? 'draft') !== 'draft')) {
            foreach ($selectedPosts as $selectedPost) {
                if (!user_can($pdo, $uid, 'core.posts.publish', ['owner_id' => (int)($selectedPost['created_by'] ?? 0)])) {
                    $pdo->rollBack();
                    respond(false, __('Access denied.'), 403, [], $returnTo);
                }
            }
        }

        $hookInput = array_replace($_POST, ['status' => $new_status]);
        do_action('admin_posts_bulk_before_mutation', $action, $selectedPosts, $pdo, $hookInput);

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE posts
                SET status = ?, updated_at = NOW()
                WHERE id IN ($in) AND type = 'article' AND is_deleted = 0";
        $params = array_merge([$new_status], $ids);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond(true, sprintf(__('%d article(s) status changed to "%s".'), $affected, $new_status), 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_categories') {
        $cat_ids = $_POST['categories'] ?? [];
        $cat_ids = array_values(array_unique(array_filter(array_map('intval', (array)$cat_ids), fn($v) => $v > 0)));

        if (empty($cat_ids)) {
            $pdo->rollBack();
            respond(false, __('Select at least one category.'), 400, [], $returnTo);
        }

        $inCats = implode(',', array_fill(0, count($cat_ids), '?'));
        $vstmt = $pdo->prepare("SELECT id, created_by FROM categories WHERE id IN ($inCats) AND is_deleted = 0 FOR UPDATE");
        $vstmt->execute($cat_ids);
        $categoryRows = $vstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($categoryRows) !== count($cat_ids)) {
            $pdo->rollBack();
            respond(false, __('Invalid category.'), 400, [], $returnTo);
        }
        if (!authorization_lock_owner_contexts($pdo, array_column($categoryRows, 'created_by'))) {
            $pdo->rollBack();
            respond(false, __('Access denied.'), 403, [], $returnTo);
        }
        foreach ($categoryRows as $categoryRow) {
            if (!user_can($pdo, $uid, 'core.categories.read', ['owner_id' => (int)($categoryRow['created_by'] ?? 0)])) {
                $pdo->rollBack();
                respond(false, __('Access denied.'), 403, [], $returnTo);
            }
        }

        $mode = (string)($_POST['cat_mode'] ?? 'add');
        if (!in_array($mode, ['add', 'remove', 'toggle'], true)) {
            $mode = 'add';
        }

        $hookInput = array_replace($_POST, ['categories' => $cat_ids, 'cat_mode' => $mode]);
        do_action('admin_posts_bulk_before_mutation', $action, $selectedPosts, $pdo, $hookInput);

        $post_ids = $ids;
        $placePost = implode(',', array_fill(0, count($post_ids), '?'));
        $placeCat  = implode(',', array_fill(0, count($cat_ids), '?'));

        $selectExistingSql = "SELECT post_id, category_id
                              FROM post_categories
                              WHERE post_id IN ($placePost) AND category_id IN ($placeCat)";
        $selectStmt = $pdo->prepare($selectExistingSql);
        $selectStmt->execute(array_merge($post_ids, $cat_ids));
        $existingRows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

        $existingSet = [];
        foreach ($existingRows as $r) {
            $existingSet[(int)$r['post_id']][] = (int)$r['category_id'];
        }

        $assigned_by = $uid;

        if ($mode === 'add') {
            $values = [];
            $holders = [];

            foreach ($post_ids as $pid) {
                foreach ($cat_ids as $cid) {
                    if (isset($existingSet[$pid]) && in_array($cid, $existingSet[$pid], true)) {
                        continue;
                    }
                    $values[] = $pid;
                    $values[] = $cid;
                    $values[] = $assigned_by;
                    $holders[] = '(?, ?, ?)';
                }
            }

            if (!empty($values)) {
                $sql = "INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $holders);
                $pdo->prepare($sql)->execute($values);
            }

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost) AND type = 'article' AND is_deleted = 0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, __('Category added to') . ' ' . count($post_ids) . ' ' . __('articles.'), 200, ['count' => count($post_ids)], $returnTo);
        }

        if ($mode === 'remove') {
            $sql = "DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)";
            $pdo->prepare($sql)->execute(array_merge($post_ids, $cat_ids));

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost) AND type = 'article' AND is_deleted = 0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, __('Selected category removed from') . ' ' . count($post_ids) . ' ' . __('articles.'), 200, ['count' => count($post_ids)], $returnTo);
        }

        if ($mode === 'toggle') {
            $toInsert = [];
            $holders = [];

            foreach ($post_ids as $pid) {
                foreach ($cat_ids as $cid) {
                    if (!isset($existingSet[$pid]) || !in_array($cid, $existingSet[$pid], true)) {
                        $toInsert[] = $pid;
                        $toInsert[] = $cid;
                        $toInsert[] = $assigned_by;
                        $holders[] = '(?, ?, ?)';
                    }
                }
            }

            $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($placePost) AND category_id IN ($placeCat)")
                ->execute(array_merge($post_ids, $cat_ids));

            if (!empty($toInsert)) {
                $pdo->prepare("INSERT INTO post_categories (post_id, category_id, assigned_by) VALUES " . implode(',', $holders))
                    ->execute($toInsert);
            }

            $pdo->prepare("UPDATE posts SET updated_at = NOW() WHERE id IN ($placePost) AND type = 'article' AND is_deleted = 0")
                ->execute($post_ids);

            $pdo->commit();
            respond(true, 'Operasi toggle kategori selesai untuk ' . count($post_ids) . ' artikel.', 200, ['count' => count($post_ids)], $returnTo);
        }

        $pdo->rollBack();
        respond(false, __('Unknown category mode.'), 400, [], $returnTo);
    }

    if ($action === 'change_author') {
        $author_id = (int)($_POST['author_id'] ?? 0);
        if ($author_id <= 0) {
            $pdo->rollBack();
            respond(false, __('Invalid author.'), 400, [], $returnTo);
        }

        $v = $pdo->prepare("
            SELECT id
            FROM users
            WHERE id = ?
              AND is_deleted = 0
              AND is_locked = 0
            LIMIT 1
            FOR UPDATE
        ");
        $v->execute([$author_id]);
        if (!$v->fetchColumn()) {
            $pdo->rollBack();
            respond(false, __('Author not found.'), 400, [], $returnTo);
        }

        $hookInput = array_replace($_POST, ['author_id' => $author_id]);
        do_action('admin_posts_bulk_before_mutation', $action, $selectedPosts, $pdo, $hookInput);

        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$author_id], $ids);

        $stmt = $pdo->prepare("
            UPDATE posts
            SET created_by = ?, updated_at = NOW()
            WHERE type = 'article' AND id IN ($in) AND is_deleted = 0
        ");
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond(true, __('Author changed for') . " {$affected} " . __('articles.'), 200, ['count' => $affected], $returnTo);
    }

    if ($action === 'change_date') {
        $created_at = trim((string)($_POST['created_at'] ?? ''));
        $updated_at = trim((string)($_POST['updated_at'] ?? ''));

        if ($created_at === '' && $updated_at === '') {
            $pdo->rollBack();
            respond(false, __('Please enter at least one date.'), 400, [], $returnTo);
        }

        $fields = [];
        $params = [];
        $normalizedCreatedAt = '';
        $normalizedUpdatedAt = '';

        if ($created_at !== '') {
            $dt = parse_datetime_local($created_at);
            if (!$dt) {
                $pdo->rollBack();
                respond(false, __('Invalid date format.'), 400, [], $returnTo);
            }
            $fields[] = 'created_at = ?';
            $normalizedCreatedAt = $dt->format('Y-m-d H:i:s');
            $params[] = $normalizedCreatedAt;
        }

        if ($updated_at !== '') {
            $dt = parse_datetime_local($updated_at);
            if (!$dt) {
                $pdo->rollBack();
                respond(false, __('Invalid date format.'), 400, [], $returnTo);
            }
            $fields[] = 'updated_at = ?';
            $normalizedUpdatedAt = $dt->format('Y-m-d H:i:s');
            $params[] = $normalizedUpdatedAt;
        }

        // Supaya MySQL tidak menggeser updated_at ke NOW() karena ON UPDATE current_timestamp
        // saat hanya created_at yang diubah.
        if ($created_at !== '' && $updated_at === '') {
            $fields[] = 'updated_at = updated_at';
        }

        $hookInput = array_replace($_POST, [
            'created_at' => $normalizedCreatedAt,
            'updated_at' => $normalizedUpdatedAt,
        ]);
        do_action('admin_posts_bulk_before_mutation', $action, $selectedPosts, $pdo, $hookInput);

        $in = implode(',', array_fill(0, count($ids), '?'));
        $set = implode(', ', $fields);
        $params = array_merge($params, $ids);

        $stmt = $pdo->prepare("
            UPDATE posts
            SET {$set}
            WHERE type = 'article' AND id IN ($in) AND is_deleted = 0
        ");
        $stmt->execute($params);
        $affected = $stmt->rowCount();

        $pdo->commit();
        respond(true, __('Date changed for') . " {$affected} " . __('articles.'), 200, ['count' => $affected], $returnTo);
    }

    $pdo->rollBack();
    respond(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('posts/bulk_action error: ' . $e->getMessage());
    respond(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}
