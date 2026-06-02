<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

adiwira_cosmetic_404_on_direct_open();

[$uid, $role] = adiwira_require_editorial($pdo, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Not found'], 404);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_redirect_with_flash((string)($_POST['return_to'] ?? ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets'), 'error', 'CSRF invalid.');
}

$id = (int)($_POST['id'] ?? 0);
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_POST['return_to'] ?? ''), ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets')
    : ADMIN_BASE_PATH . '/?page=admin/shortcodes/index&tab=presets';

if ($id <= 0) {
    adiwira_redirect_with_flash($return_to, 'error', 'ID tidak valid.');
}

try {
    $sql = "UPDATE posts SET is_deleted = 1, deleted_at = NOW() WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
    $params = [':id' => $id];
    if ($role !== 'admin') {
        $sql .= " AND created_by = :uid";
        $params[':uid'] = $uid;
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        adiwira_redirect_with_flash($return_to, 'success', 'Preset berhasil dipindahkan ke trash.');
    } else {
        adiwira_redirect_with_flash($return_to, 'error', 'Preset tidak ditemukan.');
    }
} catch (Throwable $e) {
    error_log('shortcodes/delete.php error: ' . $e->getMessage());
    adiwira_redirect_with_flash($return_to, 'error', 'Gagal menghapus preset.');
}
