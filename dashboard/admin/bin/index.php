<?php
declare(strict_types=1);

// /adiwira/admin/bin/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_admin($pdo, false);

$errors = [];

// kompatibel dengan query toast lama
$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

// ambil flash toast dari session bila ada
if (function_exists('adiwira_flash_pull')) {
    $flash = adiwira_flash_pull();
    if (is_array($flash)) {
        foreach ($flash as $f) {
            $type = isset($f['type']) ? (string)$f['type'] : 'info';
            $text = isset($f['message']) ? (string)$f['message'] : (isset($f['text']) ? (string)$f['text'] : '');
            if ($text !== '') {
                $page_toasts[] = [
                    'type' => $type,
                    'message' => $text,
                ];
            }
        }
    }
}

// base url router
$base = ADMIN_BASE_PATH;

// helper count
if (!function_exists('count_posts_trash')) {
    function count_posts_trash(PDO $pdo, string $type): int
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE type = :t AND is_deleted = 1");
        $st->execute([':t' => $type]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('count_categories_trash')) {
    function count_categories_trash(PDO $pdo): int
    {
        $st = $pdo->query("SELECT COUNT(*) FROM categories WHERE is_deleted = 1");
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('count_users_trash')) {
    function count_users_trash(PDO $pdo): int
    {
        $st = $pdo->query("SELECT COUNT(*) FROM users WHERE is_deleted = 1");
        return (int)$st->fetchColumn();
    }
}

// counts
try {
    $countArticle = count_posts_trash($pdo, 'article');
    $countPage    = count_posts_trash($pdo, 'page');
    $countTheme   = count_posts_trash($pdo, 'theme');
    $countPhoto   = count_posts_trash($pdo, 'photo');
    $countCat     = count_categories_trash($pdo);
    $countUsers   = count_users_trash($pdo);
} catch (Throwable $e) {
    error_log('admin/bin/? stats error: ' . $e->getMessage());
    $errors[] = __('Gagal mengambil statistik bin.');
    $countArticle = $countPage = $countTheme = $countPhoto = $countCat = $countUsers = 0;
}

// cek apakah halaman bin sudah ada
$exists = [
    'article'  => is_file(__DIR__ . '/article/index.php'),
    'page'     => is_file(__DIR__ . '/page/index.php'),
    'category' => is_file(__DIR__ . '/category/index.php'),
    'theme'    => is_file(__DIR__ . '/theme/index.php'),
    'photo'    => is_file(__DIR__ . '/photo/index.php'),
    'users'    => is_file(__DIR__ . '/users/index.php'),
];

$items = [
    [
        'key' => 'article',
        'title' => 'Bin Articles',
        'desc' => 'Trash untuk artikel (posts type=article).',
        'count' => $countArticle,
        'href' => $base . '/?page=admin/bin/article/index',
        'emoji' => '📝',
    ],
    [
        'key' => 'page',
        'title' => 'Bin Pages',
        'desc' => 'Trash untuk halaman (posts type=page).',
        'count' => $countPage,
        'href' => $base . '/?page=admin/bin/page/index',
        'emoji' => '📄',
    ],
    [
        'key' => 'category',
        'title' => 'Bin Categories',
        'desc' => 'Trash untuk kategori (categories).',
        'count' => $countCat,
        'href' => $base . '/?page=admin/bin/category/index',
        'emoji' => '🏷️',
    ],
    [
        'key' => 'theme',
        'title' => 'Bin Themes',
        'desc' => 'Trash untuk theme/partials (posts type=theme).',
        'count' => $countTheme,
        'href' => $base . '/?page=admin/bin/theme/index',
        'emoji' => '🎨',
    ],
    [
        'key' => 'photo',
        'title' => 'Bin Photo',
        'desc' => 'Trash untuk Photo Post (posts type=photo).',
        'count' => $countPhoto,
        'href' => $base . '/?page=admin/bin/photo/index',
        'emoji' => '🖼️',
    ],
    [
        'key' => 'users',
        'title' => 'Bin Users',
        'desc' => __('Trash for deleted users (soft delete).'),
        'count' => $countUsers,
        'href' => $base . '/?page=admin/bin/users/index',
        'emoji' => '👤',
    ],
];

$show_inline_errors = !empty($errors) && !function_exists('adiwira_bootstrap_toasts_script');
?>

<style>
.binhub-wrap{
  max-width: 980px;
  margin: 16px auto;
}

.binhub-head{
  display: flex;
  align-items: flex-end;
  gap: 12px;
  flex-wrap: wrap;
}

.binhub-head h2{
  margin: 0;
  color: var(--adam-text);
}

.binhub-sub{
  color: var(--adam-muted);
  margin-top: .35rem;
}

.binhub-back{
  margin-left: auto;
}

.binhub-grid{
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  margin-top: 14px;
}

.binhub-card{
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 180px;
  padding: 14px;
  border-radius: 12px;
  border: 1px solid var(--adam-border);
  background: var(--adam-card);
  box-shadow: var(--adam-shadow);
  transition: transform var(--transition-fast) ease, border-color var(--transition-fast) ease, background var(--transition-fast) ease;
}

.binhub-card:hover{
  transform: translateY(-2px);
  border-color: var(--adam-border-2);
  background: var(--adam-surface-2);
}

.binhub-row{
  display: flex;
  align-items: center;
  gap: 10px;
}

.binhub-icon{
  width: 38px;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  background: var(--adam-primary-soft);
  color: var(--adam-primary);
  font-size: 1.1rem;
  flex: 0 0 auto;
}

.binhub-title{
  font-weight: 700;
  color: var(--adam-text);
  line-height: 1.25;
}

.binhub-count{
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 36px;
  height: 30px;
  padding: 0 .65rem;
  border-radius: 999px;
  background: var(--adam-badge-bg);
  color: var(--adam-badge-text);
  border: 1px solid var(--adam-badge-bd);
  font-weight: 700;
  font-size: .92rem;
}

.binhub-desc{
  color: var(--adam-muted);
  font-size: .92rem;
  line-height: 1.5;
  min-height: 2.8em;
}

.binhub-actions{
  margin-top: auto;
  display: flex;
  align-items: center;
  gap: 8px;
}

.binhub-disabled{
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 40px;
  padding: .45rem .85rem;
  border-radius: 10px;
  background: var(--adam-surface-3);
  color: var(--adam-muted);
  border: 1px solid var(--adam-border);
  font-weight: 600;
}

.binhub-note{
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: 10px;
  background: var(--adam-surface-3);
  border: 1px solid var(--adam-border);
  color: var(--adam-text-3);
  font-size: .92rem;
  line-height: 1.55;
}

.binhub-inline-error{
  margin-top: 14px;
  padding: 12px 14px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--adam-danger) 10%, var(--adam-card));
  border: 1px solid color-mix(in srgb, var(--adam-danger) 28%, var(--adam-border));
  color: var(--adam-text);
}

.binhub-inline-error ul{
  margin: 0;
  padding-left: 18px;
}

@media (max-width: 640px){
  .binhub-wrap{
    margin: 12px auto;
  }

  .binhub-card{
    min-height: unset;
  }
}
</style>

<section class="adam-card binhub-wrap">
  <div class="binhub-head">
    <div>
      <h2><?=_e('Bin / Trash')?></h2>
      <div class="binhub-sub"><?=_e('Manage deleted items menu (admin only).')?></div>
    </div>
    <div class="binhub-back">
      <a class="adam-link" href="<?= htmlspecialchars($base . '/?page=admin/settings/index', ENT_QUOTES, 'UTF-8') ?>"><?=_e('← Back to Settings')?></a>
    </div>
  </div>

  <?php if ($show_inline_errors): ?>
    <div class="binhub-inline-error">
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="binhub-grid">
    <?php foreach ($items as $it):
      $ok = $exists[$it['key']] ?? false;
      $href = $it['href'];
    ?>
      <article class="binhub-card">
        <div class="binhub-row">
          <div class="binhub-icon"><?= htmlspecialchars($it['emoji'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="binhub-title"><?= htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8') ?></div>
          <span class="binhub-count"><?= (int)$it['count'] ?></span>
        </div>

        <div class="binhub-desc">
          <?= htmlspecialchars($it['desc'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="binhub-actions">
          <?php if ($ok): ?>
            <a class="adam-button" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?=_e('Open')?></a>
          <?php else: ?>
            <span class="binhub-disabled"><?=_e('Not available')?></span>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="binhub-note">
    <?=_e('This page shows a summary of soft-deleted items.')?> <?=_e('Go to each menu to restore or permanently delete if the feature is available.')?>
  </div>
</section>

<?php
if (function_exists('adiwira_bootstrap_toasts_script')) {
    $toast_items = $page_toasts;

    foreach ($errors as $msg) {
        $toast_items[] = [
            'type' => 'error',
            'message' => (string)$msg,
        ];
    }

    if (!empty($toast_items)) {
        echo adiwira_bootstrap_toasts_script($toast_items);
    }
}
?>