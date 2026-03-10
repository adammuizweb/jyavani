<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/index.php
require_once __DIR__ . '/../_guard.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    adiwira_cosmetic_404_on_direct_open();
}

[$uid, $role] = adiwira_require_editorial($pdo, false);

$embedded = isset($_GET['embedded']) && (($_GET['embedded'] === '1') || ($_GET['embedded'] === 'true'));
$initialTab = strtolower(trim((string)($_GET['tab'] ?? 'upload')));
if (!in_array($initialTab, ['upload', 'library'], true)) {
    $initialTab = 'upload';
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
  id="modalfilez-root"
  data-modalfilez-mounted="1"
  data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
  data-initial-tab="<?= htmlspecialchars($initialTab, ENT_QUOTES, 'UTF-8') ?>"
>
  <input type="hidden" id="modalfilez-csrf" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

  <div id="modalfilez-topbar">
    <div class="modalfilez-toprow">
      <div id="modalfilez-tabs">
        <div data-modalfilez-tab="upload" role="button" tabindex="0" class="modalfilez-tab">Upload</div>
        <div data-modalfilez-tab="library" role="button" tabindex="0" class="modalfilez-tab">Library</div>
      </div>
      <div id="modalfilez-hint">Klik <b>Insert</b> untuk memilih file</div>
    </div>
  </div>

  <div id="modalfilez-panel-upload" class="modalfilez-panel" style="display:none;">
    <?php include __DIR__ . '/add_modal.php'; ?>
  </div>

  <div id="modalfilez-panel-library" class="modalfilez-panel" style="display:none;">
    <div id="modalfilez-library-host" class="modalfilez-loading">Memuat library…</div>
  </div>
</div>

<script>
(function(){
  if (window.__modalfilez_index_installed) return;
  window.__modalfilez_index_installed = true;

  var lastListUrl = '/adiwira/admin/modal_file/list_modal.php';

  function getRoot() {
    return document.getElementById('modalfilez-root');
  }

  function getHost() {
    return document.getElementById('modalfilez-library-host');
  }

  function getInitialTab() {
    var root = getRoot();
    if (!root) return 'upload';
    var t = (root.getAttribute('data-initial-tab') || 'upload').toLowerCase();
    return (t === 'library') ? 'library' : 'upload';
  }

  function flash(msg, ok) {
    var hint = document.getElementById('modalfilez-hint');
    if (!hint) {
      try { alert(msg); } catch(e){}
      return;
    }

    if (!hint.dataset.orig) hint.dataset.orig = hint.innerHTML;
    hint.innerHTML = msg;
    hint.style.color = ok ? '#059669' : '#dc2626';

    setTimeout(function(){
      hint.style.color = '';
      hint.innerHTML = hint.dataset.orig || 'Klik <b>Insert</b> untuk memilih file';
    }, 1600);
  }

  function injectHtmlWithScripts(container, html) {
    try {
      var parser = new DOMParser();
      var doc = parser.parseFromString(html, 'text/html');

      var bodyHtml = '';
      Array.from(doc.body.childNodes).forEach(function(n){
        if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
        bodyHtml += n.outerHTML || n.textContent || '';
      });
      container.innerHTML = bodyHtml;

      var scripts = doc.querySelectorAll('script');
      var externals = [];

      scripts.forEach(function(s){
        if (s.src) externals.push(s.src);
      });

      externals.forEach(function(src){
        if (!document.querySelector('script[src="' + src + '"]')) {
          var el = document.createElement('script');
          el.src = src;
          el.async = false;
          document.head.appendChild(el);
        }
      });

      scripts.forEach(function(s){
        if (!s.src) {
          var el = document.createElement('script');
          el.text = s.textContent || '';
          document.body.appendChild(el);
        }
      });
    } catch (err) {
      console.error('modalfilez inject error', err);
      container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
    }
  }

  window.modalfilezInjectHtmlWithScripts = injectHtmlWithScripts;

  function setActive(tab) {
    var root = getRoot();
    if (!root) return;

    var btnUpload = root.querySelector('[data-modalfilez-tab="upload"]');
    var btnLibrary = root.querySelector('[data-modalfilez-tab="library"]');
    var panelUpload = document.getElementById('modalfilez-panel-upload');
    var panelLibrary = document.getElementById('modalfilez-panel-library');

    if (btnUpload) btnUpload.classList.toggle('modalfilez-tab-active', tab === 'upload');
    if (btnLibrary) btnLibrary.classList.toggle('modalfilez-tab-active', tab === 'library');

    if (panelUpload) panelUpload.style.display = (tab === 'upload') ? 'block' : 'none';
    if (panelLibrary) panelLibrary.style.display = (tab === 'library') ? 'block' : 'none';

    if (tab === 'library') {
      window.modalfilezLoadList(false);
    }
  }

  async function fetchIntoLibrary(url, loadingText) {
    var host = getHost();
    if (!host) return;

    host.innerHTML = '<div class="modalfilez-loading">' + (loadingText || 'Memuat…') + '</div>';

    var finalUrl = String(url || '');
    if (!finalUrl) return;

    if (finalUrl.indexOf('_ts=') === -1) {
      finalUrl += (finalUrl.indexOf('?') >= 0 ? '&' : '?') + '_ts=' + Date.now();
    }

    var res = await fetch(finalUrl, {
      credentials: 'include',
      cache: 'no-store'
    });

    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }

    var html = await res.text();
    injectHtmlWithScripts(host, html);
  }

  async function loadList(force, url) {
    var host = getHost();
    if (!host) return;

    if (url) lastListUrl = url;

    var loaded = host.getAttribute('data-loaded') === '1';
    if (!force && loaded && host.getAttribute('data-view') === 'list' && !url) return;

    host.setAttribute('data-view', 'list');
    await fetchIntoLibrary(lastListUrl, 'Memuat daftar file…');
    host.setAttribute('data-loaded', '1');
  }

  async function openSingle(id) {
    if (!id) return;

    setActive('library');

    var host = getHost();
    if (!host) return;

    host.setAttribute('data-view', 'single');
    host.innerHTML =
      '<div class="modalfilez-singlehead">' +
        '<div class="modalfilez-back" data-modalfilez-action="back">← Kembali</div>' +
        '<div class="modalfilez-singletitle">Detail File</div>' +
      '</div>' +
      '<div class="modalfilez-loading">Memuat detail…</div>';

    var url = '/adiwira/admin/modal_file/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1&_ts=' + Date.now();
    var res = await fetch(url, {
      credentials: 'include',
      cache: 'no-store'
    });

    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }

    var html = await res.text();
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var body = doc.body;
    var single = body.querySelector('#modalfilez-single-wrap') || body;

    var clone = single.cloneNode(true);
    Array.from(clone.querySelectorAll('script')).forEach(function(s){ s.remove(); });

    host.innerHTML =
      '<div class="modalfilez-singlehead">' +
        '<div class="modalfilez-back" data-modalfilez-action="back">← Kembali</div>' +
        '<div class="modalfilez-singletitle">Detail File</div>' +
      '</div>' +
      (clone.outerHTML || clone.innerHTML || '');

    Array.from(body.querySelectorAll('script')).forEach(function(s){
      if (!s.src) {
        var el = document.createElement('script');
        el.text = s.textContent || '';
        document.body.appendChild(el);
      }
    });
  }

  window.modalfilezActivate = setActive;
  window.modalfilezLoadList = function(force, url){
    return loadList(!!force, url || '');
  };
  window.modalfilezOpenSingle = function(id){
    return openSingle(id);
  };
  window.modalfilezBackToList = function(){
    return loadList(true, lastListUrl);
  };
  window.modalfilezBackToLibrary = function(){
    setActive('library');
    return loadList(true, lastListUrl);
  };
  window.modalfilezLoadIntoRoot = function(url){
    setActive('library');
    return fetchIntoLibrary(url, 'Memuat…');
  };

  document.addEventListener('click', function(ev){
    var root = getRoot();
    if (!root) return;

    var tabBtn = ev.target.closest('[data-modalfilez-tab]');
    if (tabBtn && root.contains(tabBtn)) {
      ev.preventDefault();
      setActive(tabBtn.getAttribute('data-modalfilez-tab') || 'upload');
      return;
    }

    var backBtn = ev.target.closest('[data-modalfilez-action="back"]');
    if (backBtn && root.contains(backBtn)) {
      ev.preventDefault();
      window.modalfilezBackToLibrary();
    }
  }, true);

  document.addEventListener('keydown', function(ev){
    var root = getRoot();
    if (!root) return;

    var a = document.activeElement;
    if (!a || !root.contains(a)) return;

    if (a.getAttribute && a.getAttribute('data-modalfilez-tab') && (ev.key === 'Enter' || ev.key === ' ')) {
      ev.preventDefault();
      setActive(a.getAttribute('data-modalfilez-tab') || 'upload');
    }
  }, true);

  function safeRefresh() {
    var host = getHost();
    if (!host) return;
    if (host.getAttribute('data-view') !== 'list') return;
    loadList(true, lastListUrl);
  }

  document.addEventListener('file:added', safeRefresh);
  document.addEventListener('file:updated', safeRefresh);
  document.addEventListener('file:deleted', safeRefresh);

  try {
    setActive(getInitialTab());
  } catch (e) {
    setActive('upload');
  }
})();
</script>

<?php if (!$embedded): ?>
</body>
</html>
<?php endif; ?>