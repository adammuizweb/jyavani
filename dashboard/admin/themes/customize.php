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

$menus = function_exists('menu_get_all') ? menu_get_all($pdo) : [];
$zones = function_exists('sidebar_zone_get_all') ? sidebar_zone_get_all($pdo) : [];
$mods = theme_mods_all($pdo, $folder);

// Daftar page published untuk gadget tz_pages
$pagesList = [];
try {
    $pagesList = $pdo->query("SELECT title, slug FROM posts WHERE type = 'page' AND status = 'published' AND is_deleted = 0 ORDER BY title ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Shortcode presets untuk picker di tz_html / tz_richtext
$scPresets = [];
try {
    $scStmt = $pdo->query("SELECT id, title, slug, status FROM posts WHERE type = 'sc_preset' AND is_deleted = 0 ORDER BY title ASC");
    if ($scStmt) {
        $scPresets = array_values(array_filter($scStmt->fetchAll(PDO::FETCH_ASSOC), fn($r) => strtolower((string)($r['status'] ?? '')) !== 'deleted'));
    }
} catch (Throwable $e) {}

$tzWidgets = function_exists('theme_zone_widget_types') ? theme_zone_widget_types() : [];
$themeLayout = function_exists('theme_zone_layout') ? theme_zone_layout($folder) : [];

// Zone labels + daftar partial (zone selain header/footer tampil di area Main)
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

// Partials = semua file di main/ tema (discovery) + zone declared non-header/footer
$discoveredPartials = function_exists('theme_zone_discover_partials') ? theme_zone_discover_partials($folder) : [];
foreach ($discoveredPartials as $slug => $label) {
    if (!isset($zoneZones[$slug])) $zoneZones[$slug] = $label;
}
$partialZones = array_values(array_filter(array_keys($zoneZones), fn($z) => !in_array($z, ['header', 'footer'], true)));
// Pastikan 'main' selalu tersedia jika tema declare layout.main atau customizer section main
if (!in_array('main', $partialZones, true) && (isset($themeLayout['main']) || !empty($sections['main']['fields']))) {
    $partialZones[] = 'main';
    if (!isset($zoneZones['main'])) $zoneZones['main'] = __('Main');
}
// Urutan: main dulu, lalu main.homepage, sisanya menyusul
usort($partialZones, function ($a, $b) {
    $rank = fn($z) => $z === 'main' ? 0 : ($z === 'main.homepage' ? 1 : 2);
    return $rank($a) <=> $rank($b) ?: strnatcasecmp($a, $b);
});
if (empty($partialZones)) $partialZones = ['main'];
$activePartial = (string)($_GET['partial'] ?? '');
if (!in_array($activePartial, $partialZones, true)) {
    $legacyTab = (string)($_GET['tab'] ?? '');
    $activePartial = in_array($legacyTab, $partialZones, true) ? $legacyTab : $partialZones[0];
}

// ─── Mix-aware assignment detection ───
// If any slot is assigned to a theme other than the active theme, or to a
// custom theme post, the Customize canvas is in a mixed state.
$activeTheme = function_exists('get_theme_by_folder') ? get_theme_by_folder($pdo, $folder) : null;
$activeThemeId = $activeTheme ? (int)($activeTheme['id'] ?? 0) : 0;
$mixedAssignments = [];
$isMixedTheme = false;
$checkSlots = array_unique(array_merge(['header', 'footer', 'sidebar'], $partialZones, $zoneSlugs));
foreach ($checkSlots as $slotKey) {
    $assign = function_exists('get_assignment') ? get_assignment($pdo, $slotKey) : null;
    if (!$assign) continue;

    $isOverride = false;
    $overrideType = '';
    $overrideLabel = '';
    $overridePostId = null;
    if (!empty($assign['custom_post_id'])) {
        $post = function_exists('get_post_by_id') ? get_post_by_id($pdo, (int)$assign['custom_post_id']) : null;
        if ($post && (($post['type'] ?? '') === 'theme')) {
            $isOverride = true;
            $overrideType = 'custom_post';
            $overrideLabel = __('Custom Theme Post') . ': ' . ($post['title'] ?? '#' . $assign['custom_post_id']);
            $overridePostId = (int)$assign['custom_post_id'];
        }
    } elseif (!empty($assign['theme_id']) && (int)$assign['theme_id'] !== $activeThemeId) {
        $theme = function_exists('get_theme_by_id') ? get_theme_by_id($pdo, (int)$assign['theme_id']) : null;
        $isOverride = true;
        $overrideType = 'theme';
        $overrideLabel = $theme ? ($theme['folder_name'] ?? __('Other theme')) : __('Other theme');
    }

    if ($isOverride) {
        $isMixedTheme = true;
        $mixedAssignments[$slotKey] = ['type' => $overrideType, 'label' => $overrideLabel];
        if ($overridePostId !== null) {
            $mixedAssignments[$slotKey]['post_id'] = $overridePostId;
        }
    }
}
$canvasThemeLabel = $isMixedTheme ? __('MIX Theme') : h($folder);
$canvasThemeClass = $isMixedTheme ? 'tz-theme-badge tz-theme-badge--mix' : 'tz-theme-badge';

function tz_override_class(string $slotKey, array $mixedAssignments): string {
    if (!isset($mixedAssignments[$slotKey])) return '';
    $type = $mixedAssignments[$slotKey]['type'] ?? '';
    return $type === 'theme' ? 'tz-band--override-theme' : ($type === 'custom_post' ? 'tz-band--override-post' : '');
}

function tz_override_label(string $slotKey, array $mixedAssignments): string {
    if (!isset($mixedAssignments[$slotKey])) return '';
    return (string)($mixedAssignments[$slotKey]['label'] ?? '');
}

function tz_override_type(string $slotKey, array $mixedAssignments): string {
    return $mixedAssignments[$slotKey]['type'] ?? '';
}

function tz_zone_source_folder(string $slotKey, string $activeFolder, array $mixedAssignments): ?string {
    if (!isset($mixedAssignments[$slotKey])) return $activeFolder;
    $type = $mixedAssignments[$slotKey]['type'] ?? '';
    if ($type === 'custom_post') return null;
    if ($type === 'theme') return $mixedAssignments[$slotKey]['label'] ?? $activeFolder;
    return $activeFolder;
}

function tz_override_notice(string $label, ?int $postId = null): string {
    $base = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira';
    $builderUrl = null;
    if ($postId !== null && function_exists('plugin_is_active') && plugin_is_active('jyavani-builder')) {
        $builderUrl = htmlspecialchars($base . '/?page=admin/tools/jyavani-builder&view=builder&post_id=' . $postId, ENT_QUOTES, 'UTF-8');
    }

    $noticeText = '<div class="tz-override-notice__text"><strong>' . __('Override active') . '</strong> — ' . __('This zone is rendered by') . ' <em>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</em>. ' . __('Theme zone gadgets do not apply here.') . '</div>';

    if ($builderUrl !== null) {
        $icon = '<svg class="tz-jb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>';
        return '<div class="tz-override-notice tz-override-notice--with-action">' . $noticeText . '<a class="tz-jb-button" href="' . $builderUrl . '">' . $icon . '<span>' . __('Edit with Jy Builder') . '</span></a></div>';
    }

    return '<div class="tz-override-notice">' . $noticeText . '</div>';
}

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
    adiwira_redirect_with_flash($selfUrl . '&partial=main', 'success', __('Main layout saved.'));
    return;
}

// ─── Theme zone CRUD ───

function tz_sanitize_config(string $type, array $config, array $tzWidgets): array {
    $out = [];
    $defaults = $tzWidgets[$type]['default_config'] ?? [];

    if (array_key_exists('title', $config)) {
        $out['title'] = trim((string)$config['title']);
    }

    // Universal display settings
    $tag = (string)($config['_title_tag'] ?? 'div');
    $out['_title_tag'] = in_array($tag, ['h1','h2','h3','h4','h5','h6'], true) ? $tag : 'div';
    $align = (string)($config['_align_title'] ?? 'left');
    $out['_align_title'] = in_array($align, ['left','center','right'], true) ? $align : 'left';
    $cAlign = (string)($config['_align_content'] ?? 'left');
    $out['_align_content'] = in_array($cAlign, ['left','center','right'], true) ? $cAlign : 'left';

    switch ($type) {
        case 'tz_image':
            $out['src'] = preg_match('#^(/|https?://)#i', trim((string)($config['src'] ?? ''))) ? trim((string)$config['src']) : '';
            $out['alt'] = trim((string)($config['alt'] ?? ''));
            $out['link'] = preg_match('#^(/|https?://)#i', trim((string)($config['link'] ?? ''))) ? trim((string)$config['link']) : '';
            $out['max_width'] = max(0, min(2000, (int)($config['max_width'] ?? 0)));
            break;
        case 'tz_social':
            $nets = function_exists('theme_zone_social_networks') ? theme_zone_social_networks() : [];
            $enabled = array_values(array_intersect(array_keys($nets), array_map('strval', (array)($config['enabled'] ?? []))));
            $links = [];
            foreach ((array)($config['links'] ?? []) as $k => $v) {
                $k = (string)$k;
                if (!isset($nets[$k])) continue;
                $v = trim((string)$v);
                if ($v !== '' && preg_match('#^https?://#i', $v)) $links[$k] = $v;
            }
            $out['enabled'] = $enabled;
            $out['links'] = $links;
            break;
        case 'tz_pages':
            $out['pages'] = array_values(array_filter(array_map(fn($s) => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$s), (array)($config['pages'] ?? []))));
            $out['list_class'] = preg_replace('/[^a-zA-Z0-9_\- ]/', '', trim((string)($config['list_class'] ?? 'tz-pages'))) ?: 'tz-pages';
            break;
        case 'tz_richtext':
            $out['html'] = (string)($config['html'] ?? '');
            break;
        case 'tz_sidebar_zone':
            $out['zone'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', trim((string)($config['zone'] ?? '')));
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

function tz_widget_config_form(string $zoneSlug, string $position, int $itemId, array $item, array $menus, array $sidebarZones = [], array $pagesList = [], string $base = ''): string {
    $type = (string)($item['type'] ?? '');
    $config = json_decode((string)($item['config'] ?? '{}'), true) ?: [];
    $out = '';

    $out .= '<div style="grid-column:1/-1;">';
    $out .= tz_widget_config_field($zoneSlug, $position, $itemId, 'title', __('Widget Title'), $config['title'] ?? $item['title'] ?? '');
    $out .= '</div>';

    // ── Universal display settings ──
    $titleTag = $config['_title_tag'] ?? 'div';
    $alignTitle = $config['_align_title'] ?? 'left';
    $alignContent = $config['_align_content'] ?? 'left';

    // Title tag selector
    $tagName = "widget[{$itemId}][config][_title_tag]";
    $out .= '<div>';
    $out .= '<label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted,#777);margin-bottom:4px;">' . __('Title Tag') . '</label>';
    $out .= '<select name="' . h($tagName) . '" style="width:100%;padding:6px 10px;border:1px solid var(--adam-border-2,rgba(127,127,127,.35));border-radius:6px;background:var(--adam-bg);color:var(--adam-text);font-size:13px;">';
    foreach (['div','h1','h2','h3','h4','h5','h6'] as $opt) {
        $sel = $opt === $titleTag ? ' selected' : '';
        $out .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
    }
    $out .= '</select></div>';

    // Title alignment icon buttons
    $alignFieldName = "widget[{$itemId}][config][_align_title]";
    $out .= '<div>';
    $out .= '<label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted,#777);margin-bottom:4px;">' . __('Title Align') . '</label>';
    $out .= '<div class="tz-align-btns" style="display:flex;gap:4px;">';
    foreach (['left','center','right'] as $al) {
        $active = $al === $alignTitle ? ' is-active' : '';
        $out .= '<button type="button" class="tz-align-btn' . $active . '" data-field="' . h($alignFieldName) . '" data-value="' . $al . '" style="flex:1;border:1px solid var(--adam-border-2,rgba(127,127,127,.35));border-radius:6px;padding:6px 0;cursor:pointer;background:var(--adam-bg);color:var(--adam-text);display:flex;align-items:center;justify-content:center;" onclick="this.closest(\'.tz-align-btns\').querySelectorAll(\'.tz-align-btn\').forEach(b=>b.classList.remove(\'is-active\'));this.classList.add(\'is-active\');document.getElementsByName(this.dataset.field)[0].value=this.dataset.value;">';
        $out .= svg_ico('align-' . $al, '', ['style' => 'width:16px;height:16px;']);
        $out .= '</button>';
    }
    $out .= '</div>';
    $out .= '<input type="hidden" name="' . h($alignFieldName) . '" value="' . h($alignTitle) . '">';
    $out .= '</div>';

    // Content alignment icon buttons
    $contFieldName = "widget[{$itemId}][config][_align_content]";
    $out .= '<div>';
    $out .= '<label style="display:block;font-size:12px;font-weight:600;color:var(--adam-muted,#777);margin-bottom:4px;">' . __('Content Align') . '</label>';
    $out .= '<div class="tz-align-btns" style="display:flex;gap:4px;">';
    foreach (['left','center','right'] as $al) {
        $active = $al === $alignContent ? ' is-active' : '';
        $out .= '<button type="button" class="tz-align-btn' . $active . '" data-field="' . h($contFieldName) . '" data-value="' . $al . '" style="flex:1;border:1px solid var(--adam-border-2,rgba(127,127,127,.35));border-radius:6px;padding:6px 0;cursor:pointer;background:var(--adam-bg);color:var(--adam-text);display:flex;align-items:center;justify-content:center;" onclick="this.closest(\'.tz-align-btns\').querySelectorAll(\'.tz-align-btn\').forEach(b=>b.classList.remove(\'is-active\'));this.classList.add(\'is-active\');document.getElementsByName(this.dataset.field)[0].value=this.dataset.value;">';
        $out .= svg_ico('align-' . $al, '', ['style' => 'width:16px;height:16px;']);
        $out .= '</button>';
    }
    $out .= '</div>';
    $out .= '<input type="hidden" name="' . h($contFieldName) . '" value="' . h($alignContent) . '">';
    $out .= '</div>';

    switch ($type) {
        case 'tz_image':
            $srcName = "widget[{$itemId}][config][src]";
            $srcId = 'tz-img-src-' . $itemId;
            $out .= '<div style="grid-column:1/-1;">';
            $out .= '<label for="' . $srcId . '" style="display:block; font-size:12px; font-weight:600; color:var(--adam-muted, #777); margin-bottom:4px;">' . __('Image') . '</label>';
            $out .= '<div style="display:flex; gap:.5rem; align-items:center;">';
            $out .= '<input type="text" name="' . $srcName . '" id="' . $srcId . '" value="' . h((string)($config['src'] ?? '')) . '" placeholder="/static/img/..." style="flex:1; min-width:0; padding:6px 10px; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px;">';
            $out .= '<button type="button" class="btn btn-secondary tz-img-pick-btn" data-target="' . $srcId . '" style="display:inline-flex; align-items:center; gap:5px; padding:.4rem .7rem; white-space:nowrap;">';
            $out .= '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>';
            $out .= __('Gallery') . '</button>';
            $out .= '</div>';
            $out .= '<div class="tz-img-preview" id="' . $srcId . '-preview" style="margin-top:.5rem;' . (empty($config['src']) ? 'display:none;' : '') . '"><img src="' . h((string)($config['src'] ?? '')) . '" alt="" style="max-height:64px; max-width:200px; border-radius:6px; background:rgba(127,127,127,.1); padding:4px;"></div>';
            $out .= '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'alt', __('Alt text'), $config['alt'] ?? '') . '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'link', __('Link URL (optional)'), $config['link'] ?? '') . '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'max_width', __('Max width px (0 = auto)'), $config['max_width'] ?? 0) . '</div>';
            break;
        case 'tz_social':
            $nets = function_exists('theme_zone_social_networks') ? theme_zone_social_networks() : [];
            $enabledNow = (array)($config['enabled'] ?? []);
            $linksNow = (array)($config['links'] ?? []);
            $out .= '<div style="grid-column:1/-1;">';
            foreach ($nets as $netSlug => $netDef) {
                $on = in_array($netSlug, $enabledNow, true);
                $cbName = "widget[{$itemId}][config][enabled][]";
                $urlName = "widget[{$itemId}][config][links][" . h($netSlug) . "]";
                $out .= '<div style="display:flex; align-items:center; gap:.5rem; margin-bottom:.45rem;">';
                $out .= '<label style="display:inline-flex; align-items:center; gap:6px; font-size:13px; min-width:110px; cursor:pointer;">';
                $out .= '<input type="checkbox" name="' . $cbName . '" value="' . h($netSlug) . '"' . ($on ? ' checked' : '') . ' style="width:15px; height:15px; accent-color:var(--adam-primary);">';
                $out .= h($netDef['label']) . '</label>';
                $out .= '<input type="text" name="' . $urlName . '" value="' . h((string)($linksNow[$netSlug] ?? '')) . '" placeholder="https://" style="flex:1; min-width:0; padding:5px 9px; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px;">';
                $out .= '</div>';
            }
            $out .= '</div>';
            break;
        case 'tz_pages':
            $selectedPages = (array)($config['pages'] ?? []);
            $out .= '<div style="grid-column:1/-1;"><label style="display:block; font-size:12px; font-weight:600; color:var(--adam-muted, #777); margin-bottom:4px;">' . __('Pages to show (empty = all)') . '</label>';
            if (empty($pagesList)) {
                $out .= '<p class="muted" style="margin:0; font-size:13px;">' . __('No pages published yet.') . '</p>';
            } else {
                $out .= '<div style="max-height:180px; overflow-y:auto; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; padding:.5rem .75rem; display:flex; flex-direction:column; gap:.3rem;">';
                foreach ($pagesList as $pg) {
                    $pgSlug = (string)($pg['slug'] ?? '');
                    $on = in_array($pgSlug, $selectedPages, true);
                    $out .= '<label style="display:inline-flex; align-items:center; gap:6px; font-size:13px; cursor:pointer; font-weight:400;">';
                    $out .= '<input type="checkbox" name="widget[' . $itemId . '][config][pages][]" value="' . h($pgSlug) . '"' . ($on ? ' checked' : '') . ' style="width:15px; height:15px; accent-color:var(--adam-primary);">';
                    $out .= h((string)($pg['title'] ?? $pgSlug)) . ' <code style="font-size:11px; opacity:.6;">/' . h($pgSlug) . '/</code></label>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'list_class', __('List CSS class'), $config['list_class'] ?? 'tz-pages') . '</div>';
            break;
        case 'tz_richtext':
            $rtName = "widget[{$itemId}][config][html]";
            $rtEditorKey = 'rt-' . $itemId;
            $out .= '<div style="grid-column:1/-1;"><label style="display:block; font-size:12px; font-weight:600; color:var(--adam-muted, #777); margin-bottom:4px;">' . __('Content') . '</label>';
            $out .= '<div style="display:flex; gap:.5rem; align-items:center; margin-bottom:4px;">';
            $out .= '<button type="button" class="btn btn-sm btn-secondary tz-sc-pick-btn" data-editor="' . h($rtEditorKey) . '" style="padding:.3rem .6rem; font-size:12px;">' . __('Insert Shortcode') . '</button>';
            $out .= '</div>';
            $out .= '<textarea class="tz-richtext-area" data-tz-editor="' . h($rtEditorKey) . '" name="' . $rtName . '" rows="5" style="width:100%; padding:6px 10px; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px; font-family:inherit; box-sizing:border-box;">' . h((string)($config['html'] ?? '')) . '</textarea></div>';
            break;
        case 'tz_sidebar_zone':
            $zoneOptions = ['' => __('- Choose sidebar zone -')];
            foreach ($sidebarZones as $sz) { $zoneOptions[(string)($sz['slug'] ?? '')] = (string)($sz['name'] ?? $sz['slug'] ?? ''); }
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'zone', __('Sidebar Zone'), $config['zone'] ?? '', 'select', $zoneOptions) . '</div>';
            $out .= '<div><a class="btn btn-sm btn-secondary" style="margin-top:.25rem; display:inline-flex; align-items:center; gap:4px; padding:.3rem .6rem; font-size:12px;" href="' . h($base . '/?page=admin/sidebar/index') . '" target="_blank">' . __('Manage Sidebars') . ' →</a></div>';
            break;
        case 'tz_nav_menu':
            $menuOptions = ['' => __('Theme default')];
            foreach ($menus as $m) { $menuOptions[(string)($m['slug'] ?? '')] = (string)($m['name'] ?? $m['slug'] ?? ''); }
            $out .= '<div>' . tz_widget_config_field($zoneSlug, $position, $itemId, 'menu', __('Menu'), $config['menu'] ?? 'primary', 'select', $menuOptions) . '</div>';
            $out .= '<div><a class="btn btn-sm btn-secondary" style="margin-top:.25rem; display:inline-flex; align-items:center; gap:4px; padding:.3rem .6rem; font-size:12px;" href="' . h($base . '/?page=admin/menus/index') . '" target="_blank">' . __('Manage Menus') . ' →</a></div>';
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
            $cmName = "widget[{$itemId}][config][html]";
            $cmEditorKey = 'html-' . $itemId;
            $out .= '<div style="grid-column:1/-1;"><label style="display:block; font-size:12px; font-weight:600; color:var(--adam-muted, #777); margin-bottom:4px;">' . __('HTML Content') . '</label>';
            $out .= '<div style="display:flex; gap:.5rem; align-items:center; margin-bottom:4px;">';
            $out .= '<button type="button" class="btn btn-sm btn-secondary tz-sc-pick-btn" data-editor="' . h($cmEditorKey) . '" style="padding:.3rem .6rem; font-size:12px;">' . __('Insert Shortcode') . '</button>';
            $out .= '</div>';
            $out .= '<textarea class="tz-code-area" data-tz-editor="' . h($cmEditorKey) . '" name="' . $cmName . '" rows="5" style="width:100%; padding:6px 10px; border:1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius:6px; background:var(--adam-bg); color:var(--adam-text); font-size:13px; font-family:monospace; box-sizing:border-box;">' . h((string)($config['html'] ?? '')) . '</textarea></div>';
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

// Render satu zone editor lengkap (tombol defaults + grid positions + gadget list + add form)
function tz_zone_editor_html(PDO $pdo, string $folder, string $zSlug, array $layoutDef, array $tzWidgets, array $menus, string $selfUrl, string $activePartial, array $sidebarZones = [], array $pagesList = []): string {
    global $base;
    $base = $base ?? (defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/adiwira');
    ob_start();
    $hasDefaults = !empty($layoutDef['defaults']) || in_array($zSlug, ['header', 'footer'], true);
    ?>
    <?php if ($hasDefaults): ?>
    <form method="post" id="tz-defaults-<?= h($zSlug) ?>" style="margin-bottom:.75rem; display:inline-block;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="tz_action" value="defaults">
      <input type="hidden" name="tz_zone" value="<?= h($zSlug) ?>">
      <input type="hidden" name="tz_partial" value="<?= h($activePartial) ?>">
      <button type="button" class="btn btn-sm btn-secondary" style="padding:.3rem .75rem; font-size:13px;" onclick="tzLoadDefaults('<?= h($zSlug) ?>')">
        <?= __('Load Default Layout') ?>
      </button>
    </form>
    <?php endif; ?>

    <?php
    // Group positions by row (theme.json: "row": 1|2|...; default 1)
    $posRows = [];
    foreach ($layoutDef['positions'] as $posKey => $posDef) {
        $r = (int)($posDef['row'] ?? 1);
        $posRows[$r][$posKey] = $posDef;
    }
    ksort($posRows);
    $multiRow = count($posRows) > 1;
    ?>
    <?php foreach ($posRows as $rowPositions): ?>
      <?php
      $rowCount = count($rowPositions);
      $cols = $multiRow ? $rowCount : (int)($layoutDef['columns'] ?? min(max($rowCount, 1), 4));
      $cols = max(1, min(4, $cols));
      ?>
      <div class="tz-layout-grid" data-cols="<?= $cols ?>"<?= $multiRow ? ' style="margin-bottom:1rem;"' : '' ?>>
      <?php foreach ($rowPositions as $posKey => $posDef): ?>
        <?php
        $posItems = function_exists('theme_zone_items') ? theme_zone_items($pdo, $zSlug, $posKey, $folder, false) : [];
        ?>
        <div class="tz-position" data-position="<?= h($posKey) ?>" style="background:var(--adam-card, rgba(127,127,127,.04)); border:1px dashed rgba(127,127,127,.35); border-radius:8px; padding:1rem; min-height:120px;">
          <div style="font-weight:700; font-size:.85rem; margin-bottom:.75rem; color:var(--adam-muted); text-transform:uppercase; letter-spacing:.5px;"><?= h($posDef['label'] ?? $posKey) ?></div>

          <form method="post" id="tz-form-<?= h($zSlug) ?>-<?= h($posKey) ?>">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="tz_action" value="save">
            <input type="hidden" name="tz_zone" value="<?= h($zSlug) ?>">
            <input type="hidden" name="tz_partial" value="<?= h($activePartial) ?>">
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
                      <div class="tz-config-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                        <?= tz_widget_config_form($zSlug, $posKey, $itemId, $it, $menus, $sidebarZones, $pagesList, $base) ?>
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
              <input type="hidden" name="tz_partial" value="<?= h($activePartial) ?>">
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
    <?php endforeach; ?>
    <?php
    return (string)ob_get_clean();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['tz_action']) && function_exists('theme_zone_add_item')) {
    if (!$csrfOk) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
        return;
    }
    $action = (string)$_POST['tz_action'];
    // Semua zone boleh menerima gadget kecuali main (config-only)
    $allowedPostZones = array_values(array_filter(array_keys($zoneZones), fn($z) => $z !== 'main'));
    if (empty($allowedPostZones)) $allowedPostZones = ['header'];
    $zone = in_array(($_POST['tz_zone'] ?? ''), $allowedPostZones, true) ? $_POST['tz_zone'] : $allowedPostZones[0];
    $position = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['tz_position'] ?? ''));

    // Redirect kembali ke partial yang sedang dipilih user
    $backPartial = (string)($_POST['tz_partial'] ?? '');
    if (!in_array($backPartial, $partialZones, true)) {
        $backPartial = in_array($zone, $partialZones, true) ? $zone : $partialZones[0];
    }
    $backUrl = $selfUrl . '&partial=' . rawurlencode($backPartial);

    if ($action === 'add' && !empty($_POST['tz_type'])) {
        $type = (string)$_POST['tz_type'];
        $config = (isset($tzWidgets[$type]) && is_array($tzWidgets[$type]['default_config'] ?? null)) ? $tzWidgets[$type]['default_config'] : [];
        $title = trim((string)($_POST['tz_title'] ?? ''));
        if ($title !== '') $config['title'] = $title;
        if (theme_zone_add_item($pdo, $zone, $type, $config, (string)($tzWidgets[$type]['label'] ?? $type), $position, $folder)) {
            adiwira_redirect_with_flash($backUrl, 'success', sprintf(__('Widget added to %s.'), (string)($themeLayout[$zone]['positions'][$position]['label'] ?? $position)));
            return;
        }
        adiwira_redirect_with_flash($backUrl, 'error', __('Failed to add widget.'));
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
        adiwira_redirect_with_flash($backUrl, 'success', __('Zone settings saved.'));
        return;
    }

    if ($action === 'delete' && !empty($_POST['tz_id'])) {
        $id = (int)$_POST['tz_id'];
        if (theme_zone_delete_item($pdo, $id)) {
            adiwira_redirect_with_flash($backUrl, 'success', __('Widget removed.'));
            return;
        }
        adiwira_redirect_with_flash($backUrl, 'error', __('Failed to remove widget.'));
        return;
    }

    if ($action === 'defaults') {
        $existing = function_exists('theme_zone_items') ? theme_zone_items($pdo, $zone, null, $folder, false) : [];
        if (!empty($existing)) {
            adiwira_redirect_with_flash($backUrl, 'warning', __('Zone already has widgets. Remove them first to load defaults.'));
            return;
        }

        $layoutDefaults = $themeLayout[$zone]['defaults'] ?? [];
        if (empty($layoutDefaults) || !is_array($layoutDefaults)) {
            // Fallback generic defaults hanya untuk header/footer; zone lain harus declare sendiri
            if ($zone === 'header') {
                $layoutDefaults = [
                    'logo' => [['type' => 'tz_html', 'title' => __('Site Logo'), 'config' => ['title' => '', 'html' => '<a href="/" class="tz-brand">' . h($site['title'] ?? 'Site') . '</a>']]],
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
                adiwira_redirect_with_flash($backUrl, 'warning', __('This zone does not declare default gadgets.'));
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

        adiwira_redirect_with_flash($backUrl, 'success', __('Default layout loaded.'));
        return;
    }
}

// Zone tabs dibangun dari layout tema aktif — komputasi ada di atas (dipakai juga POST handler)
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

  <!-- Theme Layout Editor — kanvas halaman utuh ala Blogspot -->
  <?php if (!empty($themeLayout)): ?>
    <!-- Topbar: label kanvas ⇄ Partials -->
    <div class="tz-topbar">
      <span class="tz-topbar-title muted"><?= __('Page Canvas') ?> — <span class="<?= h($canvasThemeClass) ?>"><?= h($canvasThemeLabel) ?></span></span>
      <select id="tz-partial-select" aria-label="<?= __('Partials') ?>">
        <?php foreach ($partialZones as $pz): ?>
          <option value="<?= h($pz) ?>" <?= $activePartial === $pz ? 'selected' : '' ?>><?= h($zoneZones[$pz] ?? $pz) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="tz-canvas">
      <!-- HEADER band -->
      <?php if (isset($themeLayout['header']) && !empty($themeLayout['header']['positions'])): ?>
        <?php $headerOverrideClass = tz_override_class('header', $mixedAssignments); ?>
        <?php $headerSourceFolder = tz_zone_source_folder('header', $folder, $mixedAssignments); ?>
        <section class="tz-band <?= h($headerOverrideClass) ?>" data-zone="header">
          <div class="tz-band-label">
            <?= h($zoneZones['header'] ?? __('Header')) ?>
            <?php if ($headerOverrideClass !== ''): ?>
              <span class="tz-override-tag <?= h($headerOverrideClass === 'tz-band--override-theme' ? 'tz-override-tag--theme' : 'tz-override-tag--post') ?>"><?= h(tz_override_label('header', $mixedAssignments)) ?></span>
            <?php endif; ?>
          </div>
          <div class="tz-band-body">
            <?php if ($headerSourceFolder === null): ?>
              <?= tz_override_notice($mixedAssignments['header']['label'] ?? '', $mixedAssignments['header']['post_id'] ?? null) ?>
            <?php else: ?>
              <?php $headerLayout = $headerSourceFolder === $folder ? $themeLayout['header'] : (theme_zone_layout($headerSourceFolder)['header'] ?? $themeLayout['header']); ?>
              <?= tz_zone_editor_html($pdo, $headerSourceFolder, 'header', $headerLayout, $tzWidgets, $menus, $selfUrl, $activePartial, $zones, $pagesList) ?>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <!-- MAIN row: partial terpilih | sidebar -->
      <div class="tz-main-row">
        <?php $mainOverrideClass = tz_override_class($activePartial, $mixedAssignments); ?>
        <section class="tz-band tz-band-main <?= h($mainOverrideClass) ?>">
          <div class="tz-band-label">
            <?= __('Main') ?> — <span id="tz-partial-name"><?= h($zoneZones[$activePartial] ?? $activePartial) ?></span>
            <?php if ($mainOverrideClass !== ''): ?>
              <span class="tz-override-tag <?= h($mainOverrideClass === 'tz-band--override-theme' ? 'tz-override-tag--theme' : 'tz-override-tag--post') ?>"><?= h(tz_override_label($activePartial, $mixedAssignments)) ?></span>
            <?php endif; ?>
          </div>
          <div class="tz-band-body">
            <?php foreach ($partialZones as $pz): ?>
              <?php $pzOverrideClass = tz_override_class($pz, $mixedAssignments); ?>
              <?php $pzSourceFolder = tz_zone_source_folder($pz, $folder, $mixedAssignments); ?>
              <div class="tz-partial-panel <?= h($pzOverrideClass) ?>" data-partial="<?= h($pz) ?>" style="display:<?= $pz === $activePartial ? 'block' : 'none' ?>;">
                <?php if ($pzSourceFolder === null): ?>
                  <?= tz_override_notice($mixedAssignments[$pz]['label'] ?? '', $mixedAssignments[$pz]['post_id'] ?? null) ?>
                <?php elseif ($pz === 'main'): ?>
                  <?php $pzSections = $pzSourceFolder === $folder ? $sections : theme_customizer_fields($pzSourceFolder); ?>
                  <?php $pzMods = $pzSourceFolder === $folder ? $mods : theme_mods_all($pdo, $pzSourceFolder); ?>
                  <p class="muted" style="margin-top:0; margin-bottom:1rem;"><?= __('Configure the main content area and sidebar visibility.') ?></p>
                  <?php $mainSection = $pzSections['main'] ?? []; ?>
                  <?php if (!empty($mainSection['fields'])): ?>
                    <form method="post" style="display:flex; flex-direction:column; gap:1rem; max-width:700px;">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="tc_main_save" value="1">
                      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
                        <?php foreach ($mainSection['fields'] as $key => $def): ?>
                          <div class="tc-field" style="background:var(--adam-card, rgba(127,127,127,.04)); border:1px solid rgba(127,127,127,.18); border-radius:8px; padding:1rem;">
                            <label style="display:block; font-weight:600; font-size:.9rem; margin-bottom:.5rem;"><?= h($def['label']) ?></label>
                            <?php if ($def['type'] === 'toggle'): ?>
                              <?php $on = (bool)($pzMods[$key] ?? true); ?>
                              <label style="display:inline-flex; align-items:center; gap:.5rem; font-weight:400; cursor:pointer;">
                                <input type="checkbox" name="<?= h($key) ?>" value="1" <?= $on ? 'checked' : '' ?> style="width:18px; height:18px;">
                                <span class="muted"><?= __('Enabled') ?></span>
                              </label>
                            <?php elseif ($def['type'] === 'image'): ?>
                              <input type="text" name="<?= h($key) ?>" value="<?= h((string)($pzMods[$key] ?? '')) ?>" placeholder="/static/img/..." style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">
                            <?php elseif ($def['type'] === 'menu'): ?>
                              <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                                <option value=""><?= __('Theme default') ?></option>
                                <?php foreach ($menus as $m): ?>
                                  <?php $slug = (string)($m['slug'] ?? ''); ?>
                                  <option value="<?= h($slug) ?>" <?= ((string)($pzMods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($m['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                                <?php endforeach; ?>
                              </select>
                            <?php elseif ($def['type'] === 'sidebar_zone'): ?>
                              <select name="<?= h($key) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit;">
                                <option value=""><?= __('None') ?></option>
                                <?php foreach ($zones as $z): ?>
                                  <?php $slug = (string)($z['slug'] ?? ''); ?>
                                  <option value="<?= h($slug) ?>" <?= ((string)($pzMods[$key] ?? '') === $slug) ? 'selected' : '' ?>><?= h((string)($z['name'] ?? $slug)) ?> (<?= h($slug) ?>)</option>
                                <?php endforeach; ?>
                              </select>
                            <?php elseif ($def['type'] === 'textarea'): ?>
                              <textarea name="<?= h($key) ?>" rows="3" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; font:inherit; box-sizing:border-box;"><?= h((string)($pzMods[$key] ?? '')) ?></textarea>
                            <?php elseif ($def['type'] === 'text'): ?>
                              <input type="text" name="<?= h($key) ?>" value="<?= h((string)($pzMods[$key] ?? '')) ?>" style="width:100%; padding:.5rem .7rem; border:1px solid rgba(127,127,127,.35); border-radius:6px; background:transparent; color:inherit; box-sizing:border-box;">
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
                <?php else: ?>
                  <?php
                  $pzDef = $themeLayout[$pz] ?? null;
                  if ($pzSourceFolder !== $folder) {
                      $pzDef = theme_zone_layout($pzSourceFolder)[$pz] ?? null;
                  }
                  if ((empty($pzDef) || empty($pzDef['positions'])) && function_exists('theme_zone_partial_positions')) {
                      $pzDef = ['label' => $zoneZones[$pz] ?? $pz, 'columns' => 1, 'positions' => []];
                      foreach (theme_zone_partial_positions($pz) as $pk => $pl) {
                          $pzDef['positions'][$pk] = ['label' => $pl];
                      }
                  }
                  ?>
                  <?php if (!empty($pzDef) && !empty($pzDef['positions']) && is_array($pzDef['positions'])): ?>
                    <?= tz_zone_editor_html($pdo, $pzSourceFolder, $pz, $pzDef, $tzWidgets, $menus, $selfUrl, $activePartial, $zones, $pagesList) ?>
                  <?php else: ?>
                    <div class="adam-alert info"><?= __('This partial does not declare positions.') ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- SIDEBAR panel (jika aktif) -->
        <?php $sidebarOn = !function_exists('theme_mod') || theme_mod('show_sidebar', true); ?>
        <aside class="tz-band tz-band-sidebar">
          <div class="tz-band-label"><?= __('Sidebar') ?> <?= $sidebarOn ? '' : '(' . __('nonaktif') . ')' ?></div>
          <div class="tz-band-body">
            <?php if (!$sidebarOn): ?>
              <p class="muted" style="margin-top:0;"><?= __('Sidebar is disabled. Enable it via the Show sidebar toggle in the Main partial.') ?></p>
            <?php endif; ?>
            <?php if (empty($zones)): ?>
              <p class="muted" style="margin-top:0;"><?= __('No sidebar zones yet.') ?></p>
            <?php else: ?>
              <ul class="tz-sidebar-list">
                <?php foreach ($zones as $sz): ?>
                  <li>
                    <strong><?= h((string)($sz['name'] ?? $sz['slug'] ?? '')) ?></strong>
                    <code><?= h((string)($sz['slug'] ?? '')) ?></code>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <a class="btn btn-sm btn-secondary" style="margin-top:.5rem; display:inline-block; padding:.35rem .75rem; font-size:13px;" href="<?= h($base . '/?page=admin/sidebar/index') ?>"><?= __('Manage Sidebars') ?> →</a>
          </div>
        </aside>
      </div>

      <!-- FOOTER band -->
      <?php if (isset($themeLayout['footer']) && !empty($themeLayout['footer']['positions'])): ?>
        <?php $footerOverrideClass = tz_override_class('footer', $mixedAssignments); ?>
        <?php $footerSourceFolder = tz_zone_source_folder('footer', $folder, $mixedAssignments); ?>
        <section class="tz-band <?= h($footerOverrideClass) ?>" data-zone="footer">
          <div class="tz-band-label">
            <?= h($zoneZones['footer'] ?? __('Footer')) ?>
            <?php if ($footerOverrideClass !== ''): ?>
              <span class="tz-override-tag <?= h($footerOverrideClass === 'tz-band--override-theme' ? 'tz-override-tag--theme' : 'tz-override-tag--post') ?>"><?= h(tz_override_label('footer', $mixedAssignments)) ?></span>
            <?php endif; ?>
          </div>
          <div class="tz-band-body">
            <?php if ($footerSourceFolder === null): ?>
              <?= tz_override_notice($mixedAssignments['footer']['label'] ?? '', $mixedAssignments['footer']['post_id'] ?? null) ?>
            <?php else: ?>
              <?php $footerLayout = $footerSourceFolder === $folder ? $themeLayout['footer'] : (theme_zone_layout($footerSourceFolder)['footer'] ?? $themeLayout['footer']); ?>
              <?= tz_zone_editor_html($pdo, $footerSourceFolder, 'footer', $footerLayout, $tzWidgets, $menus, $selfUrl, $activePartial, $zones, $pagesList) ?>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>

<form id="tz-delete-form" method="post" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="tz_action" value="delete">
  <input type="hidden" name="tz_zone" id="tz-delete-zone" value="">
  <input type="hidden" name="tz_id" id="tz-delete-id" value="">
</form>

<script src="/static/js/add/modal-helpers.js"></script>
<script src="/static/js/add/media-selector.js"></script>

<script>
window.TZ_SHORTCODE_PRESETS = <?= json_encode($scPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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

  window.tzEditors = window.tzEditors || { quill: {}, cm: {} };

  window.tzInitEditors = function(scope){
    if (!scope) return;
    scope.querySelectorAll('textarea.tz-richtext-area:not(.tz-ed-init)').forEach(function(ta){
      if (typeof Quill === 'undefined') return;
      ta.classList.add('tz-ed-init');
      var div = document.createElement('div');
      div.className = 'tz-quill-editor';
      div.innerHTML = ta.value;
      ta.style.display = 'none';
      ta.parentNode.insertBefore(div, ta.nextSibling);
      var q = new Quill(div, { theme: 'snow' });
      q.on('text-change', function(){
        ta.value = div.querySelector('.ql-editor').innerHTML;
      });
      var key = ta.getAttribute('data-tz-editor');
      if (key) window.tzEditors.quill[key] = q;
    });
    scope.querySelectorAll('textarea.tz-code-area:not(.tz-ed-init)').forEach(function(ta){
      if (typeof CodeMirror === 'undefined') return;
      ta.classList.add('tz-ed-init');
      var cm = CodeMirror.fromTextArea(ta, {
        mode: 'htmlmixed',
        lineNumbers: true,
        autoCloseTags: true,
        autoCloseBrackets: true
      });
      cm.setSize('100%', 180);
      cm.on('change', function(){ cm.save(); });
      var key = ta.getAttribute('data-tz-editor');
      if (key) window.tzEditors.cm[key] = cm;
    });
  };

  window.tzToggleWidget = function(header){
    var body = header.nextElementSibling;
    if (body && body.classList.contains('tz-body')) {
      body.style.display = body.style.display === 'none' ? 'block' : 'none';
      if (body.style.display !== 'none') tzInitEditors(body);
    }
  };

  // Init editor untuk widget body yang terbuka sejak awal
  document.querySelectorAll('.tz-body').forEach(function(body){
    if (body.style.display !== 'none') tzInitEditors(body);
  });

  // ─── Shortcode picker for tz_html / tz_richtext ───
  var tzActiveShortcodeEditor = null;
  function tzBuildShortcodePicker(){
    var presets = (window.TZ_SHORTCODE_PRESETS && window.TZ_SHORTCODE_PRESETS.length) ? window.TZ_SHORTCODE_PRESETS : [];
    var emptyMsg = <?= json_encode(__('No shortcode presets yet. Create one in the Shortcode Builder.')) ?>;
    var items = presets.map(function(p){
      var title = p.title || p.slug || ('#' + p.id);
      var slug = p.slug || p.id;
      return '<button type="button" class="tz-sc-item" data-slug="' + slug.replace(/"/g, '&quot;') + '" style="display:flex; align-items:center; justify-content:space-between; width:100%; padding:.6rem .8rem; border:1px solid var(--adam-border-2); border-radius:6px; background:var(--adam-card); color:var(--adam-text); cursor:pointer; text-align:left;">'
        + '<span style="font-weight:600;">' + title.replace(/</g, '&lt;') + '</span>'
        + '<code style="font-size:11px; background:rgba(127,127,127,.1); padding:2px 6px; border-radius:4px;">[[widget:' + slug.replace(/</g, '&lt;') + ']]</code>'
        + '</button>';
    }).join('');
    if (!items) {
      items = '<p style="margin:0; color:var(--adam-muted); font-size:13px; text-align:center;">' + emptyMsg + '</p>';
    }
    return '<div id="tz-sc-modal" style="position:fixed; inset:0; z-index:2000; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.45);">'
      + '<div class="tz-sc-box" style="width:min(420px, 92vw); max-height:80vh; overflow:auto; background:var(--adam-bg); border:1px solid var(--adam-border-2); border-radius:10px; padding:1rem; box-shadow:var(--adam-shadow, 0 12px 40px rgba(0,0,0,.15));">'
      + '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:.75rem;">'
      + '<h3 style="margin:0; font-size:1rem;"><?= __('Choose Shortcode Preset') ?></h3>'
      + '<button type="button" id="tz-sc-close" style="background:none; border:none; cursor:pointer; font-size:1.25rem; color:var(--adam-muted); line-height:1;">×</button>'
      + '</div>'
      + '<div style="display:flex; flex-direction:column; gap:.5rem;">' + items + '</div>'
      + '</div></div>';
  }
  function tzOpenShortcodePicker(editorKey){
    tzActiveShortcodeEditor = editorKey;
    var existing = document.getElementById('tz-sc-modal');
    if (existing) existing.remove();
    var wrap = document.createElement('div');
    wrap.innerHTML = tzBuildShortcodePicker();
    document.body.appendChild(wrap.firstElementChild);
    var modal = document.getElementById('tz-sc-modal');
    modal.style.display = 'flex';
    modal.addEventListener('click', function(e){
      if (e.target === modal) { modal.remove(); tzActiveShortcodeEditor = null; }
    });
    document.getElementById('tz-sc-close').addEventListener('click', function(){ modal.remove(); tzActiveShortcodeEditor = null; });
    modal.querySelectorAll('.tz-sc-item').forEach(function(item){
      item.addEventListener('click', function(){
        var slug = item.getAttribute('data-slug');
        if (tzActiveShortcodeEditor && slug) tzInsertShortcode(tzActiveShortcodeEditor, slug);
        modal.remove();
        tzActiveShortcodeEditor = null;
      });
    });
  }
  function tzInsertShortcode(editorKey, slug){
    var shortcode = '[[widget:' + slug + ']]';
    var cm = window.tzEditors.cm[editorKey];
    if (cm) {
      cm.replaceSelection(shortcode);
      cm.focus();
      return;
    }
    var q = window.tzEditors.quill[editorKey];
    if (q) {
      var range = q.getSelection(true);
      q.insertText(range ? range.index : q.getLength(), shortcode);
      q.setSelection((range ? range.index : q.getLength()) + shortcode.length);
      return;
    }
    // Fallback: textarea raw
    var ta = document.querySelector('textarea[data-tz-editor="' + editorKey + '"]');
    if (ta) {
      var start = ta.selectionStart || 0;
      var end = ta.selectionEnd || 0;
      ta.value = ta.value.substring(0, start) + shortcode + ta.value.substring(end);
      ta.selectionStart = ta.selectionEnd = start + shortcode.length;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.tz-sc-pick-btn');
    if (!btn) return;
    e.preventDefault();
    var key = btn.getAttribute('data-editor');
    if (key) tzOpenShortcodePicker(key);
  });

  // ─── Image picker via media modal CMS core ───
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.tz-img-pick-btn');
    if (!btn) return;
    e.preventDefault();
    var targetId = btn.getAttribute('data-target');
    var input = document.getElementById(targetId);
    if (!input || typeof openMediaSelector !== 'function') return;
    openMediaSelector({ url: (window.ADMIN_PATH || '/adiwira') + '/admin/modal_img/index.php?embedded=1' }).then(function(detail){
      var m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : detail;
      if (m && m.url) {
        input.value = m.url;
        var preview = document.getElementById(targetId + '-preview');
        if (preview) { preview.querySelector('img').src = m.url; preview.style.display = 'block'; }
      }
    });
  });

  // Live preview saat URL gambar diketik manual
  document.addEventListener('input', function(e){
    var inp = e.target;
    if (!inp.matches || !inp.matches('input[id^="tz-img-src-"]')) return;
    var preview = document.getElementById(inp.id + '-preview');
    if (!preview) return;
    var v = inp.value.trim();
    if (v === '') { preview.style.display = 'none'; return; }
    preview.querySelector('img').src = v;
    preview.style.display = 'block';
  });

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
    var doDelete = function(){
      document.getElementById('tz-delete-id').value = id;
      document.getElementById('tz-delete-zone').value = zone;
      document.getElementById('tz-delete-form').submit();
    };
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.danger === 'function') {
      window.NewNotifConfirm.danger({
        title: <?= json_encode(__('Delete Widget')) ?>,
        message: <?= json_encode(__('Remove this widget?')) ?>,
        confirmText: <?= json_encode(__('Delete')) ?>
      }).then(function(ok){ if (ok) doDelete(); });
    } else if (window.confirm(<?= json_encode(__('Remove this widget?')) ?>)) {
      doDelete();
    }
  };

  window.tzLoadDefaults = function(zone){
    var form = document.getElementById('tz-defaults-' + zone);
    if (!form) return;
    var go = function(){ form.submit(); };
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
      window.NewNotifConfirm.warning({
        title: <?= json_encode(__('Load Default Layout')) ?>,
        message: <?= json_encode(__('Load default gadgets for this zone? Existing widgets must be removed first.')) ?>,
        confirmText: <?= json_encode(__('Load')) ?>
      }).then(function(ok){ if (ok) go(); });
    } else {
      go();
    }
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

  // ─── Partial switcher: mengganti isi area Main ───
  var tzPartialSelect = document.getElementById('tz-partial-select');
  if (tzPartialSelect) {
    tzPartialSelect.addEventListener('change', function(){
      var v = tzPartialSelect.value;
      document.querySelectorAll('.tz-partial-panel').forEach(function(p){
        p.style.display = p.getAttribute('data-partial') === v ? 'block' : 'none';
      });
      var nameEl = document.getElementById('tz-partial-name');
      if (nameEl) nameEl.textContent = tzPartialSelect.options[tzPartialSelect.selectedIndex].textContent;
      document.querySelectorAll('input[name="tz_partial"]').forEach(function(i){ i.value = v; });
      if (window.history && history.replaceState) {
        try {
          var u = new URL(window.location.href);
          u.searchParams.set('partial', v);
          u.searchParams.delete('tab');
          history.replaceState(null, '', u.toString());
        } catch (e) {}
      }
    });
  }

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
.tz-item { transition: opacity .2s ease; min-width: 0; }
.tz-config-grid > * { min-width: 0; }
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
.tz-align-btn.is-active { border-color: var(--adam-primary, #0066ff) !important; background: var(--adam-primary-soft, rgba(0,102,255,.1)) !important; color: var(--adam-primary, #0066ff) !important; }
.tz-align-btn.is-active svg { stroke: var(--adam-primary, #0066ff); }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-secondary { background:var(--adam-surface-3); color:var(--adam-text); border-color:var(--adam-border-2); }
.btn-secondary:hover { background:var(--adam-surface-4); }
.tz-align-btn:hover { border-color:var(--adam-primary) !important; }
.tz-title { margin: 0 0 .35rem; }
.tz-add-form {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem;
  align-items: center;
}

/* Kanvas halaman utuh (ala Blogspot) */
.tz-topbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; flex-wrap: wrap; }
.tz-theme-badge {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .2rem .65rem; border-radius: 999px;
  background: var(--adam-accent, #00A89E); color: #fff;
  font-weight: 700; font-size: .82rem; text-transform: uppercase; letter-spacing: .4px;
  box-shadow: 0 2px 6px rgba(0,0,0,.12);
}
.tz-theme-badge::before {
  content: '';
  width: 7px; height: 7px; border-radius: 50%;
  background: #fff;
}
.tz-theme-badge--mix {
  background: #f59e0b;
  color: #1f2937;
}
.tz-theme-badge--mix::before {
  background: #1f2937;
}
.tz-topbar select {
  min-width: 220px; padding: .45rem .8rem; font-size: 14px; font-weight: 600;
  border: 1px solid rgba(127,127,127,.35); border-radius: 8px;
  background: var(--adam-bg); color: var(--adam-text);
}
.tz-canvas { display: flex; flex-direction: column; gap: 1rem; }
.tz-band {
  border: 1px solid rgba(127,127,127,.28); border-radius: 10px; overflow: hidden;
  background: var(--adam-card, rgba(127,127,127,.03));
}
.tz-band-label {
  padding: .45rem .9rem; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .6px; color: var(--adam-muted);
  border-bottom: 1px solid rgba(127,127,127,.18);
  background: var(--adam-surface-2, rgba(127,127,127,.06));
}
.tz-band-body { padding: 1rem; }

/* Override indicators: blue = another theme, green = custom theme post */
.tz-band--override-theme {
  border-color: rgba(59,130,246,.5);
  background: rgba(59,130,246,.06);
}
.tz-band--override-theme .tz-band-label {
  background: rgba(59,130,246,.12);
  color: #2563eb;
  border-color: rgba(59,130,246,.35);
}
.tz-band--override-post {
  border-color: rgba(34,197,94,.5);
  background: rgba(34,197,94,.06);
}
.tz-band--override-post .tz-band-label {
  background: rgba(34,197,94,.12);
  color: #16a34a;
  border-color: rgba(34,197,94,.35);
}
.tz-override-tag {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  margin-left: .5rem;
  padding: .15rem .5rem;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 700;
  text-transform: none;
  letter-spacing: 0;
  vertical-align: middle;
}
.tz-override-tag::before {
  content: '';
  width: 6px; height: 6px;
  border-radius: 50%;
}
.tz-override-tag--theme {
  background: rgba(59,130,246,.15);
  color: #2563eb;
}
.tz-override-tag--theme::before { background: #2563eb; }
.tz-override-tag--post {
  background: rgba(34,197,94,.15);
  color: #16a34a;
}
.tz-override-tag--post::before { background: #16a34a; }

.tz-override-notice {
  padding: 1rem 1.25rem;
  border-radius: 8px;
  background: var(--adam-surface-2, rgba(127,127,127,.06));
  border: 1px solid var(--adam-border, rgba(127,127,127,.25));
  color: var(--adam-text);
  font-size: .9rem;
  line-height: 1.55;
}
.tz-override-notice em {
  font-weight: 600;
  font-style: normal;
}
.tz-override-notice--with-action {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: nowrap;
}
.tz-override-notice--with-action .tz-override-notice__text {
  flex: 1 1 auto;
  min-width: 0;
}

/* Jy Builder action button — gradient aqua/blue, distinct from core buttons */
.tz-jb-button {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  padding: .5rem 1rem;
  border-radius: 8px;
  font-weight: 600;
  font-size: .88rem;
  color: #fff;
  text-decoration: none;
  white-space: nowrap;
  background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);
  border: 1px solid rgba(6,182,212,.35);
  box-shadow: 0 4px 12px rgba(59,130,246,.25);
  transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
}
.tz-jb-button:hover,
.tz-jb-button:focus-visible {
  color: #fff;
  transform: translateY(-1px);
  filter: brightness(1.08);
  box-shadow: 0 6px 18px rgba(59,130,246,.35);
}
.tz-jb-button:active {
  transform: translateY(0);
}
.tz-jb-icon {
  width: 16px;
  height: 16px;
  flex: 0 0 16px;
}

/* Dark mode adjustments */
html.theme-dark .tz-band--override-theme .tz-band-label,
html:not(.theme-light):not(.theme-dark) .tz-band--override-theme .tz-band-label {
  color: #60a5fa;
}
html.theme-dark .tz-band--override-post .tz-band-label,
html:not(.theme-light):not(.theme-dark) .tz-band--override-post .tz-band-label {
  color: #4ade80;
}
html.theme-dark .tz-override-tag--theme,
html:not(.theme-light):not(.theme-dark) .tz-override-tag--theme {
  color: #60a5fa;
}
html.theme-dark .tz-override-tag--theme::before,
html:not(.theme-light):not(.theme-dark) .tz-override-tag--theme::before {
  background: #60a5fa;
}
html.theme-dark .tz-override-tag--post,
html:not(.theme-light):not(.theme-dark) .tz-override-tag--post {
  color: #4ade80;
}
html.theme-dark .tz-override-tag--post::before,
html:not(.theme-light):not(.theme-dark) .tz-override-tag--post::before {
  background: #4ade80;
}

.tz-main-row { display: grid; grid-template-columns: 3fr 1fr; gap: 1rem; align-items: start; }
.tz-sidebar-list { list-style: none; margin: 0 0 .5rem; padding: 0; display: flex; flex-direction: column; gap: .4rem; }
.tz-sidebar-list li { display: flex; align-items: center; gap: .5rem; font-size: 13px; }
.tz-sidebar-list code { font-size: 11px; opacity: .7; background: rgba(127,127,127,.12); padding: 1px 6px; border-radius: 4px; }
@media (max-width: 900px) {
  .tz-main-row { grid-template-columns: 1fr; }
}

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
}

/* Editor gadget: Quill richtext + CodeMirror html */
.tz-quill-editor { background: var(--adam-bg, #fff); border-radius: 0 0 6px 6px; }
.tz-quill-editor .ql-editor { min-height: 120px; font-size: 14px; }
.CodeMirror { border: 1px solid var(--adam-border-2, rgba(127,127,127,.35)); border-radius: 6px; font-size: 13px; height: 180px; }
</style>
