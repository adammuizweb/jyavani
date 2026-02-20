<?php
if (!defined('DASHBOARD_CONTEXT')) {
    define('DASHBOARD_CONTEXT', true);
}
require_once __DIR__ . '/../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo '<p>Akses ditolak: belum login.</p>';
    exit;
}

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? null;

// pastikan role ada
if (!$role && $uid > 0) {
    $st = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
    $st->execute([':id' => $uid]);
    $role = $st->fetchColumn() ?: null;
    $_SESSION['user_role'] = $role;
}

if ($role !== 'admin') {
    http_response_code(403);
    echo '<p>Akses ditolak: hanya admin.</p>';
    exit;
}

$errors = [];
$success_msg = '';

$current_title = function_exists('settings_get') ? (settings_get($pdo,'site_title','Jyavani CMS') ?? 'Jyavani CMS') : 'Jyavani CMS';
$current_host  = function_exists('settings_get') ? (settings_get($pdo,'site_host','cms.jyavani.com') ?? 'cms.jyavani.com') : 'cms.jyavani.com';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) $errors[] = 'CSRF token tidak valid.';

    $site_title = trim((string)($_POST['site_title'] ?? ''));
    $site_host  = trim((string)($_POST['site_host'] ?? ''));

    if ($site_title === '') $errors[] = 'Site title tidak boleh kosong.';
    if ($site_host === '')  $errors[] = 'Site host tidak boleh kosong.';
    if ($site_host !== '' && !preg_match('/^[a-z0-9][a-z0-9\.\-]*[a-z0-9]$/i', $site_host)) {
        $errors[] = 'Format host tidak valid. Contoh: cms.jyavani.com';
    }

    if (!$errors) {
        $ok1 = settings_set($pdo, 'site_title', $site_title, 1);
        $ok2 = settings_set($pdo, 'site_host',  $site_host,  1);

        if ($ok1 && $ok2) {
            $success_msg = 'Pengaturan website berhasil disimpan.';
            $current_title = $site_title;
            $current_host  = $site_host;
        } else {
            $errors[] = 'Gagal menyimpan pengaturan.';
        }
    }
}
?>

<section class="adam-card" style="max-width:820px;margin:18px auto;">
  <h2>Pengaturan Website</h2>

  <?php if ($success_msg): ?>
    <div class="adam-success" style="margin:10px 0;">
      ✅ <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="adam-error" style="margin:10px 0;">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <label style="display:block;margin:.6rem 0;">
      Site Title (default)
      <input type="text" name="site_title"
        value="<?= htmlspecialchars($current_title, ENT_QUOTES, 'UTF-8') ?>"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;">
    </label>

    <label style="display:block;margin:.6rem 0;">
      Site Host (fallback / canonical default)
      <input type="text" name="site_host"
        value="<?= htmlspecialchars($current_host, ENT_QUOTES, 'UTF-8') ?>"
        placeholder="jyavani.com"
        style="width:100%;padding:.55rem;border:1px solid #ddd;border-radius:8px;margin-top:.35rem;">
    </label>

    <div style="margin-top:14px;display:flex;gap:10px;align-items:center;">
      <button type="submit" class="adam-button">Simpan</button>
      <a class="adam-cancle" href="/adiwira/index.php?page=admin/pengaturan/index">Kembali</a>
    </div>
  </form>
</section>
