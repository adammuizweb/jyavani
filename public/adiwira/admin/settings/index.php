<?php
// /adiwira/admin/pengaturan/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['user_role'] ?? null;

if (!$role && $uid > 0) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stmt->execute([':id' => $uid]);
    $role = $stmt->fetchColumn() ?: null;
    $_SESSION['user_role'] = $role;
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

$links = [
    ['label' => 'Profile', 'href' => $base . '/index.php?page=admin/profile/index'],
];

if ($role === 'admin') {
    $links[] = ['label' => 'Bin / Trash', 'href' => $base . '/index.php?page=admin/bin/index']; // <-- tambah ini
    $links[] = ['label' => 'Users', 'href' => $base . '/index.php?page=admin/users/index'];
//    $links[] = ['label' => 'Website', 'href' => $base . '/index.php?page=admin/settings/site'];
}

?>
<section style="max-width:820px;margin:18px auto;padding:0 12px">
  <header style="margin-bottom:14px">
    <h1 style="margin:0">Pengaturan</h1>
    <p style="margin:.25rem 0 0;color:#666">Pilih bagian untuk mengelola pengaturan. (Hanya menampilkan item yang relevan.)</p>
  </header>

  <nav aria-label="Pengaturan utama" style="border:1px;border-radius:8px;padding:12px">
    <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px">
      <?php foreach ($links as $ln): ?>
        <li>
          <a class="adam-link"
             href="<?= htmlspecialchars($ln['href'], ENT_QUOTES, 'UTF-8') ?>"
             style="display:block;padding:.6rem .8rem;border-radius:6px;border:1px solid transparent;">
             <?= htmlspecialchars($ln['label'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>
</section>
