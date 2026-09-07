<?php
declare(strict_types=1);

require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';
require_once __DIR__ . '/../../../app/controllers/PluginStoreController.php';
require_once __DIR__ . '/_store_card.php';

[$uid] = adiwira_require_permission($pdo, 'core.plugins.manage', false);
adiwira_require_site_owner($pdo, false);

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/plugins/browse';
$listUrl = $base . '/?page=admin/plugins/index';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $installAction = (string)($_POST['action'] ?? '');
    if (!in_array($installAction, ['install', 'install_activate'], true)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid action.'));
    }
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $pluginName = (string)($_POST['plugin'] ?? '');
    if (preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $pluginName) !== 1) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid plugin name.'));
    }
    $activatePlugin = $installAction === 'install_activate';
    if (file_exists(PLUGIN_PATH . '/' . $pluginName) || is_link(PLUGIN_PATH . '/' . $pluginName)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin already installed.') . ' "' . h($pluginName) . '"');
    }

    $installLocks = plugin_lifecycle_locks($pluginName);
    if ($installLocks === null) {
        adiwira_redirect_with_flash($selfUrl, 'error', plugin_last_error() ?: __('Unable to lock plugin lifecycle operation.'));
    }
    register_shutdown_function(static function () use (&$installLocks): void {
        if (is_array($installLocks) && $installLocks !== []) theme_operation_release($installLocks);
    });
    if (file_exists(PLUGIN_PATH . '/' . $pluginName) || is_link(PLUGIN_PATH . '/' . $pluginName)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin already installed.') . ' "' . h($pluginName) . '"');
    }

    $pluginData = PluginStoreController::fetchOfficialPlugin($pluginName);
    if ($pluginData === null) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin not found in store.') . ' "' . h($pluginName) . '"');
    }
    $requirementError = plugin_install_requirements_error_message($pluginData, $activatePlugin);
    if ($requirementError !== '') adiwira_redirect_with_flash($selfUrl, 'error', $requirementError);

    $tmpZip = package_download((string)$pluginData['download_url'], 'plugin-install-', 'JyavaniCMS-PluginInstall', null, false);
    if ($tmpZip === null) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to download plugin from jyavani.com.'));
    }
    $checksum = strtolower((string)$pluginData['checksum']);
    $actualChecksum = hash_file('sha256', $tmpZip);
    if (!is_string($actualChecksum) || !hash_equals($checksum, strtolower($actualChecksum))) {
        @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Plugin package integrity verification failed.'));
    }
    $prepared = plugin_prepare_package_stage($tmpZip, $pluginName, $activatePlugin, $pluginData);
    @unlink($tmpZip);
    if (!($prepared['success'] ?? false)) adiwira_redirect_with_flash($selfUrl, 'error', (string)$prepared['error']);
    $result = plugin_publish_staged_install_already_locked($prepared, $activatePlugin, $pdo);
    if (!($result['success'] ?? false)) adiwira_redirect_with_flash($selfUrl, 'error', (string)$result['error']);

    $message = $activatePlugin ? __('Plugin installed and activated from store.') : __('Plugin installed from store.');
    theme_operation_release($installLocks);
    $installLocks = [];
    adiwira_redirect_with_flash($listUrl, 'success', $message . ' "' . h($prepared['manifest']['title'] ?? $pluginName) . '"');
}

$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$requestedCursor = is_string($_GET['cursor'] ?? null) ? trim($_GET['cursor']) : '';
$catalog = PluginStoreController::fetchCatalogPage($query, $requestedCursor, 24);
$error = $catalog === null ? __('Failed to connect to jyavani.com. Try again later.') : '';
$plugins = $catalog['plugins'] ?? [];
$storeName = (string)($catalog['store_name'] ?? 'Jyavani Plugin Store');
$hasMore = ($catalog['pagination']['has_more'] ?? false) === true;
$nextCursor = is_string($catalog['pagination']['next_cursor'] ?? null) ? $catalog['pagination']['next_cursor'] : '';
$installedPlugins = plugins_all();
$csrfToken = csrf_token();
$nextUrl = $selfUrl . ($query !== '' ? '&q=' . rawurlencode($query) : '') . ($nextCursor !== '' ? '&cursor=' . rawurlencode($nextCursor) : '');
?>
<section class="plugin-store-browser">
  <div class="plugin-store-heading">
    <div>
      <h2 class="pg-title"><?= _e('Browse Plugins') ?></h2>
      <p class="pg-subtitle"><?= _e('Browse plugins from') ?> <a href="https://jyavani.com/plugins/" target="_blank" rel="noopener"><?= h($storeName) ?></a> — <?= _e('Jyavani community.') ?></p>
    </div>
    <a href="<?= h($listUrl) ?>" class="btn btn-outline btn-sm"><?= _e('&larr; Back to Installed Plugins') ?></a>
  </div>

  <form method="get" class="plugin-store-search" id="pluginStoreSearch">
    <input type="hidden" name="page" value="admin/plugins/browse">
    <label class="sr-only" for="pluginStoreQuery"><?= _e('Search plugins') ?></label>
    <input type="search" id="pluginStoreQuery" name="q" value="<?= h($query) ?>" maxlength="100" placeholder="<?= _e('Search plugins…') ?>" autocomplete="off">
    <button type="submit" class="btn btn-primary"><?= svg_ico('search', '', ['style' => 'width:15px;height:15px']) ?> <?= _e('Search') ?></button>
    <?php if ($query !== ''): ?><a href="<?= h($selfUrl) ?>" class="btn btn-outline"><?= _e('Clear') ?></a><?php endif; ?>
  </form>

  <div id="pluginStoreError" class="alert alert-error"<?= $error === '' ? ' hidden' : '' ?>><?= h($error) ?></div>
  <div id="pluginStoreEmpty" class="empty-state"<?= $error !== '' || $plugins !== [] ? ' hidden' : '' ?>><?= _e('No plugins match your search.') ?></div>
  <div class="plugin-grid" id="pluginStoreGrid">
    <?php foreach ($plugins as $plugin) echo plugin_store_card_html($plugin, $installedPlugins, $base, $csrfToken); ?>
  </div>
  <div class="plugin-store-loader" id="pluginStoreLoader" aria-live="polite">
    <a class="btn btn-outline" id="pluginStoreMore" href="<?= h($nextUrl) ?>"<?= !$hasMore ? ' hidden' : '' ?>><?= _e('Next page') ?></a>
    <span id="pluginStoreStatus"><?= $hasMore ? '' : ($plugins !== [] ? _e('All plugins loaded.') : '') ?></span>
    <span id="pluginStoreSentinel" aria-hidden="true"></span>
  </div>
</section>

<div id="pluginInstallProgress" class="plugin-install-progress" hidden role="dialog" aria-modal="true" aria-labelledby="pluginInstallProgressText">
  <div class="plugin-install-progress__panel">
    <span class="plugin-install-spinner" aria-hidden="true"></span>
    <strong id="pluginInstallProgressText"><?= _e('Installing plugin…') ?></strong>
  </div>
</div>

<style>
.plugin-store-browser{max-width:1200px}.plugin-store-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap}.pg-title{font-size:1.4rem;font-weight:700;margin:0 0 .25rem;color:var(--adam-text)}.pg-subtitle{color:var(--adam-muted);font-size:.9rem;margin:0 0 1.25rem}.pg-subtitle a{color:var(--adam-primary);text-decoration:none}.plugin-store-search{display:flex;gap:.5rem;align-items:center;margin-bottom:1.25rem}.plugin-store-search input{flex:1;min-width:180px;padding:.62rem .8rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);font:inherit}.alert{padding:.75rem 1rem;border-radius:6px;font-size:.875rem;margin-bottom:1rem}.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.empty-state{padding:2rem;text-align:center;color:var(--adam-muted)}.plugin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem}.plugin-card{background:var(--adam-card);border:1px solid var(--adam-border);border-radius:10px;display:flex;flex-direction:column;overflow:hidden;transition:box-shadow .15s}.plugin-card:hover{box-shadow:0 2px 12px rgba(0,0,0,.08)}.plugin-card-head{display:flex;gap:.75rem;align-items:center;padding:1rem 1rem 0}.plugin-icon,.plugin-icon-placeholder{width:48px;height:48px;border-radius:8px;flex-shrink:0}.plugin-icon{object-fit:contain;background:var(--adam-surface-4)}.plugin-icon-placeholder{display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:700;color:#fff}.plugin-card-body{flex:1;padding:.5rem 1rem .75rem}.plugin-card-title{font-size:1rem;font-weight:600;color:var(--adam-text);margin-bottom:.35rem}.plugin-card-desc{font-size:.82rem;color:var(--adam-muted);line-height:1.5}.plugin-card-meta{display:flex;gap:.5rem;flex-wrap:wrap;font-size:.75rem;color:var(--adam-muted-2)}.badge-php{background:var(--adam-surface-3);padding:.1rem .4rem;border-radius:4px;font-size:.72rem}.plugin-card-actions{display:flex;gap:.35rem;padding:.6rem 1rem;border-top:1px solid var(--adam-border);background:var(--adam-surface-4);flex-wrap:wrap}.plugin-install-form{display:inline-flex;gap:3px;flex-wrap:wrap}.btn-disabled{cursor:default;opacity:.55}.btn-update{background:#dbeafe;color:#1e40af;border-color:#93c5fd}.plugin-store-loader{display:flex;min-height:80px;align-items:center;justify-content:center;gap:.75rem;color:var(--adam-muted);font-size:.85rem;position:relative}.plugin-store-loader.is-loading::before{content:'';width:18px;height:18px;border:2px solid var(--adam-border-2);border-top-color:var(--adam-primary);border-radius:50%;animation:plugin-store-spin .7s linear infinite}.plugin-store-loader.is-loading #pluginStoreMore{display:none}.plugin-store-loader.is-error #pluginStoreMore{display:inline-flex}.plugin-install-progress{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);align-items:center;justify-content:center}.plugin-install-progress:not([hidden]){display:flex}.plugin-install-progress__panel{background:var(--adam-card);padding:2rem;border-radius:12px;display:flex;align-items:center;gap:1rem}.plugin-install-spinner{width:26px;height:26px;border:3px solid var(--adam-border-2);border-top-color:var(--adam-primary);border-radius:50%;animation:plugin-store-spin .7s linear infinite}@keyframes plugin-store-spin{to{transform:rotate(360deg)}}@media(max-width:640px){.plugin-store-search{align-items:stretch;flex-direction:column}.plugin-store-search>*{width:100%;box-sizing:border-box;justify-content:center}.plugin-grid{grid-template-columns:1fr}.plugin-card-actions .btn{flex:1 1 auto;justify-content:center}}@media(prefers-reduced-motion:reduce){.plugin-card{transition:none}.plugin-store-loader.is-loading::before,.plugin-install-spinner{animation-duration:1.5s}}
.plugin-store-browser .btn{text-decoration:none}.plugin-card-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch}.plugin-card-actions .plugin-install-form{display:contents}.plugin-card-actions .btn{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;min-width:0;min-height:32px;margin:0;line-height:1.2;text-align:center;white-space:normal}
</style>

<script>
(function(){
  'use strict';
  var grid = document.getElementById('pluginStoreGrid');
  var form = document.getElementById('pluginStoreSearch');
  var input = document.getElementById('pluginStoreQuery');
  var more = document.getElementById('pluginStoreMore');
  var loader = document.getElementById('pluginStoreLoader');
  var status = document.getElementById('pluginStoreStatus');
  var empty = document.getElementById('pluginStoreEmpty');
  var error = document.getElementById('pluginStoreError');
  var sentinel = document.getElementById('pluginStoreSentinel');
  var endpoint = <?= json_encode($base . '/admin/plugins/catalog.php') ?>;
  var selfUrl = <?= json_encode($selfUrl) ?>;
  var query = <?= json_encode($query) ?>;
  var cursor = <?= json_encode($nextCursor) ?>;
  var hasMore = <?= $hasMore ? 'true' : 'false' ?>;
  var loading = false;
  var requestSequence = 0;
  var controller = null;
  var debounce = null;
  var visitedCursors = new Set();
  var seen = new Set(Array.prototype.map.call(grid.querySelectorAll('[data-plugin]'), function(card){ return card.dataset.plugin; }));
  more.textContent = <?= json_encode(__('Load more')) ?>;

  function setUrl(){
    if (!history.replaceState) return;
    var url = new URL(selfUrl, window.location.origin);
    if (query) url.searchParams.set('q', query);
    history.replaceState(null, '', url.pathname + url.search);
  }
  function updateState(message){
    loader.classList.toggle('is-loading', loading);
    loader.classList.remove('is-error');
    more.hidden = loading || !hasMore;
    if (message !== undefined) status.textContent = message;
    if (!loading && !hasMore && seen.size > 0) status.textContent = <?= json_encode(__('All plugins loaded.')) ?>;
    empty.hidden = seen.size > 0 || loading || error.textContent !== '';
  }
  function appendCards(html){
    var template = document.createElement('template');
    template.innerHTML = html;
    var appended = 0;
    template.content.querySelectorAll('[data-plugin]').forEach(function(card){
      var name = card.dataset.plugin || '';
      if (!name || seen.has(name)) { card.remove(); return; }
      seen.add(name);
      appended++;
    });
    grid.appendChild(template.content);
    return appended;
  }
  function load(reset){
    if (loading && !reset) return;
    if (!reset && !hasMore) return;
    if (controller) controller.abort();
    controller = new AbortController();
    var sequence = ++requestSequence;
    if (reset) {
      query = input.value.trim(); cursor = ''; hasMore = true; visitedCursors.clear(); seen.clear(); grid.replaceChildren(); error.textContent = ''; error.hidden = true; setUrl();
    } else if (cursor) {
      if (visitedCursors.has(cursor)) { hasMore = false; updateState(''); return; }
    }
    var requestedCursor = cursor;
    loading = true;
    updateState(<?= json_encode(__('Loading plugins…')) ?>);
    var url = endpoint + '?limit=24&q=' + encodeURIComponent(query) + (cursor ? '&cursor=' + encodeURIComponent(cursor) : '');
    fetch(url, {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal})
      .then(function(response){ return response.json().then(function(data){ if (!response.ok || !data.ok) throw new Error(data.error || <?= json_encode(__('Failed to load plugin list.')) ?>); return data; }); })
      .then(function(data){
        if (sequence !== requestSequence) return;
        var appended = appendCards(data.html || '');
        if (requestedCursor) visitedCursors.add(requestedCursor);
        var nextCursor = typeof data.next_cursor === 'string' ? data.next_cursor : '';
        hasMore = data.has_more === true && nextCursor !== '' && !visitedCursors.has(nextCursor);
        cursor = nextCursor;
        loading = false;
        more.textContent = <?= json_encode(__('Load more')) ?>;
        more.href = selfUrl + (query ? '&q=' + encodeURIComponent(query) : '') + (cursor ? '&cursor=' + encodeURIComponent(cursor) : '');
        updateState('');
        window.requestAnimationFrame(function(){
          if (hasMore && appended > 0 && sentinel.getBoundingClientRect().top <= window.innerHeight + 320) load(false);
        });
      })
      .catch(function(reason){
        if (reason.name === 'AbortError' || sequence !== requestSequence) return;
        loading = false; loader.classList.add('is-error'); more.hidden = false; more.textContent = <?= json_encode(__('Try Again')) ?>;
        status.textContent = reason.message || <?= json_encode(__('Failed to load plugin list.')) ?>;
        empty.hidden = true;
      });
  }
  form.addEventListener('submit', function(event){ event.preventDefault(); window.clearTimeout(debounce); load(true); });
  input.addEventListener('input', function(){ window.clearTimeout(debounce); debounce = window.setTimeout(function(){ load(true); }, 350); });
  more.addEventListener('click', function(event){ event.preventDefault(); load(false); });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function(entries){ if (entries[0] && entries[0].isIntersecting) load(false); }, {rootMargin:'320px'}).observe(sentinel);
  }
  grid.addEventListener('click', function(event){
    var button = event.target.closest('[data-install-action]');
    if (!button) return;
    event.preventDefault();
    var installForm = button.closest('form');
    var action = button.dataset.installAction;
    var title = button.dataset.pluginTitle || '';
    var options = {
      title: action === 'install_activate' ? <?= json_encode(__('Install & Activate Plugin')) ?> : <?= json_encode(__('Install Plugin')) ?>,
      message: (action === 'install_activate' ? <?= json_encode(__('Install and activate plugin')) ?> : <?= json_encode(__('Install plugin')) ?>) + ' "' + title + '"?',
      confirmText: <?= json_encode(__('Yes, Install')) ?>,
      cancelText: <?= json_encode(__('Cancel')) ?>
    };
    var decision = window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function'
      ? window.NewNotifConfirm.warning(options) : Promise.resolve(window.confirm(options.message));
    decision.then(function(confirmed){
      if (!confirmed) return;
      installForm.querySelector('input[name="action"]').value = action;
      document.getElementById('pluginInstallProgress').hidden = false;
      installForm.submit();
    });
  });
  updateState();
})();
</script>
