<?php
// /adiwira/admin/themes/delete.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);

require_once __DIR__ . '/../../bootstrap.php';

function adiwira_root(): string {
  $base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  $pos  = strpos($base, '/admin');
  return ($pos !== false) ? substr($base, 0, $pos) : $base; // => /adiwira
}

function redirect_themes_index(string $msg = '', string $err = ''): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $_SESSION['flash'] = $_SESSION['flash'] ?? [];
  if ($msg !== '') $_SESSION['flash'][] = ['type'=>'success','text'=>$msg];
  if ($err !== '') $_SESSION['flash'][] = ['type'=>'error','text'=>$err];
  header('Location: ' . adiwira_root() . '/index.php?page=admin/themes/index');
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect_themes_index();
if (session_status() === PHP_SESSION_NONE) session_start();

// ===== ADMIN ONLY GUARD =====
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) redirect_themes_index('', 'Akses ditolak: belum login.');

$role = $_SESSION['user_role'] ?? '';
if ($role === '' || $role === null) {
  $r = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
  $r->execute([':id'=>$uid]);
  $role = (string)$r->fetchColumn();
  $_SESSION['user_role'] = $role;
}
$role = strtolower(trim($role ?: 'guest'));

if ($role !== 'admin') redirect_themes_index('', 'Akses ditolak: menu Themes hanya untuk admin.');
// ===== END ADMIN ONLY GUARD =====

$token = $_POST['csrf_token'] ?? '';
if (!csrf_check($token)) redirect_themes_index('', 'CSRF token tidak valid.');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect_themes_index('', 'ID tidak valid.');

$stmt = $pdo->prepare("SELECT id FROM posts WHERE id=:id AND type='theme' AND is_deleted=0 LIMIT 1");
$stmt->execute([':id' => $id]);
if (!$stmt->fetchColumn()) redirect_themes_index('', 'Theme partial tidak ditemukan.');

try {
  $pdo->beginTransaction();

  $pdo->prepare("
    UPDATE posts
    SET is_deleted=1, deleted_at=NOW(), updated_at=NOW()
    WHERE id=:id AND type='theme' AND is_deleted=0
    LIMIT 1
  ")->execute([':id'=>$id]);

  $pdo->commit();
  redirect_themes_index('Theme partial berhasil dihapus 🚮');

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_themes_index('', 'Gagal menghapus theme: ' . $e->getMessage());
}