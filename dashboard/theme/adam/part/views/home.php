<?php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}
/** @var PDO $pdo */
require_once __DIR__ . '/widgets.php';

$base = ADMIN_BASE_PATH;

$widgets = apply_filters('dashboard_widgets', [
    'cms_info'      => ['title' => __('CMS Info'),      'render' => 'dash_widget_cms_info'],
    'update_status' => ['title' => __('Update Status'),  'render' => 'dash_widget_update_status'],
    'quick_stats'   => ['title' => __('Quick Stats'),    'render' => 'dash_widget_quick_stats'],
    'recent_posts'  => ['title' => __('Recent Posts'),   'render' => 'dash_widget_recent_posts'],
    'system_info'   => ['title' => __('System Info'),    'render' => 'dash_widget_system_info'],
]);

$layoutJson = settings_get($pdo, 'dashboard_widget_layout', '');
$order = $layoutJson ? json_decode($layoutJson, true) : null;

if (!$order || !is_array($order)) {
    $order = ['cms_info:l', 'quick_stats:r', 'system_info:l', 'update_status:r', 'recent_posts:l'];
}
// backward compat: old format [{"w":"cms_info","col":1},...] → new format ["cms_info:l",...]
if ($order && isset($order[0]['w'])) {
    $map = [1 => 'l', 2 => 'r'];
    $order = array_map(fn($o) => ($o['w'] ?? '?') . ':' . ($map[$o['col'] ?? 1] ?? 'l'), $order);
}

function dash_parse_item(string $item): array
{
    $parts = explode(':', $item, 2);
    $key = $parts[0];
    $col = $parts[1] ?? '';
    return [$key, $col];
}

function dash_render_widget(string $key, array $widgets, PDO $pdo): string
{
    if (!isset($widgets[$key])) return '';
    $fn = $widgets[$key]['render'];
    $html = function_exists($fn) ? $fn($pdo) : '';
    if (!$html) return '';
    $fw = !empty($widgets[$key]['full_width']) ? ' data-full-width="1"' : '';
    $h = h($key);
    return '<div class="dw-widget" data-widget="' . $h . '"' . $fw . '>'
         . '<div class="dw-drag-handle" draggable="true" title="' . __('Drag to reorder') . '">'
         . svg_ico('grip-vertical')
         . '<button type="button" class="dw-hide-btn" title="' . __('Hide widget') . '">'
         . svg_ico('eye-off')
         . '</button>'
         . '</div><div class="dw-widget-body">' . $html . '</div></div>';
}

// Build segments: full_width from registry always wins, column hint for normal
$segments = [];
$cur = [];
foreach ($order as $item) {
    [$key, $col] = dash_parse_item($item);
    if (!empty($widgets[$key]['full_width'])) {
        if ($cur) { $segments[] = ['normal' => $cur]; $cur = []; }
        $segments[] = ['full' => $key];
    } else {
        $cur[] = $item;
    }
}
if ($cur) $segments[] = ['normal' => $cur];
?>
<?php do_action('admin_home'); ?>
<div class="dw-dashboard">
  <div class="dw-heading">
    <h2 class="dw-heading-title"><?=_e('Dashboard')?></h2>
    <div class="dw-heading-actions">
      <button type="button" class="adam-button" id="dw-arrange-toggle" data-active="0"><?=_e('Arrange Widgets')?></button>
    </div>
  </div>

  <div class="dw-grid" id="dw-grid">
    <?php
    $left = []; $right = [];
    foreach ($segments as $seg):
      if (!empty($seg['full'])):
        if ($left || $right):
          echo '<div class="dw-row"><div class="dw-col">' . implode('', $left) . '</div><div class="dw-col">' . implode('', $right) . '</div></div>';
          $left = []; $right = [];
        endif;
        echo '<div class="dw-row-full">' . dash_render_widget($seg['full'], $widgets, $pdo) . '</div>';
      else:
        foreach ($seg['normal'] as $item):
          [$key, $col] = dash_parse_item($item);
          $html = dash_render_widget($key, $widgets, $pdo);
          if (!$html) continue;
          if ($col === 'r') $right[] = $html;
          else              $left[] = $html;
        endforeach;
      endif;
    endforeach;
    if ($left || $right):
      echo '<div class="dw-row"><div class="dw-col">' . implode('', $left) . '</div><div class="dw-col">' . implode('', $right) . '</div></div>';
    endif;
    ?>
  </div>

  <form id="dw-layout-form" method="post" action="<?=h($base)?>/admin/save_dashboard_layout.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="layout" id="dw-layout-input" value="">
  </form>
  <div id="dw-widget-panel" style="display:none">
    <h3><?=_e('Widget Manager')?></h3>
    <div id="dw-widget-list" data-keys='<?=h(json_encode(array_keys($widgets)))?>'></div>
  </div>
</div>

<script src="/static/dashboard/js/dashboard-widgets.js" defer></script>
<style>
.adam-flash-wrap{ max-width:1200px; margin:0 auto 1rem; }
</style>
