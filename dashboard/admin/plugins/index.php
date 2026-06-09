<?php
declare(strict_types=1);
// Plugin Manager — Kelola plugin (aktif/nonaktif/update)

require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

require_once __DIR__ . '/../../../app/controllers/PluginStoreController.php';

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/plugins/index';

// --- Handle toggle ---
$action = (string)($_POST['action'] ?? '');
$pluginName = (string)($_POST['plugin'] ?? '');
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
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to deactivate plugin.'));
    } else {
        if (plugin_enable($pluginName)) {
            adiwira_redirect_with_flash($selfUrl, 'success', __('Plugin') . ' "' . h($manifest['title'] ?? $pluginName) . '" ' . __('activated.'));
        }
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to activate plugin.'));
    }
}

// --- Handle delete ---
if ($action === 'delete' && $pluginName !== '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }

    $manifest = plugin_manifest($pluginName);
    if (!$manifest) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin') . ' "' . h($pluginName) . '" ' . __('not found.'));
    }

    if (plugin_delete($pluginName)) {
        adiwira_redirect_with_flash($selfUrl, 'success', __('Plugin') . ' "' . h($manifest['title'] ?? $pluginName) . '" ' . __('deleted successfully.'));
    }
    adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to delete plugin.'));
}

// --- Handle check-updates ---
if ($action === 'check-updates') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $updates = PluginStoreController::checkUpdates($pdo);
    $count = count($updates);
    if ($count > 0) {
        adiwira_redirect_with_flash($selfUrl, 'success', $count . ' ' . __('plugin(s) update available.'));
    } else {
        adiwira_redirect_with_flash($selfUrl, 'info', __('Semua plugin sudah versi terbaru.'));
    }
}

// --- Handle apply-update ---
if ($action === 'apply-update' && $pluginName !== '') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_check($csrf)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $updateResult = PluginStoreController::applyUpdate($pdo, $pluginName);
    if ($updateResult['success']) {
        adiwira_redirect_with_flash($selfUrl, 'success', __('Plugin') . ' "' . h($pluginName) . '" ' . __('updated to v') . h($updateResult['new_version']) . '.');
    } else {
        adiwira_redirect_with_flash($selfUrl, 'error', h($updateResult['error']));
    }
}

// --- Collect plugin data ---
$allPlugins = plugins_all();
$activePlugins = plugins_active();
$availableUpdates = PluginStoreController::getCachedUpdates();
$hasStoreUrl = false;
foreach ($allPlugins as $name => $p) {
    if (!empty($p['store'])) { $hasStoreUrl = true; break; }
}
$pageToasts = function_exists('adiwira_collect_query_toasts') ? adiwira_collect_query_toasts() : [];
?>
<h2 class="pg-title"><?=_e('Plugin')?></h2>
<p class="pg-subtitle"><?=_e('Manage installed plugins.')?></p>

<div style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
  <a href="<?= h($base) ?>/?page=admin/plugins/upload" class="btn btn-primary btn-sm">+ Upload Plugin</a>
  <a href="<?= h($base) ?>/?page=admin/plugins/browse" class="btn btn-sm btn-outline" style="border-color:var(--adam-primary);color:var(--adam-primary)"><?= svg_ico('store', '', ['style' => 'width:14px;height:14px;vertical-align:middle']) ?> <?=_e('Browse Plugins')?></a>
  <?php if ($hasStoreUrl): ?>
  <form method="post" style="display:inline">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="check-updates">
    <button type="submit" class="btn btn-sm btn-outline" style="border-color:var(--adam-primary);color:var(--adam-primary);display:inline-flex;align-items:center;gap:4px"><?= svg_ico('refresh-cw', '', ['style' => 'width:14px;height:14px']) ?> <?=_e('Check Update')?></button>
  </form>
  <?php endif; ?>
</div>

<?php if (empty($allPlugins)): ?>
<div class="empty-state">
  <p><?=_e('No plugins installed yet. Add a plugin folder with')?> <code>plugin.json</code> <?=_e('to the')?> <code>plugins/</code> <?=_e('folder.')?></p>
</div>
<?php else: ?>
<div class="table-wrap">
<table class="data-table" data-sortable>
  <thead>
    <tr>
      <th><?=_e('Plugin')?></th>
      <th><?=_e('Version')?></th>
      <th><?=_e('Status')?></th>
      <th><?=_e('Action')?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($allPlugins as $name => $p):
      $title = $p['title'] ?? $name;
      $desc = $p['description'] ?? '';
      $version = $p['version'] ?? '—';
      $author = $p['author'] ?? '';
      $authorUri = $p['author_uri'] ?? '';
      $pluginUri = $p['plugin_uri'] ?? '';
      $isActive = isset($activePlugins[$name]);
      $hasUpdate = isset($availableUpdates[$name]);
      $updateInfo = $hasUpdate ? $availableUpdates[$name] : null;
    ?>
    <tr>
      <td>
        <a href="<?= h($base) ?>/?page=admin/plugins/detail&name=<?= h($name) ?>" class="plugin-name-link"><?= h($title) ?></a>
        <?php if ($desc): ?><br><span class="text-muted" style="font-size:0.85rem"><?= h($desc) ?></span><?php endif; ?>
        <?php if ($author): ?><br><span class="text-muted" style="font-size:0.8rem">
          <?=_e('by')?> <?php if ($authorUri): ?><a href="<?= h($authorUri) ?>" target="_blank" rel="noopener"><?= h($author) ?></a><?php else: ?><?= h($author) ?><?php endif; ?>
          <?php if ($pluginUri): ?>&middot; <a href="<?= h($pluginUri) ?>" target="_blank" rel="noopener"><?=_e('Visit Web')?></a><?php endif; ?>
        </span><?php endif; ?>
      </td>
      <td>
        <?= h($version) ?>
        <?php if ($hasUpdate): ?><br><span class="badge badge-update">v<?= h($updateInfo['new_version']) ?> <?=_e('available')?></span><?php endif; ?>
      </td>
      <td>
        <?php if ($isActive): ?>
          <span class="badge badge-success"><?=_e('Active')?></span>
        <?php else: ?>
          <span class="badge badge-muted"><?=_e('Inactive')?></span>
        <?php endif; ?>
      </td>
      <td>
        <div style="display:flex;gap:.35rem;flex-wrap:wrap">
        <a href="<?= h($base) ?>/?page=admin/plugins/detail&name=<?= h($name) ?>" class="btn btn-sm btn-outline"><?=_e('Detail')?></a>
        <?php if ($hasUpdate): ?>
        <form method="post" style="display:inline" class="js-confirm-form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="apply-update">
          <input type="hidden" name="plugin" value="<?= h($name) ?>">
          <button type="submit" class="btn btn-sm btn-update js-confirm-btn"
            data-confirm-title="<?=_e('Update Plugin')?>"
            data-confirm-text="<?=__('Update plugin')?> &quot;<?= h($title) ?>&quot; <?=__('from v')?><?= h($version) ?> <?=__('to v')?><?= h($updateInfo['new_version']) ?>? <?=__('Backup will be created automatically.')?>"
            data-confirm-action="update"><?=_e('Update to v')?><?= h($updateInfo['new_version']) ?></button>
        </form>
        <?php elseif (!empty($p['store'])): ?>
        <span class="btn btn-sm btn-disabled" style="cursor:default;opacity:.5;display:inline-flex;align-items:center;gap:4px"><?= svg_ico('circle-check', '', ['style' => 'width:14px;height:14px']) ?> <?=_e('Latest')?></span>
        <?php endif; ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="plugin" value="<?= h($name) ?>">
          <?php if ($isActive): ?>
            <button type="submit" class="btn btn-sm btn-outline js-confirm-btn"
              data-confirm-title="<?=_e('Deactivate Plugin')?>"
              data-confirm-text="<?=__('Deactivate plugin')?> &quot;<?= h($title) ?>&quot;?"
              data-confirm-action="deactivate"><?=_e('Deactivate')?></button>
          <?php else: ?>
            <button type="submit" class="btn btn-sm btn-primary"><?=_e('Activate')?></button>
          <?php endif; ?>
        </form>
        <form method="post" style="display:inline" class="js-confirm-form">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="plugin" value="<?= h($name) ?>">
          <button type="submit" class="btn btn-sm btn-danger js-confirm-btn"
            data-confirm-title="<?=_e('Delete Plugin')?>"
            data-confirm-text="<?=__('Delete plugin')?> &quot;<?= h($title) ?>&quot; <?=__('and all its files? This action cannot be undone.')?>"
            data-confirm-action="delete"><?=_e('Delete')?></button>
        </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- Konfirmasi Modal -->
<div class="adam-modal" id="pluginConfirmModal" style="display:none">
  <div class="adam-modal__panel" style="max-width:420px">
    <div class="adam-modal__title" id="pluginConfirmTitle"><?=_e('Confirmation')?></div>
    <div class="adam-modal__text" id="pluginConfirmText" style="margin-bottom:1.25rem;line-height:1.5"></div>
    <div class="adam-modal__actions" style="display:flex;gap:.5rem;justify-content:flex-end">
      <button type="button" class="btn btn-outline" onclick="hidePluginConfirm()"><?=_e('Cancel')?></button>
      <button type="button" class="btn" id="pluginConfirmApply" onclick="applyPluginConfirm()"><?=_e('Yes')?></button>
    </div>
  </div>
</div>

<!-- Progress Overlay -->
<div id="pluginUpdateProgress" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);align-items:center;justify-content:center">
  <div style="background:var(--adam-surface);padding:2rem 2.5rem;border-radius:12px;text-align:center;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,.3);width:90%">
    <div id="progressSpinner" style="width:40px;height:40px;border:4px solid var(--adam-border-2);border-top-color:var(--adam-primary);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 1rem"></div>
    <div id="progressStatus" style="font-weight:600;font-size:1rem;color:var(--adam-text)"><?=__('Updating plugin…')?></div>
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
.table-wrap { overflow-x:auto; }
.data-table { width:100%; border-collapse:collapse; }
.data-table th,
.data-table td { text-align:left; padding:.6rem .75rem; border-bottom:1px solid var(--adam-border); vertical-align:middle; color:var(--adam-text); }
.data-table th { font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--adam-muted); background:var(--adam-surface-3); }
.badge { display:inline-block; padding:.15rem .5rem; font-size:.75rem; font-weight:600; border-radius:999px; }
.badge-success { background:#d1fae5; color:#065f46; }
.badge-muted { background:var(--adam-surface-3); color:var(--adam-muted); }
.badge-update { background:#fef3c7; color:#92400e; }
.btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .75rem; font-size:.8rem; font-weight:500; border-radius:6px; cursor:pointer; border:1px solid transparent; font-family:inherit; line-height:1; text-decoration:none; }
.btn-sm { padding:.3rem .6rem; font-size:.75rem; }
.btn-primary { background:var(--adam-primary); color:#fff; border-color:var(--adam-primary); }
.btn-primary:hover { background:var(--adam-primary-600); }
.btn-outline { background:transparent; color:var(--adam-muted); border-color:var(--adam-border-2); }
.btn-outline:hover { background:var(--adam-surface-3); color:var(--adam-text); }
.btn-danger { background:var(--adam-danger); color:#fff; border-color:var(--adam-danger); }
.btn-danger:hover { background:var(--adam-danger-600); }
.btn-update { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }
.btn-update:hover { background:#bfdbfe; }
.plugin-name-link { color:var(--adam-text); font-weight:600; text-decoration:none; }
.plugin-name-link:hover { color:var(--adam-primary); text-decoration:underline; }
</style>

<script>
var _confirmForm = null;
var _confirmAction = '';
var _csrfToken = '<?= h(csrf_token()) ?>';

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
    if (action === 'delete') {
      applyBtn.className = 'btn btn-danger';
      applyBtn.textContent = '<?=__('Yes, Delete')?>';
    } else if (action === 'update') {
      applyBtn.className = 'btn btn-update';
      applyBtn.textContent = '<?=__('Yes, Update')?>';
    } else {
      applyBtn.className = 'btn btn-primary';
      applyBtn.textContent = '<?=__('Yes')?>';
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
    _confirmForm.submit();
    hidePluginConfirm();
  }
}

function startPluginUpdate(pluginName) {
  var token = makeProgressToken();
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

// Flash sukses update
document.addEventListener('DOMContentLoaded', function() {
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('update_ok') === '1' && window.NewNotifToast) {
    NewNotifToast.success('<?=__('Plugin updated successfully!')?>');
    window.history.replaceState({}, '', '<?= $selfUrl ?>');
  }
});
</script>