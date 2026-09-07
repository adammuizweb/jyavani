<?php
declare(strict_types=1);

require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';
require_once DASH_PATH . '/admin/_notify.php';
require_once __DIR__ . '/../../../app/controllers/ThemeStoreClient.php';
require_once __DIR__ . '/_store_card.php';

[$uid] = adiwira_require_permission($pdo, 'core.themes.manage', false);
adiwira_require_site_owner($pdo, false);

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/themes/browse';
$listUrl = $base . '/?page=admin/themes/assign';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (($_POST['action'] ?? '') !== 'install') {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid action.'));
    }
    if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $themeName = (string)($_POST['theme'] ?? '');
    if (preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $themeName) !== 1) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid theme name.'));
    }
    $themeDir = rtrim(VIEWS_BASE, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $themeName;
    if (file_exists($themeDir) || is_link($themeDir)) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Theme already installed.') . ' "' . h($themeName) . '"');
    }

    $themeData = ThemeStoreClient::fetchOfficialTheme($themeName);
    if ($themeData === null) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Theme not found in store.') . ' "' . h($themeName) . '"');
    }
    $tmpZip = package_download((string)$themeData['download_url'], 'theme-install-', 'JyavaniCMS-ThemeInstall', null, false);
    if ($tmpZip === null) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to download theme from jyavani.com.'));
    }
    $actualChecksum = hash_file('sha256', $tmpZip);
    if (!is_string($actualChecksum) || !hash_equals((string)$themeData['checksum'], strtolower($actualChecksum))) {
        @unlink($tmpZip);
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid theme package checksum.'));
    }
    try {
        $result = install_theme_from_zip($pdo, $tmpZip, false, $uid, $themeName, $themeData);
    } finally {
        @unlink($tmpZip);
    }
    if (($result['success'] ?? false) !== true) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Installation failed:') . ' ' . (string)($result['message'] ?? __('Unknown error.')));
    }
    adiwira_redirect_with_flash($listUrl, 'success', __('Theme installed from store.') . ' "' . h($themeName) . '"');
}

$query = is_string($_GET['q'] ?? null) && preg_match('//u', $_GET['q']) === 1 ? trim($_GET['q']) : '';
$requestedCursor = is_string($_GET['cursor'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{0,2048}\z/', $_GET['cursor']) === 1 ? trim($_GET['cursor']) : '';
$installedThemes = theme_store_installed_map($pdo);
$csrfToken = csrf_token();
$catalog = ThemeStoreClient::fetchCatalogPage($query, $requestedCursor, 24);
$error = $catalog === null ? __('Failed to connect to jyavani.com. Try again later.') : '';
$themes = $catalog['themes'] ?? [];
$storeName = (string)($catalog['store_name'] ?? 'Jyavani Theme Store');
$hasMore = ($catalog['pagination']['has_more'] ?? false) === true;
$nextCursor = is_string($catalog['pagination']['next_cursor'] ?? null) ? $catalog['pagination']['next_cursor'] : '';
$nextUrl = $selfUrl . ($query !== '' ? '&q=' . rawurlencode($query) : '') . ($nextCursor !== '' ? '&cursor=' . rawurlencode($nextCursor) : '');
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<section class="theme-store-browser">
  <div class="theme-store-heading">
    <div>
      <h2 class="pg-title"><?= _e('Browse Themes') ?></h2>
      <p class="pg-subtitle"><?= _e('Explore themes from') ?> <a href="https://jyavani.com/themes/" target="_blank" rel="noopener"><?= h($storeName) ?></a> — <?= _e('Jyavani community.') ?></p>
    </div>
    <a href="<?= h($listUrl) ?>" class="btn btn-outline btn-sm"><?= _e('&larr; Back to Theme Manager') ?></a>
  </div>

  <form method="get" class="theme-store-search" id="themeStoreSearch">
    <input type="hidden" name="page" value="admin/themes/browse">
    <label class="sr-only" for="themeStoreQuery"><?= _e('Search themes') ?></label>
    <input type="search" id="themeStoreQuery" name="q" value="<?= h($query) ?>" maxlength="100" placeholder="<?= _e('Search themes…') ?>" autocomplete="off">
    <button type="submit" class="btn btn-primary"><?= svg_ico('search', '', ['style' => 'width:15px;height:15px']) ?> <?= _e('Search') ?></button>
    <?php if ($query !== ''): ?><a href="<?= h($selfUrl) ?>" class="btn btn-outline"><?= _e('Clear') ?></a><?php endif; ?>
  </form>

  <div id="themeStoreError" class="alert alert-error"<?= $error === '' ? ' hidden' : '' ?>><?= h($error) ?></div>
  <div id="themeStoreEmpty" class="empty-state"<?= $error !== '' || $themes !== [] ? ' hidden' : '' ?>><?= _e('No themes match your search.') ?></div>
  <div class="theme-grid" id="themeStoreGrid">
    <?php foreach ($themes as $theme) echo theme_store_card_html($theme, $installedThemes, $base, $csrfToken); ?>
  </div>
  <div class="theme-store-loader" id="themeStoreLoader" aria-live="polite">
    <a class="btn btn-outline" id="themeStoreMore" href="<?= h($nextUrl) ?>"<?= !$hasMore ? ' hidden' : '' ?>><?= _e('Next page') ?></a>
    <span id="themeStoreStatus"><?= $hasMore ? '' : ($themes !== [] ? _e('All themes loaded.') : '') ?></span>
    <span id="themeStoreSentinel" aria-hidden="true"></span>
  </div>
</section>

<div id="themeInstallProgress" class="theme-install-progress" hidden role="status" aria-live="assertive" aria-labelledby="themeInstallProgressText">
  <div class="theme-install-progress__panel"><span class="theme-install-spinner" aria-hidden="true"></span><strong id="themeInstallProgressText"><?= _e('Installing theme…') ?></strong></div>
</div>

<style>
.theme-store-browser{max-width:1200px}.theme-store-browser .btn{text-decoration:none}.theme-store-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap}.pg-title{font-size:1.4rem;font-weight:700;margin:0 0 .25rem;color:var(--adam-text)}.pg-subtitle{color:var(--adam-muted);font-size:.9rem;margin:0 0 1.25rem}.pg-subtitle a{color:var(--adam-primary);text-decoration:none}.theme-store-search{display:flex;gap:.5rem;align-items:center;margin-bottom:1.25rem}.theme-store-search input{flex:1;min-width:180px;padding:.62rem .8rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);font:inherit}.alert{padding:.75rem 1rem;border-radius:6px;font-size:.875rem;margin-bottom:1rem}.alert-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.empty-state{padding:2rem;text-align:center;color:var(--adam-muted)}.theme-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem}.theme-card{background:var(--adam-card);border:1px solid var(--adam-border);border-radius:10px;display:flex;flex-direction:column;overflow:hidden;transition:box-shadow .15s}.theme-card:hover{box-shadow:0 2px 12px rgba(0,0,0,.08)}.theme-card-shot{width:100%;aspect-ratio:16/10;overflow:hidden;background:var(--adam-surface-3)}.theme-card-shot img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s}.theme-card:hover .theme-card-shot img{transform:scale(1.03)}.theme-card-placeholder{display:flex;align-items:center;justify-content:center;height:100%;font-size:2rem;font-weight:700;color:var(--adam-muted)}.theme-card-body{flex:1;padding:1rem}.theme-card-title{font-size:1rem;font-weight:600;color:var(--adam-text);margin-bottom:.35rem}.theme-card-desc{font-size:.82rem;color:var(--adam-muted);line-height:1.5;margin-bottom:.5rem}.theme-card-meta{display:flex;gap:.5rem;flex-wrap:wrap;font-size:.75rem;color:var(--adam-muted-2)}.badge-php{background:var(--adam-surface-3);padding:.1rem .4rem;border-radius:4px;font-size:.72rem}.theme-card-actions{display:flex;align-items:stretch;gap:.35rem;padding:.6rem 1rem;border-top:1px solid var(--adam-border);background:var(--adam-surface-4);flex-wrap:wrap}.theme-card-actions form{display:flex;flex:1 1 0}.theme-card-actions .btn{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;flex:1 1 0;min-height:32px;margin:0;line-height:1.2;text-align:center}.btn-disabled{cursor:default;opacity:.55}.btn-update{background:#dbeafe;color:#1e40af;border-color:#93c5fd}.theme-store-loader{display:flex;min-height:80px;align-items:center;justify-content:center;gap:.75rem;color:var(--adam-muted);font-size:.85rem;position:relative}.theme-store-loader.is-loading::before{content:'';width:18px;height:18px;border:2px solid var(--adam-border-2);border-top-color:var(--adam-primary);border-radius:50%;animation:theme-store-spin .7s linear infinite}.theme-install-progress{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);align-items:center;justify-content:center}.theme-install-progress:not([hidden]){display:flex}.theme-install-progress__panel{background:var(--adam-card);padding:2rem;border-radius:12px;display:flex;align-items:center;gap:1rem}.theme-install-spinner{width:26px;height:26px;border:3px solid var(--adam-border-2);border-top-color:var(--adam-primary);border-radius:50%;animation:theme-store-spin .7s linear infinite}@keyframes theme-store-spin{to{transform:rotate(360deg)}}@media(max-width:640px){.theme-store-search{align-items:stretch;flex-direction:column}.theme-store-search>*{width:100%;box-sizing:border-box;justify-content:center}.theme-grid{grid-template-columns:1fr}}@media(prefers-reduced-motion:reduce){.theme-card,.theme-card-shot img{transition:none}.theme-store-loader.is-loading::before,.theme-install-spinner{animation-duration:1.5s}}
</style>

<script>
(function(){
  'use strict';
  var grid=document.getElementById('themeStoreGrid'),form=document.getElementById('themeStoreSearch'),input=document.getElementById('themeStoreQuery'),more=document.getElementById('themeStoreMore'),loader=document.getElementById('themeStoreLoader'),status=document.getElementById('themeStoreStatus'),empty=document.getElementById('themeStoreEmpty'),error=document.getElementById('themeStoreError'),sentinel=document.getElementById('themeStoreSentinel');
  var endpoint=<?= json_encode($base . '/admin/themes/catalog.php', $jsonFlags) ?>,selfUrl=<?= json_encode($selfUrl, $jsonFlags) ?>,query=<?= json_encode($query, $jsonFlags) ?>,cursor=<?= json_encode($nextCursor, $jsonFlags) ?>,hasMore=<?= $hasMore ? 'true' : 'false' ?>;
  var loading=false,requestSequence=0,controller=null,debounce=null,visitedCursors=new Set(),seen=new Set(Array.prototype.map.call(grid.querySelectorAll('[data-theme]'),function(card){return card.dataset.theme;}));
  more.textContent=<?= json_encode(__('Load more'), $jsonFlags) ?>;
  function setUrl(){if(!history.replaceState)return;var url=new URL(selfUrl,window.location.origin);if(query)url.searchParams.set('q',query);history.replaceState(null,'',url.pathname+url.search);}
  function updateState(message){loader.classList.toggle('is-loading',loading);loader.classList.remove('is-error');more.hidden=loading||!hasMore;if(message!==undefined)status.textContent=message;if(!loading&&!hasMore&&seen.size>0)status.textContent=<?= json_encode(__('All themes loaded.'), $jsonFlags) ?>;empty.hidden=seen.size>0||loading||error.textContent!=='';}
  function appendCards(html){var template=document.createElement('template'),appended=0;template.innerHTML=html;template.content.querySelectorAll('[data-theme]').forEach(function(card){var name=card.dataset.theme||'';if(!name||seen.has(name)){card.remove();return;}seen.add(name);appended++;});grid.appendChild(template.content);return appended;}
  function load(reset){if(loading&&!reset)return;if(!reset&&!hasMore)return;if(controller)controller.abort();controller=new AbortController();var sequence=++requestSequence;if(reset){query=input.value.trim();cursor='';hasMore=true;visitedCursors.clear();seen.clear();grid.replaceChildren();error.textContent='';error.hidden=true;setUrl();}else if(cursor&&visitedCursors.has(cursor)){hasMore=false;updateState('');return;}var requestedCursor=cursor;loading=true;updateState(<?= json_encode(__('Loading themes…'), $jsonFlags) ?>);var url=endpoint+'?limit=24&q='+encodeURIComponent(query)+(cursor?'&cursor='+encodeURIComponent(cursor):'');fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal}).then(function(response){return response.json().then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||<?= json_encode(__('Failed to load theme list.'), $jsonFlags) ?>);return data;});}).then(function(data){if(sequence!==requestSequence)return;var appended=appendCards(data.html||'');if(requestedCursor)visitedCursors.add(requestedCursor);var nextCursor=typeof data.next_cursor==='string'?data.next_cursor:'';hasMore=data.has_more===true&&nextCursor!==''&&!visitedCursors.has(nextCursor);cursor=nextCursor;loading=false;more.textContent=<?= json_encode(__('Load more'), $jsonFlags) ?>;more.href=selfUrl+(query?'&q='+encodeURIComponent(query):'')+(cursor?'&cursor='+encodeURIComponent(cursor):'');updateState('');window.requestAnimationFrame(function(){if(hasMore&&appended>0&&sentinel.getBoundingClientRect().top<=window.innerHeight+320)load(false);});}).catch(function(reason){if(reason.name==='AbortError'||sequence!==requestSequence)return;loading=false;loader.classList.add('is-error');more.hidden=false;more.textContent=<?= json_encode(__('Try Again'), $jsonFlags) ?>;status.textContent=reason.message||<?= json_encode(__('Failed to load theme list.'), $jsonFlags) ?>;empty.hidden=true;});}
  form.addEventListener('submit',function(event){event.preventDefault();window.clearTimeout(debounce);load(true);});input.addEventListener('input',function(){window.clearTimeout(debounce);debounce=window.setTimeout(function(){load(true);},350);});more.addEventListener('click',function(event){event.preventDefault();load(false);});if('IntersectionObserver'in window)new IntersectionObserver(function(entries){if(entries[0]&&entries[0].isIntersecting)load(false);},{rootMargin:'320px'}).observe(sentinel);
  grid.addEventListener('click',function(event){var button=event.target.closest('[data-install-theme]');if(!button)return;event.preventDefault();var installForm=button.closest('form'),title=button.dataset.themeTitle||'',options={title:<?= json_encode(__('Install theme'), $jsonFlags) ?>,message:<?= json_encode(__('Install theme'), $jsonFlags) ?>+' "'+title+'"?',confirmText:<?= json_encode(__('Yes, Install'), $jsonFlags) ?>,cancelText:<?= json_encode(__('Cancel'), $jsonFlags) ?>};var decision=window.NewNotifConfirm&&typeof window.NewNotifConfirm.warning==='function'?window.NewNotifConfirm.warning(options):Promise.resolve(window.confirm(options.message));decision.then(function(confirmed){if(!confirmed)return;document.getElementById('themeInstallProgress').hidden=false;installForm.submit();});});updateState();
})();
</script>
