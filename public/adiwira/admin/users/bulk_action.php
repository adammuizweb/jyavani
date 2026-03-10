<?php
// /adiwira/admin/users/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Akses ditolak';
    exit;
}

$uid = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? null;
if (!$role) {
    $stmtR = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmtR->execute([':id' => $uid]);
    $role = $stmtR->fetchColumn() ?: 'author';
    $_SESSION['user_role'] = $role;
}

$role = strtolower(trim((string)$role));
if ($role !== 'admin') {
    http_response_code(403);
    echo 'Akses ditolak';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$redirectUrl = $base . '/index.php?page=admin/users/index';

function safe_redirect(string $url): void {
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    echo '</head><body></body></html>';
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) {
    $_SESSION['_flash_error'] = 'CSRF token tidak valid.';
    safe_redirect($redirectUrl);
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    $_SESSION['_flash_error'] = 'Tidak ada user dipilih.';
    safe_redirect($redirectUrl);
}

$ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
$ids = array_values(array_filter($ids, fn($v) => $v !== $uid)); // jangan proses diri sendiri

if (empty($ids)) {
    $_SESSION['_flash_error'] = 'Tidak ada user valid dipilih.';
    safe_redirect($redirectUrl);
}

$action = (string)($_POST['action'] ?? '');

try {
    $pdo->beginTransaction();

    if ($action === 'delete') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_deleted = 1, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        $_SESSION['_flash_success'] = "Berhasil menghapus $cnt user.";
        safe_redirect($redirectUrl);
    }

    if ($action === 'change_role') {
        $newRole = trim((string)($_POST['role'] ?? ''));
        $allowed = ['author','editor','admin'];

        if (!in_array($newRole, $allowed, true)) {
            $pdo->rollBack();
            $_SESSION['_flash_error'] = 'Role tujuan tidak valid.';
            safe_redirect($redirectUrl);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET role = ?, updated_at = NOW() WHERE id IN ($in)";
        $params = array_merge([$newRole], $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        $_SESSION['_flash_success'] = "Berhasil mengubah role $cnt user menjadi $newRole.";
        safe_redirect($redirectUrl);
    }

    if ($action === 'lock') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_locked = 1, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        $_SESSION['_flash_success'] = "Berhasil mengunci $cnt user.";
        safe_redirect($redirectUrl);
    }

    if ($action === 'unlock') {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE users SET is_locked = 0, updated_at = NOW() WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $cnt = $stmt->rowCount();

        $pdo->commit();
        $_SESSION['_flash_success'] = "Berhasil meng-approve / unlock $cnt user.";
        safe_redirect($redirectUrl);
    }

    $pdo->rollBack();
    $_SESSION['_flash_error'] = 'Aksi bulk tidak dikenal.';
    safe_redirect($redirectUrl);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[users/bulk_action] ' . $e->getMessage());
    $_SESSION['_flash_error'] = 'Terjadi kesalahan saat proses bulk action.';
    safe_redirect($redirectUrl);
}