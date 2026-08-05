<?php
// /adiwira/admin/users/bulk_action.php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_role($pdo, ['admin'], true);

if (!function_exists('adiwira_users_is_ajax_request')) {
    function adiwira_users_is_ajax_request(): bool {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return ($xrw === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
    }
}

$defaultReturnTo = ADMIN_BASE_PATH . '/?page=admin/users/index';
$returnTo = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), $defaultReturnTo)
    : $defaultReturnTo;

if (!function_exists('adiwira_users_bulk_respond')) {
    function adiwira_users_bulk_respond(bool $ok, string $message = '', int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
        $redirect = $redirect ?: ADMIN_BASE_PATH . '/?page=admin/users/index';

        if (adiwira_users_is_ajax_request()) {
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
    adiwira_users_bulk_respond(false, __('Method Not Allowed'), 405, [], $returnTo);
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!adiwira_csrf_validate($token)) {
    adiwira_users_bulk_respond(false, __('Invalid CSRF token.'), 419, [], $returnTo);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    adiwira_users_bulk_respond(false, __('No users selected.'), 400, [], $returnTo);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
$ids = array_values(array_filter($ids, fn($v) => $v !== $uid)); // jangan proses diri sendiri

if (empty($ids)) {
    adiwira_users_bulk_respond(false, __('No valid user selected.'), 400, [], $returnTo);
}

$action = (string)($_POST['action'] ?? '');
if ($action === '') {
    adiwira_users_bulk_respond(false, __('Unknown bulk action.'), 400, [], $returnTo);
}

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) deleted.'), $cnt), 200, ['count' => $cnt], $returnTo);
    }

    if ($action === 'change_role') {
        $newRole = trim((string)($_POST['role'] ?? ''));
        $allowed = ['author','editor','admin'];

        if (!in_array($newRole, $allowed, true)) {
            $pdo->rollBack();
            adiwira_users_bulk_respond(false, __('Invalid target role.'), 400, [], $returnTo);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET role = ?, updated_at = NOW() WHERE id IN ($in)";
        $params = array_merge([$newRole], $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) role changed to "%s".'), $cnt, $newRole), 200, ['count' => $cnt], $returnTo);
    }

    if ($action === 'lock') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_locked = 1, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) locked.'), $cnt), 200, ['count' => $cnt], $returnTo);
    }

    if ($action === 'unlock') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_locked = 0, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        adiwira_users_bulk_respond(true, sprintf(__('%d user(s) approved / unlocked.'), $cnt), 200, ['count' => $cnt], $returnTo);
    }

    $pdo->rollBack();
    adiwira_users_bulk_respond(false, __('Unknown bulk action.'), 400, [], $returnTo);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[users/bulk_action] ' . $e->getMessage());
    adiwira_users_bulk_respond(false, __('An error occurred during bulk action.'), 500, [], $returnTo);
}