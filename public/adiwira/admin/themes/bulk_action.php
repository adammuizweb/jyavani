<?php
// /adiwira/admin/themes/bulk_action.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos  = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base; // => /adiwira
}

function is_ajax_request(): bool {
  $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return (strtolower($xrw) === 'xmlhttprequest') || (strpos($accept, 'application/json') !== false);
}

function respond(bool $ok, string $message, int $httpCode = 200, array $extra = [], ?string $redirect = null): void {
  $isAjax = is_ajax_request();

  if ($isAjax) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>$ok,'message'=>$message], $extra), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  $_SESSION['flash'] = $_SESSION['flash'] ?? [];
  $_SESSION['flash'][] = ['type' => $ok ? 'success' : 'error', 'text' => $message];

  $to = $redirect ?? (adiwira_root() . '/index.php?page=admin/themes/index');
  header('Location: ' . $to);
  exit;
}

$back = adiwira_root() . '/index.php?page=admin/themes/index';

// ===== ADMIN ONLY GUARD =====
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) respond(false, 'Akses ditolak: belum login.', 403, [], $back);

$role = $_SESSION['user_role'] ?? '';
if ($role === '' || $role === null) {
  $r = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
  $r->execute([':id'=>$uid]);
  $role = (string)$r->fetchColumn();
  $_SESSION['user_role'] = $role;
}
$role = strtolower(trim($role ?: 'guest'));

if ($role !== 'admin') respond(false, 'Akses ditolak: menu Themes hanya untuk admin.', 403, [], $back);
// ===== END ADMIN ONLY GUARD =====

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(false, 'Method Not Allowed', 405, [], $back);

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) respond(false, 'CSRF token tidak valid.', 419, [], $back);

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) respond(false, 'Tidak ada theme dipilih.', 400, [], $back);

$ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
if (empty($ids)) respond(false, 'ID theme tidak valid.', 400, [], $back);

$action = (string)($_POST['action'] ?? '');
$in = implode(',', array_fill(0, count($ids), '?'));

try {
  $pdo->beginTransaction();

  if ($action === 'delete') {
    $stmt = $pdo->prepare("
      UPDATE posts
      SET is_deleted=1, deleted_at=NOW(), updated_at=NOW()
      WHERE id IN ($in) AND type='theme' AND is_deleted=0
    ");
    $stmt->execute($ids);
    $affected = $stmt->rowCount();

    $pdo->commit();
    respond(true, "Berhasil memindahkan {$affected} theme partial ke Trash.", 200, ['count'=>$affected], $back);
  }

  if ($action === 'change_status') {
    $new_status = (string)($_POST['status'] ?? '');
    if (!in_array($new_status, ['draft','published','private'], true)) {
      $pdo->rollBack();
      respond(false, 'Status tidak valid.', 400, [], $back);
    }

    $stmt = $pdo->prepare("
      UPDATE posts
      SET status=?, updated_at=NOW()
      WHERE id IN ($in) AND type='theme' AND is_deleted=0
    ");
    $stmt->execute(array_merge([$new_status], $ids));
    $affected = $stmt->rowCount();

    $pdo->commit();
    respond(true, "Berhasil mengubah status {$affected} theme partial menjadi {$new_status}.", 200, ['count'=>$affected], $back);
  }

  $pdo->rollBack();
  respond(false, 'Aksi bulk tidak dikenal.', 400, [], $back);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('themes/bulk_action.php error: '.$e->getMessage());
  respond(false, 'Terjadi kesalahan saat proses bulk.', 500, [], $back);
}