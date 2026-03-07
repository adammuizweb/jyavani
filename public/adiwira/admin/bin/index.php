<?php
// /adiwira/admin/bin/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
  http_response_code(403);
  exit('Forbidden');
}

if (session_status() === PHP_SESSION_NONE) session_start();

$messages = [];
$errors = [];
if (!empty($_GET['msg'])) $messages[] = urldecode($_GET['msg']);
if (!empty($_GET['err'])) $errors[] = urldecode($_GET['err']);

// auth
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  http_response_code(403);
  exit('Akses ditolak: belum login.');
}

$role = $_SESSION['user_role'] ?? null;
if (!$role) {
  // pakai helper kalau ada
  if (function_exists('current_user_role')) {
    $role = current_user_role($pdo) ?: null;
  }
  // fallback query DB
  if (!$role) {
    $st = $pdo->prepare("SELECT role FROM users WHERE id=:id AND is_deleted=0 LIMIT 1");
    $st->execute([':id' => $uid]);
    $role = $st->fetchColumn() ?: null;
  }
  $_SESSION['user_role'] = $role;
}

if ($role !== 'admin') {
  http_response_code(403);
  exit('Akses ditolak: hanya admin.');
}

// base url router
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

// helper count
function count_posts_trash(PDO $pdo, string $type): int {
  $st = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE type=:t AND is_deleted=1");
  $st->execute([':t' => $type]);
  return (int)$st->fetchColumn();
}
function count_categories_trash(PDO $pdo): int {
  $st = $pdo->query("SELECT COUNT(*) FROM categories WHERE is_deleted=1");
  return (int)$st->fetchColumn();
}

// counts (safe)
try {
  $countArticle = count_posts_trash($pdo, 'article');
  $countPage    = count_posts_trash($pdo, 'page');
  $countTheme   = count_posts_trash($pdo, 'theme');
  $countPhoto = count_posts_trash($pdo, 'photo');
  $countCat     = count_categories_trash($pdo);
} catch (Throwable $e) {
  $errors[] = 'Gagal mengambil statistik bin: ' . $e->getMessage();
  $countArticle = $countPage = $countTheme = $countCat = 0;
}

// cek apakah halaman bin sudah ada
$exists = [
  'article'  => is_file(__DIR__ . '/article/index.php'),
  'page'     => is_file(__DIR__ . '/page/index.php'),
  'category' => is_file(__DIR__ . '/category/index.php'),
  'theme'    => is_file(__DIR__ . '/theme/index.php'),
  'photo'    => is_file(__DIR__ . '/photo/index.php'),
];

$items = [
  [
    'key' => 'article',
    'title' => 'Bin Articles',
    'desc' => 'Trash untuk artikel (posts type=article).',
    'count' => $countArticle,
    'href' => $base . '/index.php?page=admin/bin/article/index',
  ],
  [
    'key' => 'page',
    'title' => 'Bin Pages',
    'desc' => 'Trash untuk halaman (posts type=page).',
    'count' => $countPage,
    'href' => $base . '/index.php?page=admin/bin/page/index',
  ],
  [
    'key' => 'category',
    'title' => 'Bin Categories',
    'desc' => 'Trash untuk kategori (categories).',
    'count' => $countCat,
    'href' => $base . '/index.php?page=admin/bin/category/index',
  ],
  [
    'key' => 'theme',
    'title' => 'Bin Themes',
    'desc' => 'Trash untuk theme/partials (posts type=theme).',
    'count' => $countTheme,
    'href' => $base . '/index.php?page=admin/bin/theme/index',
  ],
  [
    'key' => 'photo',
    'title' => 'Bin Photo',
    'desc' => 'Trash untuk Photo Post (posts type=photo).',
    'count' => $countPhoto,
    'href' => $base . '/index.php?page=admin/bin/photo/index',
  ],
];
?>

<section class="adam-card" style="max-width:980px;margin:16px auto;">
  <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
    <div>
      <h2 style="margin:0">Bin / Trash</h2>
      <div style="color:#666;margin-top:.25rem;">Menu pengelolaan item yang dihapus (admin only).</div>
    </div>
    <div style="margin-left:auto;">
      <a class="adam-link" href="<?= htmlspecialchars($base . '/index.php?page=admin/pengaturan/index', ENT_QUOTES, 'UTF-8') ?>">← Kembali ke Pengaturan</a>
    </div>
  </div>

  <?php if (!empty($messages)): ?>
    <div class="adam-alert success" style="margin-top:1rem;margin-bottom:1rem;padding:.8rem 1rem;background:#e8f7ec;border:1px solid #b6e2c2;border-radius:6px;color:#246;">
      <?php foreach ($messages as $m): ?><div><?= htmlspecialchars($m, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="adam-alert error" style="margin-top:1rem;margin-bottom:1rem;padding:.8rem 1rem;background:#fee;border:1px solid #fbb;border-radius:6px;color:#600;">
      <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px;">
    <?php foreach ($items as $it):
      $ok = $exists[$it['key']] ?? false;
      $href = $it['href'];
    ?>
      <div style="border:1px solid #eee;border-radius:10px;background:#fff;padding:14px;display:flex;flex-direction:column;gap:8px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="font-weight:700;"><?= htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8') ?></div>
          <span style="margin-left:auto;display:inline-block;min-width:34px;text-align:center;padding:.15rem .55rem;border-radius:999px;border:1px solid rgba(30,100,200,.15);background:rgba(30,100,200,.06);color:#1e64c8;">
            <?= (int)$it['count'] ?>
          </span>
        </div>

        <div style="color:#666;font-size:.9rem;line-height:1.35;">
          <?= htmlspecialchars($it['desc'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div style="margin-top:auto;">
          <?php if ($ok): ?>
            <a class="adam-button" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block;">Buka</a>
          <?php else: ?>
            <span style="display:inline-block;padding:.45rem .75rem;border-radius:8px;background:#f5f5f5;color:#777;border:1px solid #eee;">
              Belum tersedia
            </span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<script>
  // auto-hide alerts (konsisten dengan modul lain)
  setTimeout(() => {
    const alert = document.querySelector('.adam-alert');
    if (alert) {
      alert.style.transition = 'opacity .5s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 600);
    }
  }, 3000);
</script>
