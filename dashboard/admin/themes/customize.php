<?php
declare(strict_types=1);

// /adiwira/admin/themes/customize.php — Theme Customizer + Layout Editor
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
$selfUrl = $base . '/?page=admin/themes/customize';

$folder = get_active_theme_folder($pdo);
$sections = theme_customizer_fields($folder);
$hasFields = !empty($sections);

$menus = function_exists('menu_get_all') ? menu_get_all($pdo) : [];
$zones = function_exists('sidebar_zone_get_all') ? sidebar_zone_get_all($pdo) : [];
$mods = theme_mods_all($pdo, $folder);

$tzWidgets = function_exists('theme_zone_default_widget_types') ? theme_zone_default_widget_types() : [];
$themeLayout = function_exists('theme_zone_layout') ? theme_zone_layout($folder) : [];

// Field sanitizers for saving
$field_sanitizers = [
    'image'        => fn($v) => preg_match('#^(/|https?://)#i', trim((string)$v)) ? trim((string)$v) : '',
    'menu'         => fn($v) => preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)$v)),
    'sidebar_zone' => fn($v) => preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)$v)),
    'text'         => fn($v) => trim((string)$v),
    'textarea'     => fn($v) => trim((string)$v),
    'toggle'       => fn($v) => (bool)$v,
];

$csrfOk = function_exists('csrf_check') && csrf_check((string)($_POST['csrf_token'] ?? ''));

// ─── Save customizer fields ───
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['tc_save'])) {
    if (!$csrfOk) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        return;
    }

    foreach ($sections as $sectionKey => $section) {
        if ($sectionKey === 'main') continue;
        foreach ($section['fields'] as $key => $def) {
            $type = $def['type'];
            $raw = $_POST[$key] ?? '';
            $mods[$key] = $field_sanitizers[$type]($raw);
        }
    }

    // Remove keys that are no longer declared (schema changed / renamed) - skip main keys
    $validKeys = [];
    foreach ($sections as $sectionKey => $section) {
        if ($sectionKey === 'main') continue;
        foreach ($section['fields'] as $key => $def) {
            $validKeys[$key] = true;
        }
    }
    $mods = array_intersect_key($mods, $validKeys);

    theme_mods_save($pdo, $folder, $mods);
    adiwira_redirect_with_flash($selfUrl, 'success', __('Customization saved.'));
    return;
}

// ─── Save main layout settings ───
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['tc_main_save'])) {
    if (!$csrfOk) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        return;
    }
    $mainSection = $sections['main'] ?? [];
    if (!empty($mainSection['fields']) && is_array($mainSection['fields'])) {
        foreach ($mainSection['fields'] as $key => $def) {
            $type = $def['type'];
            $raw = $_POST[$key] ?? '';
            $mods[$key] = $field_sanitizers[$type]($raw);
        }
        theme_mods_save($pdo, $folder, $mods);
    }
    adiwira_redirect_with_flash($selfUrl . '&tab=main', 'success', __('Main layout saved.'));
    return;
}

// ─── Theme zone CRUD ───

function tz_sanitize_config(string $type, array $config, array $tzWidgets): array {
    $out = [];
    $defaults = $tzWidgets[$type]['default_config'] ?? [];

    if (array_key_exists('title', $config)) {
        $out['title'] = trim((string)$config['title']);
    }

    switch ($type) {
        case 'tz_logo':
            $out['use_logo'] = !empty($config['use_logo']);
            $out['show_title'] = !empty($config['show_title']);
            $out['html'] = (string)($config['html'] ?? '');
            break;
        case 'tz_nav_menu':
            $out['menu'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)($config['menu'] ?? $defaults['menu'] ?? 'primary')));
            $out['menu_class'] = preg_replace('/[^a-zA-Z0-9_\- ]/', '', trim((string)($config['menu_class'] ?? $defaults['menu_class'] ?? 'menu')));
            $out['depth'] = max(0, min(5, (int)($config['depth'] ?? $defaults['depth'] ?? 1)));
            $out['ul_attr'] = trim((string)($config['ul_attr'] ?? $defaults['ul_attr'] ?? ''));
            break;
        case 'tz_theme_toggle':
            $out['label'] = trim((string)($config['label'] ?? $defaults['label'] ?? ''));
            break;
        case 'tz_lang_switcher':
            $out['title'] = trim((string)($config['title'] ?? $defaults['title'] ?? ''));
            break;
        case 'tz_search':
            $out['placeholder'] = trim((string)($config['placeholder'] ?? $defaults['placeholder'] ?? __('Search...')));
            $out['button'] = !empty($config['button']);
            break;
        case 'tz_html':
            $out['html'] = (string)($config['html'] ?? '');
            break;
        case 'tz_post_author':
            $out['show_avatar'] = !empty($config['show_avatar']);
            break;
        case 'tz_post_meta':
            $out['show_date'] = !empty($config['show_date']);
            $out['show_updated'] = !empty($config['show_updated']);
            $out['show_read_time'] = !empty($config['show_read_time']);
            break;
    }
    return $out;
}

function tz_widget_config_field(string $zoneSlug, string $position, int $itemId, string $key, string $label, $value, string $type = 'text', array $options = []): string {
    $name = "widget[{$itemId}][config][" . h($key) . "]";
    $id = 'tz-' . h($zoneSlug) . '-' . h($position) . '-' . $itemId . '-' . h($key);
    $out = '<label for="' . $id . '" style="display:block; font-size:12px; font-weight:600; color:var(--adam-muted, #777); margin-bottom:4px;">' . h($label) . '</label>';
    if ($type === 'select') {
        $out .= '<select name="' . $name . '" id="' . $id . '" style="width:100%; padding:6px 10px; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px;">';
        foreach ($options as $optVal => $optLabel) {
            $out .= '<option value="' . h((string)$optVal) . '"' . ((string)$value === (string)$optVal ? ' selected' : '') . '>' . h($optLabel) . '</option>';
        }
        $out .= '</select>';
    } elseif ($type === 'textarea') {
        $out .= '<textarea name="' . $name . '" id="' . $id . '" rows="3" style="width:100%; padding:6px 10px; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px; font-family:inherit; resize:vertical;">' . h((string)$value) . '</textarea>';
    } elseif ($type === 'checkbox') {
        $checked = (bool)$value ? ' checked' : '';
        $out .= '<input type="hidden" name="' . $name . '" value="0">';
        $out .= '<label style="display:inline-flex; align-items:center; gap:6px; font-weight:400; font-size:13px; cursor:pointer;">';
        $out .= '<input type="checkbox" name="' . $name . '" value="1"' . $checked . ' style="width:16px; height:16px; accent-color:var(--adam-primary);">';
        $out .= '<span>' . h($label) . '</span></label>';
    } else {
        $out .= '<input type="text" name="' . $name . '" id="' . $id . '" value="' . h((string)$value) . '" style="width:100%; padding:6px 10px; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px; box-sizing:border-box;">';
    }
    return $out;
}

function tz_widget_config_form(string $zoneSlug, string $position, int $itemId, array $item, array $menus): string {
    $type = (string)($item['type'] ?? '');
    $config = json_decode((string)($item['config'] ?? '{}'), true) ?: [];
    $out = '';

    $out .= '<div style="grid-column:1/-1;">';
    $out .= tz_widget_config_field($zoneSlug, $position, $itemId, 'title', __('Widget Title'), $config['title'] ?? $item['title'] ?? '');
    $out .= '</div>';

    switch ($type) {
        case 'tz_logo':
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'use_logo', __('Use logo image'), $config['use_logo'] ?? true, 'checkbox') . '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'show_title', __('Show site title'), $config['show_title'] ?? false, 'checkbox') . '</div>';
            $out .= '<div style="grid-column:1/-1;">' . tz_widget_config_field($zoneSlug, $position, $itemId, 'html', __('Custom HTML (overrides logo/title)'), $config['html'] ?? '', 'textarea') . '</div>';
            break;
        case 'tz_nav_menu':
            $menuOptions = ['' => __('Theme default')];
            foreach ($menus as $m) { $menuOptions[(string)($m['slug'] ?? '')] = (string)($m['name'] ?? $m['slug'] ?? ''); }
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'menu', __('Menu'), $config['menu'] ?? 'primary', 'select', $menuOptions) . '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'menu_class', __('CSS class'), $config['menu_class'] ?? 'menu') . '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'depth', __('Depth'), $config['depth'] ?? 1) . '</div>';
            $out .= '<div style="grid-column:1/-1;">' . tz_widget_config_field($zoneSlug, $position, $itemId, 'ul_attr', __('UL attributes'), $config['ul_attr'] ?? '') . '</div>';
            break;
        case 'tz_theme_toggle':
            $out .= '<div style="grid-column:1/-1;">' . tz_widget_config_field($zoneSlug, $position, $itemId, 'label', __('Label'), $config['label'] ?? '') . '</div>';
            break;
        case 'tz_lang_switcher':
            $out .= '<div style="grid-column:1/-1;">' . tz_widget_config_field($zoneSlug, $position, $itemId, 'title', __('Title'), $config['title'] ?? '') . '</div>';
            break;
        case 'tz_search':
            $out .= '<div style="grid-column:1/-1;">' . tz_widget_config_field($zoneSlug, $position, $itemId, 'placeholder', __('Placeholder'), $config['placeholder'] ?? __('Search...')) . '</div>';
            $out .= '<div style="grid-column:1/-1;">' . tz_widget_config_field($zoneSlug, $position, $itemId, 'button', __('Show button'), $config['button'] ?? false, 'checkbox') . '</div>';
            break;
        case 'tz_html':
            $out .= '<div style="grid-column:1/-1;">' . tz_widget_config_field($zoneSlug, $position, $itemId, 'html', __('HTML Content'), $config['html'] ?? '', 'textarea') . '</div>';
            break;
        case 'tz_post_author':
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'show_avatar', __('Show avatar'), $config['show_avatar'] ?? true, 'checkbox') . '</div>';
            break;
        case 'tz_post_meta':
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'show_date', __('Show date'), $config['show_date'] ?? true, 'checkbox') . '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'show_updated', __('Show updated'), $config['show_updated'] ?? false, 'checkbox') . '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'show_read_time', __('Show read time'), $config['show_read_time'] ?? true, 'checkbox') . '</div>';
            break;
    }
    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['tz_action']) && function_exists('theme_zone_add_item')) {
    if (!$csrfOk) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        return;
    }
    $action = (string)$_POST['tz_action'];
    $allowedPostZones = [];
    foreach ($themeLayout as $lk => $lv) {
        if ($lk !== 'main' && !empty($lv['positions']) && is_array($lv['positions'])) $allowedPostZones[] = $lk;
    }
    if (empty($allowedPostZones)) $allowedPostZones = ['header'];
    $zone = in_array(($_POST['tz_zone'] ?? ''), $allowedPostZones, true) ? $_POST['tz_zone'] : $allowedPostZones[0];
    $position = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['tz_position'] ?? ''));

    if ($action === 'add' && !empty($_POST['tz_type'])) {
        $type = (string)$_POST['tz_type'];
        $config = (isset($tzWidgets[$type]) && is_array($tzWidgets[$type]['default_config'] ?? null)) ? $tzWidgets[$type]['default_config'] : [];
        $title = trim((string)($_POST['tz_title'] ?? ''));
        if ($title !== '') $config['title'] = $title;
        if (theme_zone_add_item($pdo, $zone, $type, $config, (string)($tzWidgets[$type]['label'] ?? $type), $position, $folder)) {
            adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'success', __('Widget added to %s.', [($themeLayout[$zone]['positions'][$position]['label'] ?? $position)]));
            return;
        }
        adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'error', __('Failed to add widget.'));
        return;
    }

    if ($action === 'save' && !empty($_POST['widget']) && is_array($_POST['widget'])) {
        $orderRaw = (string)($_POST['_widget_order'] ?? '');
        $orderIds = array_filter(array_map('trim', explode(',', $orderRaw)));
        $submitted = (array)$_POST['widget'];
        $idx = 0;
        foreach ($orderIds as $wid) {
            $wid = (int)$wid;
            if ($wid <= 0 || !isset($submitted[$wid])) continue;
            $data = $submitted[$wid];
            $type = (string)($data['type'] ?? '');
            if (!isset($tzWidgets[$type])) continue;
            $config = (array)($data['config'] ?? []);
            $title = trim((string)($config['title'] ?? ''));
            $active = !empty($data['active']);
            $newPosition = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['position'] ?? ''));
            $config = tz_sanitize_config($type, $config, $tzWidgets);
            theme_zone_update_item($pdo, $wid, $config, $title, $newPosition);
            theme_zone_toggle_item($pdo, $wid, $active);
            theme_zone_set_order($pdo, $wid, $idx++);
        }
        adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'success', __('Zone settings saved.'));
        return;
    }

    if ($action === 'delete' && !empty($_POST['tz_id'])) {
        $id = (int)$_POST['tz_id'];
        if (theme_zone_delete_item($pdo, $id)) {
            adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'success', __('Widget removed.'));
            return;
        }
        adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'error', __('Failed to remove widget.'));
        return;
    }

    if ($action === 'defaults') {
        $existing = function_exists('theme_zone_items') ? theme_zone_items($pdo, $zone, null, $folder, false) : [];
        if (!empty($existing)) {
            adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'warning', __('Zone already has widgets. Remove them first to load defaults.'));
            return;
        }

        $layoutDefaults = $themeLayout[$zone]['defaults'] ?? [];
        if (empty($layoutDefaults) || !is_array($layoutDefaults)) {
            // Fallback generic defaults hanya untuk header/footer; zone lain harus declare sendiri
            if ($zone === 'header') {
                $layoutDefaults = [
                    'logo' => [['type' => 'tz_logo', 'title' => __('Site Logo'), 'config' => ['use_logo' => true, 'show_title' => false]]],
                    'nav' => [['type' => 'tz_nav_menu', 'title' => __('Navigation'), 'config' => ['menu' => 'primary', 'menu_class' => 'menu', 'depth' => 1, 'ul_attr' => '']]],
                    'controls' => [
                        ['type' => 'tz_theme_toggle', 'title' => __('Theme Toggle'), 'config' => ['label' => '']],
                        ['type' => 'tz_lang_switcher', 'title' => __('Language'), 'config' => ['title' => '']],
                        ['type' => 'tz_search', 'title' => __('Search'), 'config' => ['placeholder' => __('Search...'), 'button' => false]],
                    ],
                ];
            } elseif ($zone === 'footer') {
                $layoutDefaults = [
                    'main' => [['type' => 'tz_html', 'title' => __('Footer Text'), 'config' => ['title' => '', 'html' => '<p>&copy; ' . date('Y') . ' ' . h($site['title'] ?? '') . '</p>']]],
                ];
            } else {
                adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'warning', __('This zone does not declare default gadgets.'));
                return;
            }
        }

        foreach ($layoutDefaults as $position => $items) {
            if (!is_array($items)) continue;
            // A single default widget can be declared as an object instead of an array
            if (isset($items['type'])) { $items = [$items]; }
            foreach ($items as $d) {
                if (!is_array($d) || empty($d['type'])) continue;
                $type = (string)$d['type'];
                $title = trim((string)($d['title'] ?? ($tzWidgets[$type]['label'] ?? $type)));
                $config = is_array($d['config'] ?? null) ? $d['config'] : ($tzWidgets[$type]['default_config'] ?? []);
                theme_zone_add_item($pdo, $zone, $type, $config, $title, $position, $folder);
            }
        }

        adiwira_redirect_with_flash($selfUrl . '&tab=' . $zone, 'success', __('Default layout loaded.'));
        return;
    }
}

// Zone tabs — dibangun dari layout tema aktif (header/main/footer dulu, sisanya menyusul)
$zoneZones = [];
foreach (['header', 'main', 'footer'] as $preferred) {
    if (isset($themeLayout[$preferred])) {
        $zoneZones[$preferred] = (string)($themeLayout[$preferred]['label'] ?? ucfirst($preferred));
    }
}
foreach ($themeLayout as $zSlug => $zDef) {
    if (!isset($zoneZones[$zSlug])) {
        $zoneZones[$zSlug] = (string)($zDef['label'] ?? ucwords(str_replace(['.', '_', '-'], ' ', (string)$zSlug)));
    }
}
$zoneSlugs = array_keys($zoneZones);
$activeZoneTab = in_array(($_GET['tab'] ?? ''), $zoneSlugs, true) ? (string)$_GET['tab'] : ($zoneSlugs[0] ?? 'header');

// Zone yang boleh menerima gadget (punya positions dan bukan main yang config-only)
$gadgetZones = array_values(array_filter($zoneSlugs, function ($z) use ($themeLayout) {
    return $z !== 'main' && !empty($themeLayout[$z]['positions']) && is_array($themeLayout[$z]['positions']);
}));
?>

<div class="tc-wrap" style="max-width:1100px;">
  <div class="tc-header" style="margin-bottom:1.25rem;">
    <h2 style="margin:0 0 .25rem;"><?= __('Customize Layout') ?> — <?= h($folder) ?></h2>
    <p class="muted"><?= __('Drag or place gadgets into the theme layout positions. Each position maps to a real area in the active theme.') ?></p>
  </div>

  <?php foreach ($page_toasts as $t): ?>
    <div class="adam-alert <?= h($t['type'] ?? 'success') ?> auto-dismiss"><?= h((string)($t['message'] ?? '')) ?></div>
  <?php endforeach; ?>

  <?php if (empty($themeLayout)): ?>
    <div class="adam-alert warning"><?= __('Active theme does not declare a layout. Theme zones are disabled.') ?></div>
  <?php endif; ?>

  <!-- Theme Layout Editor -->
  <?php if (!empty($themeLayout)): ?>
    <!-- Page map wireframe (ala Blogspot) -->
    <div class="tz-pagemap" style="margin-bottom:1.25rem;">
      <?php foreach ($zoneZones as $pmSlug => $pmLabel): ?>
        <?php
        $pmDef = $themeLayout[$pmSlug] ?? [];
        $pmPositions = array_keys($pmDef['positions'] ?? []);
        ?>
        <a href="<?= h($selfUrl) ?>&tab=<?= h($pmSlug) ?>" class="tz-pm-row<?= $activeZoneTab === $pmSlug ? ' active' : '' ?>">
          <span class="tz-pm-label"><?= h($pmLabel) ?></span>
          <span class="tz-pm-cells">
            <?php if ($pmSlug === 'main'): ?>
              <span class="tz-pm-cell tz-pm-cell-wide"><?= __('Content') ?></span>
              <span class="tz-pm-cell tz-pm-cell-side"><?= __('Sidebar') ?></span>
            <?php elseif (empty($pmPositions)): ?>
              <span class="tz-pm-cell tz-pm-cell-wide">—</span>
            <?php else: ?>
              <?php foreach ($pmPositions as $pmPos): ?>
                <span class="tz-pm-cell"><?= h((string)($pmDef['positions'][$pmPos]['label'] ?? $pmPos)) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="tz-layout-tabs" style="display:flex; gap:.5rem; margin-bottom:1rem; border-bottom:1px solid rgba(127,127,127,.18); overflow-x:auto;">
      <?php foreach ($zoneZones as $zSlug => $zLabel): ?>
        <a href="<?= h($selfUrl) ?>&tab=<?= h($zSlug) ?>" style="padding:.5rem 1rem; text-decoration:none; border-bottom:2px solid transparent; <?= $activeZoneTab === $zSlug ? 'border-bottom-color:var(--adam-primary, #0066ff); font-weight:600;' : 'opacity:.7;' ?>"><?= h($zLabel) ?></a>
      <?php endforeach; ?>
    </div>

    <?php foreach ($zoneZones as $zSlug => $zLabel): ?>
      <?php if ($activeZoneTab !== $zSlug) continue; ?>
      <?php
      $layoutDef = $themeLayout[$zSlug] ?? null;
      if (empty($layoutDef) || empty($layoutDef['positions']) || !is_array($layoutDef['positions'])) continue;
      ?>

      <?php if ($zSlug === 'main'): ?>
        <div class="tz-layout tz-layout-main" data-zone="main">
          <h3 style="margin:0 0 .75rem; font-size:1.1rem;"><?= h($layoutDef['label'] ?? $zLabel) ?></h3>
          <p class="muted" style="margin-top:-.5rem; margin-bottom:1rem;"><?= __('Configure the main content area and sidebar visibility.') ?></p>

          <?php $mainSection = $sections['main'] ?? []; ?>
          <?php if (!empty($mainSection['fields'])): ?>
            <form method="post" style="display:flex; flex-direction:column; gap:1rem; max-width:700px;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="tc_main_save" value="1">

              <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
                <?php foreach ($mainSection['fields'] as $key => $def): ?>
                  <div class="tc-field" style="background:var(--adam-card, rgba(127,127,127,.04)); border:1px solid rgba(127,127,127,.18); border-radius:8px; padding:1rem;">
                    <label style="display:block; font-weight:600; font-size:.9rem; margin-bottom:.5rem;"><?= h($def['label']) ?></label>

                    <?php if ($def['type'] === 'toggle'): ?>
                      <?php $on = (bool)($mods[$key] ?? true); ?>
                      <label style="display:inline-flex; align-items:center; gap:.5rem; font-weight:400; cursor:pointer;">
                        <input type="checkbox" name="<?= h($key) ?>" value="1" <?= $on ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <span class="muted"><?= __('Enabled') ?></span>
                      </label>
                    <?php elseif ($def['type'] === 'image'): ?>
                      <input type="text" name="<?= h($key) ?>" value="<?= h((string)($mods[$key] ?? '')) ?>" placeholder="/static/img/..." style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">
                    <?php elseif ($def['type'] === 'menu'): ?>
                      <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                        <option value=""><?= __('Theme default') ?></option>
                        <?php foreach ($menus as $m): ?>
                          <?php $slug = (string)($m['slug'] ?? ''); ?>
                          <option value="<?= h($slug) ?>" <?= ((string)($mods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($m['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($def['type'] === 'sidebar_zone'): ?>
                      <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                        <option value=""><?= __('None') ?></option>
                        <?php foreach ($zones as $z): ?>
                          <?php $slug = (string)($z['slug'] ?? ''); ?>
                          <option value="<?= h($slug) ?>" <?= ((string)($mods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($z['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    <?php elseif ($def['type'] === 'textarea'): ?>
                      <textarea name="<?= h($key) ?>" rows="3" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; font:inherit; box-sizing:border-box;"><?= h((string)($mods[$key] ?? '')) ?></textarea>
                    <?php elseif ($def['type'] === 'text'): ?>
                      <input type="text" name="<?= h($key) ?>" value="<?= h((string)($mods[$key] ?? '')) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>

              <div>
                <button type="submit" class="btn btn-primary"><?= __('Save Main Layout') ?></button>
              </div>
            </form>
          <?php else: ?>
            <div class="adam-alert info"><?= __('No main layout options declared by this theme.') ?></div>
          <?php endif; ?>

          <div style="margin-top:1.5rem; padding:1rem; background:var(--adam-card, rgba(127,127,127,.04)); border:1px dashed rgba(127,127,127,.35); border-radius:8px; text-align:center; color:var(--adam-muted); font-size:13px;"
               title="<?= __('Visual preview of the main + sidebar layout') ?>">
            <div style="font-weight:600; margin-bottom:.5rem;"><?= __('Main Area') ?></div>
            <div class="tz-main-preview" style="display:grid; grid-template-columns: 3fr 1fr; gap:1rem; opacity:.7;">
              <div style="border:1px dashed rgba(127,127,127,.35); border-radius:6px; padding:1rem;"><?= __('Content') ?></div>
              <div style="border:1px dashed rgba(127,127,127,.35); border-radius:6px; padding:1rem;"><?= __('Sidebar') ?></div>
            </div>
          </div>
        </div>
        <?php continue; ?>
      <?php endif; ?>

      <div class="tz-layout" data-zone="<?= h($zSlug) ?>">
        <h3 style="margin:0 0 .75rem; font-size:1.1rem;"><?= h($layoutDef['label'] ?? $zLabel) ?></h3>

        <form method="post" style="margin-bottom:.75rem; display:inline-block;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="tz_action" value="defaults">
          <input type="hidden" name="tz_zone" value="<?= h($zSlug) ?>">
          <button type="submit" class="btn btn-sm btn-secondary" style="padding:.3rem .75rem; font-size:13px;" onclick="return confirm('<?= __('Load default gadgets for this zone?') ?>')">
            <?= __('Load Default Layout') ?>
          </button>
        </form>

        <?php
        $posCount = count($layoutDef['positions']);
        $cols = (int)($layoutDef['columns'] ?? min(max($posCount, 1), 4));
        $cols = max(1, min(4, $cols));
        ?>
        <div class="tz-layout-grid" data-cols="<?= $cols ?>">
          <?php foreach ($layoutDef['positions'] as $posKey => $posDef): ?>
            <?php
            $posItems = function_exists('theme_zone_items') ? theme_zone_items($pdo, $zSlug, $posKey, $folder, false) : [];
            ?>
            <div class="tz-position" data-position="<?= h($posKey) ?>" style="background:var(--adam-card, rgba(127,127,127,.04)); border:1px dashed rgba(127,127,127,.35); border-radius:8px; padding:1rem; min-height:120px;">
              <div style="font-weight:700; font-size:.85rem; margin-bottom:.75rem; color:var(--adam-muted); text-transform:uppercase; letter-spacing:.5px;"><?= h($posDef['label'] ?? $posKey) ?></div>

              <form method="post" id="tz-form-<?= h($zSlug) ?>-<?= h($posKey) ?>">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="tz_action" value="save">
                <input type="hidden" name="tz_zone" value="<?= h($zSlug) ?>">
                <input type="hidden" name="_widget_order" id="tz-order-<?= h($zSlug) ?>-<?= h($posKey) ?>" value="<?= h(implode(',', array_map(fn($it) => $it['id'] ?? '', $posItems))) ?>">

                <div class="tz-list" id="tz-list-<?= h($zSlug) ?>-<?= h($posKey) ?>" data-position="<?= h($posKey) ?>" style="display:flex; flex-direction:column; gap:.75rem; margin-bottom:1rem;">
                  <?php if (empty($posItems)): ?>
                    <div class="tz-empty" style="padding:1rem; text-align:center; color:var(--adam-muted); font-size:13px; border:1px dashed rgba(127,127,127,.25); border-radius:6px;"><?= __('Drop gadget here') ?></div>
                  <?php else: ?>
                    <?php foreach ($posItems as $it): ?>
                      <?php
                      $itemId = (int)($it['id'] ?? 0);
                      $type = (string)($it['type'] ?? '');
                      $active = !empty($it['active']);
                      $typeInfo = $tzWidgets[$type] ?? ['label' => ucfirst($type), 'desc' => ''];
                      $isOpen = (string)($_GET['edit'] ?? '') === (string)$itemId;
                      ?>
                      <div class="tz-item" data-id="<?= $itemId ?>" style="background:var(--adam-bg); border:1px solid rgba(127,127,127,.18); border-radius:8px; <?= $active ? '' : 'opacity:.55;' ?>">
                        <input type="hidden" name="widget[<?= $itemId ?>][type]" value="<?= h($type) ?>">
                        <input type="hidden" name="widget[<?= $itemId ?>][position]" value="<?= h($posKey) ?>">

                        <div class="tz-header" style="display:flex; align-items:center; gap:.5rem; padding:.6rem .9rem; cursor:pointer; user-select:none;" onclick="tzToggleWidget(this)">
                          <span class="tz-grip" style="cursor:grab; color:var(--adam-muted); font-size:18px; line-height:1; touch-action:none;" title="<?= __('Drag to reorder or move to another position') ?>" onclick="event.stopPropagation();"><?= '⠿' ?></span>
                          <span style="background:var(--adam-primary-soft, rgba(127,127,127,.18)); color:var(--adam-primary, inherit); padding:2px 8px; border-radius:5px; font-size:11px; font-weight:700; white-space:nowrap;"><?= h($typeInfo['label']) ?></span>
                          <span style="flex:1; font-weight:600; font-size:14px;"><?= h($it['title'] ?: $typeInfo['label']) ?></span>

                          <input type="hidden" name="widget[<?= $itemId ?>][active]" value="0">
                          <label style="display:flex; align-items:center; gap:4px; cursor:pointer; font-size:12px; white-space:nowrap;" onclick="event.stopPropagation();">
                            <input type="checkbox" name="widget[<?= $itemId ?>][active]" value="1" <?= $active ? 'checked' : '' ?> style="width:16px; height:16px; accent-color:var(--adam-primary); cursor:pointer;" onchange="this.closest('.tz-item').style.opacity = this.checked ? '' : '0.55';">
                            <?= __('Active') ?>
                          </label>

                          <div style="display:flex; gap:4px;">
                            <button type="button" onclick="event.stopPropagation(); tzMoveWidget(this, -1)" style="background:none; border:1px solid rgba(127,127,127,.25); border-radius:4px; padding:2px 6px; cursor:pointer; font-size:13px; color:var(--adam-muted);" title="<?= __('Move up') ?>">▲</button>
                            <button type="button" onclick="event.stopPropagation(); tzMoveWidget(this, 1)" style="background:none; border:1px solid rgba(127,127,127,.25); border-radius:4px; padding:2px 6px; cursor:pointer; font-size:13px; color:var(--adam-muted);" title="<?= __('Move down') ?>">▼</button>
                          </div>
                          <button type="button" onclick="event.stopPropagation(); tzDeleteWidget(<?= $itemId ?>, '<?= h($zSlug) ?>')" style="background:none; border:none; cursor:pointer; color:var(--adam-danger); font-size:18px; line-height:1; padding:2px 4px;" title="<?= __('Delete widget') ?>">×</button>
                        </div>

                        <div class="tz-body" style="border-top:1px solid rgba(127,127,127,.18); padding:1rem; display:<?= $isOpen ? 'block' : 'none' ?>;">
                          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:600px;">
                            <?= tz_widget_config_form($zSlug, $posKey, $itemId, $it, $menus) ?>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>

                <?php if (!empty($posItems)): ?>
                  <div style="margin-bottom:1rem;">
                    <button type="submit" class="btn btn-primary" style="padding:.45rem 1rem;"><?= __('Save Widgets') ?></button>
                  </div>
                <?php endif; ?>
              </form>

              <!-- Add Widget to this position -->
              <div style="background:var(--adam-surface-2, rgba(127,127,127,.08)); border-radius:6px; padding:.75rem;">
                <form class="tz-add-form" method="post" style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="tz_action" value="add">
                  <input type="hidden" name="tz_zone" value="<?= h($zSlug) ?>">
                  <input type="hidden" name="tz_position" value="<?= h($posKey) ?>">
                  <select name="tz_type" required style="flex:1; min-width:140px; padding:.4rem .6rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px;">
                    <option value=""><?= __('- Select gadget -') ?></option>
                    <?php foreach ($tzWidgets as $typeKey => $typeDef): ?>
                      <option value="<?= h($typeKey) ?>"><?= h($typeDef['label'] ?? $typeKey) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="tz_title" placeholder="<?= __('Title (optional)') ?>" style="flex:1; min-width:120px; padding:.4rem .6rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px;">
                  <button type="submit" class="btn btn-secondary" style="padding:.4rem .75rem;"><?= __('Add') ?></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Theme Settings (legacy customizer fields) -->
  <?php if ($hasFields): ?>
    <div class="tc-panel" style="margin-top:2rem; background:var(--adam-card, transparent); border:1px solid rgba(127,127,127,.18); border-radius:8px; padding:1.25rem;">
      <h3 style="margin:0 0 1rem; font-size:1.1rem; border-bottom:1px solid rgba(127,127,127,.18); padding-bottom:.5rem;"><?= __('Theme Settings') ?></h3>
      <p class="muted" style="margin-top:-.5rem; margin-bottom:1rem;"><?= __('Global options declared by the theme in theme.json.') ?></p>

      <form method="post" style="display:flex; flex-direction:column; gap:1.5rem;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="tc_save" value="1">

      <?php foreach ($sections as $sectionKey => $section): ?>
        <?php if ($sectionKey === 'main') continue; ?>
        <div>
          <h4 style="margin:0 0 .75rem; font-size:1rem;"><?= h($section['label']) ?></h4>
            <div style="display:grid; grid-template-columns: 1fr; gap:1.25rem;">
              <?php foreach ($section['fields'] as $key => $def): ?>
                <div class="tc-field">
                  <label style="display:block; font-weight:600; font-size:.9rem; margin-bottom:.4rem;"><?= h($def['label']) ?></label>

                  <?php if ($def['type'] === 'image'): ?>
                    <input type="text" name="<?= h($key) ?>" id="tc-<?= h($key) ?>" value="<?= h((string)($mods[$key] ?? '')) ?>"
                           placeholder="/static/img/logo.svg" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">
                    <div class="tc-image-preview" id="tc-preview-<?= h($key) ?>" style="margin-top:.6rem; <?= empty($mods[$key]) ? 'display:none;' : '' ?>">
                      <img src="<?= h((string)($mods[$key] ?? '')) ?>" alt="" style="max-height:56px; max-width:220px; border-radius:6px; background:rgba(127,127,127,.1); padding:6px;">
                    </div>

                  <?php elseif ($def['type'] === 'menu'): ?>
                    <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                      <option value=""><?= __('Theme default') ?></option>
                      <?php foreach ($menus as $m): ?>
                        <?php $slug = (string)($m['slug'] ?? ''); ?>
                        <option value="<?= h($slug) ?>" <?= ((string)($mods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($m['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                      <?php endforeach; ?>
                    </select>

                  <?php elseif ($def['type'] === 'sidebar_zone'): ?>
                    <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                      <option value=""><?= __('None') ?></option>
                      <?php foreach ($zones as $z): ?>
                        <?php $slug = (string)($z['slug'] ?? ''); ?>
                        <option value="<?= h($slug) ?>" <?= ((string)($mods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($z['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                      <?php endforeach; ?>
                    </select>

                  <?php elseif ($def['type'] === 'textarea'): ?>
                    <textarea name="<?= h($key) ?>" rows="3" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; font:inherit; box-sizing:border-box;"><?= h((string)($mods[$key] ?? '')) ?></textarea>

                  <?php elseif ($def['type'] === 'text'): ?>
                    <input type="text" name="<?= h($key) ?>" value="<?= h((string)($mods[$key] ?? '')) ?>"
                           style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">

                  <?php elseif ($def['type'] === 'toggle'): ?>
                    <?php $on = (bool)($mods[$key] ?? true); ?>
                    <label style="display:inline-flex; align-items:center; gap:.5rem; font-weight:400; cursor:pointer;">
                      <input type="checkbox" name="<?= h($key) ?>" value="1" <?= $on ? 'checked' : '' ?>
                             style="width:18px; height:18px;">
                      <span class="muted"><?= __('Enabled') ?></span>
                    </label>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div>
          <button type="submit" class="btn btn-primary"><?= __('Save Theme Settings') ?></button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</div>

<form id="tz-delete-form" method="post" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="tz_action" value="delete">
  <input type="hidden" name="tz_zone" id="tz-delete-zone" value="">
  <input type="hidden" name="tz_id" id="tz-delete-id" value="">
</form>

<script>
(function(){
  document.querySelectorAll('input[type="text"][id^="tc-"]').forEach(function(inp){
    const preview = document.getElementById('tc-preview-' + inp.id.replace('tc-',''));
    if (!preview) return;
    inp.addEventListener('input', function(){
      const v = inp.value.trim();
      if (v === '') { preview.style.display = 'none'; return; }
      preview.querySelector('img').src = v;
      preview.style.display = 'block';
    });
  });

  window.tzToggleWidget = function(header){
    var body = header.nextElementSibling;
    if (body && body.classList.contains('tz-body')) {
      body.style.display = body.style.display === 'none' ? 'block' : 'none';
    }
  };

  window.tzMoveWidget = function(btn, dir){
    var item = btn.closest('.tz-item');
    if (!item) return;
    var list = item.closest('.tz-list');
    var items = list.querySelectorAll('.tz-item');
    var idx = Array.prototype.indexOf.call(items, item);
    var target = idx + dir;
    if (target < 0 || target >= items.length) return;
    if (dir < 0) {
      list.insertBefore(item, items[target]);
    } else {
      list.insertBefore(item, items[target].nextSibling);
    }
    tzUpdateOrder(list);
    tzMarkDirty(list);
  };

  window.tzDeleteWidget = function(id, zone){
    if (!confirm('<?= __('Remove this widget?') ?>')) return;
    document.getElementById('tz-delete-id').value = id;
    document.getElementById('tz-delete-zone').value = zone;
    document.getElementById('tz-delete-form').submit();
  };

  window.tzUpdateOrder = function(list){
    var suffix = list.getAttribute('id').replace('tz-list-', '');
    var items = list.querySelectorAll('.tz-item');
    var ids = [];
    items.forEach(function(el){ var id = el.getAttribute('data-id'); if (id) ids.push(id); });
    var input = document.getElementById('tz-order-' + suffix);
    if (input) input.value = ids.join(',');
  };

  window.tzMarkDirty = function(list){
    var form = list.closest('form');
    if (!form) return;
    var btn = form.querySelector('button[type="submit"]');
    if (btn && !btn.classList.contains('tz-dirty')) {
      btn.classList.add('tz-dirty');
      btn.textContent = <?= json_encode(__('Save Widgets')) ?> + ' •';
    }
  };

  // ─── Drag & Drop (dalam + antar position) ───
  var TZ_EMPTY_TEXT = <?= json_encode(__('Drop gadget here')) ?>;
  var tzDragItem = null;
  var tzPlaceholder = null;

  function tzGetPlaceholder(){
    if (!tzPlaceholder) {
      tzPlaceholder = document.createElement('div');
      tzPlaceholder.className = 'tz-drop-placeholder';
    }
    return tzPlaceholder;
  }

  function tzRefreshEmpty(list){
    var has = list.querySelector('.tz-item');
    var empty = list.querySelector('.tz-empty');
    if (has && empty) { empty.remove(); return; }
    if (!has && !empty) {
      var d = document.createElement('div');
      d.className = 'tz-empty';
      d.style.cssText = 'padding:1rem; text-align:center; color:var(--adam-muted); font-size:13px; border:1px dashed rgba(127,127,127,.25); border-radius:6px;';
      d.textContent = TZ_EMPTY_TEXT;
      list.appendChild(d);
    }
  }

  document.querySelectorAll('.tz-item').forEach(function(item){
    var handle = item.querySelector('.tz-grip');
    if (!handle) return;
    handle.addEventListener('mousedown', function(){ item.draggable = true; });
    handle.addEventListener('touchstart', function(){ item.draggable = true; }, {passive:true});
    item.addEventListener('dragstart', function(e){
      tzDragItem = item;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', item.getAttribute('data-id') || '');
      setTimeout(function(){ item.classList.add('tz-dragging'); }, 0);
    });
    item.addEventListener('dragend', function(){
      item.draggable = false;
      item.classList.remove('tz-dragging');
      if (tzPlaceholder && tzPlaceholder.parentNode) tzPlaceholder.parentNode.removeChild(tzPlaceholder);
      document.querySelectorAll('.tz-list').forEach(function(l){ l.classList.remove('tz-drag-over'); });
      tzDragItem = null;
    });
  });

  document.querySelectorAll('.tz-list').forEach(function(list){
    list.addEventListener('dragover', function(e){
      if (!tzDragItem) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      list.classList.add('tz-drag-over');
      var ph = tzGetPlaceholder();
      var siblings = Array.prototype.slice.call(list.querySelectorAll('.tz-item:not(.tz-dragging)'));
      var inserted = false;
      for (var i = 0; i < siblings.length; i++) {
        var r = siblings[i].getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) {
          list.insertBefore(ph, siblings[i]);
          inserted = true;
          break;
        }
      }
      if (!inserted) list.appendChild(ph);
    });
    list.addEventListener('dragleave', function(e){
      if (e.relatedTarget && list.contains(e.relatedTarget)) return;
      list.classList.remove('tz-drag-over');
    });
    list.addEventListener('drop', function(e){
      if (!tzDragItem) return;
      e.preventDefault();
      var ph = tzGetPlaceholder();
      if (ph.parentNode === list) {
        list.insertBefore(tzDragItem, ph);
        list.removeChild(ph);
      } else {
        list.appendChild(tzDragItem);
      }
      // pindah position → update hidden input milik item
      var posInput = tzDragItem.querySelector('input[name$="[position]"]');
      if (posInput) posInput.value = list.getAttribute('data-position') || '';
      document.querySelectorAll('.tz-list').forEach(function(l){
        tzRefreshEmpty(l);
        tzUpdateOrder(l);
        tzMarkDirty(l);
      });
    });
  });

  document.querySelectorAll('.tz-list').forEach(function(list){
    var observer = new MutationObserver(function(){ tzUpdateOrder(list); });
    observer.observe(list, { childList: true, subtree: false });
  });
})();
</script>

<style>
.tc-wrap { width: 100%; box-sizing: border-box; }
.tz-layout { max-width: 100%; overflow-x: auto; }
.tz-layout-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
}
@media (min-width: 768px) {
  .tz-layout-grid[data-cols="2"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .tz-layout-grid[data-cols="3"] { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .tz-layout-grid[data-cols="4"] { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
.tz-position { min-width: 0; }
.tz-item { transition: opacity .2s ease; }
.tz-item.tz-dragging { opacity: .35; }
.tz-drop-placeholder {
  border: 2px dashed var(--adam-primary, #0066ff);
  border-radius: 8px;
  min-height: 56px;
  background: var(--adam-primary-soft, rgba(0,102,255,.06));
}
.tz-list.tz-drag-over { outline: 1px dashed rgba(127,127,127,.35); outline-offset: 4px; border-radius: 6px; }
.tz-header { border-radius: var(--adam-radius, 8px); flex-wrap: wrap; }
.tz-header:hover { background: var(--adam-surface-2, rgba(127,127,127,.08)); }
.tz-grip { cursor: grab; }
.tz-grip:active { cursor: grabbing; }
.tz-item .tz-body { grid-template-columns: 1fr; }
button.tz-dirty { box-shadow: 0 0 0 2px var(--adam-primary, #0066ff); }
.tz-add-form {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem;
  align-items: center;
}

/* Page map wireframe (ala Blogspot) */
.tz-pagemap { display: flex; flex-direction: column; gap: .5rem; max-width: 720px; }
.tz-pm-row {
  display: flex; align-items: center; gap: .75rem;
  border: 1px solid rgba(127,127,127,.28); border-radius: 8px;
  padding: .5rem .75rem; text-decoration: none; color: inherit;
  background: var(--adam-card, rgba(127,127,127,.04));
  transition: border-color .15s ease, background .15s ease;
}
.tz-pm-row:hover { border-color: var(--adam-primary, #0066ff); }
.tz-pm-row.active { border-color: var(--adam-primary, #0066ff); background: var(--adam-primary-soft, rgba(0,102,255,.07)); }
.tz-pm-label { flex: 0 0 110px; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--adam-muted); }
.tz-pm-cells { display: flex; gap: .4rem; flex: 1; min-width: 0; }
.tz-pm-cell {
  flex: 1; min-width: 0; text-align: center; font-size: 12px;
  border: 1px dashed rgba(127,127,127,.4); border-radius: 6px;
  padding: .35rem .25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tz-pm-cell-wide { flex: 3; }
.tz-pm-cell-side { flex: 1; }

@media (max-width: 768px) {
  .tz-layout-grid { grid-template-columns: 1fr !important; }
  .tz-header { gap: .35rem; }
  .tz-add-form {
    flex-direction: column;
    align-items: stretch;
  }
  .tz-add-form > * {
    width: 100%;
    box-sizing: border-box;
  }
  .tz-main-preview { grid-template-columns: 1fr !important; }
  .tz-pm-label { flex-basis: 84px; font-size: 11px; }
  .tz-pm-cell { font-size: 11px; padding: .25rem .15rem; }
}
</style>
