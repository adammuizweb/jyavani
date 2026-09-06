<?php
declare(strict_types=1);

// /adiwira/admin/bin/theme/bulk_action.php
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

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/bin/theme/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('respond_theme_bin_bulk')) {
    function respond_theme_bin_bulk(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/bin/theme/index';

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
    respond_theme_bin_bulk(false, 'Method Not Allowed', 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    respond_theme_bin_bulk(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    respond_theme_bin_bulk(false, __('No themes selected.'), 400, [], $returnTo);
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
sort($ids, SORT_NUMERIC);
if (empty($ids)) {
    respond_theme_bin_bulk(false, __('Invalid theme ID.'), 400, [], $returnTo);
}
if (count($ids) > 100) {
    respond_theme_bin_bulk(false, __('You can select up to 100 items at a time.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    respond_theme_bin_bulk(false, __('Unknown bulk action.'), 400, [], $returnTo);
}

$in = implode(',', array_fill(0, count($ids), '?'));

try {
    $affected = shortcode_collection_layout_content_mutation($pdo, static function () use ($pdo, $uid, $ids, $in, $action): int {
        $pdo->beginTransaction();
        try {
            if (!authorization_lock_actor_permissions($pdo, $uid)) throw new DomainException('Theme actor permission lock failed.');
            $selected = $pdo->prepare("SELECT id, created_by, slug FROM posts WHERE id IN ($in) AND type = 'theme' AND is_deleted = 1 ORDER BY id FOR UPDATE");
            $selected->execute($ids);
            $themes = $selected->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($themes) !== count($ids) || !authorization_lock_owner_contexts($pdo, array_column($themes, 'created_by'))) {
                throw new DomainException('Theme selection changed.');
            }
            $permission = $action === 'restore' ? 'core.theme_content.restore' : 'core.theme_content.purge';
            foreach ($themes as $theme) {
                if (!user_can($pdo, $uid, $permission, ['owner_id' => (int)$theme['created_by']])) throw new DomainException('Theme bin permission changed.');
            }

            if ($action === 'restore') {
                $slugs = array_map(static fn(array $theme): string => (string)$theme['slug'], $themes);
                if (count(array_unique($slugs)) !== count($slugs)) throw new DomainException(__('One or more theme partial slugs are duplicated.'));
                $slugIn = implode(',', array_fill(0, count($slugs), '?'));
                $collision = $pdo->prepare("SELECT id FROM posts WHERE slug IN ($slugIn) AND type IN ('article', 'page', 'theme') AND is_deleted = 0 LIMIT 1 FOR UPDATE");
                $collision->execute($slugs);
                if ($collision->fetchColumn()) throw new DomainException(__('One or more theme partial slugs are already active.'));
                $stmt = $pdo->prepare("UPDATE posts SET is_deleted = 0, deleted_at = NULL, updated_at = NOW(), updated_by = ? WHERE id IN ($in) AND type = 'theme' AND is_deleted = 1");
                $stmt->execute(array_merge([$uid], $ids));
            } elseif ($action === 'delete_permanent') {
                $pdo->prepare("DELETE FROM post_categories WHERE post_id IN ($in)")->execute($ids);
                $stmt = $pdo->prepare("DELETE FROM posts WHERE id IN ($in) AND type = 'theme' AND is_deleted = 1");
                $stmt->execute($ids);
            } else {
                throw new InvalidArgumentException(__('Unknown bulk action.'));
            }

            $affected = $stmt->rowCount();
            if ($affected !== count($ids)) throw new RuntimeException('Theme Bin bulk mutation did not affect the complete selection.');
            $pdo->commit();
            return $affected;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    });

    $message = $action === 'restore'
        ? "Successfully restored  {$affected} theme."
        : "Permanently deleted  {$affected} theme.";
    respond_theme_bin_bulk(true, $message, 200, ['count' => $affected], $returnTo);

} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond_theme_bin_bulk(false, $e->getMessage(), 400, [], $returnTo);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond_theme_bin_bulk(false, $e->getMessage(), 409, [], $returnTo);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('bin/theme/bulk_action.php error: ' . $e->getMessage());
    respond_theme_bin_bulk(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}
