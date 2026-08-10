<?php
declare(strict_types=1);

// /adiwira/admin/menus/?
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

$base = ADMIN_BASE_PATH;
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
$menuTranslations = $selectedMenu && function_exists('ct_menu_item_translations_for_menu')
    ? ct_menu_item_translations_for_menu($pdo, $selectedMenuId)
    : [];
$translationLocales = function_exists('ct_enabled_locales') ? ct_enabled_locales($pdo) : [];

// Fetch available items for adding to menu
$articles = [];
$pages = [];
$themePages = [];
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
    $st = $pdo->query("SELECT p.id, p.title, p.slug, cr.path AS public_path FROM posts p INNER JOIN content_routes cr ON cr.post_id = p.id AND cr.locale = '' AND cr.canonical_slot = 1 WHERE p.type='theme' AND p.status='published' AND p.is_deleted=0 ORDER BY p.title ASC LIMIT 200");
    $themePages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

try {
    $st = $pdo->query("SELECT id, name, slug FROM categories WHERE is_deleted=0 ORDER BY name ASC LIMIT 200");
    $categories = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

if (!function_exists('render_menu_items_admin')) {
    function render_menu_items_admin(array $items, int $depth, array $translations = []): string {
        if (empty($items)) return '';
        $html = '<ul class="menu-sortable"' . ($depth > 0 ? ' style="margin-left:20px;"' : '') . '>';
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $label = htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $type = htmlspecialchars((string)($item['type'] ?? 'custom'), ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars((string)($item['url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $targetId = (int)($item['target_id'] ?? 0);
            $targetBlank = !empty($item['target_blank']) ? '1' : '0';
            $hidden = !empty($item['hidden']) ? '1' : '0';
            $hasChildren = !empty($item['children']);
            $localized = htmlspecialchars((string)json_encode($translations[$id] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
            $html .= '<li class="menu-item-admin' . ($hidden === '1' ? ' menu-item-hidden' : '') . '" data-id="' . $id . '" data-type="' . $type . '" data-label="' . $label . '" data-target="' . $targetId . '" data-url="' . $url . '" data-target-blank="' . $targetBlank . '" data-hidden="' . $hidden . '" data-translations="' . $localized . '">';
            $html .= '<div class="menu-item-bar">';
            $html .= '<span class="menu-item-handle">&#9776;</span>';
            $html .= '<span class="menu-item-label">' . $label . '</span>';
            $html .= '<span class="menu-item-type">' . $type . '</span>';
            $html .= '<button type="button" class="menu-item-hide adam-ubah" title="' . __('Toggle visibility') . '">' . ($hidden === '1' ? '&#128064;' : '&#128065;') . '</button>';
            $html .= '<button type="button" class="menu-item-indent adam-ubah" title="' . __('Make sub-menu') . '">&#8594;</button>';
            $html .= '<button type="button" class="menu-item-outdent adam-ubah" title="' . __('Raise level') . '">&#8592;</button>';
            $html .= '<button type="button" class="menu-item-edit adam-ubah" title="' . __('Edit') . '">&#9998;</button>';
            $html .= '<button type="button" class="menu-item-remove adam-hapus" title="' . __('Delete') . '">&#10005;</button>';
            $html .= '</div>';
            if ($hasChildren) {
                $html .= render_menu_items_admin($item['children'], $depth + 1, $translations);
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
      <h2 style="margin:0"><?=_e('Menu Manager')?></h2>
      <div style="margin-top:6px;font-size:12px" class="pht-muted">
        <?=_e('Create and manage navigation menus like WordPress.')?>
      </div>
    </div>
  </div>

  <hr style="margin:16px 0;border:none;border-top:1px solid var(--adam-border-softer);">

  <div class="menus-grid" style="display:grid;grid-template-columns:1fr 320px;gap:20px;">

    <!-- LEFT: Menu Structure -->
    <div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
        <label style="font-weight:600;"><?=_e('Select Menu:')?></label>
        <select id="menuSelect" onchange="if(this.value) window.location.href='<?= htmlspecialchars($base . '/?page=admin/menus/index&menu_id=', ENT_QUOTES, 'UTF-8') ?>'+this.value" class="pht-select" style="min-width:200px;">
          <option value=""><?=_e('-- Select Menu --')?></option>
          <?php foreach ($allMenus as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= $selectedMenuId === (int)$m['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($m['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
              <?= !empty($m['is_default']) ? ' ' . __('(Default)') : '' ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?php if ($selectedMenu && empty($selectedMenu['is_default'])): ?>
          <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/save.php', ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="set_default">
            <input type="hidden" name="menu_id" value="<?= (int)$selectedMenu['id'] ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($base . '/?page=admin/menus/index&menu_id=' . $selectedMenuId, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="adam-button" style="padding:6px 12px;font-size:12px;"><?=_e('Set Default')?></button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($selectedMenu): ?>
        <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span style="font-weight:600;"><?=_e('Menu:')?></span>
          <span id="menuNameDisplay"><?= htmlspecialchars((string)($selectedMenu['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
          <span style="color:var(--adam-muted);font-size:12px;">(slug: <?= htmlspecialchars((string)($selectedMenu['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</span>
          <button type="button" id="btnRenameMenu" class="adam-ubah" style="font-size:12px;"><?=_e('Rename')?></button>

          <?php if (count($allMenus) > 1): ?>
            <span style="margin-left:auto;">
              <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/delete.php', ENT_QUOTES, 'UTF-8') ?>" style="display:inline;" onsubmit="return confirm('<?=__('Delete menu')?> &quot;<?= htmlspecialchars((string)($selectedMenu['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>&quot;? <?=__('All items will be deleted.')?>');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="menu_id" value="<?= (int)$selectedMenu['id'] ?>">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($base . '/?page=admin/menus/index', ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="adam-hapus" style="font-size:12px;"><?=_e('Delete Menu')?></button>
              </form>
            </span>
          <?php endif; ?>
        </div>

        <!-- Menu Items Tree -->
        <div id="menuItemsContainer" style="border:1px solid var(--adam-border-2);border-radius:12px;padding:12px;background:var(--adam-surface-4);min-height:100px;">
          <?php if (empty($menuTree)): ?>
            <div style="text-align:center;padding:24px;color:var(--adam-muted);font-size:13px;">
              <?=_e('No menu items yet. Add items from the right panel.')?>
            </div>
          <?php else: ?>
            <?= render_menu_items_admin($menuTree, 0, $menuTranslations) ?>
          <?php endif; ?>
        </div>

        <div id="menuItemEditForm" style="display:none;margin-top:16px;padding:16px;border:1px solid var(--adam-border-2);border-radius:12px;background:var(--adam-surface-4);">
          <h4 style="margin:0 0 12px 0;"><?=_e('Edit Item')?></h4>
           <input type="hidden" id="editItemId" value="">
           <div style="display:grid;gap:8px;">
             <?php if (!empty($translationLocales)): ?>
             <div>
               <label style="display:block;font-size:12px;margin-bottom:4px;"><?=_e('Language')?></label>
               <select id="editItemLocale" class="pht-select"><option value=""><?=_e('Source language')?></option><?php foreach ($translationLocales as $locale): ?><option value="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($locale), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
             </div>
             <?php endif; ?>
            <div>
              <label style="display:block;font-size:12px;margin-bottom:4px;"><?=_e('Label')?></label>
              <input id="editItemLabel" class="pht-input" placeholder="<?=_e('Label menu')?>">
            </div>
            <div>
              <label style="display:block;font-size:12px;margin-bottom:4px;"><?=_e('URL (custom link only)')?></label>
              <input id="editItemUrl" class="pht-input" placeholder="<?=_e('https://...')?>">
            </div>
            <div>
              <label style="display:block;font-size:12px;margin-bottom:4px;">
                <input type="checkbox" id="editItemTargetBlank"> <?=_e('Open in new tab')?>
              </label>
            </div>
            <div style="display:flex;gap:8px;">
              <button type="button" id="saveItemEdit" class="adam-button" style="padding:6px 16px;"><?= _e('Save') ?></button>
              <button type="button" id="cancelItemEdit" class="adam-cancle" style="padding:6px 16px;"><?= _e('Cancel') ?></button>
            </div>
          </div>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;">
          <button type="button" id="btnSaveItems" class="adam-button"><?=_e('Save All Items')?></button>
          <span id="saveItemsStatus" style="font-size:12px;color:var(--adam-muted);align-self:center;"></span>
        </div>

      <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--adam-muted);">
          <?=_e('No menus yet. Create a new menu from the right panel.')?>
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Add Items + New Menu -->
    <div>
      <!-- Create New Menu -->
      <div style="border:1px solid var(--adam-border-2);border-radius:12px;padding:16px;margin-bottom:16px;background:var(--adam-surface-4);">
        <h4 style="margin:0 0 12px 0;"><?=_e('Create New Menu')?></h4>
        <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/save.php', ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="return_to" value="<?= htmlspecialchars($base . '/?page=admin/menus/index', ENT_QUOTES, 'UTF-8') ?>">
          <div style="display:flex;gap:8px;flex-direction:column;">
            <input type="text" name="name" class="pht-input" placeholder="<?=_e('Menu name (e.g. Primary)')?>" required>
            <input type="text" name="slug" class="pht-input" placeholder="<?=_e('Slug (e.g. primary)')?>" required>
            <button type="submit" class="adam-button"><?=_e('Create Menu')?></button>
          </div>
        </form>
      </div>

      <?php if ($selectedMenu): ?>
      <!-- Add Items -->
      <div style="border:1px solid var(--adam-border-2);border-radius:12px;padding:16px;background:var(--adam-surface-4);">
        <h4 style="margin:0 0 12px 0;"><?=_e('Add Item')?></h4>

        <div style="display:flex;gap:4px;margin-bottom:12px;flex-wrap:wrap;">
          <button type="button" class="add-item-tab adam-button" data-tab="custom" style="padding:4px 10px;font-size:12px;"><?=_e('Custom Link')?></button>
          <button type="button" class="add-item-tab adam-button" data-tab="article" style="padding:4px 10px;font-size:12px;"><?=_e('Articles')?></button>
          <button type="button" class="add-item-tab adam-button" data-tab="page" style="padding:4px 10px;font-size:12px;"><?= _e('Page') ?></button>
          <button type="button" class="add-item-tab adam-button" data-tab="theme" style="padding:4px 10px;font-size:12px;"><?=_e('Theme pages')?></button>
          <button type="button" class="add-item-tab adam-button" data-tab="category" style="padding:4px 10px;font-size:12px;"><?= _e('Categories') ?></button>
        </div>

        <!-- Custom Link -->
        <div class="add-item-panel" id="panel-custom">
          <div style="display:grid;gap:8px;">
            <input type="text" id="customLabel" class="pht-input" placeholder="<?=_e('Label')?>">
            <input type="url" id="customUrl" class="pht-input" placeholder="<?=_e('https://...')?>">
            <label style="font-size:12px;"><input type="checkbox" id="customTargetBlank"> <?=_e('Open in new tab')?></label>
            <button type="button" class="add-item-btn adam-button" data-type="custom"><?=_e('Add to Menu')?></button>
          </div>
        </div>

        <!-- Articles -->
        <div class="add-item-panel" id="panel-article" style="display:none;">
          <input type="text" id="articleSearch" class="pht-input" placeholder="<?=_e('Search articles...')?>" style="margin-bottom:8px;">
          <div class="source-list" id="articleList" style="max-height:200px;overflow-y:auto;">
            <?php if (empty($articles)): ?>
              <div style="font-size:12px;color:var(--adam-muted);"><?=_e('No articles.')?></div>
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
          <input type="text" id="pageSearch" class="pht-input" placeholder="<?=_e('Search pages...')?>" style="margin-bottom:8px;">
          <div class="source-list" id="pageList" style="max-height:200px;overflow-y:auto;">
            <?php if (empty($pages)): ?>
              <div style="font-size:12px;color:var(--adam-muted);"><?=_e('No pages.')?></div>
            <?php else: ?>
              <?php foreach ($pages as $p): ?>
                <div class="source-item" data-id="<?= (int)$p['id'] ?>" data-type="page" data-label="<?= htmlspecialchars((string)($p['title'] ?: $p['slug']), ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 8px;cursor:pointer;border-radius:6px;font-size:13px;">
                  <?= htmlspecialchars((string)($p['title'] ?: $p['slug']), ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Public Theme pages -->
        <div class="add-item-panel" id="panel-theme" style="display:none;">
          <input type="text" id="themeSearch" class="pht-input" placeholder="<?=_e('Search Theme pages...')?>" style="margin-bottom:8px;">
          <div class="source-list" id="themeList" style="max-height:200px;overflow-y:auto;">
            <?php if (empty($themePages)): ?>
              <div style="font-size:12px;color:var(--adam-muted);"><?=_e('No public Theme pages.')?></div>
            <?php else: ?>
              <?php foreach ($themePages as $themePage): ?>
                <div class="source-item" data-id="<?= (int)$themePage['id'] ?>" data-type="theme" data-label="<?= htmlspecialchars((string)($themePage['title'] ?: $themePage['public_path']), ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 8px;cursor:pointer;border-radius:6px;font-size:13px;">
                  <?= htmlspecialchars((string)($themePage['title'] ?: $themePage['public_path']), ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Categories -->
        <div class="add-item-panel" id="panel-category" style="display:none;">
          <input type="text" id="categorySearch" class="pht-input" placeholder="<?=_e('Search categories...')?>" style="margin-bottom:8px;">
          <div class="source-list" id="categoryList" style="max-height:200px;overflow-y:auto;">
            <?php if (empty($categories)): ?>
              <div style="font-size:12px;color:var(--adam-muted);"><?=_e('No categories.')?></div>
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
    <h4 style="margin:0 0 12px 0;"><?=_e('Rename Menu')?></h4>
    <form method="post" action="<?= htmlspecialchars($base . '/admin/menus/save.php', ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="rename">
      <input type="hidden" name="menu_id" value="<?= $selectedMenuId ?>">
      <input type="hidden" name="return_to" value="<?= htmlspecialchars($base . '/?page=admin/menus/index&menu_id=' . $selectedMenuId, ENT_QUOTES, 'UTF-8') ?>">
      <div style="display:grid;gap:8px;">
        <label style="font-size:12px;"><?=_e('New Name')?></label>
        <input type="text" name="name" class="pht-input" value="<?= htmlspecialchars((string)($selectedMenu['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
        <label style="font-size:12px;"><?=_e('New Slug')?></label>
        <input type="text" name="slug" class="pht-input" value="<?= htmlspecialchars((string)($selectedMenu['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
        <div style="display:flex;gap:8px;margin-top:8px;">
          <button type="submit" class="adam-button"><?= _e('Save') ?></button>
          <button type="button" class="adam-cancle" onclick="document.getElementById('renameModal').style.display='none'"><?= _e('Cancel') ?></button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const CSRF = <?= json_encode($csrf) ?>;
  const MENU_ID = <?= json_encode($selectedMenuId) ?>;
  const SAVE_URL = <?= json_encode($base . '/admin/menus/items_save.php') ?>;

  const container = document.getElementById('menuItemsContainer');
  const saveBtn = document.getElementById('btnSaveItems');
  const saveStatus = document.getElementById('saveItemsStatus');

  // Temporary ID counter for new items (negative numbers avoid DB ID conflicts)
  var tempIdCounter = 0;

  // =============== Helpers ===============

  function toast(type, message, title){
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message });
      return;
    }
    alert(message);
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }

  function getItemData(li){
    var rawId = li.getAttribute('data-id') || '';
    var id = rawId === '' ? null : parseInt(rawId, 10);
    return {
      id: id,
      type: li.getAttribute('data-type') || 'custom',
      label: li.getAttribute('data-label') || '',
      targetId: parseInt(li.getAttribute('data-target') || '0'),
      url: li.getAttribute('data-url') || '',
      targetBlank: li.getAttribute('data-target-blank') === '1',
      hidden: parseInt(li.getAttribute('data-hidden') || '0')
      ,translations: JSON.parse(li.getAttribute('data-translations') || '{}')
    };
  }

  function setItemData(li, data){
    if (data.id) li.setAttribute('data-id', data.id);
    li.setAttribute('data-type', data.type || 'custom');
    li.setAttribute('data-label', data.label || '');
    li.setAttribute('data-target', String(data.targetId || 0));
    li.setAttribute('data-url', data.url || '');
    li.setAttribute('data-target-blank', data.targetBlank ? '1' : '0');
    li.setAttribute('data-hidden', data.hidden ? '1' : '0');
    li.setAttribute('data-translations', JSON.stringify(data.translations || {}));
    // Update display
    const labelEl = li.querySelector('.menu-item-label');
    const typeEl = li.querySelector('.menu-item-type');
    if (labelEl) labelEl.textContent = data.label || '';
    if (typeEl) typeEl.textContent = data.type || 'custom';
    // Update hidden CSS class + eye icon
    var hideBtn = li.querySelector('.menu-item-hide');
    if (data.hidden) {
      li.classList.add('menu-item-hidden');
      if (hideBtn) hideBtn.innerHTML = '&#128064;';
    } else {
      li.classList.remove('menu-item-hidden');
      if (hideBtn) hideBtn.innerHTML = '&#128065;';
    }
  }

  function buildListItemHTML(data){
    var isHidden = data.hidden ? true : false;
    return '<div class="menu-item-bar">'
      + '<span class="menu-item-handle">&#9776;</span>'
      + '<span class="menu-item-label">' + escapeHtml(data.label) + '</span>'
      + '<span class="menu-item-type">' + escapeHtml(data.type) + '</span>'
      + '<button type="button" class="menu-item-hide adam-ubah" title="<?=_e('Toggle visibility')?>">' + (isHidden ? '&#128064;' : '&#128065;') + '</button>'
      + '<button type="button" class="menu-item-indent adam-ubah" title="<?=_e('Make sub-menu')?>">&#8594;</button>'
      + '<button type="button" class="menu-item-outdent adam-ubah" title="<?=_e('Raise level')?>">&#8592;</button>'
      + '<button type="button" class="menu-item-edit adam-ubah" title="<?=_e('Edit')?>">&#9998;</button>'
      + '<button type="button" class="menu-item-remove adam-hapus" title="<?= _e('Delete') ?>">&#10005;</button>'
      + '</div>';
  }

  function showEmptyState(){
    container.innerHTML = '<div style="text-align:center;padding:24px;color:var(--adam-muted);font-size:13px;">'
      + '<?=__('No menu items yet. Add items from the right panel.')?>'
      + '</div>';
  }

  // =============== Tab switching ===============

  document.querySelectorAll('.add-item-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      const tab = this.getAttribute('data-tab');
      document.querySelectorAll('.add-item-panel').forEach(function(p){ p.style.display = 'none'; });
      var panel = document.getElementById('panel-' + tab);
      if (panel) panel.style.display = '';
    });
  });

  // =============== Source item click ===============

  document.querySelectorAll('.source-item').forEach(function(item){
    item.addEventListener('click', function(){
      addItemToMenu({
        type: this.getAttribute('data-type'),
        label: this.getAttribute('data-label'),
        targetId: parseInt(this.getAttribute('data-id') || '0'),
        url: ''
      });
    });
  });

  // =============== Custom link add ===============

  var addBtn = document.querySelector('.add-item-btn');
  if (addBtn) {
    addBtn.addEventListener('click', function(){
      var label = document.getElementById('customLabel').value.trim();
      var url = document.getElementById('customUrl').value.trim();
      var targetBlank = document.getElementById('customTargetBlank').checked;

      if (!label || !url) {
        toast('error', '<?=__('Label and URL are required.')?>');
        return;
      }

      addItemToMenu({ type: 'custom', label: label, targetId: 0, url: url, targetBlank: targetBlank });
      document.getElementById('customLabel').value = '';
      document.getElementById('customUrl').value = '';
      document.getElementById('customTargetBlank').checked = false;
    });
  }

  // =============== Search filtering ===============

  function setupSearch(inputId, listId) {
    var input = document.getElementById(inputId);
    var list = document.getElementById(listId);
    if (!input || !list) return;
    input.addEventListener('input', function(){
      var q = this.value.toLowerCase();
      list.querySelectorAll('.source-item').forEach(function(el){
        el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  }
  setupSearch('articleSearch', 'articleList');
  setupSearch('pageSearch', 'pageList');
  setupSearch('themeSearch', 'themeList');
  setupSearch('categorySearch', 'categoryList');

  // =============== Add item to menu ===============

  function addItemToMenu(data){
    var emptyMsg = container.querySelector('div[style*="text-align:center"]');
    if (emptyMsg) emptyMsg.remove();

    var ul = container.querySelector('ul.menu-sortable');
    if (!ul) {
      ul = document.createElement('ul');
      ul.className = 'menu-sortable';
      container.appendChild(ul);
    }

    var li = document.createElement('li');
    li.className = 'menu-item-admin';
    li.innerHTML = buildListItemHTML(data);
    tempIdCounter--;
    li.setAttribute('data-id', String(tempIdCounter));
    li.setAttribute('data-type', data.type || 'custom');
    li.setAttribute('data-label', data.label || '');
    li.setAttribute('data-target', String(data.targetId || 0));
    li.setAttribute('data-url', data.url || '');
    li.setAttribute('data-target-blank', data.targetBlank ? '1' : '0');
    li.setAttribute('data-hidden', data.hidden ? '1' : '0');
    li.setAttribute('data-translations', '{}');

    ul.appendChild(li);
    toast('success', '<?=__('Item added to menu')?> "' + data.label + '"');
  }

  // =============== Remove item ===============

  // =============== Hide toggle ===============

  container.addEventListener('click', function(e){
    var btn = e.target.closest('.menu-item-hide');
    if (!btn) return;
    var li = btn.closest('.menu-item-admin');
    if (!li) return;
    var data = getItemData(li);
    data.hidden = data.hidden ? 0 : 1;
    setItemData(li, data);
  });

  // =============== Remove item ===============

  container.addEventListener('click', function(e){
    var btn = e.target.closest('.menu-item-remove');
    if (!btn) return;

    var li = btn.closest('.menu-item-admin');
    if (li && confirm('<?=__('Remove this item from menu?')?>')) {
      li.remove();
      if (!container.querySelector('ul.menu-sortable') || !container.querySelector('ul.menu-sortable').querySelector('li')) {
        showEmptyState();
      }
    }
  });

  // =============== Edit item ===============

  container.addEventListener('click', function(e){
    var btn = e.target.closest('.menu-item-edit');
    if (!btn) return;
    var li = btn.closest('.menu-item-admin');
    if (!li) return;

    var data = getItemData(li);
    document.getElementById('editItemId').value = data.id || '';
    document.getElementById('editItemLabel').value = data.label;
    document.getElementById('editItemUrl').value = data.url;
    document.getElementById('editItemTargetBlank').checked = data.targetBlank;

    var form = document.getElementById('menuItemEditForm');
    form.style.display = '';
    form._targetLi = li;
    form._editingLocale = '';
    var localeSelect = document.getElementById('editItemLocale');
    if (localeSelect) localeSelect.value = '';
  });

  var localeSelect = document.getElementById('editItemLocale');
  if (localeSelect) localeSelect.addEventListener('change', function(){
    var form = document.getElementById('menuItemEditForm');
    var li = form._targetLi;
    if (!li) return;
    var data = getItemData(li);
    var oldLocale = form._editingLocale || '';
    if (oldLocale) {
      data.translations[oldLocale] = {label: document.getElementById('editItemLabel').value.trim(), url: document.getElementById('editItemUrl').value.trim()};
    }
    var nextLocale = this.value;
    var localized = nextLocale ? (data.translations[nextLocale] || {}) : data;
    document.getElementById('editItemLabel').value = localized.label || '';
    document.getElementById('editItemUrl').value = localized.url || '';
    document.getElementById('editItemTargetBlank').checked = data.targetBlank;
    form._editingLocale = nextLocale;
    setItemData(li, data);
  });

  document.getElementById('saveItemEdit').addEventListener('click', function(){
    var form = document.getElementById('menuItemEditForm');
    var li = form._targetLi;
    if (!li) return;

    var data = getItemData(li);
    var locale = form._editingLocale || '';
    if (locale) {
      data.translations[locale] = {label: document.getElementById('editItemLabel').value.trim(), url: document.getElementById('editItemUrl').value.trim()};
    } else {
      data.label = document.getElementById('editItemLabel').value.trim() || data.label;
      data.url = document.getElementById('editItemUrl').value.trim();
    }
    data.targetBlank = document.getElementById('editItemTargetBlank').checked;

    setItemData(li, data);
    form.style.display = 'none';
    toast('success', '<?=__('Item updated')?>');
  });

  document.getElementById('cancelItemEdit').addEventListener('click', function(){
    document.getElementById('menuItemEditForm').style.display = 'none';
  });

  // =============== Indent / Outdent ===============

  container.addEventListener('click', function(e){
    var btn = e.target.closest('.menu-item-indent');
    if (!btn) return;
    var li = btn.closest('.menu-item-admin');
    if (!li) return;

    var prevLi = li.previousElementSibling;
    if (!prevLi || prevLi.tagName !== 'LI') return;

    var childUl = prevLi.querySelector(':scope > ul.menu-sortable');
    if (!childUl) {
      childUl = document.createElement('ul');
      childUl.className = 'menu-sortable';
      childUl.style.marginLeft = '20px';
      prevLi.appendChild(childUl);
    }
    childUl.appendChild(li);
  });

  container.addEventListener('click', function(e){
    var btn = e.target.closest('.menu-item-outdent');
    if (!btn) return;
    var li = btn.closest('.menu-item-admin');
    if (!li) return;

    var parentUl = li.closest('ul.menu-sortable');
    var grandParentLi = parentUl ? parentUl.closest('.menu-item-admin') : null;
    if (!grandParentLi) return;

    var grandParentUl = grandParentLi.parentNode;
    if (!grandParentUl || grandParentUl.tagName !== 'UL') return;

    // Move li after grandParentLi
    var nextSibling = grandParentLi.nextElementSibling;
    if (nextSibling) {
      grandParentUl.insertBefore(li, nextSibling);
    } else {
      grandParentUl.appendChild(li);
    }

    // Clean up empty child ul
    if (parentUl && !parentUl.querySelector('li')) {
      parentUl.remove();
    }
  });

  // =============== Drag and Drop ===============

  var dragSrcLi = null;

  container.addEventListener('dragstart', function(e){
    var li = e.target.closest('.menu-item-admin');
    if (!li) return;
    dragSrcLi = li;
    e.dataTransfer.effectAllowed = 'move';
    li.style.opacity = '0.4';
  });

  container.addEventListener('dragend', function(e){
    var li = e.target.closest('.menu-item-admin');
    if (li) li.style.opacity = '';
    dragSrcLi = null;
    document.querySelectorAll('.menu-item-admin').forEach(function(el){
      el.classList.remove('drag-over');
    });
  });

  container.addEventListener('dragover', function(e){
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    var li = e.target.closest('.menu-item-admin');
    if (!li || li === dragSrcLi) return;
    document.querySelectorAll('.menu-item-admin').forEach(function(el){
      el.classList.remove('drag-over');
    });
    li.classList.add('drag-over');
  });

  container.addEventListener('dragleave', function(e){
    var li = e.target.closest('.menu-item-admin');
    if (li) li.classList.remove('drag-over');
  });

  container.addEventListener('drop', function(e){
    e.preventDefault();
    if (!dragSrcLi) return;

    var targetLi = e.target.closest('.menu-item-admin');
    if (!targetLi || targetLi === dragSrcLi) return;

    // Determine drop position based on mouse Y within target
    var rect = targetLi.getBoundingClientRect();
    var offsetY = e.clientY - rect.top;
    var parentUl = targetLi.parentNode;

    var dropPosition = offsetY < rect.height * 0.25 ? 'before' :
                       offsetY > rect.height * 0.75 ? 'after' : 'inside';

    if (dropPosition === 'inside') {
      // Drop as child
      var childUl = targetLi.querySelector(':scope > ul.menu-sortable');
      if (!childUl) {
        childUl = document.createElement('ul');
        childUl.className = 'menu-sortable';
        childUl.style.marginLeft = '20px';
        targetLi.appendChild(childUl);
      }
      childUl.appendChild(dragSrcLi);
    } else if (dropPosition === 'before') {
      parentUl.insertBefore(dragSrcLi, targetLi);
    } else {
      var nextSibling = targetLi.nextElementSibling;
      if (nextSibling) {
        parentUl.insertBefore(dragSrcLi, nextSibling);
      } else {
        parentUl.appendChild(dragSrcLi);
      }
    }

    document.querySelectorAll('.menu-item-admin').forEach(function(el){
      el.classList.remove('drag-over');
    });
  });

  // Make all items draggable
  function initDraggable(){
    container.querySelectorAll('.menu-item-admin').forEach(function(li){
      li.setAttribute('draggable', 'true');
    });
  }
  initDraggable();

  // Re-init draggable when new items added
  var observer = new MutationObserver(function(){
    container.querySelectorAll('.menu-item-admin:not([draggable])').forEach(function(li){
      li.setAttribute('draggable', 'true');
    });
  });
  observer.observe(container, { childList: true, subtree: true });

  // =============== Collect items (recursive) ===============

  function collectItems(){
    var items = [];

    function walk(ul, parentId){
      var lis = ul.querySelectorAll(':scope > li.menu-item-admin');
      lis.forEach(function(li, idx){
        var data = getItemData(li);
        var itemId = data.id; // null for new items (insert), negative for new items with children, positive for existing
        items.push({
          id: itemId,
          parent_id: parentId,
          sort_order: idx,
          type: data.type,
          label: data.label,
          target_id: data.targetId,
          url: data.url,
          target_blank: data.targetBlank ? 1 : 0,
          hidden: data.hidden ? 1 : 0
          ,translations: data.translations || {}
        });
        var childUl = li.querySelector(':scope > ul.menu-sortable');
        if (childUl) {
          // Use temp negative ID as parent reference so children know their parent
          walk(childUl, itemId !== null ? itemId : parentId);
        }
      });
    }

    var rootUl = container.querySelector(':scope > ul.menu-sortable');
    if (rootUl) walk(rootUl, null);

    return items;
  }

  // =============== Save ===============

  if (saveBtn) {
    saveBtn.addEventListener('click', function(){
      var items = collectItems();
      saveBtn.disabled = true;
      saveStatus.textContent = '<?=__('Saving...')?>';

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
          toast('success', '<?=__('Menu saved successfully.')?>');
          setTimeout(function(){ window.location.reload(); }, 600);
        } else {
          toast('error', (data && data.error) ? data.error : '<?=__('Failed to save.')?>');
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
  }

  // =============== Rename menu ===============

  var renameBtn = document.getElementById('btnRenameMenu');
  if (renameBtn) {
    renameBtn.addEventListener('click', function(){
      document.getElementById('renameModal').style.display = 'flex';
    });
  }
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
  gap: 6px;
  padding: 6px 10px;
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
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.menu-item-type {
  font-size: 10px;
  color: var(--adam-muted);
  background: var(--adam-surface-4);
  padding: 1px 5px;
  border-radius: 4px;
  text-transform: uppercase;
  white-space: nowrap;
}
.menu-item-admin.menu-item-hidden > .menu-item-bar {
  opacity: 0.5;
  background: repeating-linear-gradient(45deg, transparent, transparent 4px, rgba(0,0,0,0.03) 4px, rgba(0,0,0,0.03) 8px);
}
.menu-item-admin.menu-item-hidden .menu-item-label {
  text-decoration: line-through;
}
.menu-item-admin.drag-over > .menu-item-bar {
  border-color: #3b82f6;
  background: rgba(59,130,246,0.08);
}
.menu-item-hide,
.menu-item-indent,
.menu-item-outdent,
.menu-item-edit {
  font-size: 12px;
  padding: 2px 6px;
  cursor: pointer;
}
.menu-item-remove {
  font-size: 12px;
  padding: 2px 6px;
  cursor: pointer;
}
.source-item:hover {
  background: var(--adam-surface-3);
}
.source-item:active {
  background: var(--adam-border-2);
}
@media (max-width:768px){
  .menus-grid{ grid-template-columns:1fr !important; }
}
</style>
