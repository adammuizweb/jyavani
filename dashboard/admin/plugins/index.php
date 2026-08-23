<?php
declare(strict_types=1);
// Plugin Manager — Kelola plugin (aktif/nonaktif/update/bulk)

require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.plugins.manage', false);
adiwira_require_site_owner($pdo, false);

require_once __DIR__ . '/../../../app/controllers/PluginStoreController.php';
require_once __DIR__ . '/../../../app/controllers/UpdateStatusController.php';

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/plugins/index';

// --- Handle POST actions ---
$action = (string)($_POST['action'] ?? '');
$pluginName = (string)($_POST['plugin'] ?? '');

// Toggle single plugin
if ($action === 'toggle' && $pluginName !== '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $manifest = plugin_manifest($pluginName);
    if (!$manifest) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin') . ' "' . h($pluginName) . '" ' . __('not found.'));
    }
    if (plugin_is_active($pluginName)) {
        if (plugin_disable($pluginName)) {
            adiwira_redirect_with_flash($selfUrl, 'success', __('Plugin') . ' "' . h($manifest['title'] ?? $pluginName) . '" ' . __('deactivated.'));
        }
        adiwira_redirect_with_flash($selfUrl, 'error', plugin_last_error() ?: __('Failed to deactivate plugin.'));
    } else {
        if (plugin_enable($pluginName)) {
            adiwira_redirect_with_flash($selfUrl, 'success', __('Plugin') . ' "' . h($manifest['title'] ?? $pluginName) . '" ' . __('activated.'));
        }
        adiwira_redirect_with_flash($selfUrl, 'error', plugin_last_error() ?: __('Failed to activate plugin.'));
    }
}

// Uninstall single plugin
if ($action === 'delete' && $pluginName !== '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $manifest = plugin_manifest($pluginName);
    if (!$manifest) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin') . ' "' . h($pluginName) . '" ' . __('not found.'));
    }
    $keepData = !empty($_POST['keep_data']);
    $ok = plugin_uninstall($pluginName, $keepData);
    if ($ok) {
        $msg = __('Plugin') . ' "' . h($manifest['title'] ?? $pluginName) . '" ' . ($keepData ? __('uninstalled. Data kept.') : __('uninstalled completely.'));
        adiwira_redirect_with_flash($selfUrl, 'success', $msg);
    }
    adiwira_redirect_with_flash($selfUrl, 'error', plugin_last_error() ?: __('Failed to uninstall plugin.'));
}

// Bulk actions
if ($action === 'bulk' && !empty($_POST['bulk_action']) && !empty($_POST['plugins'])) {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $bulkAction = (string)$_POST['bulk_action'];
    $pluginNames = array_map('strval', (array)$_POST['plugins']);
    if ($bulkAction === 'activate') $pluginNames = plugin_order_names_by_dependencies($pluginNames, true);
    if (in_array($bulkAction, ['deactivate', 'uninstall'], true)) $pluginNames = plugin_order_names_by_dependencies($pluginNames, false);
    $success = 0;
    $failed = 0;
    $skipped = 0;
    $failureMessages = [];

    foreach ($pluginNames as $pn) {
        $manifest = plugin_manifest($pn);
        if (!$manifest) { $failed++; continue; }
        $title = $manifest['title'] ?? $pn;

        switch ($bulkAction) {
            case 'activate':
                if (!plugin_is_active($pn) && plugin_enable($pn)) { $success++; }
                elseif (plugin_is_active($pn)) { $skipped++; }
                else { $failed++; if (plugin_last_error() !== '') $failureMessages[] = plugin_last_error(); }
                break;

            case 'deactivate':
                if (plugin_is_active($pn) && plugin_disable($pn)) { $success++; }
                elseif (!plugin_is_active($pn)) { $skipped++; }
                else { $failed++; if (plugin_last_error() !== '') $failureMessages[] = plugin_last_error(); }
                break;

            case 'uninstall':
                // Bulk uninstall always keeps data — destructive cleanup must be done individually
                if (plugin_uninstall($pn, true)) { $success++; }
                else { $failed++; if (plugin_last_error() !== '') $failureMessages[] = plugin_last_error(); }
                break;

            default:
                $failed++;
        }
    }

    $parts = [];
    if ($success > 0) $parts[] = $success . ' ' . __('succeeded');
    if ($skipped > 0) $parts[] = $skipped . ' ' . __('skipped (already in target state)');
    if ($failed > 0) $parts[] = $failed . ' ' . __('failed');
    $msg = ucfirst($bulkAction) . ': ' . implode(', ', $parts) . '.';
    if ($failureMessages !== []) $msg .= ' ' . implode(' ', array_unique($failureMessages));
    $type = $failed > 0 ? 'warning' : 'success';
    adiwira_redirect_with_flash($selfUrl, $type, $msg);
}

// Check updates
if ($action === 'check-updates') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    session_write_close();
    try {
        $snapshot = UpdateStatusController::checkAll($pdo);
    } catch (Throwable $error) {
        ensure_session_started(true);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to check updates.'));
    }
    ensure_session_started(true);
    UpdateStatusController::hydrateCoreSession($snapshot);
    $count = (int)($snapshot['total'] ?? 0);
    if (($snapshot['state'] ?? 'ok') !== 'ok') {
        adiwira_redirect_with_flash($selfUrl, 'warning', __('Update check completed with partial results.') . ' ' . $count . ' ' . __('update(s) available across Core, plugins, and themes.'));
    } elseif ($count > 0) {
        adiwira_redirect_with_flash($selfUrl, 'success', $count . ' ' . __('update(s) available across Core, plugins, and themes.'));
    } else {
        adiwira_redirect_with_flash($selfUrl, 'info', __('All Core, plugins, and themes are up to date.'));
    }
}

// Apply update
if ($action === 'apply-update' && $pluginName !== '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $updateResult = PluginStoreController::applyUpdate($pdo, $pluginName);
    if ($updateResult['success']) {
        UpdateStatusController::removeUpdate('plugins', $pluginName);
        adiwira_redirect_with_flash($selfUrl, 'success', __('Plugin') . ' "' . h($pluginName) . '" ' . __('updated to v') . h($updateResult['new_version']) . '.');
    } else {
        adiwira_redirect_with_flash($selfUrl, 'error', h($updateResult['error']));
    }
}

// --- Collect plugin data ---
$allPlugins = plugins_all();
$activePlugins = plugins_active();
$requirementDiagnostics = plugin_requirement_diagnostics();
$availableUpdates = UpdateStatusController::getComponentUpdates('plugins');
$hasStoreUrl = false;
foreach ($allPlugins as $name => $p) {
    if (!empty($p['store'])) { $hasStoreUrl = true; break; }
}
$pageToasts = function_exists('adiwira_collect_query_toasts') ? adiwira_collect_query_toasts() : [];

// --- Search ---
$search = trim($_GET['q'] ?? '');

// --- Filter ---
$filterStatus = $_GET['status'] ?? '';

// --- Per page ---
$allowedPerPage = [10, 20, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 20;

// --- Filter plugins ---
$filteredPlugins = [];
foreach ($allPlugins as $name => $p) {
    $title = $p['title'] ?? $name;
    $desc = $p['description'] ?? '';
    $isActive = isset($activePlugins[$name]);
    $hasUpdate = isset($availableUpdates[$name]);

    // Search
    if ($search !== '') {
        $q = mb_strtolower($search);
        $haystack = mb_strtolower($title . ' ' . $name . ' ' . $desc . ' ' . ($p['author'] ?? ''));
        if (mb_strpos($haystack, $q) === false) continue;
    }

    // Status filter
    if ($filterStatus === 'active' && !$isActive) continue;
    if ($filterStatus === 'inactive' && $isActive) continue;
    if ($filterStatus === 'update' && !$hasUpdate) continue;

    $filteredPlugins[$name] = $p;
}

// --- Pagination ---
$totalPlugins = count($filteredPlugins);
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$totalPages = max(1, (int)ceil($totalPlugins / $perPage));
if ($pageNum > $totalPages) $pageNum = $totalPages;
$offset = ($pageNum - 1) * $perPage;
$pagedPlugins = array_slice($filteredPlugins, $offset, $perPage, true);

// --- Build pagination ---
if (!function_exists('build_pagination_items')) {
    function build_pagination_items(int $current, int $total, int $max_visible = 9): array {
        if ($total <= $max_visible) return range(1, $total);
        $items = [];
        $reserved = 6;
        $middle_slots = max(1, $max_visible - $reserved);
        $half = (int)floor($middle_slots / 2);
        $start = max(3, $current - $half);
        $end = min($total - 2, $current + $half);
        if ($start === 3) $end = min($total - 2, $start + $middle_slots - 1);
        if ($end === $total - 2) $start = max(3, $end - $middle_slots + 1);
        $items[] = 1;
        $items[] = 2;
        if ($start > 3) $items[] = '...';
        for ($i = $start; $i <= $end; $i++) $items[] = $i;
        if ($end < $total - 2) $items[] = '...';
        $items[] = $total - 1;
        $items[] = $total;
        while (count($items) > $max_visible) {
            for ($i = 0; $i < count($items); $i++) {
                if (is_int($items[$i]) && $items[$i] !== 1 && $items[$i] !== 2 && $items[$i] !== $total - 1 && $items[$i] !== $total) {
                    array_splice($items, $i, 1);
                    break;
                }
            }
        }
        return $items;
    }
}
$pagingItems = build_pagination_items($pageNum, $totalPages, 9);

// Helper: build URL preserving current query
$buildUrl = function(array $overrides = []) use ($base): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    $q['page'] = 'admin/plugins/index';
    return $base . '/?' . http_build_query($q);
};
?>
<h2 class="pg-title"><?=_e('Plugin')?></h2>
<div data-update-status-page hidden></div>
<p class="pg-subtitle"><?=_e('Manage installed plugins.')?></p>

<div class="form-row">
  <a href="<?= h($base) ?>/?page=admin/plugins/browse" class="adam-button"><?= svg_ico('store') ?> <?=_e('Browse Plugins')?></a>
  <a href="<?= h($base) ?>/?page=admin/plugins/upload" class="adam-button"><?= svg_ico('upload') ?> <?=_e('Upload Plugin')?></a>
  <form method="post" class="form-inline">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="check-updates">
    <button type="submit" class="adam-button"><?= svg_ico('refresh-cw') ?> <?=_e('Check All Updates')?></button>
  </form>
</div>

<!-- Toolbar: search + filter + per-page -->
<form method="get" class="plugin-toolbar">
  <input type="hidden" name="page" value="admin/plugins/index">
  <div class="toolbar-search">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="<?=_e('Search plugins…')?>" class="inp">
    <button type="submit" class="btn-icon btn-icon-search" title="<?=_e('Search')?>"><?= svg_ico('search', 'lucide-icon-sm') ?></button>
  </div>
  <select name="status" class="inp" onchange="this.form.submit()">
    <option value=""><?=_e('All Status')?></option>
    <option value="active" <?= $filterStatus==='active'?'selected':'' ?>><?=_e('Active')?></option>
    <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>><?=_e('Inactive')?></option>
    <option value="update" <?= $filterStatus==='update'?'selected':'' ?>><?=_e('Update Available')?></option>
  </select>
  <select name="per_page" class="inp" onchange="this.form.submit()">
    <?php foreach ($allowedPerPage as $pp): ?>
      <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?> / <?=_e('page')?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($search !== '' || $filterStatus !== ''): ?>
    <a href="<?= h($buildUrl(['q'=>null,'status'=>null,'p'=>null])) ?>" class="btn btn-sm btn-outline"><?=_e('Clear')?></a>
  <?php endif; ?>
</form>

<?php if (empty($allPlugins)): ?>
<div class="empty-state">
  <p><?=_e('No plugins installed yet. Add a plugin folder with')?> <code>plugin.json</code> <?=_e('to the')?> <code>plugins/</code> <?=_e('folder.')?></p>
</div>
<?php elseif (empty($pagedPlugins)): ?>
<div class="empty-state">
  <p><?=_e('No plugins match your search/filter.')?></p>
</div>
<?php else: ?>

<!-- Bulk action bar -->
<form method="post" id="bulkForm" class="bulk-bar" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="bulk">
  <span id="bulkCount" class="bulk-count">0</span> <?=_e('selected')?>
  <select name="bulk_action" id="bulkActionSelect" class="inp inp-sm">
    <option value=""><?=_e('-- Bulk action --')?></option>
    <option value="activate"><?=_e('Activate')?></option>
    <option value="deactivate"><?=_e('Deactivate')?></option>
    <option value="uninstall"><?=_e('Uninstall (keep data)')?></option>
  </select>
  <button type="submit" class="btn btn-sm btn-primary" id="bulkApplyBtn" disabled><?=_e('Apply')?></button>
</form>

<div class="table-wrap">
<table class="data-table">
  <thead>
    <tr>
      <th class="th-check"><input type="checkbox" id="selectAllPlugins" title="<?=_e('Select all')?>"></th>
      <th><?=_e('Plugin')?></th>
      <th><?=_e('Version')?></th>
      <th><?=_e('Status')?></th>
      <th class="th-actions"><?=_e('Actions')?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($pagedPlugins as $name => $p):
      $title = $p['title'] ?? $name;
      $desc = $p['description'] ?? '';
      $version = $p['version'] ?? '—';
      $author = $p['author'] ?? '';
      $authorUri = $p['author_uri'] ?? '';
      $pluginUri = $p['plugin_uri'] ?? '';
      $isActive = isset($activePlugins[$name]);
      $requirementDiagnostic = $requirementDiagnostics[$name] ?? '';
      $hasUpdate = isset($availableUpdates[$name]);
      $updateInfo = $hasUpdate ? $availableUpdates[$name] : null;
      $updateActionable = !$hasUpdate || (($updateInfo['actionable'] ?? false) === true);
      $updateCompatible = !$hasUpdate || (($updateInfo['compatible'] ?? true) === true);
      $hasStore = !empty($p['store']) || str_starts_with((string)($p['plugin_uri'] ?? ''), 'https://jyavani.com/plugin/');
    ?>
    <?php
      $iconRaw = $p['icon'] ?? '';
      if ($iconRaw !== '' && !str_starts_with($iconRaw, 'http://') && !str_starts_with($iconRaw, 'https://')) {
          $iconFile = PLUGIN_PATH . '/' . $name . '/' . $iconRaw;
          if (is_file($iconFile)) {
              $iconUrl = '/plugins/icon/' . rawurlencode($name) . '/';
          } else {
              $iconUrl = '';
          }
      } else {
          $iconUrl = $iconRaw;
      }
      $firstLetter = mb_strtoupper(mb_substr($title, 0, 1));
      $iconColors = ['#6366f1','#ec4899','#14b8a6','#f97316','#8b5cf6','#ef4444','#06b6d4','#84cc16','#d946ef','#0ea5e9'];
      $iconColor = $iconColors[crc32($name) % count($iconColors)];
    ?>
    <tr data-plugin="<?= h($name) ?>">
      <td class="td-check">
        <input type="checkbox" class="plugin-checkbox" value="<?= h($name) ?>">
      </td>
      <td>
        <div class="plugin-cell">
          <?php if ($iconUrl): ?>
          <img class="plugin-cell-icon" src="<?= h($iconUrl) ?>" alt="" loading="lazy" width="40" height="40">
          <?php else: ?>
          <span class="plugin-cell-placeholder" style="background:<?= $iconColor ?>"><?= h($firstLetter) ?></span>
          <?php endif; ?>
          <div>
            <a href="<?= h($base) ?>/?page=admin/plugins/detail&name=<?= h($name) ?>" class="plugin-name-link"><?= h($title) ?></a>
             <?php if ($desc): ?><br><span class="text-muted" style="font-size:0.85rem"><?= h($desc) ?></span><?php endif; ?>
            <?php if ($requirementDiagnostic !== ''): ?><br><span style="font-size:.8rem;color:var(--adam-danger)"><?= h($requirementDiagnostic) ?></span><?php endif; ?>
            <?php if ($author): ?><br><span class="plugin-meta">
              <?php if ($authorUri): ?>
                <a href="<?= h($authorUri) ?>" target="_blank" rel="noopener" class="plugin-meta-link"><?= svg_ico('user', 'lucide-icon-xs') ?> <?= h($author) ?></a>
              <?php else: ?>
                <span class="plugin-meta-item"><?= svg_ico('user', 'lucide-icon-xs') ?> <?= h($author) ?></span>
              <?php endif; ?>
              <?php if ($pluginUri): ?>
                <a href="<?= h($pluginUri) ?>" target="_blank" rel="noopener" class="plugin-meta-link"><?= svg_ico('external-link', 'lucide-icon-xs') ?> <?=_e('Visit Web')?></a>
              <?php endif; ?>
            </span><?php endif; ?>
          </div>
        </div>
      </td>
      <td class="td-version">
        <span class="version-text"><?= h($version) ?></span>
        <?php if ($hasUpdate): ?>
          <br><span class="badge badge-update">v<?= h($updateInfo['new_version']) ?></span>
          <?php if (!$updateActionable): ?><br><span style="font-size:.72rem;color:var(--adam-muted)"><?=_e('Run "Check for Updates" first.')?></span><?php endif; ?>
          <?php if (!$updateCompatible): ?><br><span style="font-size:.72rem;color:var(--adam-danger)"><?= h(implode('; ', (array)($updateInfo['compatibility_errors'] ?? []))) ?></span><?php endif; ?>
        <?php elseif ($hasStore): ?>
          <br><span class="badge badge-latest"><?=_e('Latest')?></span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($requirementDiagnostic !== ''): ?>
          <span class="badge badge-update"><?=_e('Incompatible')?></span>
        <?php elseif ($isActive): ?>
          <span class="badge badge-success"><?=_e('Active')?></span>
        <?php else: ?>
          <span class="badge badge-muted"><?=_e('Inactive')?></span>
        <?php endif; ?>
      </td>
      <td class="td-actions">
        <div class="action-btns">
          <?php if ($hasUpdate && $updateActionable && $updateCompatible): ?>
          <form method="post" class="form-inline js-confirm-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="apply-update">
            <input type="hidden" name="plugin" value="<?= h($name) ?>">
            <button type="submit" class="btn-icon btn-icon-update js-confirm-btn"
              title="<?=__('Update to v')?><?= h($updateInfo['new_version']) ?>"
              data-confirm-title="<?=_e('Update Plugin')?>"
              data-confirm-text="<?=__('Update plugin')?> &quot;<?= h($title) ?>&quot; <?=__('from v')?><?= h($version) ?> <?=__('to v')?><?= h($updateInfo['new_version']) ?>? <?=__('Backup will be created automatically.')?>"
              data-confirm-action="update"><?= svg_ico('download', 'lucide-icon-sm') ?></button>
          </form>
          <?php endif; ?>
          <form method="post" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="plugin" value="<?= h($name) ?>">
            <?php if ($isActive): ?>
              <button type="submit" class="btn-icon btn-icon-toggle js-confirm-btn"
                title="<?=_e('Deactivate')?>"
                data-confirm-title="<?=_e('Deactivate Plugin')?>"
                data-confirm-text="<?=__('Deactivate plugin')?> &quot;<?= h($title) ?>&quot;?"
                data-confirm-action="deactivate"><?= svg_ico('power', 'lucide-icon-sm') ?></button>
            <?php else: ?>
              <button type="submit" class="btn-icon btn-icon-activate js-confirm-btn"
                title="<?=_e('Activate')?>"
                data-confirm-title="<?=_e('Activate Plugin')?>"
                data-confirm-text="<?=__('Activate plugin')?> &quot;<?= h($title) ?>&quot;?"
                data-confirm-action="activate"><?= svg_ico('zap', 'lucide-icon-sm') ?></button>
            <?php endif; ?>
          </form>
          <form method="post" class="form-inline js-confirm-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="plugin" value="<?= h($name) ?>">
            <input type="hidden" name="keep_data" value="1" class="js-keep-data">
            <button type="submit" class="btn-icon btn-icon-danger js-confirm-btn"
              title="<?=_e('Uninstall')?>"
              data-confirm-title="<?=_e('Uninstall Plugin')?>"
              data-confirm-action="delete"><?= svg_ico('trash-2', 'lucide-icon-sm') ?></button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<nav class="adam-pagination pagination-wrap">
  <?php foreach ($pagingItems as $item):
    if ($item === '...') {
      echo '<span class="dots">…</span> ';
      continue;
    }
    $i = (int)$item;
    $link = $buildUrl(['p' => $i]);
  ?>
    <?php if ($i === $pageNum): ?>
      <strong><?= $i ?></strong>
    <?php else: ?>
      <a href="<?= h($link) ?>"><?= $i ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="plugin-count">
  <?= $totalPlugins ?> <?=_e('plugin(s)')?>
  <?php if ($totalPages > 1): ?> — <?=_e('Page')?> <?= $pageNum ?> <?=_e('of')?> <?= $totalPages ?><?php endif; ?>
</div>

<?php endif; ?>

<!-- Konfirmasi Modal -->
<div class="adam-modal" id="pluginConfirmModal" style="display:none">
  <div class="adam-modal__panel" style="max-width:420px">
    <div class="adam-modal__title" id="pluginConfirmTitle"><?=_e('Confirmation')?></div>
    <div class="adam-modal__text" id="pluginConfirmText" style="margin-bottom:1.25rem;line-height:1.5"></div>
    <div id="pluginConfirmKeepData" style="display:none;margin-bottom:1rem;padding:.6rem;background:var(--adam-surface-3);border-radius:6px">
      <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
        <input type="checkbox" id="pluginKeepDataCheck" checked>
        <span style="font-size:.875rem;color:var(--adam-text)"><?=__('Keep plugin data for future reinstallation')?></span>
      </label>
    </div>
    <div class="adam-modal__actions" style="display:flex;gap:.5rem;justify-content:flex-end">
      <button type="button" class="btn btn-outline" onclick="hidePluginConfirm()"><?=_e('Cancel')?></button>
      <button type="button" class="btn" id="pluginConfirmApply" onclick="applyPluginConfirm()"><?=_e('Yes')?></button>
    </div>
  </div>
</div>

<!-- Progress Overlay -->
<div id="pluginUpdateProgress" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);align-items:center;justify-content:center">
  <div style="background:var(--adam-card);padding:2rem 2.5rem;border-radius:12px;text-align:center;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.3);width:90%">
    <div id="progressSpinner" style="width:40px;height:40px;border:4px solid var(--adam-border-2);border-top-color:var(--adam-primary);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 1rem"></div>
    <div id="progressStatus" style="font-weight:600;font-size:1rem;color:var(--adam-text)"><?=__('Processing…')?></div>
    <div id="progressDetail" style="margin-top:.4rem;font-size:.8rem;color:var(--adam-muted);min-height:1.2em"></div>
    <div style="margin-top:1rem;background:var(--adam-border-2);border-radius:999px;height:8px;overflow:hidden">
      <div id="progressBar" style="width:0%;height:100%;background:var(--adam-primary);border-radius:999px;transition:width .4s ease"></div>
    </div>
    <div id="progressPct" style="margin-top:.3rem;font-size:.75rem;color:var(--adam-muted)">0%</div>
  </div>
</div>
<style>
@keyframes spin { to { transform:rotate(360deg); } }
</style>

<style>
.pg-title { font-size:1.4rem; font-weight:700; margin:0 0 .25rem; color:var(--adam-text); }
.pg-subtitle { color:var(--adam-muted); font-size:.9rem; margin:0 0 1.5rem; }
.text-muted { color:var(--adam-muted); }
.empty-state { padding:2rem; text-align:center; color:var(--adam-muted); }

/* Toolbar */
.plugin-toolbar { display:flex; gap:.5rem; align-items:center; margin-bottom:1rem; flex-wrap:wrap; }
.toolbar-search { display:flex; gap:.25rem; align-items:center; flex:1; min-width:200px; }
.toolbar-search .inp { flex:1; }
.plugin-toolbar .inp { padding:.35rem .6rem; border:1px solid var(--adam-border-2); border-radius:6px; font-size:.85rem; background:var(--adam-card); color:var(--adam-text); }
.plugin-toolbar .inp:focus { outline:none; border-color:var(--adam-primary); box-shadow:0 0 0 2px rgba(220,38,38,.15); }

/* Mobile: stack toolbar cleanly */
@media (max-width: 640px){
  .plugin-toolbar{ flex-direction:column; align-items:stretch; gap:.4rem; }
  .toolbar-search{ min-width:0; width:100%; }
  .plugin-toolbar select.inp{ width:100%; }
  .plugin-toolbar .btn-outline{ width:100%; text-align:center; justify-content:center; }
}
.inp-sm { font-size:.8rem; padding:.25rem .5rem; }

/* Bulk bar */
.bulk-bar { display:flex; gap:.5rem; align-items:center; padding:.5rem .75rem; background:var(--adam-surface-3); border:1px solid var(--adam-border); border-radius:8px; margin-bottom:.75rem; font-size:.85rem; color:var(--adam-text); }
.bulk-count { font-weight:700; color:var(--adam-primary); }
.bulk-bar .inp-sm { min-width:160px; }

/* Table */
.table-wrap { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; }
.data-table th,
.data-table td { text-align:left; padding:.6rem .75rem; border-bottom:1px solid var(--adam-border); vertical-align:middle; color:var(--adam-text); }
.data-table th { font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--adam-muted); background:var(--adam-surface-3); }
.th-check { width:36px; text-align:center; }
.td-check { text-align:center; }
.th-actions { width:100px; text-align:right; }
.td-actions { text-align:right; }
.td-version { white-space:nowrap; }

/* Badges */
.badge { display:inline-block; padding:.15rem .5rem; font-size:.75rem; font-weight:600; border-radius:999px; }
.badge-success { background:#d1fae5; color:#065f46; }
.badge-muted { background:var(--adam-surface-3); color:var(--adam-muted); }
.badge-update { background:#fef3c7; color:#92400e; }
.badge-latest { background:#d1fae5; color:#065f46; font-size:.65rem; padding:.1rem .4rem; letter-spacing:.02em; }
.version-text { font-weight:500; }

/* Buttons */
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-danger { background:var(--adam-danger); color:#fff; border-color:var(--adam-danger); }
.btn-danger:hover { background:var(--adam-danger-600); }

/* Icon buttons */
.action-btns { display:flex; gap:.25rem; justify-content:flex-end; }
.btn-icon { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; cursor:pointer; border:1px solid var(--adam-border-2); background:var(--adam-card); color:var(--adam-muted); transition:all .15s; }
.btn-icon:hover { background:var(--adam-surface-3); color:var(--adam-text); border-color:var(--adam-border); }
.btn-icon-activate:hover { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }
.btn-icon-toggle:hover { background:#fef3c7; color:#92400e; border-color:#fde68a; }
.btn-icon-update:hover { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }
.btn-icon-danger:hover { background:#fee2e2; color:#dc2626; border-color:#fecaca; }
.btn-icon-search { width:32px; height:32px; border-color:var(--adam-primary); color:var(--adam-primary); }
.btn-icon-search:hover { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }

/* Plugin cell */
.plugin-name-link { color:var(--adam-text); font-weight:600; text-decoration:none; }
.plugin-name-link:hover { color:var(--adam-primary); text-decoration:underline; }
.plugin-cell { display:flex; gap:.75rem; align-items:center; }
.plugin-cell-icon { width:40px; height:40px; border-radius:6px; object-fit:contain; flex-shrink:0; background:var(--adam-surface-4); }
.plugin-cell-placeholder { width:40px; height:40px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:700; color:#fff; flex-shrink:0; }

/* Plugin meta (author + links) */
.plugin-meta { display:inline-flex; gap:.6rem; align-items:center; font-size:.75rem; color:var(--adam-muted); }
.plugin-meta-link { display:inline-flex; align-items:center; gap:.2rem; color:var(--adam-muted); text-decoration:none; transition:color .15s; }
.plugin-meta-link:hover { color:var(--adam-primary); }
.plugin-meta-item { display:inline-flex; align-items:center; gap:.2rem; }

/* Pagination */
.plugin-count { margin-top:.75rem; font-size:.8rem; color:var(--adam-muted); }

/* Small icons */
.lucide-icon-sm { width:14px; height:14px; }
.lucide-icon-xs { width:12px; height:12px; }

/* Row highlight on checkbox */
tr.row-selected { background:var(--adam-surface-4); }
</style>

<script>
var _confirmForm = null;
var _confirmAction = '';
var _csrfToken = '<?= h(csrf_token()) ?>';

// ─── Confirm modal ───
document.querySelectorAll('.js-confirm-btn').forEach(function(btn) {
  btn.addEventListener('click', function(e) {
    var form = this.closest('.js-confirm-form') || this.closest('form');
    if (!form) return;
    var title = this.getAttribute('data-confirm-title') || '<?=__('Confirmation')?>';
    var text = this.getAttribute('data-confirm-text') || '<?=__('Continue?')?>';
    var action = this.getAttribute('data-confirm-action') || '';
    document.getElementById('pluginConfirmTitle').textContent = title;
    document.getElementById('pluginConfirmText').textContent = text;
    var applyBtn = document.getElementById('pluginConfirmApply');
    var keepDataEl = document.getElementById('pluginConfirmKeepData');
    if (action === 'delete') {
      applyBtn.className = 'btn btn-danger';
      applyBtn.textContent = '<?=__('Yes, Uninstall')?>';
      keepDataEl.style.display = 'block';
    } else if (action === 'update') {
      keepDataEl.style.display = 'none';
      applyBtn.className = 'btn btn-update';
      applyBtn.textContent = '<?=__('Yes, Update')?>';
    } else {
      applyBtn.className = 'btn btn-primary';
      applyBtn.textContent = '<?=__('Yes')?>';
      keepDataEl.style.display = 'none';
    }
    _confirmForm = form;
    _confirmAction = action;
    document.getElementById('pluginConfirmModal').style.display = 'flex';
    e.preventDefault();
  });
});

function hidePluginConfirm() {
  document.getElementById('pluginConfirmModal').style.display = 'none';
  _confirmForm = null;
  _confirmAction = '';
}

// ─── Progress overlay ───
function updateProgressBar(pct, status) {
  var bar = document.getElementById('progressBar');
  var pctEl = document.getElementById('progressPct');
  var detailEl = document.getElementById('progressDetail');
  var statusEl = document.getElementById('progressStatus');
  var spinner = document.getElementById('progressSpinner');
  if (bar) bar.style.width = Math.min(pct, 100) + '%';
  if (pctEl) pctEl.textContent = pct + '%';
  if (detailEl) detailEl.textContent = status || '';
  if (pct >= 100 && statusEl) {
    statusEl.textContent = '<?=__('Done!')?>';
    if (spinner) spinner.style.display = 'none';
  }
}

function showProgressOverlay() {
  var overlay = document.getElementById('pluginUpdateProgress');
  if (!overlay) return;
  updateProgressBar(0, '<?=__('Starting...')?>');
  overlay.style.display = 'flex';
}

function hideProgressOverlay() {
  var overlay = document.getElementById('pluginUpdateProgress');
  if (overlay) overlay.style.display = 'none';
}

function makeProgressToken() {
  var hex = '0123456789abcdef';
  var token = '';
  for (var i = 0; i < 32; i++) token += hex[Math.floor(Math.random() * 16)];
  return token;
}

function applyPluginConfirm() {
  if (!_confirmForm) return;
  if (_confirmAction === 'update') {
    var pluginName = _confirmForm.querySelector('input[name="plugin"]')?.value || '';
    if (!pluginName) return;
    hidePluginConfirm();
    startPluginUpdate(pluginName);
  } else {
    var form = _confirmForm;
    if (_confirmAction === 'delete') {
      var keepField = form.querySelector('.js-keep-data');
      if (keepField) keepField.value = document.getElementById('pluginKeepDataCheck').checked ? '1' : '0';
    }
    hidePluginConfirm();
    var statusText = '<?=__('Processing…')?>';
    if (_confirmAction === 'activate') statusText = '<?=__('Activating plugin…')?>';
    else if (_confirmAction === 'deactivate') statusText = '<?=__('Deactivating plugin…')?>';
    else if (_confirmAction === 'delete') statusText = '<?=__('Uninstalling plugin…')?>';
    document.getElementById('progressStatus').textContent = statusText;
    showProgressOverlay();
    updateProgressBar(0, '');
    setTimeout(function() { form.submit(); }, 100);
  }
}

function startPluginUpdate(pluginName) {
  var token = makeProgressToken();
  document.getElementById('progressStatus').textContent = '<?=__('Updating plugin…')?>';
  showProgressOverlay();
  updateProgressBar(2, '<?=__('Preparing...')?>');

  var baseUrl = '<?= $base ?>';
  var progressUrl = baseUrl + '/admin/plugins/update_progress.php?token=' + token;
  var applyUrl = baseUrl + '/admin/plugins/update_apply.php';

  var pollTimer = setInterval(function() {
    fetch(progressUrl, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      updateProgressBar(data.percentage || 0, data.status || '');
      if (data.done || data.error) {
        clearInterval(pollTimer);
        if (data.error) {
          setTimeout(function() {
            hideProgressOverlay();
            alert('<?=__('Failed: ')?>' + data.error);
          }, 1500);
        } else {
          setTimeout(function() {
            if (window.adamRefreshUpdateStatus) window.adamRefreshUpdateStatus();
            window.location.href = '<?= $selfUrl ?>&update_ok=1';
          }, 1000);
        }
      }
    })
    .catch(function() {});
  }, 1500);

  var formData = new FormData();
  formData.append('csrf_token', _csrfToken);
  formData.append('action', 'apply-update');
  formData.append('plugin', pluginName);
  formData.append('token', token);

  fetch(applyUrl, {
    method: 'POST',
    credentials: 'same-origin',
    cache: 'no-store',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json',
    },
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (!data.ok && data.error && !pollTimer._done) {
      clearInterval(pollTimer);
      setTimeout(function() {
        hideProgressOverlay();
        alert('<?=__('Failed: ')?>' + data.error);
      }, 1500);
    }
  })
  .catch(function(err) {
    clearInterval(pollTimer);
    setTimeout(function() {
      hideProgressOverlay();
      alert('<?=__('Failed: ')?>' + err.message);
    }, 1500);
  });
}

// ─── Bulk selection ───
(function(){
  var selectAll = document.getElementById('selectAllPlugins');
  var checkboxes = document.querySelectorAll('.plugin-checkbox');
  var bulkBar = document.getElementById('bulkForm');
  var bulkCount = document.getElementById('bulkCount');
  var bulkSelect = document.getElementById('bulkActionSelect');
  var bulkApply = document.getElementById('bulkApplyBtn');

  function updateBulkBar() {
    var checked = document.querySelectorAll('.plugin-checkbox:checked');
    var count = checked.length;
    if (bulkCount) bulkCount.textContent = count;
    if (bulkBar) bulkBar.style.display = count > 0 ? 'flex' : 'none';
    if (bulkApply) bulkApply.disabled = count === 0 || !bulkSelect || bulkSelect.value === '';
    // Row highlight
    checkboxes.forEach(function(cb) {
      var row = cb.closest('tr');
      if (row) row.classList.toggle('row-selected', cb.checked);
    });
  }

  if (selectAll) {
    selectAll.addEventListener('change', function() {
      checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
      updateBulkBar();
    });
  }

  checkboxes.forEach(function(cb) {
    cb.addEventListener('change', function() {
      if (selectAll) {
        selectAll.checked = document.querySelectorAll('.plugin-checkbox:checked').length === checkboxes.length;
        selectAll.indeterminate = !selectAll.checked && document.querySelectorAll('.plugin-checkbox:checked').length > 0;
      }
      updateBulkBar();
    });
  });

  if (bulkSelect) {
    bulkSelect.addEventListener('change', updateBulkBar);
  }

  // Bulk form submit — collect checked plugins
  if (bulkBar) {
    bulkBar.addEventListener('submit', function(e) {
      var checked = document.querySelectorAll('.plugin-checkbox:checked');
      if (checked.length === 0) { e.preventDefault(); return; }
      var action = bulkSelect ? bulkSelect.value : '';
      if (!action) { e.preventDefault(); return; }

      // Clear old hidden inputs
      bulkBar.querySelectorAll('input[name="plugins[]"]').forEach(function(el) { el.remove(); });

      checked.forEach(function(cb) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'plugins[]';
        inp.value = cb.value;
        bulkBar.appendChild(inp);
      });

      if (action === 'uninstall') {
        if (!confirm('<?=__('Uninstall')?> ' + checked.length + ' <?=__('plugin(s)? Data will be kept for all plugins. To remove data, uninstall individually.')?>')) {
          e.preventDefault();
          return;
        }
      } else {
        var label = action === 'activate' ? '<?=__('Activate')?>' : '<?=__('Deactivate')?>';
        if (!confirm(label + ' ' + checked.length + ' <?=__('plugin(s)?')?>')) {
          e.preventDefault();
          return;
        }
      }

      showProgressOverlay();
      document.getElementById('progressStatus').textContent = '<?=__('Processing bulk action…')?>';
      updateProgressBar(0, '');
    });
  }
})();

// Flash sukses update
document.addEventListener('DOMContentLoaded', function() {
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('update_ok') === '1' && window.NewNotifToast) {
    NewNotifToast.success('<?=__('Plugin updated successfully!')?>');
    window.history.replaceState({}, '', '<?= $selfUrl ?>');
  }
});
</script>
