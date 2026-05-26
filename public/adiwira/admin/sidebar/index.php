<?php
declare(strict_types=1);
require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
[$uid, $role] = adiwira_require_admin($pdo, false);
$errors = [];
$success_msg = '';
$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$self_url = $base . '/index.php?page=admin/sidebar/index';
$current_widgets = [];
$stored = function_exists('settings_get') ? settings_get($pdo, 'sidebar_widgets') : null;
if ($stored !== null && $stored !== '') {
    $decoded = json_decode($stored, true);
    if (is_array($decoded)) $current_widgets = $decoded;
}
usort($current_widgets, function($a, $b) {
    return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
});
$widget_types = [
    'search' => [
        'label' => 'Pencarian',
        'desc'  => 'Form pencarian artikel.',
        'default_config' => ['title' => 'Cari', 'placeholder' => 'Cari artikel...'],
    ],
    'last_posts' => [
        'label' => 'Last Post (List Title)',
        'desc'  => 'Daftar artikel terbaru - hanya judul.',
        'default_config' => ['title' => 'Artikel Terbaru', 'limit' => 5, 'type' => 'article'],
    ],
    'editor_pick' => [
        'label' => 'Editor Pick (Card)',
        'desc'  => 'Post pilihan dengan tampilan card/shortcode.',
        'default_config' => ['title' => 'Pilihan Editor', 'limit' => 3, 'layout' => 'cards', 'type' => 'article', 'random' => false],
    ],
    'html' => [
        'label' => 'Custom HTML',
        'desc'  => 'Konten HTML bebas.',
        'default_config' => ['title' => '', 'html' => ''],
    ],
    'categories' => [
        'label' => 'Kategori',
        'desc'  => 'Daftar kategori.',
        'default_config' => ['title' => 'Kategori', 'limit' => 30, 'only_parents' => true],
    ],
    'shortcode_preset' => [
        'label' => 'Shortcode Preset',
        'desc'  => 'Gunakan preset shortcode yang sudah ada.',
        'default_config' => ['title' => '', 'preset_slug' => ''],
    ],
];
$presets = [];
$pst = $pdo->prepare("SELECT slug, title FROM posts WHERE type = 'sc_preset' AND status = 'published' AND is_deleted = 0 ORDER BY title ASC");
$pst->execute();
$presets = $pst->fetchAll(PDO::FETCH_ASSOC);
$cats = [];
$cst = $pdo->query("SELECT id, name, slug FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
if ($cst) $cats = $cst->fetchAll(PDO::FETCH_ASSOC);if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = 'CSRF token tidak valid.';
    }
    $action = (string)($_POST['_action'] ?? 'save');
    if ($action === 'add') {
        $type = (string)($_POST['new_type'] ?? '');
        if (!isset($widget_types[$type])) {
            $errors[] = 'Tipe widget tidak valid.';
        } else {
            $cfg = $widget_types[$type]['default_config'];
            $newTitle = trim((string)($_POST['new_title'] ?? ''));
            if ($newTitle !== '') $cfg['title'] = $newTitle;
            $maxOrder = 0;
            foreach ($current_widgets as $w) {
                if (($w['order'] ?? 0) > $maxOrder) $maxOrder = $w['order'];
            }
            $current_widgets[] = [
                'id'     => bin2hex(random_bytes(8)),
                'type'   => $type,
                'active' => true,
                'order'  => $maxOrder + 1,
                'config' => $cfg,
            ];
            if (!$errors) {
                $encoded = json_encode($current_widgets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (settings_set($pdo, 'sidebar_widgets', $encoded, 1) === false) {
                    $errors[] = 'Gagal menyimpan pengaturan sidebar.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $delId = (string)($_POST['delete_id'] ?? '');
        $current_widgets = array_values(array_filter($current_widgets, fn($w) => ($w['id'] ?? '') !== $delId));
        if (!$errors) {
            $encoded = json_encode($current_widgets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (settings_set($pdo, 'sidebar_widgets', $encoded, 1) === false) {
                $errors[] = 'Gagal menyimpan pengaturan sidebar.';
            }
        }
    } elseif ($action === 'save') {
        $orderRaw = (string)($_POST['_widget_order'] ?? '');
        $orderIds = array_filter(array_map('trim', explode(',', $orderRaw)));
        $newList = [];
        $submitted = (array)($_POST['widget'] ?? []);
        $idx = 0;
        foreach ($orderIds as $wid) {
            $wid = (string)$wid;
            if ($wid === '' || !isset($submitted[$wid])) continue;
            $data = $submitted[$wid];
            $type = (string)($data['type'] ?? '');
            if (!isset($widget_types[$type])) continue;
            $config = (array)($data['config'] ?? []);
            if ($type === 'last_posts') {
                $config['limit'] = max(1, min(50, (int)($config['limit'] ?? 5)));
                $config['type'] = in_array($config['type'] ?? '', ['article','page'], true) ? $config['type'] : 'article';
            }
            if ($type === 'editor_pick') {
                $config['limit'] = max(1, min(20, (int)($config['limit'] ?? 3)));
                $config['layout'] = in_array($config['layout'] ?? '', ['cards','list','card2','sliderpage'], true) ? $config['layout'] : 'cards';
                $config['type'] = in_array($config['type'] ?? '', ['article','page'], true) ? $config['type'] : 'article';
                $config['random'] = !empty($config['random']);
            }
            if ($type === 'categories') {
                $config['limit'] = max(1, min(200, (int)($config['limit'] ?? 30)));
                $config['only_parents'] = !empty($config['only_parents']);
            }
            if ($type === 'html') {
                $config['html'] = (string)($config['html'] ?? '');
            }
            if ($type === 'shortcode_preset') {
                $config['preset_slug'] = (string)($config['preset_slug'] ?? '');
            }
            $newList[] = [
                'id'     => $wid,
                'type'   => $type,
                'active' => !empty($data['active']),
                'order'  => $idx++,
                'config' => $config,
            ];
        }
        $encoded = json_encode($newList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ok = settings_set($pdo, 'sidebar_widgets', $encoded, 1);
        if ($ok === false) {
            $errors[] = 'Gagal menyimpan pengaturan sidebar.';
        } else {
            $current_widgets = $newList;
        }
    }
    if (!$errors) {
        $msg = match ($action) {
            'add'    => 'Widget berhasil ditambahkan.',
            'delete' => 'Widget berhasil dihapus.',
            default  => 'Pengaturan sidebar berhasil disimpan.',
        };
        if (function_exists('adiwira_redirect_with_flash')) {
            adiwira_redirect_with_flash($self_url, 'success', $msg);
        }
        $success_msg = $msg;
    }
}

$show_inline_success = ($success_msg !== '' && !function_exists('adiwira_bootstrap_toasts_script'));
$show_inline_errors  = (!empty($errors) && !function_exists('adiwira_bootstrap_toasts_script'));
?>
<div class="panel" style="max-width:860px;margin:20px auto;">

  <div style="margin-bottom:20px;">
    <h2 style="margin:0 0 4px;">Sidebar Widgets</h2>
    <div class="muted" style="font-size:13px;">Atur konten sidebar - tambah, hapus, urutkan, dan konfigurasi widget.</div>
  </div>

  <?php if ($show_inline_success): ?>
    <div style="background:var(--adam-success);color:#fff;padding:10px 14px;border-radius:var(--adam-radius);margin-bottom:14px;font-size:14px;">&#10004; <?= h($success_msg) ?></div>
  <?php endif; ?>

  <?php if ($show_inline_errors): ?>
    <div style="background:var(--adam-danger);color:#fff;padding:10px 14px;border-radius:var(--adam-radius);margin-bottom:14px;font-size:14px;">
      <ul style="margin:0;padding-left:18px"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <!-- Add Widget Form -->
  <div id="add-widget-box" style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:16px;margin-bottom:20px;">
    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="add">
      <strong style="font-size:14px;white-space:nowrap;">+ Tambah Widget</strong>
      <select name="new_type" required style="padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        <option value="">- Pilih tipe -</option>
        <?php foreach ($widget_types as $wt => $wi): ?>
          <option value="<?= h($wt) ?>"><?= h($wi['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="new_title" placeholder="Judul widget (opsional)" style="flex:1;min-width:160px;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
      <button type="submit" class="adam-button" style="padding:6px 16px;white-space:nowrap;">Tambah</button>
    </form>
  </div>  <!-- Existing Widgets -->
  <form method="post" id="sidebar-widgets-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="_widget_order" id="widget-order" value="<?= h(implode(',', array_map(fn($w) => $w['id'] ?? '', $current_widgets))) ?>">

    <div id="sidebar-widgets-list">
      <?php if (empty($current_widgets)): ?>
        <div style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:24px;text-align:center;color:var(--adam-muted);font-size:14px;">
          Belum ada widget. Tambah widget baru di atas.
        </div>
      <?php else: ?>
        <?php foreach ($current_widgets as $wi => $w):
          $wid = $w['id'] ?? '';
          $type = $w['type'] ?? '';
          $active = !empty($w['active']);
          $config = (array)($w['config'] ?? []);
          $typeInfo = $widget_types[$type] ?? ['label' => ucfirst($type), 'desc' => ''];
          $isOpen = (string)($_GET['edit'] ?? '') === $wid;
        ?>
          <div class="sw-item" data-id="<?= h($wid) ?>" style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);margin-bottom:10px;<?= $active ? '' : 'opacity:.55;' ?>">
            <input type="hidden" name="widget[<?= h($wid) ?>][type]" value="<?= h($type) ?>">

            <!-- Header bar -->
            <div class="sw-header" style="display:flex;align-items:center;gap:8px;padding:10px 14px;cursor:pointer;user-select:none;" onclick="toggleWidget(this)">
              <span class="sw-drag" style="cursor:grab;color:var(--adam-muted);font-size:18px;line-height:1;" title="Seret untuk urutkan">&#x283F;</span>
              <span class="sw-badge" style="background:var(--adam-primary-soft,var(--adam-surface-3));color:var(--adam-primary,var(--adam-text));padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;white-space:nowrap;"><?= h($typeInfo['label']) ?></span>
              <span class="sw-title" style="flex:1;font-weight:600;font-size:14px;color:var(--adam-text);"><?= h($config['title'] ?? $typeInfo['label']) ?></span>
              <input type="hidden" name="widget[<?= h($wid) ?>][active]" value="0">
              <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;white-space:nowrap;">
                <input type="checkbox" name="widget[<?= h($wid) ?>][active]" value="1" <?= $active ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--adam-primary);cursor:pointer;" onchange="this.closest('.sw-item').style.opacity=this.checked?'':'0.55'">
                Aktif
              </label>
              <div style="display:flex;gap:4px;">
                <button type="button" class="sw-up" onclick="moveWidget(this,-1)" style="background:none;border:1px solid var(--adam-border-2);border-radius:4px;padding:2px 6px;cursor:pointer;font-size:13px;color:var(--adam-muted);" title="Naik">&#x25B2;</button>
                <button type="button" class="sw-down" onclick="moveWidget(this,1)" style="background:none;border:1px solid var(--adam-border-2);border-radius:4px;padding:2px 6px;cursor:pointer;font-size:13px;color:var(--adam-muted);" title="Turun">&#x25BC;</button>
              </div>
              <button type="button" class="sw-delete" onclick="deleteWidget(this)" style="background:none;border:none;cursor:pointer;color:var(--adam-danger);font-size:18px;line-height:1;padding:2px 4px;" title="Hapus widget">&#x2715;</button>
            </div>

            <!-- Edit fields (collapsible) -->
            <div class="sw-body" style="border-top:1px solid var(--adam-border);padding:14px;display:<?= $isOpen ? 'block' : 'none' ?>;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:600px;">

                <!-- Common: title -->
                <div style="grid-column:1/-1;">
                  <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Judul Widget</label>
                  <input type="text" name="widget[<?= h($wid) ?>][config][title]" value="<?= h($config['title'] ?? '') ?>" placeholder="<?= h($typeInfo['label']) ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                </div>

                <?php if ($type === 'search'): ?>
                  <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Placeholder</label>
                    <input type="text" name="widget[<?= h($wid) ?>][config][placeholder]" value="<?= h($config['placeholder'] ?? 'Cari artikel...') ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                  </div>

                <?php elseif ($type === 'last_posts'): ?>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Jumlah</label>
                    <input type="number" name="widget[<?= h($wid) ?>][config][limit]" value="<?= (int)($config['limit'] ?? 5) ?>" min="1" max="50" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Tipe</label>
                    <select name="widget[<?= h($wid) ?>][config][type]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value="article" <?= ($config['type'] ?? 'article') === 'article' ? 'selected' : '' ?>>Artikel</option>
                      <option value="page" <?= ($config['type'] ?? '') === 'page' ? 'selected' : '' ?>>Halaman</option>
                    </select>
                  </div>                <?php elseif ($type === 'editor_pick'): ?>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Jumlah</label>
                    <input type="number" name="widget[<?= h($wid) ?>][config][limit]" value="<?= (int)($config['limit'] ?? 3) ?>" min="1" max="20" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Layout</label>
                    <select name="widget[<?= h($wid) ?>][config][layout]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <?php foreach (['cards' => 'Cards', 'list' => 'List', 'card2' => 'Card 2', 'sliderpage' => 'Slider'] as $lv => $ll): ?>
                        <option value="<?= h($lv) ?>" <?= ($config['layout'] ?? 'cards') === $lv ? 'selected' : '' ?>><?= h($ll) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Tipe</label>
                    <select name="widget[<?= h($wid) ?>][config][type]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value="article" <?= ($config['type'] ?? 'article') === 'article' ? 'selected' : '' ?>>Artikel</option>
                      <option value="page" <?= ($config['type'] ?? '') === 'page' ? 'selected' : '' ?>>Halaman</option>
                    </select>
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Kategori (opsional)</label>
                    <select name="widget[<?= h($wid) ?>][config][category]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value="">- Semua -</option>
                      <?php foreach ($cats as $c): ?>
                        <option value="<?= h($c['slug']) ?>" <?= ($config['category'] ?? '') === $c['slug'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                      <input type="hidden" name="widget[<?= h($wid) ?>][config][random]" value="0">
                      <input type="checkbox" name="widget[<?= h($wid) ?>][config][random]" value="1" <?= !empty($config['random']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--adam-primary);cursor:pointer;">
                      Acak (random)
                    </label>
                  </div>

                <?php elseif ($type === 'html'): ?>
                  <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Konten HTML</label>
                    <textarea name="widget[<?= h($wid) ?>][config][html]" rows="6" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;font-family:monospace;resize:vertical;"><?= h($config['html'] ?? '') ?></textarea>
                  </div>

                <?php elseif ($type === 'categories'): ?>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Jumlah</label>
                    <input type="number" name="widget[<?= h($wid) ?>][config][limit]" value="<?= (int)($config['limit'] ?? 30) ?>" min="1" max="200" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                  </div>
                  <div style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                      <input type="hidden" name="widget[<?= h($wid) ?>][config][only_parents]" value="0">
                      <input type="checkbox" name="widget[<?= h($wid) ?>][config][only_parents]" value="1" <?= !empty($config['only_parents']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--adam-primary);cursor:pointer;">
                      Hanya kategori induk (parent)
                    </label>
                  </div>

                <?php elseif ($type === 'shortcode_preset'): ?>
                  <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Pilih Preset</label>
                    <select name="widget[<?= h($wid) ?>][config][preset_slug]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value="">- Pilih -</option>
                      <?php foreach ($presets as $p): ?>
                        <option value="<?= h($p['slug']) ?>" <?= ($config['preset_slug'] ?? '') === $p['slug'] ? 'selected' : '' ?>><?= h($p['title']) ?> (<?= h($p['slug']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (empty($presets)): ?>
                      <div style="margin-top:6px;font-size:12px;color:var(--adam-muted);">Belum ada preset. <a href="<?= h($base . '/index.php?page=admin/shortcodes/edit') ?>">Buat preset</a> terlebih dulu.</div>
                    <?php endif; ?>
                  </div>

                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;">
      <button type="submit" class="adam-button" style="padding:8px 24px;">Simpan Semua Widget</button>
      <a class="adam-cancle" href="<?= h($base . '/index.php?page=admin/settings/index') ?>">Kembali ke Settings</a>
    </div>
  </form>  <!-- Delete form (hidden, submitted by JS) -->
  <form id="sw-delete-form" method="post" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="delete">
    <input type="hidden" name="delete_id" id="sw-delete-id">
  </form>

  <div style="background:var(--adam-surface-3);border-radius:var(--adam-radius);padding:14px 16px;font-size:12px;color:var(--adam-muted);line-height:1.7;">
    <strong style="font-size:13px;">📖 Cara Menggunakan Sidebar Widgets</strong>
    <ol style="margin:6px 0 0;padding-left:20px;">
      <li><strong>Tambah widget</strong> — pilih tipe dari dropdown, lalu klik <strong>Tambah</strong>. Widget langsung tersimpan.</li>
      <li><strong>Konfigurasi</strong> — klik judul widget untuk memperluas form edit. Ubah title, limit, layout, dsb.</li>
      <li><strong>Urutkan</strong> — gunakan tombol ▲ (naik) / ▼ (turun) untuk mengubah posisi widget.</li>
      <li><strong>Aktif/Nonaktif</strong> — centang checkbox <strong>Aktif</strong> untuk menampilkan atau menyembunyikan widget.</li>
      <li><strong>Hapus</strong> — klik ✕ untuk menghapus widget dari sidebar.</li>
      <li>Setelah selesai mengubah konfigurasi atau urutan, klik <strong>Simpan Semua Widget</strong> untuk menyimpan perubahan.</li>
      <li>Hasil perubahan langsung terlihat di halaman depan website.</li>
    </ol>
  </div>
</div>

<script>
function toggleWidget(header) {
  var body = header.nextElementSibling;
  if (body && body.classList.contains('sw-body')) {
    body.style.display = body.style.display === 'none' ? 'block' : 'none';
  }
}

function moveWidget(btn, dir) {
  var item = btn.closest('.sw-item');
  if (!item) return;
  var list = document.getElementById('sidebar-widgets-list');
  var items = list.querySelectorAll('.sw-item');
  var idx = Array.prototype.indexOf.call(items, item);
  var target = idx + dir;
  if (target < 0 || target >= items.length) return;
  if (dir < 0) {
    list.insertBefore(item, items[target]);
  } else {
    list.insertBefore(item, items[target].nextSibling);
  }
  updateOrder();
}

function deleteWidget(btn) {
  if (!confirm('Hapus widget ini dari sidebar?')) return;
  var item = btn.closest('.sw-item');
  if (!item) return;
  var id = item.getAttribute('data-id');
  document.getElementById('sw-delete-id').value = id;
  document.getElementById('sw-delete-form').submit();
}

function updateOrder() {
  var items = document.querySelectorAll('#sidebar-widgets-list .sw-item');
  var ids = [];
  items.forEach(function(el) {
    var id = el.getAttribute('data-id');
    if (id) ids.push(id);
  });
  document.getElementById('widget-order').value = ids.join(',');
}

document.addEventListener('DOMContentLoaded', function() {
  var observer = new MutationObserver(function() { updateOrder(); });
  var list = document.getElementById('sidebar-widgets-list');
  if (list) observer.observe(list, { childList: true, subtree: false });
});
</script>

<style>
.sw-item { transition: opacity .2s ease; }
.sw-header { border-radius: var(--adam-radius, 8px); }
.sw-drag { touch-action: none; }
.sw-delete:hover { transform: scale(1.2); }
.sw-up:hover, .sw-down:hover { background: var(--adam-surface-2, #eee) !important; }
</style>