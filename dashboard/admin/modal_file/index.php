<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/?
require_once __DIR__ . '/../_guard.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    adiwira_cosmetic_404_on_direct_open();
}

[$uid, $role] = adiwira_require_editorial($pdo, false);

$embedded = isset($_GET['embedded']) && (($_GET['embedded'] === '1') || ($_GET['embedded'] === 'true'));
$initialTab = strtolower(trim((string)($_GET['tab'] ?? 'upload')));
if (!in_array($initialTab, ['upload', 'library'], true)) {
    $initialTab = 'library';
}

$csrfToken = '';
try {
    if (function_exists('csrf_token')) {
        $csrfToken = (string) csrf_token();
    }
} catch (Throwable $e) {
    $csrfToken = '';
}

if (!$embedded):
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>File Modal</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body>
<?php endif; ?>

<div
  id="mdlib-root"
  data-mdlib-mounted="1"
  data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
  data-initial-tab="<?= htmlspecialchars($initialTab, ENT_QUOTES, 'UTF-8') ?>"
>
  <input type="hidden" id="mdlib-csrf" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

  <div id="mdlib-topbar">
    <div class="mdlib-toprow">
      <div id="mdlib-tabs">
        <div data-mdlib-tab="upload" role="button" tabindex="0" class="mdlib-tab">Upload</div>
        <div data-mdlib-tab="library" role="button" tabindex="0" class="mdlib-tab">Library</div>
      </div>
      <div id="mdlib-hint">Klik <b>Insert</b> untuk memilih file</div>
      <button id="mdlib-close-btn" class="mdlib-btn mdlib-close-btn" title="Tutup">&times;</button>
    </div>
  </div>

  <div id="mdlib-panel-upload" class="mdlib-panel" style="display:none;">
    <?php include __DIR__ . '/add_modal.php'; ?>
  </div>

  <div id="mdlib-panel-library" class="mdlib-panel" style="display:none;">
    <div id="mdlib-library-host" class="mdlib-loading">Memuat library…</div>
  </div>
</div>

<script>
(function(){
  if (!window.mdlibUi) {
    window.mdlibUi = (function(){
      function getToastApi(){
        try {
          if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') return window.NewNotifToast;
          if (window.parent && window.parent !== window && window.parent.NewNotifToast && typeof window.parent.NewNotifToast.show === 'function') {
            return window.parent.NewNotifToast;
          }
        } catch(e){}
        return null;
      }

      function getConfirmApi(){
        try {
          if (window.NewNotifConfirm) return window.NewNotifConfirm;
          if (window.parent && window.parent !== window && window.parent.NewNotifConfirm) {
            return window.parent.NewNotifConfirm;
          }
        } catch(e){}
        return null;
      }

      function toast(type, title, message, duration){
        const api = getToastApi();
        if (api) {
          api.show({
            type: type || 'info',
            title: title || null,
            message: message || '',
            duration: duration
          });
          return;
        }
        alert(message || title || 'Terjadi sesuatu.');
      }

      function ask(variant, opts){
        const api = getConfirmApi();
        if (api) {
          if (variant === 'danger' && typeof api.danger === 'function') {
            return api.danger(opts || {});
          }
          if (typeof api.warning === 'function') {
            return api.warning(opts || {});
          }
        }
        return Promise.resolve(window.confirm((opts && opts.message) ? opts.message : 'Lanjutkan aksi ini?'));
      }

      function readJsonSafe(txt){
        try { return txt ? JSON.parse(txt) : null; }
        catch(e){ return null; }
      }

      function getCsrfToken(){
        try {
          const local = document.getElementById('mdlib-csrf');
          if (local && local.value) return local.value;
        } catch(e){}

        try {
          const local2 = document.querySelector('input[name="csrf_token"]');
          if (local2 && local2.value) return local2.value;
        } catch(e){}

        try {
          const parentEl = window.parent && window.parent.document
            ? window.parent.document.querySelector('input[name="csrf_token"], #csrf_token')
            : null;
          if (parentEl) return parentEl.value || parentEl.textContent || '';
        } catch(e){}

        return '';
      }

      return {
        toast: toast,
        ask: ask,
        readJsonSafe: readJsonSafe,
        getCsrfToken: getCsrfToken
      };
    })();
  }

  const root = document.getElementById('mdlib-root');
  if (!root || root.dataset.mdlibReady === '1') return;
  root.dataset.mdlibReady = '1';

  let lastListUrl = '<?= ADMIN_BASE_PATH ?>/admin/modal_file/list_modal.php';

  function uiToast(type, title, message, duration){
    window.mdlibUi.toast(type, title, message, duration);
  }

  function getHost() {
    return document.getElementById('mdlib-library-host');
  }

  function getInitialTab() {
    const t = (root.getAttribute('data-initial-tab') || 'upload').toLowerCase();
    return (t === 'library') ? 'library' : 'upload';
  }

  function injectHtmlWithScripts(container, html) {
    try {
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');

      let bodyHtml = '';
      Array.from(doc.body.childNodes).forEach(function(n){
        if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
        bodyHtml += n.outerHTML || n.textContent || '';
      });
      container.innerHTML = bodyHtml;

      const scripts = doc.querySelectorAll('script');
      const externals = [];

      scripts.forEach(function(s){
        if (s.src) externals.push(s.src);
      });

      externals.forEach(function(src){
        if (!document.querySelector('script[src="' + src + '"]')) {
          const el = document.createElement('script');
          el.src = src;
          el.async = false;
          document.head.appendChild(el);
        }
      });

      scripts.forEach(function(s){
        if (!s.src) {
          const el = document.createElement('script');
          el.text = s.textContent || '';
          document.body.appendChild(el);
        }
      });
    } catch (err) {
      console.error('mdlib inject error', err);
      container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
      uiToast('error', 'Library File', 'Gagal memuat konten modal.', 5000);
    }
  }

  window.mdlibInjectHtmlWithScripts = injectHtmlWithScripts;

  function setActive(tab) {
    const btnUpload = root.querySelector('[data-mdlib-tab="upload"]');
    const btnLibrary = root.querySelector('[data-mdlib-tab="library"]');
    const panelUpload = document.getElementById('mdlib-panel-upload');
    const panelLibrary = document.getElementById('mdlib-panel-library');

    if (btnUpload) btnUpload.classList.toggle('mdlib-tab-active', tab === 'upload');
    if (btnLibrary) btnLibrary.classList.toggle('mdlib-tab-active', tab === 'library');

    if (panelUpload) panelUpload.style.display = (tab === 'upload') ? 'block' : 'none';
    if (panelLibrary) panelLibrary.style.display = (tab === 'library') ? 'block' : 'none';

    if (tab === 'library') {
      window.mdlibLoadList(false);
    }
  }

  async function fetchIntoLibrary(url, loadingText) {
    const host = getHost();
    if (!host) return;

    host.innerHTML = '<div class="mdlib-loading">' + (loadingText || 'Memuat…') + '</div>';

    let finalUrl = String(url || '');
    if (!finalUrl) return;

    if (finalUrl.indexOf('_ts=') === -1) {
      finalUrl += (finalUrl.indexOf('?') >= 0 ? '&' : '?') + '_ts=' + Date.now();
    }

    const res = await fetch(finalUrl, {
      credentials: 'include',
      cache: 'no-store'
    });

    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }

    const html = await res.text();
    injectHtmlWithScripts(host, html);
  }

  async function loadList(force, url) {
    const host = getHost();
    if (!host) return;

    if (url) lastListUrl = url;

    const loaded = host.getAttribute('data-loaded') === '1';
    if (!force && loaded && host.getAttribute('data-view') === 'list' && !url) return;

    host.setAttribute('data-view', 'list');

    try {
      await fetchIntoLibrary(lastListUrl, 'Memuat daftar file…');
      host.setAttribute('data-loaded', '1');
    } catch (err) {
      console.error('mdlib loadList error', err);
      host.innerHTML = '<div class="mdlib-loading" style="color:#dc2626">Gagal memuat library file.</div>';
      uiToast('error', 'Library File', 'Gagal memuat daftar file: ' + (err.message || err), 6000);
    }
  }

  async function openSingle(id) {
    if (!id) return;

    setActive('library');

    const host = getHost();
    if (!host) return;

    host.setAttribute('data-view', 'single');
    host.innerHTML =
      '<div class="mdlib-singlehead">' +
        '<div class="mdlib-back" data-mdlib-action="back">← Kembali</div>' +
        '<div class="mdlib-singletitle">Detail File</div>' +
      '</div>' +
      '<div class="mdlib-loading">Memuat detail…</div>';

    try {
      const url = '<?= ADMIN_BASE_PATH ?>/admin/modal_file/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1&_ts=' + Date.now();
      const res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store'
      });

      if (!res.ok) {
        throw new Error('HTTP ' + res.status);
      }

      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const body = doc.body;
      const single = body.querySelector('#mdlib-single-wrap') || body;

      const clone = single.cloneNode(true);
      Array.from(clone.querySelectorAll('script')).forEach(function(s){ s.remove(); });

      host.innerHTML =
        '<div class="mdlib-singlehead">' +
          '<div class="mdlib-back" data-mdlib-action="back">← Kembali</div>' +
          '<div class="mdlib-singletitle">Detail File</div>' +
        '</div>' +
        (clone.outerHTML || clone.innerHTML || '');

      Array.from(body.querySelectorAll('script')).forEach(function(s){
        if (!s.src) {
          const el = document.createElement('script');
          el.text = s.textContent || '';
          document.body.appendChild(el);
        }
      });
    } catch (err) {
      console.error('mdlib openSingle error', err);
      host.innerHTML =
        '<div class="mdlib-singlehead">' +
          '<div class="mdlib-back" data-mdlib-action="back">← Kembali</div>' +
          '<div class="mdlib-singletitle">Detail File</div>' +
        '</div>' +
        '<div class="mdlib-loading" style="color:#dc2626">Gagal memuat detail file.</div>';
      uiToast('error', 'Library File', 'Gagal memuat detail file: ' + (err.message || err), 6000);
    }
  }

  window.mdlibActivate = setActive;
  window.mdlibLoadList = function(force, url){
    return loadList(!!force, url || '');
  };
  window.mdlibOpenSingle = function(id){
    return openSingle(id);
  };
  window.mdlibBackToList = function(){
    return loadList(true, lastListUrl);
  };
  window.mdlibBackToLibrary = function(){
    setActive('library');
    return loadList(true, lastListUrl);
  };
  window.mdlibLoadIntoRoot = function(url){
    setActive('library');
    return fetchIntoLibrary(url, 'Memuat…').catch(function(err){
      uiToast('error', 'Library File', 'Gagal memuat konten: ' + (err.message || err), 6000);
    });
  };

  root.addEventListener('click', function(ev){
    const tabBtn = ev.target.closest('[data-mdlib-tab]');
    if (tabBtn && root.contains(tabBtn)) {
      ev.preventDefault();
      setActive(tabBtn.getAttribute('data-mdlib-tab') || 'upload');
      return;
    }

    const backBtn = ev.target.closest('[data-mdlib-action="back"]');
    if (backBtn && root.contains(backBtn)) {
      ev.preventDefault();
      window.mdlibBackToLibrary();
      return;
    }

    const closeBtn = ev.target.closest('#mdlib-close-btn');
    if (closeBtn) {
      ev.preventDefault();
      try { if (window.parent && window.parent !== window && typeof window.parent.adamModalClose === 'function') { window.parent.adamModalClose(); return; } } catch(e){}
      try { if (typeof window.adamModalClose === 'function') { window.adamModalClose(); return; } } catch(e){}
      window.close();
      return;
    }
  }, true);

  root.addEventListener('keydown', function(ev){
    const a = document.activeElement;
    if (!a || !root.contains(a)) return;

    if (a.getAttribute && a.getAttribute('data-mdlib-tab') && (ev.key === 'Enter' || ev.key === ' ')) {
      ev.preventDefault();
      setActive(a.getAttribute('data-mdlib-tab') || 'upload');
    }
  }, true);

  if (!window.__mdlib_refresh_bound) {
    window.__mdlib_refresh_bound = true;

    const safeRefresh = function(){
      const host = document.getElementById('mdlib-library-host');
      if (!host) return;
      if (host.getAttribute('data-view') !== 'list') return;
      if (typeof window.mdlibLoadList === 'function') {
        window.mdlibLoadList(true);
      }
    };

    document.addEventListener('file:added', safeRefresh);
    document.addEventListener('file:updated', safeRefresh);
    document.addEventListener('file:deleted', safeRefresh);
  }

  setActive(getInitialTab());
})();
</script>

<?php if (!$embedded): ?>
</body>
</html>
<?php endif; ?>
