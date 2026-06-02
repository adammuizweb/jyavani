<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);
$isAdmin = ($role === 'admin');

if (!function_exists('slugify_sc')) {
    function slugify_sc(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $text);
        $text = preg_replace('/[-]{2,}/', '-', $text);
        return trim((string)$text, '-') ?: bin2hex(random_bytes(4));
    }
}

$base = ADMIN_BASE_PATH;
$return_to = function_exists('adiwira_safe_return_to')
    ? adiwira_safe_return_to((string)($_REQUEST['return_to'] ?? ''), $base . '/?page=admin/shortcodes/index&tab=presets')
    : ($base . '/?page=admin/shortcodes/index&tab=presets');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

$preset = null;
$pref_title = '';
$pref_slug = '';
$pref_status = 'published';
$pref_config = [
    'source' => 'posts',
    'type' => 'article',
    'category' => '',
    'author' => null,
    'limit' => 5,
    'offset' => 0,
    'order_by' => 'created_at',
    'order_dir' => 'DESC',
    'layout' => 'list',
    'include_children' => '1',
    'excerpt_len' => 90,
    'class_prefix' => '',
    'wrap' => '1',
    'date_from' => null,
    'date_to' => null,
];

if ($isEdit) {
    $sql = "SELECT * FROM posts WHERE id = :id AND type = 'sc_preset' AND is_deleted = 0";
    $params = [':id' => $id];
    if (!$isAdmin) {
        $sql .= " AND created_by = :uid";
        $params[':uid'] = $uid;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $preset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$preset) {
        http_response_code(404);
        echo '<p>Preset tidak ditemukan.</p>';
        return;
    }

    $pref_title = (string)($preset['title'] ?? '');
    $pref_slug = (string)($preset['slug'] ?? '');
    $pref_status = (string)($preset['status'] ?? 'draft');

    $meta = (string)($preset['meta'] ?? '');
    if ($meta !== '') {
        $decoded = json_decode($meta, true);
        if (is_array($decoded)) {
            $pref_config = array_merge($pref_config, $decoded);
        }
    }
}

$save_nonce = bin2hex(random_bytes(12));
if ($isEdit) {
    $_SESSION['sc_save_nonce_' . $id] = $save_nonce;
} else {
    $_SESSION['sc_add_nonce'] = $save_nonce;
}

// Fetch categories for dropdown
$cats = [];
try {
    $cs = $pdo->query("SELECT id, name, slug FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
    $cats = $cs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Fetch users for author dropdown (admin only)
$users = [];
if ($isAdmin) {
    try {
        $us = $pdo->query("SELECT id, username, name FROM users WHERE is_deleted = 0 ORDER BY username ASC");
        $users = $us->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// Available layouts from global + default
$layoutOptions = ['list', 'cards', 'card2', 'sliderpage'];
$layoutDir = realpath(__DIR__ . '/../../../views/partials/shortcodes/post_cat');
if ($layoutDir && is_dir($layoutDir)) {
    $files = scandir($layoutDir);
    foreach ($files as $f) {
        if (str_ends_with($f, '.php')) {
            $name = pathinfo($f, PATHINFO_FILENAME);
            if (!in_array($name, $layoutOptions, true)) {
                $layoutOptions[] = $name;
            }
        }
    }
}
?>
<section class="adam-card">
  <h2><?= $isEdit ? 'Edit Preset' : 'Tambah Preset Baru' ?></h2>

  <form method="post" id="sc-form" action="<?= h($base . '/admin/shortcodes/save.php') ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="save_nonce" value="<?= h($save_nonce) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="return_to" value="<?= h($return_to) ?>">
    <input type="hidden" name="config_json" id="config-json" value="">

    <div class="form-toolbar" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.8rem;">
      <button type="submit" class="adam-button" id="btn-save"><?= $isEdit ? '💾 Simpan Perubahan' : '💾 Simpan' ?></button>
      <a href="<?= h($return_to) ?>" class="adam-cancle">Batal</a>
      <div style="margin-left:auto;font-size:.9rem;color:var(--adam-muted,#555);">
        <?php if ($isEdit): ?>
          Updated: <span id="updated-at"><?= h(function_exists('format_datetime_indo') ? format_datetime_indo((string)($preset['updated_at'] ?? '-')) : (string)($preset['updated_at'] ?? '-')) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="adam-accordion" id="sc-general-accordion" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-general-body">
        ⚙️ Pengaturan Preset
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-general-body">
        <label>Nama Preset<br>
          <input type="text" name="title" value="<?= h($pref_title) ?>" class="inpud" required>
        </label>
        <label style="display:block;margin-top:.6rem">Widget Name (slug) — dipakai di sidebar: <code>widget('nama_ini')</code><br>
          <input type="text" name="slug" value="<?= h($pref_slug) ?>" class="inpud" placeholder="Kosongi untuk auto-generate">
        </label>
        <label style="display:block;margin-top:.6rem">Status<br>
          <select name="status" class="inpud">
            <option value="published" <?= $pref_status === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="draft" <?= $pref_status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="private" <?= $pref_status === 'private' ? 'selected' : '' ?>>Private</option>
          </select>
        </label>
      </div>
    </div>

    <div class="adam-accordion" id="sc-filter-accordion" style="margin-top:.5rem" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-filter-body">
        🔍 Filter Konten
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-filter-body">
        <label>Post Type<br>
          <select name="filter_type" class="inpud" id="filter-type">
            <option value="article" <?= ($pref_config['type'] ?? 'article') === 'article' ? 'selected' : '' ?>>Article</option>
            <option value="page" <?= ($pref_config['type'] ?? '') === 'page' ? 'selected' : '' ?>>Page</option>
          </select>
        </label>

        <label style="display:block;margin-top:.6rem">Kategori (kosongi untuk semua)<br>
          <select name="filter_category" class="inpud">
            <option value="">-- Semua Kategori --</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= h($c['slug']) ?>" <?= ($pref_config['category'] ?? '') === $c['slug'] ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= h($c['slug']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <?php if ($isAdmin): ?>
        <label style="display:block;margin-top:.6rem">Author (kosongi untuk semua)<br>
          <select name="filter_author" class="inpud">
            <option value="">-- Semua Author --</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= ($pref_config['author'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= h($u['username'] . ($u['name'] ? ' (' . $u['name'] . ')' : '')) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php else: ?>
          <input type="hidden" name="filter_author" value="">
        <?php endif; ?>

        <div style="display:flex;gap:1rem;margin-top:.6rem;flex-wrap:wrap;">
          <label>Limit<br>
            <input type="number" name="filter_limit" value="<?= (int)($pref_config['limit'] ?? 5) ?>" class="inpud" style="width:100px" min="1" max="200">
          </label>
          <label>Offset<br>
            <input type="number" name="filter_offset" value="<?= (int)($pref_config['offset'] ?? 0) ?>" class="inpud" style="width:100px" min="0">
          </label>
          <label>Excerpt Length<br>
            <input type="number" name="filter_excerpt" value="<?= (int)($pref_config['excerpt_len'] ?? 90) ?>" class="inpud" style="width:100px" min="10" max="1000">
          </label>
        </div>

        <label style="display:block;margin-top:.6rem">Include Child Categories<br>
          <select name="filter_include_children" class="inpud" style="width:auto;">
            <option value="1" <?= (string)($pref_config['include_children'] ?? '1') === '1' ? 'selected' : '' ?>>Ya</option>
            <option value="0" <?= (string)($pref_config['include_children'] ?? '1') === '0' ? 'selected' : '' ?>>Tidak</option>
          </select>
        </label>
      </div>
    </div>

    <div class="adam-accordion" style="margin-top:.5rem" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-order-body">
        📊 Urutan &amp; Tanggal
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-order-body">
        <label>Urutan<br>
          <select name="filter_order" class="inpud" id="filter-order" style="width:auto;">
            <option value="latest" <?= ($pref_config['order_by'] ?? '') === 'created_at' && ($pref_config['order_dir'] ?? 'DESC') === 'DESC' ? 'selected' : '' ?>>Terbaru (Latest)</option>
            <option value="oldest" <?= ($pref_config['order_by'] ?? '') === 'created_at' && ($pref_config['order_dir'] ?? 'DESC') === 'ASC' ? 'selected' : '' ?>>Terlama (Oldest)</option>
            <option value="random" <?= ($pref_config['order_by'] ?? '') === 'RAND()' ? 'selected' : '' ?>>Acak (Random)</option>
            <option value="custom" <?= !in_array(($pref_config['order_by'] ?? 'created_at'), ['created_at', 'RAND()']) || ($pref_config['order_by'] ?? 'created_at') === 'created_at' && !in_array(($pref_config['order_dir'] ?? 'DESC'), ['DESC', 'ASC']) ? (in_array(($pref_config['order_by'] ?? ''), ['sort_order', 'id', 'updated_at', 'title']) ? 'selected' : '') : '' ?> id="order-custom-opt">Kustom</option>
          </select>
        </label>

        <div id="order-custom-fields" style="display:<?= in_array(($pref_config['order_by'] ?? ''), ['sort_order', 'id', 'updated_at', 'title']) ? 'flex' : 'none' ?>;gap:1rem;margin-top:.6rem;">
          <label>Column<br>
            <select name="filter_order_custom_col" class="inpud" style="width:auto;">
              <option value="created_at" <?= ($pref_config['order_by'] ?? '') === 'created_at' ? 'selected' : '' ?>>created_at</option>
              <option value="sort_order" <?= ($pref_config['order_by'] ?? '') === 'sort_order' ? 'selected' : '' ?>>sort_order</option>
              <option value="id" <?= ($pref_config['order_by'] ?? '') === 'id' ? 'selected' : '' ?>>id</option>
              <option value="updated_at" <?= ($pref_config['order_by'] ?? '') === 'updated_at' ? 'selected' : '' ?>>updated_at</option>
              <option value="title" <?= ($pref_config['order_by'] ?? '') === 'title' ? 'selected' : '' ?>>title</option>
            </select>
          </label>
          <label>Direction<br>
            <select name="filter_order_custom_dir" class="inpud" style="width:auto;">
              <option value="DESC" <?= ($pref_config['order_dir'] ?? 'DESC') === 'DESC' ? 'selected' : '' ?>>DESC</option>
              <option value="ASC" <?= ($pref_config['order_dir'] ?? 'DESC') === 'ASC' ? 'selected' : '' ?>>ASC</option>
            </select>
          </label>
        </div>

        <div style="display:flex;gap:1rem;margin-top:.6rem;flex-wrap:wrap;">
          <label>Date From (YYYY-MM-DD)<br>
            <input type="text" name="filter_date_from" value="<?= h($pref_config['date_from'] ?? '') ?>" class="inpud" style="width:160px" placeholder="2024-01-01">
          </label>
          <label>Date To (YYYY-MM-DD)<br>
            <input type="text" name="filter_date_to" value="<?= h($pref_config['date_to'] ?? '') ?>" class="inpud" style="width:160px" placeholder="2024-12-31">
          </label>
        </div>
      </div>
    </div>

    <div class="adam-accordion" style="margin-top:.5rem" data-open="1">
      <button type="button" class="adam-accordion-toggle" aria-expanded="true" aria-controls="sc-layout-body">
        🎨 Tampilan (Layout)
        <span class="chevron">▸</span>
      </button>
      <div class="adam-accordion-body" id="sc-layout-body">
        <label>Template Layout<br>
          <select name="filter_layout" class="inpud" style="width:auto;">
            <?php foreach ($layoutOptions as $lo): ?>
              <option value="<?= h($lo) ?>" <?= ($pref_config['layout'] ?? 'list') === $lo ? 'selected' : '' ?>><?= h($lo) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label style="display:block;margin-top:.6rem">Class Prefix (opsional)<br>
          <input type="text" name="filter_class_prefix" value="<?= h($pref_config['class_prefix'] ?? '') ?>" class="inpud" placeholder="contoh: featured-news">
        </label>

        <label style="display:block;margin-top:.6rem">Wrapper<br>
          <select name="filter_wrap" class="inpud" style="width:auto;">
            <option value="1" <?= (string)($pref_config['wrap'] ?? '1') === '1' ? 'selected' : '' ?>>Ya (bungkus dengan div)</option>
            <option value="0" <?= (string)($pref_config['wrap'] ?? '1') === '0' ? 'selected' : '' ?>>Tidak</option>
          </select>
        </label>
      </div>
    </div>
  </form>
</section>

<script>
(function(){
  var form = document.getElementById('sc-form');
  var btn = document.getElementById('btn-save');
  var orderSelect = document.getElementById('filter-order');
  var customFields = document.getElementById('order-custom-fields');
  var customOpt = document.getElementById('order-custom-opt');

  if (orderSelect && customFields) {
    orderSelect.addEventListener('change', function() {
      customFields.style.display = this.value === 'custom' ? 'flex' : 'none';
    });
  }

  if (form) {
    form.addEventListener('submit', function(e) {
      if (btn) btn.disabled = true;

      // Build config JSON
      var config = {};

      // Remove hidden config_json field value - we'll rebuild
      var configField = document.getElementById('config-json');

      var type = document.querySelector('[name="filter_type"]');
      if (type) config.type = type.value;

      var cat = document.querySelector('[name="filter_category"]');
      if (cat && cat.value) config.category = cat.value;

      var author = document.querySelector('[name="filter_author"]');
      if (author && author.value) config.author = parseInt(author.value, 10);

      var limit = document.querySelector('[name="filter_limit"]');
      if (limit) config.limit = parseInt(limit.value, 10) || 5;

      var offset = document.querySelector('[name="filter_offset"]');
      if (offset) config.offset = parseInt(offset.value, 10) || 0;

      var excerpt = document.querySelector('[name="filter_excerpt"]');
      if (excerpt) config.excerpt_len = parseInt(excerpt.value, 10) || 90;

      var inc = document.querySelector('[name="filter_include_children"]');
      if (inc) config.include_children = inc.value;

      var order = orderSelect ? orderSelect.value : 'latest';
      if (order === 'latest') {
        config.order_by = 'created_at';
        config.order_dir = 'DESC';
      } else if (order === 'oldest') {
        config.order_by = 'created_at';
        config.order_dir = 'ASC';
      } else if (order === 'random') {
        config.order_by = 'RAND()';
        delete config.order_dir;
      } else if (order === 'custom') {
        var col = document.querySelector('[name="filter_order_custom_col"]');
        var dir = document.querySelector('[name="filter_order_custom_dir"]');
        config.order_by = col ? col.value : 'created_at';
        config.order_dir = dir ? dir.value : 'DESC';
      }

      var df = document.querySelector('[name="filter_date_from"]');
      if (df && df.value) config.date_from = df.value;

      var dt = document.querySelector('[name="filter_date_to"]');
      if (dt && dt.value) config.date_to = dt.value;

      var layout = document.querySelector('[name="filter_layout"]');
      if (layout) config.layout = layout.value;

      var cp = document.querySelector('[name="filter_class_prefix"]');
      if (cp && cp.value) config.class_prefix = cp.value;

      var wrap = document.querySelector('[name="filter_wrap"]');
      if (wrap) config.wrap = wrap.value;

      if (configField) configField.value = JSON.stringify(config);
    });
  }

  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      if (form) form.requestSubmit();
    }
  });
})();
</script>
