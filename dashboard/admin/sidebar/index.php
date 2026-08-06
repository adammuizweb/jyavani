<?php
declare(strict_types=1);
require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
[$uid, $role] = adiwira_require_admin($pdo, false);

require_once __DIR__ . '/../../../cfg/helpers/sidebar_helper.php';

$errors = [];
$success_msg = '';
$base = ADMIN_BASE_PATH;
$self_url = $base . '/?page=admin/sidebar/index';
$translationLocales = function_exists('ct_enabled_locales') ? ct_enabled_locales($pdo) : [];
$translationLocale = trim((string)($_GET['ct_locale'] ?? ''));
if (!in_array($translationLocale, $translationLocales, true)) $translationLocale = '';

$hasHelper = function_exists('sidebar_zone_get_all');
$zones = $hasHelper ? sidebar_zone_get_all($pdo) : [];

$selectedZoneId = (int)($_GET['zone_id'] ?? 0);
if ($selectedZoneId > 0 && $hasHelper && function_exists('sidebar_zone_get_by_id')) {
    $zoneCheck = sidebar_zone_get_by_id($pdo, $selectedZoneId);
    if (!$zoneCheck) $selectedZoneId = 0;
} else {
    $selectedZoneId = 0;
}
if ($selectedZoneId <= 0) {
    $first = $zones[0] ?? null;
    $selectedZoneId = $first ? (int)$first['id'] : 0;
}
$currentZone = null;
$currentItems = [];
if ($selectedZoneId > 0 && $hasHelper) {
    $currentZone = sidebar_zone_get_by_id($pdo, $selectedZoneId);
    $currentItems = $currentZone ? sidebar_zone_get_items($pdo, $selectedZoneId) : [];
}

$widget_types = [
    'search' => [
        'label' => __('Search'),
        'desc'  => __('Article search form.'),
        'default_config' => ['title' => __('Search'), 'placeholder' => __('Search articles...')],
    ],
    'last_posts' => [
        'label' => 'Last Post (List Title)',
        'desc'  => __('Latest articles list - titles only.'),
        'default_config' => ['title' => __('Recent Articles'), 'limit' => 5, 'type' => 'article'],
    ],
    'editor_pick' => [
        'label' => 'Editor Pick (Card)',
        'desc'  => __('Featured posts with card/shortcode display.'),
        'default_config' => ['title' => __("Editor's Pick"), 'limit' => 3, 'layout' => 'cards', 'type' => 'article', 'random' => false],
    ],
    'html' => [
        'label' => 'Custom HTML',
        'desc'  => __('Free HTML content.'),
        'default_config' => ['title' => '', 'html' => ''],
    ],
    'categories' => [
        'label' => __('Categories'),
        'desc'  => __('Category list.'),
        'default_config' => ['title' => __('Categories'), 'limit' => 30, 'only_parents' => true],
    ],
    'shortcode_preset' => [
        'label' => 'Shortcode Preset',
        'desc'  => __('Use existing shortcode preset.'),
        'default_config' => ['title' => '', 'preset_slug' => ''],
    ],
];
$widget_types = apply_filters('sidebar_widget_types', $widget_types);
$presets = [];
$pst = $pdo->prepare("SELECT slug, title FROM posts WHERE type = 'sc_preset' AND status = 'published' AND is_deleted = 0 ORDER BY title ASC");
$pst->execute();
$presets = $pst->fetchAll(PDO::FETCH_ASSOC);
$cats = [];
$cst = $pdo->query("SELECT id, name, slug FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
if ($cst) $cats = $cst->fetchAll(PDO::FETCH_ASSOC);

// ── POST handling ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }
    $action = (string)($_POST['_action'] ?? 'save');

    if ($action === 'add') {
        $type = (string)($_POST['new_type'] ?? '');
        if (!isset($widget_types[$type])) {
            $errors[] = __('Tipe widget tidak valid.');
        } elseif (!$currentZone) {
            $errors[] = __('Choose a zone first.');
        } else {
            $cfg = $widget_types[$type]['default_config'];
            $newTitle = trim((string)($_POST['new_title'] ?? ''));
            if ($newTitle !== '') $cfg['title'] = $newTitle;

            if ($type === 'shortcode_preset') {
                $presetSlug = trim((string)($_POST['new_preset_slug'] ?? ''));
                if ($presetSlug !== '') $cfg['preset_slug'] = $presetSlug;
            }

            $maxOrder = 0;
            foreach ($currentItems as $it) {
                if ((int)$it['ordering'] > $maxOrder) $maxOrder = (int)$it['ordering'];
            }

            if (!$errors) {
                $st = $pdo->prepare("INSERT INTO sidebar_zone_items (zone_id, type, title, config, ordering, active) VALUES (:zid, :typ, :title, :cfg, :ord, 1)");
                $st->execute([
                    ':zid' => $selectedZoneId,
                    ':typ' => $type,
                    ':title' => $cfg['title'] ?? $widget_types[$type]['label'],
                    ':cfg' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':ord' => $maxOrder + 1,
                ]);
            }
        }
    } elseif ($action === 'delete') {
        $delId = (int)($_POST['delete_id'] ?? 0);
        if ($delId > 0) {
            $st = $pdo->prepare("DELETE FROM sidebar_zone_items WHERE id = :id AND zone_id = :zid");
            $st->execute([':id' => $delId, ':zid' => $selectedZoneId]);
        }
    } elseif ($action === 'save') {
        $orderRaw = (string)($_POST['_widget_order'] ?? '');
        $orderIds = array_filter(array_map('trim', explode(',', $orderRaw)));
        $submitted = (array)($_POST['widget'] ?? []);
        $keepIds = [];
        $idx = 0;

        foreach ($orderIds as $wid) {
            $wid = (int)$wid;
            if ($wid <= 0 || !isset($submitted[$wid])) continue;
            $data = $submitted[$wid];
            $type = (string)($data['type'] ?? '');
            if (!isset($widget_types[$type])) continue;
            $config = (array)($data['config'] ?? []);
            $title = (string)($config['title'] ?? '');
            $active = !empty($data['active']);
            $translation = is_array($data['translation'] ?? null) ? $data['translation'] : [];

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

            $st = $pdo->prepare("UPDATE sidebar_zone_items SET type = :typ, title = :title, config = :cfg, ordering = :ord, active = :act WHERE id = :id AND zone_id = :zid");
            $st->execute([
                ':typ' => $type,
                ':title' => $title,
                ':cfg' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ord' => $idx++,
                ':act' => $active ? 1 : 0,
                ':id' => $wid,
                ':zid' => $selectedZoneId,
            ]);
            $keepIds[] = $wid;
            if ($translationLocale !== '' && function_exists('ct_save_sidebar_item_translation')) {
                ct_save_sidebar_item_translation($pdo, $wid, $translationLocale, $translation);
            }
        }

        if ($selectedZoneId > 0) {
            if (!empty($keepIds)) {
                $ph = implode(',', array_fill(0, count($keepIds), '?'));
                $st = $pdo->prepare("DELETE FROM sidebar_zone_items WHERE zone_id = ? AND id NOT IN ($ph)");
                $st->execute(array_merge([$selectedZoneId], $keepIds));
            } else {
                $st = $pdo->prepare("DELETE FROM sidebar_zone_items WHERE zone_id = ?");
                $st->execute([$selectedZoneId]);
            }
        }
    }

    if (!$errors) {
        // Refresh after mutation
        if (function_exists('sidebar_zone_invalidate_cache')) sidebar_zone_invalidate_cache();
        $zones = function_exists('sidebar_zone_get_all') ? sidebar_zone_get_all($pdo) : [];
        $currentItems = $currentZone ? sidebar_zone_get_items($pdo, $selectedZoneId) : [];

        $msg = match ($action) {
            'add'    => __('Widget added successfully.'),
            'delete' => __('Widget deleted successfully.'),
            default  => __('Widget settings saved successfully.'),
        };
        if (function_exists('adiwira_redirect_with_flash')) {
            $url = $self_url . '&zone_id=' . $selectedZoneId . ($translationLocale !== '' ? '&ct_locale=' . rawurlencode($translationLocale) : '');
            adiwira_redirect_with_flash($url, 'success', $msg);
        }
        $success_msg = $msg;
    }
}

$show_inline_success = ($success_msg !== '' && !function_exists('adiwira_bootstrap_toasts_script'));
$show_inline_errors  = (!empty($errors) && !function_exists('adiwira_bootstrap_toasts_script'));
$zone_edit_id = (int)($_GET['edit_zone'] ?? 0);
$zone_to_edit = $zone_edit_id > 0 ? sidebar_zone_get_by_id($pdo, $zone_edit_id) : null;
$zone_to_delete = (int)($_GET['delete_zone'] ?? 0);
?>
<div class="panel" style="max-width:900px;margin:20px auto;">

  <div style="margin-bottom:20px;">
    <h2 style="margin:0 0 4px;">Sidebar Zones</h2>
    <div class="muted" style="font-size:13px;"><?=__('Create & manage multiple sidebar zones, then select the primary one to display on the front page.')?></div>
  </div>

  <?php if (!empty($translationLocales)): ?>
  <div style="margin-bottom:16px;display:flex;align-items:center;gap:8px;">
    <label for="ct-sidebar-locale" style="font-weight:600;font-size:13px;"><?=_e('Edit translation')?></label>
    <select id="ct-sidebar-locale" class="pht-select" onchange="location=this.value">
      <option value="<?= h($self_url . '&zone_id=' . $selectedZoneId) ?>"><?=_e('Source language')?></option>
      <?php foreach ($translationLocales as $locale): ?>
      <option value="<?= h($self_url . '&zone_id=' . $selectedZoneId . '&ct_locale=' . rawurlencode($locale)) ?>" <?= $translationLocale === $locale ? 'selected' : '' ?>><?= h(strtoupper($locale)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($translationLocale !== ''): ?><span class="pht-muted" style="font-size:12px;"><?=_e('Only translated text is saved; widget behavior stays global.')?></span><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($show_inline_success): ?>
    <div style="background:var(--adam-success);color:#fff;padding:10px 14px;border-radius:var(--adam-radius);margin-bottom:14px;font-size:14px;">&#10004; <?= h($success_msg) ?></div>
  <?php endif; ?>
  <?php if ($show_inline_errors): ?>
    <div style="background:var(--adam-danger);color:#fff;padding:10px 14px;border-radius:var(--adam-radius);margin-bottom:14px;font-size:14px;">
      <ul style="margin:0;padding-left:18px"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <!-- Zone Selector & Management -->
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:12px 16px;margin-bottom:16px;">
    <strong style="font-size:14px;white-space:nowrap;"><?=_e('Select Zone:')?></strong>
    <select id="zone-selector" onchange="location=this.value" style="flex:1;min-width:140px;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
      <?php foreach ($zones as $z): ?>
        <option value="<?= h($self_url . '&zone_id=' . $z['id']) ?>" <?= (int)$z['id'] === $selectedZoneId ? 'selected' : '' ?>>
          <?= h($z['name']) ?><?= !empty($z['is_primary']) ? ' ★' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="button" class="adam-button" style="padding:6px 14px;white-space:nowrap;" onclick="showCreateZone()"><?=_e('+ New Zone')?></button>

    <?php if ($currentZone): ?>
      <a href="<?= h($self_url . '&zone_id=' . $selectedZoneId . '&edit_zone=' . $selectedZoneId) ?>" class="adam-cancle" style="padding:6px 12px;font-size:13px;text-decoration:none;">&#9998; Edit</a>
      <?php if (empty($currentZone['is_primary'])): ?>
        <form method="post" action="<?= h($base) ?>/?page=admin/sidebar/save" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="set_primary">
          <input type="hidden" name="zone_id" value="<?= $selectedZoneId ?>">
          <button type="submit" class="adam-cancle" style="padding:6px 12px;font-size:13px;cursor:pointer;"><?=_e('Make Primary')?></button>
        </form>
      <?php endif; ?>
      <a href="<?= h($self_url . '&zone_id=' . $selectedZoneId . '&delete_zone=' . $selectedZoneId) ?>" class="adam-cancle" style="padding:6px 12px;font-size:13px;text-decoration:none;color:var(--adam-danger);" onclick="return confirm('<?=__('Delete zone')?> &quot;<?= h($currentZone['name']) ?>&quot; <?=__('and all its widgets?')?>')">&#x2715; <?=_e('Delete')?></a>
    <?php endif; ?>
  </div>

  <!-- Create Zone Modal (inline toggle) -->
  <div id="create-zone-box" style="display:none;background:var(--adam-card);border:1px solid var(--adam-border-2);border-radius:var(--adam-radius);padding:16px;margin-bottom:16px;">
    <form method="post" action="<?= h($base) ?>/?page=admin/sidebar/save">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:500px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Zone Name')?></label>
          <input type="text" name="name" required placeholder="<?=_e('e.g. Main Sidebar')?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Slug')?></label>
          <input type="text" name="slug" required placeholder="main" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        </div>
        <div style="grid-column:1/-1;">
          <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Description (optional)')?></label>
          <input type="text" name="description" placeholder="<?=_e('Main website sidebar')?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        </div>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;">
        <button type="submit" class="adam-button" style="padding:6px 16px;"><?=_e('Create Zone')?></button>
        <button type="button" class="adam-cancle" style="padding:6px 16px;" onclick="document.getElementById('create-zone-box').style.display='none'"><?=_e('Cancel')?></button>
      </div>
    </form>
  </div>

  <!-- Edit Zone Modal (inline toggle) -->
  <?php if ($zone_to_edit): ?>
  <div id="edit-zone-box" style="background:var(--adam-card);border:1px solid var(--adam-border-2);border-radius:var(--adam-radius);padding:16px;margin-bottom:16px;">
    <form method="post" action="<?= h($base) ?>/?page=admin/sidebar/save">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="rename">
      <input type="hidden" name="zone_id" value="<?= $selectedZoneId ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:500px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Zone Name')?></label>
          <input type="text" name="name" required value="<?= h($zone_to_edit['name']) ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Slug')?></label>
          <input type="text" name="slug" required value="<?= h($zone_to_edit['slug']) ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        </div>
        <div style="grid-column:1/-1;">
          <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Description')?></label>
          <input type="text" name="description" value="<?= h($zone_to_edit['description'] ?? '') ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        </div>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;">
        <button type="submit" class="adam-button" style="padding:6px 16px;"><?=_e('Save')?></button>
        <a href="<?= h($self_url . '&zone_id=' . $selectedZoneId) ?>" class="adam-cancle" style="padding:6px 16px;text-decoration:none;"><?=_e('Cancel')?></a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- Delete Zone form -->
  <?php if ($zone_to_delete > 0 && $zone_to_delete === $selectedZoneId): ?>
  <form method="post" action="<?= h($base) ?>/?page=admin/sidebar/delete" style="display:none;" id="delete-zone-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="zone_id" value="<?= $selectedZoneId ?>">
  </form>
  <script>document.getElementById('delete-zone-form').submit();</script>
  <?php endif; ?>

  <?php if ($currentZone): ?>
  <!-- Add Widget Form -->
  <div style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:16px;margin-bottom:16px;">
    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="add">
      <strong style="font-size:14px;white-space:nowrap;"><?=_e('+ Add Widget')?></strong>
      <select name="new_type" id="sw-add-type" onchange="swTogglePresetField()" style="padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        <option value=""><?=_e('- Select type -')?></option>
        <?php foreach ($widget_types as $wt => $wi): ?>
          <option value="<?= h($wt) ?>"><?= h($wi['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="new_title" placeholder="<?=_e('Widget title (optional)')?>" style="flex:1;min-width:160px;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
      <span id="sw-preset-field" style="display:none;">
        <?php if (!empty($presets)): ?>
        <select name="new_preset_slug" style="padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;min-width:180px;">
          <option value=""><?=_e('- Select preset -')?></option>
          <?php foreach ($presets as $p): ?>
            <option value="<?= h($p['slug']) ?>"><?= h($p['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="new_preset_slug" placeholder="<?=_e('Preset slug (manual)')?>" style="width:180px;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
        <?php endif; ?>
      </span>
      <button type="submit" class="adam-button" style="padding:6px 16px;white-space:nowrap;"><?=_e('Add')?></button>
    </form>
  </div>
  <script>
  function swTogglePresetField() {
    var t = document.getElementById('sw-add-type');
    var w = document.getElementById('sw-preset-field');
    if (t && w) w.style.display = t.value === 'shortcode_preset' ? 'inline' : 'none';
  }
  document.addEventListener('DOMContentLoaded', swTogglePresetField);
  </script>

  <!-- Widget Items List -->
  <form method="post" id="sidebar-widgets-form">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="_widget_order" id="widget-order" value="<?= h(implode(',', array_map(fn($it) => $it['id'] ?? '', $currentItems))) ?>">

    <div id="sidebar-widgets-list">
      <?php if (empty($currentItems)): ?>
        <div style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:24px;text-align:center;color:var(--adam-muted);font-size:14px;">
          <?=__('No widgets yet in zone')?> <strong><?= h($currentZone['name']) ?></strong>. <?=__('Add new widget above.')?>
        </div>
      <?php else: ?>
        <?php foreach ($currentItems as $it):
          $itemId = (int)$it['id'];
          $type = (string)$it['type'];
          $active = !empty($it['active']);
           $config = is_array($it['config']) ? $it['config'] : [];
           $itemTranslation = $translationLocale !== '' && function_exists('ct_sidebar_item_translation') ? ct_sidebar_item_translation($pdo, $itemId, $translationLocale) : null;
           $translationConfig = is_array($itemTranslation['config'] ?? null) ? $itemTranslation['config'] : [];
          $typeInfo = $widget_types[$type] ?? ['label' => ucfirst($type), 'desc' => ''];
          $isOpen = (string)($_GET['edit'] ?? '') === (string)$itemId;
        ?>
          <div class="sw-item" data-id="<?= $itemId ?>" style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);margin-bottom:10px;<?= $active ? '' : 'opacity:.55;' ?>">
            <input type="hidden" name="widget[<?= $itemId ?>][type]" value="<?= h($type) ?>">

            <!-- Header bar -->
            <div class="sw-header" style="display:flex;align-items:center;gap:8px;padding:10px 14px;cursor:pointer;user-select:none;" onclick="toggleWidget(this)">
              <span class="sw-drag" style="cursor:grab;color:var(--adam-muted);font-size:18px;line-height:1;" title="<?=_e('Drag to reorder')?>">&#x283F;</span>
              <span class="sw-badge" style="background:var(--adam-primary-soft,var(--adam-surface-3));color:var(--adam-primary,var(--adam-text));padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;white-space:nowrap;"><?= h($typeInfo['label']) ?></span>
              <span class="sw-title" style="flex:1;font-weight:600;font-size:14px;color:var(--adam-text);"><?= h($it['title'] ?: $typeInfo['label']) ?></span>
              <input type="hidden" name="widget[<?= $itemId ?>][active]" value="0">
              <label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px;white-space:nowrap;">
                <input type="checkbox" name="widget[<?= $itemId ?>][active]" value="1" <?= $active ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--adam-primary);cursor:pointer;" onchange="this.closest('.sw-item').style.opacity=this.checked?'':'0.55'">
                <?=_e('Active')?>
              </label>
              <div style="display:flex;gap:4px;">
                <button type="button" class="sw-up" onclick="moveWidget(this,-1)" style="background:none;border:1px solid var(--adam-border-2);border-radius:4px;padding:2px 6px;cursor:pointer;font-size:13px;color:var(--adam-muted);" title="<?=_e('Move up')?>">&#x25B2;</button>
                <button type="button" class="sw-down" onclick="moveWidget(this,1)" style="background:none;border:1px solid var(--adam-border-2);border-radius:4px;padding:2px 6px;cursor:pointer;font-size:13px;color:var(--adam-muted);" title="<?=_e('Move down')?>">&#x25BC;</button>
              </div>
              <button type="button" class="sw-delete" onclick="deleteWidget(this, <?= $itemId ?>)" style="background:none;border:none;cursor:pointer;color:var(--adam-danger);font-size:18px;line-height:1;padding:2px 4px;" title="<?=_e('Delete widget')?>">&#x2715;</button>
            </div>

            <!-- Edit fields (collapsible) -->
            <div class="sw-body" style="border-top:1px solid var(--adam-border);padding:14px;display:<?= $isOpen ? 'block' : 'none' ?>;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:600px;">
                <!-- Common: title -->
                 <div style="grid-column:1/-1;">
                  <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Widget Title')?></label>
                  <input type="text" name="widget[<?= $itemId ?>][config][title]" value="<?= h($config['title'] ?? $it['title'] ?? '') ?>" placeholder="<?= h($typeInfo['label']) ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                 </div>

                 <?php if ($translationLocale !== ''): ?>
                 <div style="grid-column:1/-1;border-top:1px solid var(--adam-border);padding-top:10px;">
                   <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?= h(strtoupper($translationLocale)) ?> <?=_e('Widget Title')?></label>
                   <input type="text" name="widget[<?= $itemId ?>][translation][title]" value="<?= h($itemTranslation['title'] ?? '') ?>" placeholder="<?= h($config['title'] ?? $it['title'] ?? '') ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                 </div>
                 <?php endif; ?>

                 <?php if ($type === 'search'): ?>
                  <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;">Placeholder</label>
                    <input type="text" name="widget[<?= $itemId ?>][config][placeholder]" value="<?= h($config['placeholder'] ?? __('Search articles...')) ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                   </div>
                   <?php if ($translationLocale !== ''): ?>
                   <div style="grid-column:1/-1;">
                     <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?= h(strtoupper($translationLocale)) ?> <?=_e('Placeholder')?></label>
                     <input type="text" name="widget[<?= $itemId ?>][translation][config][placeholder]" value="<?= h($translationConfig['placeholder'] ?? '') ?>" placeholder="<?= h($config['placeholder'] ?? '') ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                   </div>
                   <div style="grid-column:1/-1;">
                     <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?= h(strtoupper($translationLocale)) ?> <?=_e('Button text')?></label>
                     <input type="text" name="widget[<?= $itemId ?>][translation][config][button]" value="<?= h($translationConfig['button'] ?? '') ?>" placeholder="<?= h($config['button'] ?? '') ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                   </div>
                   <?php endif; ?>

                <?php elseif ($type === 'last_posts'): ?>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Amount')?></label>
                    <input type="number" name="widget[<?= $itemId ?>][config][limit]" value="<?= (int)($config['limit'] ?? 5) ?>" min="1" max="50" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Type')?></label>
                    <select name="widget[<?= $itemId ?>][config][type]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value="article" <?= ($config['type'] ?? 'article') === 'article' ? 'selected' : '' ?>><?=_e('Articles')?></option>
                      <option value="page" <?= ($config['type'] ?? '') === 'page' ? 'selected' : '' ?>><?=_e('Pages')?></option>
                    </select>
                  </div>

                <?php elseif ($type === 'editor_pick'): ?>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Amount')?></label>
                    <input type="number" name="widget[<?= $itemId ?>][config][limit]" value="<?= (int)($config['limit'] ?? 3) ?>" min="1" max="20" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Layout')?></label>
                    <select name="widget[<?= $itemId ?>][config][layout]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <?php foreach (['cards' => 'Cards', 'list' => 'List', 'card2' => 'Card 2', 'sliderpage' => 'Slider'] as $lv => $ll): ?>
                        <option value="<?= h($lv) ?>" <?= ($config['layout'] ?? 'cards') === $lv ? 'selected' : '' ?>><?= h($ll) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Type')?></label>
                    <select name="widget[<?= $itemId ?>][config][type]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value="article" <?= ($config['type'] ?? 'article') === 'article' ? 'selected' : '' ?>><?=_e('Articles')?></option>
                      <option value="page" <?= ($config['type'] ?? '') === 'page' ? 'selected' : '' ?>><?=_e('Pages')?></option>
                    </select>
                  </div>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Category (optional)')?></label>
                    <select name="widget[<?= $itemId ?>][config][category]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value=""><?=_e('- All -')?></option>
                      <?php foreach ($cats as $c): ?>
                        <option value="<?= h($c['slug']) ?>" <?= ($config['category'] ?? '') === $c['slug'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                      <input type="hidden" name="widget[<?= $itemId ?>][config][random]" value="0">
                      <input type="checkbox" name="widget[<?= $itemId ?>][config][random]" value="1" <?= !empty($config['random']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--adam-primary);cursor:pointer;">
                      <?=_e('Random')?>
                    </label>
                  </div>

                 <?php elseif ($type === 'html'): ?>
                  <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('HTML Content')?></label>
                    <textarea name="widget[<?= $itemId ?>][config][html]" rows="6" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;font-family:monospace;resize:vertical;"><?= h($config['html'] ?? '') ?></textarea>
                   </div>
                   <?php if ($translationLocale !== ''): ?>
                   <div style="grid-column:1/-1;">
                     <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?= h(strtoupper($translationLocale)) ?> <?=_e('HTML Content')?></label>
                     <textarea name="widget[<?= $itemId ?>][translation][config][html]" rows="6" placeholder="<?= h($config['html'] ?? '') ?>" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;font-family:monospace;resize:vertical;"><?= h($translationConfig['html'] ?? '') ?></textarea>
                   </div>
                   <?php endif; ?>

                <?php elseif ($type === 'categories'): ?>
                  <div>
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Amount')?></label>
                    <input type="number" name="widget[<?= $itemId ?>][config][limit]" value="<?= (int)($config['limit'] ?? 30) ?>" min="1" max="200" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                  </div>
                  <div style="grid-column:1/-1;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                      <input type="hidden" name="widget[<?= $itemId ?>][config][only_parents]" value="0">
                      <input type="checkbox" name="widget[<?= $itemId ?>][config][only_parents]" value="1" <?= !empty($config['only_parents']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--adam-primary);cursor:pointer;">
                      <?=_e('Only parent categories')?>
                    </label>
                  </div>

                <?php elseif ($type === 'shortcode_preset'): ?>
                  <div style="grid-column:1/-1;">
                    <label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted);margin-bottom:3px;"><?=_e('Select Preset')?></label>
                    <select name="widget[<?= $itemId ?>][config][preset_slug]" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2);border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">
                      <option value=""><?=_e('- Select -')?></option>
                      <?php foreach ($presets as $p): ?>
                        <option value="<?= h($p['slug']) ?>" <?= ($config['preset_slug'] ?? '') === $p['slug'] ? 'selected' : '' ?>><?= h($p['title']) ?> (<?= h($p['slug']) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (empty($presets)): ?>
                      <div style="margin-top:6px;font-size:12px;color:var(--adam-muted);"><?=__('No preset yet.')?> <a href="<?= h($base . '/?page=admin/shortcodes/edit') ?>"><?=__('Create preset')?></a> <?=__('first.')?></div>
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
      <button type="submit" class="adam-button" style="padding:8px 24px;"><?=_e('Save All Widgets')?></button>
      <a class="adam-cancle" href="<?= h($base . '/?page=admin/settings/index') ?>"><?=_e('Back to Settings')?></a>
    </div>
  </form>
  <?php else: ?>
    <div style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:24px;text-align:center;color:var(--adam-muted);font-size:14px;">
      <?=__('No sidebar zones yet.')?> <?=__('Click')?> <strong>+ <?=_e('New Zone')?></strong> <?=__('to create the first zone.')?>
    </div>
  <?php endif; ?>

  <!-- Delete form (hidden, submitted by JS) -->
  <form id="sw-delete-form" method="post" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="delete">
    <input type="hidden" name="delete_id" id="sw-delete-id">
  </form>

  <div style="background:var(--adam-surface-3);border-radius:var(--adam-radius);padding:14px 16px;font-size:12px;color:var(--adam-muted);line-height:1.7;margin-top:20px;">
    <strong style="font-size:13px;"><?= svg_ico('book-open', '', ['style' => 'width:14px;height:14px;vertical-align:middle']) ?> <?=_e('How to Use Sidebar Zones')?></strong>
    <ol style="margin:6px 0 0;padding-left:20px;">
      <li><strong><?=_e('Create Zone')?></strong> — <?=_e('click')?> <strong>+ <?=_e('New Zone')?></strong> <?=_e('to create a new sidebar zone (e.g. Main, Alt, Footer).')?></li>
      <li><strong><?=_e('Select Primary')?></strong> — <?=_e('zone marked with')?> <strong>★</strong> <?=_e('is')?> <strong><?=_e('primary')?></strong> <?=_e('and will appear on the front page. Use the')?> <strong><?=_e('Make Primary')?></strong> <?=_e('button to change it.')?></li>
      <li><strong><?=_e('Add Widget')?></strong> — <?=_e('select a zone, then choose a type from the dropdown, click')?> <strong><?=_e('Add')?></strong>.</li>
      <li><strong><?=_e('Configure')?></strong> — <?=_e('click the widget title to expand the edit form.')?></li>
      <li><strong><?=_e('Reorder')?></strong> — <?=_e('use the ▲ (up) / ▼ (down) buttons to change widget position.')?></li>
      <li><strong><?=_e('Active/Inactive')?></strong> — <?=_e('check the')?> <strong><?=_e('Active')?></strong> <?=_e('checkbox to show or hide a widget.')?></li>
      <li><?=_e('Click')?> <strong><?=_e('Save All Widgets')?></strong> <?=_e('to save configuration changes and order.')?></li>
      <li><?=_e('Results are immediately visible on the website front page according to the selected primary zone.')?></li>
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

function deleteWidget(btn, id) {
  if (!confirm('<?=__('Delete this widget?')?>')) return;
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

function showCreateZone() {
  var box = document.getElementById('create-zone-box');
  box.style.display = box.style.display === 'none' ? 'block' : 'none';
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
