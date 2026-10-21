<?php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}
/** @var PDO $pdo */
require_once __DIR__ . '/widgets.php';
require_once dirname(__DIR__, 5) . '/cfg/helpers/dashboard_layout.php';

$base = ADMIN_BASE_PATH;

$homeActor = function_exists('authorization_actor') ? authorization_actor($pdo) : null;
$canManageDashboardLayout = function_exists('current_user_can')
    && current_user_can($pdo, 'core.dashboard.layout.manage');
$widgets = dashboard_widgets_registry($pdo, $homeActor);
$siteHealthRequired = $homeActor !== null && $homeActor['is_site_owner'] === true
    && function_exists('current_user_can') && current_user_can($pdo, 'core.settings.manage');
$homeNoticeContext = [
    'schema' => 1,
    'surface' => 'dashboard.home',
    'user_id' => max(0, (int)($homeActor['id'] ?? 0)),
    'is_site_owner' => ($homeActor['is_site_owner'] ?? false) === true,
    'can_manage_layout' => $canManageDashboardLayout,
    'admin_base_path' => $base,
    'locale' => function_exists('get_locale') ? get_locale() : 'en',
];
$coreHomeNotices = [];
if ($siteHealthRequired) {
    $coreHomeNotices[] = [
        'id' => 'core.site-health-reminder',
        'revision' => '2',
        'type' => 'info',
        'icon' => 'shield-check',
        'title' => __('Keep an eye on Site Health'),
        'message' => __('Review Site Health regularly to spot unexpected file changes and other security signals.'),
        'action' => [
            'label' => __('Open Site Health'),
            'url' => $base . '/?page=admin/settings/health',
        ],
        'dismissible' => true,
    ];
}
$homeNotices = dashboard_home_notices_collect($pdo, $homeNoticeContext, $coreHomeNotices);
$layoutJson = settings_get($pdo, 'dashboard_widget_layout', '');
$layoutJson = is_string($layoutJson) ? $layoutJson : '';
$order = $layoutJson === '' ? null : json_decode($layoutJson, true);

if ($layoutJson === '' || !is_array($order)) {
    $order = dashboard_widgets_default_layout();
}
// Backward compatibility for the original object-based layout format.
$order = dashboard_layout_normalize_legacy($order);
$normalizedOrder = [];
$normalizedKeys = [];
foreach (array_slice($order, 0, 100) as $item) {
    $itemKey = dashboard_layout_item_key($item);
    if ($itemKey === null || isset($normalizedKeys[$itemKey])) continue;
    $normalizedKeys[$itemKey] = true;
    $normalizedOrder[] = $item;
}
$order = $normalizedOrder;
$layoutKeys = array_map(static fn($item): string => explode(':', (string)$item, 2)[0], $order);
foreach ($widgets as $requiredKey => $definition) {
    if (empty($definition['required']) || in_array($requiredKey, $layoutKeys, true)) continue;
    if (count($order) >= 100) {
        for ($index = count($order) - 1; $index >= 0; $index--) {
            $candidateKey = explode(':', (string)$order[$index], 2)[0];
            if (!empty($widgets[$candidateKey]['required'])) continue;
            array_splice($order, $index, 1);
            break;
        }
    }
    if (count($order) < 100) {
        $order[] = $requiredKey . ':r';
        $layoutKeys[] = $requiredKey;
    }
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
    $required = !empty($widgets[$key]['required']);
    $protected = !empty($widgets[$key]['protected']);
    $h = h($key);
    return '<div class="dw-widget" data-widget="' . $h . '"' . $fw . ($required ? ' data-required="1"' : '') . ($protected ? ' data-protected="1"' : '') . '>'
         . '<div class="dw-drag-handle" draggable="' . ($protected ? 'false' : 'true') . '" title="' . h(__('Drag to reorder')) . '">'
         . svg_ico('grip-vertical')
         . ($required || $protected ? '' : '<button type="button" class="dw-hide-btn" title="' . h(__('Hide widget')) . '" aria-label="' . h(__('Hide widget')) . '">' . svg_ico('eye-off') . '</button>')
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
    <h2 class="dw-heading-title page-heading"><?=_e('Dashboard')?></h2>
    <div class="dw-heading-actions">
      <?php if ($canManageDashboardLayout): ?><button type="button" class="adam-button" id="dw-arrange-toggle" data-active="0"><?=_e('Arrange Widgets')?></button><?php endif; ?>
    </div>
  </div>

  <?= dashboard_home_notices_render($homeNotices, $homeNoticeContext) ?>

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

  <?php if ($canManageDashboardLayout): ?><form id="dw-layout-form" method="post" action="<?=h($base)?>/admin/save_dashboard_layout.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
    <input type="hidden" name="layout" id="dw-layout-input" value="">
    <input type="hidden" name="layout_revision" value="<?=h(hash('sha256', $layoutJson))?>">
  </form>
  <div id="dw-widget-panel" style="display:none">
    <h3><?=_e('Widget Manager')?></h3>
    <?php $managerWidgetKeys = array_keys(array_filter($widgets, static fn(array $definition): bool => empty($definition['protected']))); ?>
    <div id="dw-widget-list" data-keys='<?=h(json_encode($managerWidgetKeys))?>'></div>
  </div><?php endif; ?>
</div>

<?php
$dashboardWidgetsFile = defined('PUBLIC_PATH') ? PUBLIC_PATH . '/static/dashboard/js/dashboard-widgets.js' : '';
$dashboardWidgetsVersion = is_file($dashboardWidgetsFile) ? (int)filemtime($dashboardWidgetsFile) : 0;
?>
<script src="/static/dashboard/js/dashboard-widgets.js?v=<?=$dashboardWidgetsVersion?>" defer></script>
<style>
.adam-flash-wrap{ max-width:1200px; margin:0 auto 1rem; }
</style>
