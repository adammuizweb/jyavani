<?php
declare(strict_types=1);

// /adiwira/admin/menus/index.php
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['editor', 'admin'], false);

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$csrf = csrf_token();

$allMenus = function_exists('menu_get_all') ? menu_get_all($pdo) : [];

$selectedMenuId = (int)($_GET['menu_id'] ?? 0);
$selectedMenu = null;
$menuTree = [];
$menuItems = [];

if ($selectedMenuId > 0) {
    foreach ($allMenus as $m) {
        if ((int)$m['id'] === $selectedMenuId) {
            $selectedMenu = $m;
            break;
        }
    }
}

if (!$selectedMenu && !empty($allMenus)) {
    $selectedMenu = $allMenus[0];
    $selectedMenuId = (int)$selectedMenu['id'];
}

if ($selectedMenu && function_exists('menu_build_tree')) {
    $menuTree = menu_build_tree($pdo, $selectedMenuId);
    $menuItems = menu_get_items($pdo, $selectedMenuId);
}

// Fetch available items for adding to menu
$articles = [];
$pages = [];
$categories = [];

try {
    $st = $pdo->query("SELECT id, title, slug FROM posts WHERE type='article' AND is_deleted=0 ORDER BY title ASC LIMIT 200");
    $articles = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $pdo->query("SELECT id, title, slug FROM posts WHERE type='page' AND is_deleted=0 ORDER BY title ASC LIMIT 200");
    $pages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $pdo->query("SELECT id, name, slug FROM categories WHERE is_deleted=0 ORDER BY name ASC LIMIT 200");
    $categories = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

if (!function_exists('render_menu_items_admin')) {
    function render_menu_items_admin(array $items, int $depth): string {
        if (empty($items)) return '';
        $html = '<ul class="menu-sortable"' . ($depth > 0 ? ' style="margin-left:20px;"' : '') . '>';
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $label = htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $type = htmlspecialchars((string)($item['type'] ?? 'custom'), ENT_QUOTES, 'UTF-8');
            $hasChildren = !empty($item['children']);
            $html .= '<li class="menu-item-admin" data-id="' . $id . '">';
            $html .= '<div class="menu-item-bar">';
            $html .= '<span class="menu-item-handle">&#9776;</span>';
            $html .= '<span class="menu-item-label">' . $label . '</span>';
            $html .= '<span class="menu-item-type">' . $type . '</span>';
            $html .= '<button type="button" class="menu-item-edit adam-ubah" data-id="' . $id . '">Edit</button>';
            $html .= '<button type="button" class="menu-item-remove adam-hapus" data-id="' . $id . '">Hapus</button>';
            $html .= '</div>';
            if ($hasChildren) {
                $html .= render_menu_items_admin($item['children'], $depth + 1);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}
?>
<section class="adam-card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
    <div>
      <h2 style="margin:0">Menu Manager</h2>
      <div style="margin-top:6px;font-size:12px" class="pht-muted">
        Buat dan kelola menu navigasi seperti WordPress.
      </div>
    </div>
  </div>

  <hr style="margin:16px 0;border:none;border-top:1px solid var(--adam-border-softer);">

  <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;">

    <!-- LEFT: Menu Structure -->
    <div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
        <label style="font-weight:600;">Pilih Menu:</label>
        <select id="menuSelect" onchange="if(this.value) window.location.href='?page=admin/menus/index&menu_id='+this.value" class="pht-select" style="min-width:200px;">
          <option value="">-- Pilih Menu --</option>
          <?php foreach ($allMenus as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= $selectedMenuId === (int)$m['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($m['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              <?= !empty($m['is_default']) ? ' (Default)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?php if ($selectedMenu && empty($selectedMenu['is_default'])): ?>
          <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/save.php', ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="set_default">
            <input type="hidden" name="menu_id" value="<?= (int)$selectedMenu['id'] ?>">
            <button type="submit" class="adam-button" style="padding:6px 12px;font-size:12px;">Set Default</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($selectedMenu): ?>
        <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span style="font-weight:600;">Menu:</span>
          <span id="menuNameDisplay"><?= htmlspecialchars((string)($selectedMenu['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
          <span style="color:var(--adam-muted);font-size:12px;">(slug: <?= htmlspecialchars((string)($selectedMenu['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</span>
          <button type="button" id="btnRenameMenu" class="adam-ubah" style="font-size:12px;">Ganti Nama</button>

          <?php if (count($allMenus) > 1): ?>
            <span style="margin-left:auto;">
              <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:inline;" onsubmit="return confirm('Hapus menu &quot;<?= htmlspecialchars((string)($selectedMenu['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>&quot;? Semua item akan ikut terhapus.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="menu_id" value="<?= (int)$selectedMenu['id'] ?>">
                <button type="submit" class="adam-hapus" style="font-size:12px;">Hapus Menu</button>
              </form>
            </span>
          <?php endif; ?>
        </div>

        <!-- Menu Items Tree -->
        <div id="menuItemsContainer" style="border:1px solid var(--adam-border-2);border-radius:12px;padding:12px;background:var(--adam-surface-4);min-height:100px;">
          <?php if (empty($menuTree)): ?>
            <div style="text-align:center;padding:24px;color:var(--adam-muted);font-size:13px;">
              Belum ada item menu. Tambah item dari panel sebelah kanan.
            </div>
          <?php else: ?>
            <?= render_menu_items_admin($menuTree, 0) ?>
          <?php endif; ?>
        </div>

        <div id="menuItemEditForm" style="display:none;margin-top:16px;padding:16px;border:1px solid var(--adam-border-2);border-radius:12px;background:var(--adam-surface-4);">
          <h4 style="margin:0 0 12px 0;">Edit Item</h4>
          <input type="hidden" id="editItemId" value="">
          <div style="display:grid;gap:8px;">
            <div>
              <label style="display:block;font-size:12px;margin-bottom:4px;">Label</label>
              <input id="editItemLabel" class="pht-input" placeholder="Label menu">
            </div>
            <div>
              <label style="display:block;font-size:12px;margin-bottom:4px;">URL (hanya untuk custom link)</label>
              <input id="editItemUrl" class="pht-input" placeholder="https://...">
            </div>
            <div>
              <label style="display:block;font-size:12px;margin-bottom:4px;">
                <input type="checkbox" id="editItemTargetBlank"> Buka di tab baru
              </label>
            </div>
            <div style="display:flex;gap:8px;">
              <button type="button" id="saveItemEdit" class="adam-button" style="padding:6px 16px;">Simpan</button>
              <button type="button" id="cancelItemEdit" class="adam-cancle" style="padding:6px 16px;">Batal</button>
            </div>
          </div>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;">
          <button type="button" id="btnSaveItems" class="adam-button">Simpan Semua Item</button>
          <span id="saveItemsStatus" style="font-size:12px;color:var(--adam-muted);align-self:center;"></span>
        </div>

      <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--adam-muted);">
          Belum ada menu. Buat menu baru dari panel sebelah kanan.
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Add Items + New Menu -->
    <div>
      <!-- Create New Menu -->
      <div style="border:1px solid var(--adam-border-2);border-radius:12px;padding:16px;margin-bottom:16px;background:var(--adam-surface-4);">
        <h4 style="margin:0 0 12px 0;">Buat Menu Baru</h4>
        <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/save.php', ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="create">
          <div style="display:flex;gap:8px;flex-direction:column;">
            <input type="text" name="name" class="pht-input" placeholder="Nama menu (contoh: Primary)" required>
            <input type="text" name="slug" class="pht-input" placeholder="Slug (contoh: primary)" required>
            <button type="submit" class="adam-button">Buat Menu</button>
          </div>
        </form>
      </div>

      <?php if ($selectedMenu): ?>
      <!-- Add Items -->
      <div style="border:1px solid var(--adam-border-2);border-radius:12px;padding:16px;background:var(--adam-surface-4);">
        <h4 style="margin:0 0 12px 0;">Tambah Item</h4>

        <div style="display:flex;gap:4px;margin-bottom:12px;flex-wrap:wrap;">
          <button type="button" class="add-item-tab adam-button" data-tab="custom" style="padding:4px 10px;font-size:12px;">Custom Link</button>
          <button type="button" class="add-item-tab adam-button" data-tab="article" style="padding:4px 10px;font-size:12px;">Artikel</button>
          <button type="button" class="add-item-tab adam-button" data-tab="page" style="padding:4px 10px;font-size:12px;">Halaman</button>
          <button type="button" class="add-item-tab adam-button" data-tab="category" style="padding:4px 10px;font-size:12px;">Kategori</button>
        </div>

        <!-- Custom Link -->
        <div class="add-item-panel" id="panel-custom">
          <div style="display:grid;gap:8px;">
            <input type="text" id="customLabel" class="pht-input" placeholder="Label">
            <input type="url" id="customUrl" class="pht-input" placeholder="https://...">
            <label style="font-size:12px;"><input type="checkbox" id="customTargetBlank"> Buka di tab baru</label>
            <button type="button" class="add-item-btn adam-button" data-type="custom">Tambah ke Menu</button>
          </div>
        </div>

        <!-- Articles -->
        <div class="add-item-panel" id="panel-article" style="display:none;">
          <input type="text" id="articleSearch" class="pht-input" placeholder="Cari artikel..." style="margin-bottom:8px;">
          <div class="source-list" id="articleList" style="max-height:200px;overflow-y:auto;">
            <?php if (empty($articles)): ?>
              <div style="font-size:12px;color:var(--adam-muted);">Tidak ada artikel.</div>
            <?php else: ?>
              <?php foreach ($articles as $a): ?>
                <div class="source-item" data-id="<?= (int)$a['id'] ?>" data-type="article" data-label="<?= htmlspecialchars((string)($a['title'] ?: $a['slug']), ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 8px;cursor:pointer;border-radius:6px;font-size:13px;">
                  <?= htmlspecialchars((string)($a['title'] ?: $a['slug']), ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Pages -->
        <div class="add-item-panel" id="panel-page" style="display:none;">
          <input type="text" id="pageSearch" class="pht-input" placeholder="Cari halaman..." style="margin-bottom:8px;">
          <div class="source-list" id="pageList" style="max-height:200px;overflow-y:auto;">
            <?php if (empty($pages)): ?>
              <div style="font-size:12px;color:var(--adam-muted);">Tidak ada halaman.</div>
            <?php else: ?>
              <?php foreach ($pages as $p): ?>
                <div class="source-item" data-id="<?= (int)$p['id'] ?>" data-type="page" data-label="<?= htmlspecialchars((string)($p['title'] ?: $p['slug']), ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 8px;cursor:pointer;border-radius:6px;font-size:13px;">
                  <?= htmlspecialchars((string)($p['title'] ?: $p['slug']), ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Categories -->
        <div class="add-item-panel" id="panel-category" style="display:none;">
          <input type="text" id="categorySearch" class="pht-input" placeholder="Cari kategori..." style="margin-bottom:8px;">
          <div class="source-list" id="categoryList" style="max-height:200px;overflow-y:auto;">
            <?php if (empty($categories)): ?>
              <div style="font-size:12px;color:var(--adam-muted);">Tidak ada kategori.</div>
            <?php else: ?>
              <?php foreach ($categories as $c): ?>
                <div class="source-item" data-id="<?= (int)$c['id'] ?>" data-type="category" data-label="<?= htmlspecialchars((string)($c['name'] ?: $c['slug']), ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 8px;cursor:pointer;border-radius:6px;font-size:13px;">
                  <?= htmlspecialchars((string)($c['name'] ?: $c['slug']), ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</section>

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<!-- Rename Menu Modal -->
<div id="renameModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;" onclick="if(event.target===this)document.getElementById('renameModal').style.display='none'">
  <div style="background:var(--adam-card);padding:24px;border-radius:16px;min-width:320px;max-width:90vw;" onclick="event.stopPropagation()">
    <h4 style="margin:0 0 12px 0;">Ganti Nama Menu</h4>
    <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/save.php', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="rename">
      <input type="hidden" name="menu_id" value="<?= $selectedMenuId ?>">
      <div style="display:grid;gap:8px;">
        <label style="font-size:12px;">Nama Baru</label>
        <input type="text" name="name" class="pht-input" value="<?= htmlspecialchars((string)($selectedMenu['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
        <label style="font-size:12px;">Slug Baru</label>
        <input type="text" name="slug" class="pht-input" value="<?= htmlspecialchars((string)($selectedMenu['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
        <div style="display:flex;gap:8px;margin-top:8px;">
          <button type="submit" class="adam-button">Simpan</button>
          <button type="button" class="adam-cancle" onclick="document.getElementById('renameModal').style.display='none'">Batal</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script src="/adiwira/static/js/add/modal-helpers.js"></script>
<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const MENU_ID = <?= json_encode($selectedMenuId) ?>;
  const SAVE_URL = <?= json_encode($base . '/admin/menus/items_save.php') ?>;

  const container = document.getElementById('menuItemsContainer');
  const saveBtn = document.getElementById('btnSaveItems');
  const saveStatus = document.getElementById('saveItemsStatus');

  function toast(type, message, title){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
      return;
    }
    alert(message);
  }

  // Tab switching for add-item panels
  document.querySelectorAll('.add-item-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      const tab = this.getAttribute('data-tab');
      document.querySelectorAll('.add-item-panel').forEach(function(p){ p.style.display = 'none'; });
      document.getElementById('panel-' + tab).style.display = '';
    });
  });

  // Source item click to add to menu
  document.querySelectorAll('.source-item').forEach(function(item){
    item.addEventListener('click', function(){
      const id = this.getAttribute('data-id');
      const type = this.getAttribute('data-type');
      const label = this.getAttribute('data-label');

      addItemToMenu(type, label, id, '');
    });
  });

  // Custom link add
  document.querySelector('.add-item-btn') && document.querySelector('.add-item-btn').addEventListener('click', function(){
    const label = document.getElementById('customLabel').value.trim();
    const url = document.getElementById('customUrl').value.trim();
    const targetBlank = document.getElementById('customTargetBlank').checked;

    if (!label || !url) {
      toast('error', 'Label dan URL harus diisi');
      return;
    }

    addItemToMenu('custom', label, '0', url, targetBlank);
    document.getElementById('customLabel').value = '';
    document.getElementById('customUrl').value = '';
    document.getElementById('customTargetBlank').checked = false;
  });

  // Search filtering
  function setupSearch(inputId, listId) {
    const input = document.getElementById(inputId);
    const list = document.getElementById(listId);
    if (!input || !list) return;
    input.addEventListener('input', function(){
      const q = this.value.toLowerCase();
      list.querySelectorAll('.source-item').forEach(function(el){
        el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  }
  setupSearch('articleSearch', 'articleList');
  setupSearch('pageSearch', 'pageList');
  setupSearch('categorySearch', 'categoryList');

  // Add item to menu structure
  function addItemToMenu(type, label, targetId, url, targetBlank){
    const emptyMsg = container.querySelector('div[style*="text-align:center"]');
    if (emptyMsg) emptyMsg.remove();

    let ul = container.querySelector('ul.menu-sortable');
    if (!ul) {
      ul = document.createElement('ul');
      ul.className = 'menu-sortable';
      container.appendChild(ul);
    }

    const li = document.createElement('li');
    li.className = 'menu-item-admin';
    li.setAttribute('data-new-item', '1');
    li.innerHTML = '<div class="menu-item-bar">'
      + '<span class="menu-item-handle">&#9776;</span>'
      + '<span class="menu-item-label">' + escapeHtml(label) + '</span>'
      + '<span class="menu-item-type">' + escapeHtml(type) + '</span>'
      + '<button type="button" class="menu-item-remove adam-hapus" title="Hapus">Hapus</button>'
      + '</div>'
      + '<input type="hidden" class="item-type" value="' + escapeHtml(type) + '">'
      + '<input type="hidden" class="item-label" value="' + escapeHtml(label) + '">'
      + '<input type="hidden" class="item-target" value="' + escapeHtml(targetId) + '">'
      + '<input type="hidden" class="item-url" value="' + escapeHtml(url || '') + '">'
      + '<input type="hidden" class="item-target-blank" value="' + (targetBlank ? '1' : '0') + '">';

    ul.appendChild(li);

    toast('success', 'Item "' + label + '" ditambahkan ke menu');
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }

  // Remove item from menu structure
  container.addEventListener('click', function(e){
    const btn = e.target.closest('.menu-item-remove');
    if (!btn) return;

    const li = btn.closest('.menu-item-admin');
    if (li && confirm('Hapus item ini dari menu?')) {
      li.remove();
      // Show empty state if no items left
      if (!container.querySelector('ul.menu-sortable') || !container.querySelector('ul.menu-sortable').querySelector('li')) {
        container.innerHTML = '<div style="text-align:center;padding:24px;color:var(--adam-muted);font-size:13px;">Belum ada item menu. Tambah item dari panel sebelah kanan.</div>';
      }
    }
  });

  // Save all items via AJAX
  saveBtn && saveBtn.addEventListener('click', function(){
    const items = collectItems();
    saveBtn.disabled = true;
    saveStatus.textContent = 'Menyimpan...';

    fetch(SAVE_URL, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        csrf_token: CSRF,
        menu_id: MENU_ID,
        items: items
      }),
      credentials: 'same-origin'
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (data && data.ok) {
        toast('success', 'Menu berhasil disimpan');
        // Reload page to reflect changes properly
        setTimeout(function(){ window.location.reload(); }, 600);
      } else {
        toast('error', (data && data.error) ? data.error : 'Gagal menyimpan');
        saveBtn.disabled = false;
        saveStatus.textContent = '';
      }
    })
    .catch(function(err){
      toast('error', 'Error: ' + err.message);
      saveBtn.disabled = false;
      saveStatus.textContent = '';
    });
  });

  function collectItems(){
    const items = [];
    const uls = container.querySelectorAll('ul.menu-sortable');
    uls.forEach(function(ul){
      const parentLi = ul.closest('.menu-item-admin');
      const parentId = parentLi ? (parentLi.getAttribute('data-id') || 'new') : '0';

      ul.querySelectorAll(':scope > li.menu-item-admin').forEach(function(li, idx){
        const isNew = li.getAttribute('data-new-item') === '1';
        const id = isNew ? null : (li.getAttribute('data-id') || null);
        const typeEl = li.querySelector('.item-type');
        const labelEl = li.querySelector('.item-label');
        const targetEl = li.querySelector('.item-target');
        const urlEl = li.querySelector('.item-url');
        const targetBlankEl = li.querySelector('.item-target-blank');

        const item = {
          id: id ? parseInt(id) : null,
          parent_id: parentId !== '0' ? parseInt(parentId) : null,
          sort_order: idx,
          type: typeEl ? typeEl.value : 'custom',
          label: labelEl ? labelEl.value : '',
          target_id: targetEl ? parseInt(targetEl.value) : 0,
          url: urlEl ? urlEl.value : '',
          target_blank: targetBlankEl ? parseInt(targetBlankEl.value) : 0
        };
        items.push(item);

        // Process children
        const childUl = li.querySelector(':scope > ul.menu-sortable');
        if (childUl) {
          childUl.querySelectorAll(':scope > li.menu-item-admin').forEach(function(childLi, childIdx){
            const childIsNew = childLi.getAttribute('data-new-item') === '1';
            items.push({
              id: childIsNew ? null : (childLi.getAttribute('data-id') ? parseInt(childLi.getAttribute('data-id')) : null),
              parent_id: id ? parseInt(id) : null,
              sort_order: childIdx,
              type: childLi.querySelector('.item-type') ? childLi.querySelector('.item-type').value : 'custom',
              label: childLi.querySelector('.item-label') ? childLi.querySelector('.item-label').value : '',
              target_id: childLi.querySelector('.item-target') ? parseInt(childLi.querySelector('.item-target').value) : 0,
              url: childLi.querySelector('.item-url') ? childLi.querySelector('.item-url').value : '',
              target_blank: childLi.querySelector('.item-target-blank') ? parseInt(childLi.querySelector('.item-target-blank').value) : 0
            });
          });
        }
      });
    });
    return items;
  }

  // Rename menu button
  document.getElementById('btnRenameMenu') && document.getElementById('btnRenameMenu').addEventListener('click', function(){
    document.getElementById('renameModal').style.display = 'flex';
  });
})();
</script>

<style>
.menu-sortable {
  list-style: none;
  margin: 0;
  padding: 0;
}
.menu-item-admin {
  margin: 4px 0;
  padding: 0;
}
.menu-item-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--adam-card);
  border: 1px solid var(--adam-border);
  border-radius: 8px;
  font-size: 13px;
  cursor: default;
}
.menu-item-handle {
  cursor: grab;
  color: var(--adam-muted);
  font-size: 16px;
  user-select: none;
}
.menu-item-handle:active {
  cursor: grabbing;
}
.menu-item-label {
  flex: 1;
  font-weight: 500;
}
.menu-item-type {
  font-size: 11px;
  color: var(--adam-muted);
  background: var(--adam-surface-4);
  padding: 2px 6px;
  border-radius: 4px;
  text-transform: uppercase;
}
.menu-item-remove {
  font-size: 12px;
  padding: 2px 8px;
}
.source-item:hover {
  background: var(--adam-surface-3);
}
.source-item:active {
  background: var(--adam-border-2);
}
@media (max-width:768px){
  .menu-layout{ grid-template-columns:1fr; }
}
</style>
