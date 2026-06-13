<?php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}
/** @var PDO $pdo */
require_once __DIR__ . '/widgets.php';

$base = ADMIN_BASE_PATH;

// Load widget layout from settings
$layoutJson = settings_get($pdo, 'dashboard_widget_layout', '');
$layout = $layoutJson ? json_decode($layoutJson, true) : null;

// Default layout if none saved
if (!$layout || !is_array($layout)) {
    $layout = [
        ['w' => 'cms_info', 'col' => 1],
        ['w' => 'update_status', 'col' => 2],
        ['w' => 'quick_stats', 'col' => 1],
        ['w' => 'recent_posts', 'col' => 2],
        ['w' => 'system_info', 'col' => 1],
    ];
}

// Available widgets registry
$widgets = [
    'cms_info'      => ['title' => __('CMS Info'),      'render' => 'dash_widget_cms_info'],
    'update_status' => ['title' => __('Update Status'),  'render' => 'dash_widget_update_status'],
    'quick_stats'   => ['title' => __('Quick Stats'),    'render' => 'dash_widget_quick_stats'],
    'recent_posts'  => ['title' => __('Recent Posts'),   'render' => 'dash_widget_recent_posts'],
    'system_info'   => ['title' => __('System Info'),    'render' => 'dash_widget_system_info'],
];

// Group by column
$col1 = [];
$col2 = [];
foreach ($layout as $item) {
    $key = $item['w'] ?? '';
    if (!isset($widgets[$key])) continue;
    if (($item['col'] ?? 1) === 2) {
        $col2[] = $key;
    } else {
        $col1[] = $key;
    }
}
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
    <div class="dw-col" id="dw-col-1">
      <?php foreach ($col1 as $key):
        $fn = $widgets[$key]['render'];
        $html = function_exists($fn) ? $fn($pdo) : '';
        if (!$html) continue;
      ?>
      <div class="dw-widget" data-widget="<?=h($key)?>" data-col="1">
        <div class="dw-drag-handle" draggable="true" title="<?=_e('Drag to reorder')?>">
          <?=svg_ico('grip-vertical')?>
        </div>
        <div class="dw-widget-body"><?=$html?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="dw-col" id="dw-col-2">
      <?php foreach ($col2 as $key):
        $fn = $widgets[$key]['render'];
        $html = function_exists($fn) ? $fn($pdo) : '';
        if (!$html) continue;
      ?>
      <div class="dw-widget" data-widget="<?=h($key)?>" data-col="2">
        <div class="dw-drag-handle" draggable="true" title="<?=_e('Drag to reorder')?>">
          <?=svg_ico('grip-vertical')?>
        </div>
        <div class="dw-widget-body"><?=$html?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <form id="dw-layout-form" method="post" action="<?=h($base)?>/admin/save_dashboard_layout.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="layout" id="dw-layout-input" value="">
  </form>
</div>

<script src="/static/dashboard/js/dashboard-widgets.js" defer></script>
<style>
/* inline flash override for this page */
.adam-flash-wrap{ max-width:1200px; margin:0 auto 1rem; }
</style>
