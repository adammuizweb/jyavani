<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.sidebar.manage', false);

$errors = [];
$success_msg = '';

$current_enabled = function_exists('settings_get')
    ? (settings_get($pdo, 'sidebar_enabled', '1') ?? '1')
    : '1';
$current_position = function_exists('settings_get')
    ? (settings_get($pdo, 'sidebar_position', 'right') ?? 'right')
    : 'right';

$controller_contexts = [
    'home'          => 'Homepage',
    '404'           => '404 Page',
    'search'        => 'Search Results',
    'archive'       => 'Archive',
    'category'      => 'Category',
    'tag'           => 'Tag',
    'author'        => 'Author',
    'single.article'=> 'Article Detail',
    'single.page'   => 'Page Detail',
    'list.article'  => 'Article List',
    'list.page'     => 'Page List',
    'list.category' => 'Category — Articles',
    'index.category'=> 'Category Index',
];

$current_overrides = [];
$stored_overrides = function_exists('settings_get')
    ? settings_get($pdo, 'sidebar_controller_overrides')
    : null;
if ($stored_overrides !== null) {
    $decoded = json_decode($stored_overrides, true);
    if (is_array($decoded)) $current_overrides = $decoded;
}

$base = ADMIN_BASE_PATH;
$self_url = $base . '/?page=admin/settings/sidebar';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $enabled = (string)($_POST['sidebar_enabled'] ?? '0');
    $position = (string)($_POST['sidebar_position'] ?? 'right');
    $current_enabled = $enabled;
    $current_position = $position;

    $new_overrides = [];
    $submitted_ctx = (array)($_POST['ctx'] ?? []);
    foreach ($submitted_ctx as $ctx_key => $val) {
        $ctx_key = (string)$ctx_key;
        if ($ctx_key === '' || !isset($controller_contexts[$ctx_key])) continue;

        $pos = trim((string)($val['position'] ?? ''));
        $hide = !empty($val['hide']);

        if ($pos !== '' && $pos !== 'left' && $pos !== 'right') {
            $pos = '';
        }

        if ($hide || $pos !== '') {
            $entry = [];
            if ($hide) $entry['hide'] = true;
            if ($pos !== '') $entry['position'] = $pos;
            $new_overrides[$ctx_key] = $entry;
        }
    }
    $current_overrides = $new_overrides;

    if (!in_array($enabled, ['0', '1'], true)) {
        $errors[] = __('Invalid enable value.');
    }
    if (!in_array($position, ['left', 'right'], true)) {
        $errors[] = __('Invalid position value.');
    }

    if (!$errors) {
        $ok1 = settings_set($pdo, 'sidebar_enabled', $enabled, 1);
        $ok2 = settings_set($pdo, 'sidebar_position', $position, 1);
        if (!($ok1 && $ok2)) {
            $errors[] = __('Failed to save global settings.');
        }
    }

    if (!$errors) {
        $encoded = !empty($new_overrides) ? json_encode($new_overrides, JSON_UNESCAPED_UNICODE) : '';
        $ok3 = settings_set($pdo, 'sidebar_controller_overrides', $encoded, 1);

        if ($ok3 === false) {
            $errors[] = __('Failed to save per-controller override.');
        }
    }

    if (!$errors) {
        if (function_exists('adiwira_redirect_with_flash')) {
            adiwira_redirect_with_flash($self_url, 'success', __('Sidebar settings saved successfully.'));
            exit;
        }
        $success_msg = __('Sidebar settings saved successfully.');
    }
}

$show_inline_success = ($success_msg !== '' && !function_exists('adiwira_bootstrap_toasts_script'));
$show_inline_errors  = (!empty($errors) && !function_exists('adiwira_bootstrap_toasts_script'));
?>
<div class="panel" style="max-width:820px;margin:20px auto;">

  <div style="margin-bottom:20px;">
    <h2 style="margin:0 0 4px;">Sidebar</h2>
    <div class="muted" style="font-size:13px;"><?=_e('Manage sidebar display — enable/disable, position, and per-page override.')?></div>
  </div>

  <?php if ($show_inline_success): ?>
    <div style="background:var(--adam-success);color:#fff;padding:10px 14px;border-radius:var(--adam-radius);margin-bottom:14px;font-size:14px;">
      <?= svg_ico('circle-check') ?> <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($show_inline_errors): ?>
    <div style="background:var(--adam-danger);color:#fff;padding:10px 14px;border-radius:var(--adam-radius);margin-bottom:14px;font-size:14px;">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" novalidate id="sidebar-settings-form" data-unsaved-guard<?= (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $errors) ? ' data-unsaved-guard-initial-dirty' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <!-- Priority Z & A -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
      <div style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:16px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
          <span class="badge" style="background:var(--adam-primary);color:#fff;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;">Z</span>
          <strong style="font-size:14px;">Master Enable/Disable</strong>
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:14px;">
          <input type="checkbox" name="sidebar_enabled" value="1" <?= $current_enabled === '1' ? 'checked' : '' ?> style="width:17px;height:17px;accent-color:var(--adam-primary);">
          <?=_e('Enable Sidebar')?>
        </label>
        <div class="muted" style="font-size:12px;margin-top:6px;"><?=_e('Disable to hide sidebar on <strong>all</strong> pages. Highest priority, other overrides do not apply.')?></div>
      </div>

      <div style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:16px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
          <span class="badge" style="background:var(--adam-text-3);color:var(--adam-card);padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;">A</span>
          <strong style="font-size:14px;"><?=_e('Global Default Position')?></strong>
        </div>
        <div style="display:flex;gap:18px;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:500;">
            <input type="radio" name="sidebar_position" value="left" <?= $current_position === 'left' ? 'checked' : '' ?> style="accent-color:var(--adam-primary);">
            <?=_e('Left')?>
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:500;">
            <input type="radio" name="sidebar_position" value="right" <?= $current_position === 'right' ? 'checked' : '' ?> style="accent-color:var(--adam-primary);">
            <?=_e('Right')?>
          </label>
        </div>
        <div class="muted" style="font-size:12px;margin-top:6px;"><?=_e('Default position when no override from controller (B) or content (C).')?></div>
      </div>
    </div>

    <!-- Priority B -->
    <div style="background:var(--adam-card);border:1px solid var(--adam-border);border-radius:var(--adam-radius);padding:16px;margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <span class="badge" style="background:var(--adam-warning);color:#fff;padding:1px 8px;border-radius:4px;font-size:11px;font-weight:600;">B</span>
        <strong style="font-size:14px;">Override Per Controller</strong>
      </div>
      <div class="muted" style="font-size:12px;margin-bottom:14px;"><?=_e('Set position or hide sidebar for specific page types (archive, category, etc.). Lower priority than content override (C), but higher than default position (A).')?></div>

      <table class="adam-table" style="width:100%;font-size:13px;">
        <thead>
          <tr>
            <th style="padding:8px 10px;text-align:left;white-space:nowrap;"><?=_e('Page')?></th>
            <th style="padding:8px 10px;text-align:left;width:130px;"><?=_e('Position')?></th>
            <th style="padding:8px 10px;text-align:center;width:80px;"><?=_e('Hide')?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($controller_contexts as $ctx_key_raw => $ctx_label):
            $ctx_key = (string)$ctx_key_raw;
            $override = $current_overrides[$ctx_key] ?? [];
            $ov_position = (string)($override['position'] ?? '');
            $ov_hide = !empty($override['hide']);
          ?>
            <tr>
              <td style="padding:7px 10px;white-space:nowrap;"><?= htmlspecialchars($ctx_label, ENT_QUOTES, 'UTF-8') ?></td>
              <td style="padding:7px 10px;">
                <select name="ctx[<?= htmlspecialchars($ctx_key, ENT_QUOTES, 'UTF-8') ?>][position]" style="padding:3px 6px;border:1px solid var(--adam-border-2);border-radius:5px;background:var(--adam-bg);color:var(--adam-text);font-size:12px;width:100%;max-width:120px;">
                  <option value="">— Default —</option>
                  <option value="left" <?= $ov_position === 'left' ? 'selected' : '' ?>><?=_e('Left')?></option>
                  <option value="right" <?= $ov_position === 'right' ? 'selected' : '' ?>><?=_e('Right')?></option>
                </select>
              </td>
              <td style="padding:7px 10px;text-align:center;">
                <input type="checkbox" name="ctx[<?= htmlspecialchars($ctx_key, ENT_QUOTES, 'UTF-8') ?>][hide]" value="1" <?= $ov_hide ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--adam-danger);cursor:pointer;">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-bottom:20px;">
      <button type="submit" class="adam-button" style="padding:8px 20px;"><?=_e('Save All Settings')?></button>
      <a class="adam-cancle" href="<?= htmlspecialchars($base . '/?page=admin/settings/index', ENT_QUOTES, 'UTF-8') ?>"><?=_e('Back')?></a>
    </div>
  </form>

  <div style="background:var(--adam-surface-3);border-radius:var(--adam-radius);padding:14px 16px;font-size:12px;color:var(--adam-muted);line-height:1.7;">
    <strong style="font-size:13px;"><?= svg_ico('rows-3', '', ['style' => 'width:16px;height:16px;vertical-align:middle;margin-right:4px']) ?> <?=_e('Override Priority (highest → lowest)')?></strong>
    <ol style="margin:6px 0 0;padding-left:20px;">
      <li><strong>Z — <?=_e('Master')?></strong> — <?=_e('global enable/disable. Turn off to disable sidebar on all pages.')?></li>
      <li><strong>C — <?=_e('Per Content')?></strong> — <?=_e('set position or hide sidebar for specific articles/pages (from editor).')?></li>
      <li><strong>B — <?=_e('Per Controller')?></strong> — <?=_e('set position or hide per page type (table above).')?></li>
      <li><strong>A — <?=_e('Global')?></strong> — <?=_e('default left/right position (used when no other override).')?></li>
    </ol>
  </div>
</div>

<?php
if (function_exists('adiwira_bootstrap_toasts_script')) {
    $toast_items = $page_toasts ?? [];
    if ($success_msg !== '') {
        $toast_items[] = ['type' => 'success', 'message' => $success_msg];
    }
    foreach ($errors as $msg) {
        $toast_items[] = ['type' => 'error', 'message' => (string)$msg];
    }
    if (!empty($toast_items)) {
        echo adiwira_bootstrap_toasts_script($toast_items);
    }
}
?>
