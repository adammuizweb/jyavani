<?php
// /adiwira/admin/modal_file/index.php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
adiwira_require_admin(false);

$embedded = isset($_GET['embedded']) && (($_GET['embedded'] === '1') || ($_GET['embedded'] === 'true'));

$csrfToken = '';
try { if (function_exists('csrf_token')) $csrfToken = (string)csrf_token(); } catch (Throwable $e) { $csrfToken = ''; }

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

<div id="modalfilez-root" data-modalfilez-mounted="1">
  <input type="hidden" id="modalfilez-csrf" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

  <div id="modalfilez-topbar">
    <div class="modalfilez-toprow">
      <div id="modalfilez-tabs">
        <div data-modalfilez-tab="upload" role="button" tabindex="0"
             class="modalfilez-tab modalfilez-tab-active"
             style="pointer-events:auto;position:relative;z-index:2147483647;">
          Upload
        </div>
        <div data-modalfilez-tab="library" role="button" tabindex="0"
             class="modalfilez-tab"
             style="pointer-events:auto;position:relative;z-index:2147483647;">
          Library
        </div>
      </div>
      <div id="modalfilez-hint">Klik <b>Insert</b> untuk memilih file</div>
    </div>
  </div>

  <div id="modalfilez-panel-upload" class="modalfilez-panel" style="display:block;">
    <?php include __DIR__ . '/add_modal.php'; ?>
  </div>

  <div id="modalfilez-panel-library" class="modalfilez-panel" style="display:none;">
    <div id="modalfilez-library-host" class="modalfilez-loading">Memuat library…</div>
  </div>
</div>

<script>
/**
 * 2) TAB HANDLER (FIX UTAMA):
 *    - pointerdown: hanya STOPPER (jangan ubah UI di sini)
 *    - click: baru pindah tab (menghindari "click jatuh ke backdrop" → modal close)
 */
(function(){
  if (window.__modalfilez_tabs_global_installed) return;
  window.__modalfilez_tabs_global_installed = true;

  function root(){ return document.getElementById('modalfilez-root'); }

  function setActive(tab){
    var r = root(); if (!r) return;

    var btnU = r.querySelector('[data-modalfilez-tab="upload"]');
    var btnL = r.querySelector('[data-modalfilez-tab="library"]');

    var pU = document.getElementById('modalfilez-panel-upload');
    var pL = document.getElementById('modalfilez-panel-library');

    if (btnU) btnU.classList.toggle('modalfilez-tab-active', tab === 'upload');
    if (btnL) btnL.classList.toggle('modalfilez-tab-active', tab === 'library');

    if (pU) pU.style.display = (tab === 'upload') ? 'block' : 'none';
    if (pL) pL.style.display = (tab === 'library') ? 'block' : 'none';

    if (tab === 'library') {
      try { window.modalfilezLoadList(false); } catch(e){}
    }
  }

  function isTabEventTarget(ev){
    var r = root(); if (!r) return null;
    var t = ev.target;
    if (!t || !t.closest) return null;
    var btn = t.closest('[data-modalfilez-tab]');
    if (!btn || !r.contains(btn)) return null;
    return btn;
  }

  // pointerdown: stopper saja
  window.addEventListener('pointerdown', function(ev){
    var btn = isTabEventTarget(ev);
    if (!btn) return;
    // STOPPER: biar gak "tembus" handler lain, tapi JANGAN ubah UI dulu
    ev.stopImmediatePropagation();
    // jangan preventDefault di sini
  }, true);

  // click: baru eksekusi pindah tab
  window.addEventListener('click', function(ev){
    var btn = isTabEventTarget(ev);
    if (!btn) return;
    ev.preventDefault();
    ev.stopImmediatePropagation();
    setActive(btn.getAttribute('data-modalfilez-tab') || 'upload');
  }, true);

  // keyboard
  window.addEventListener('keydown', function(ev){
    var r = root(); if (!r) return;
    var a = document.activeElement;
    if (!a || !a.getAttribute) return;
    if (!r.contains(a)) return;

    if (a.getAttribute('data-modalfilez-tab') && (ev.key === 'Enter' || ev.key === ' ')) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      setActive(a.getAttribute('data-modalfilez-tab'));
    }
  }, true);

  window.modalfilezActivate = setActive;
  setTimeout(function(){ setActive('upload'); }, 0);
})();
</script>

<script>
/**
 * 3) LIST + SINGLE integrator (biarkan seperti versi kamu yg sudah perfect)
 *    Catatan: bagian ini tetap pakai pointerdown untuk action list/single,
 *    karena tidak mengubah layout topbar sebelum click.
 */
(function(){
  function broadcast(name, detail){
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { if (window.parent && window.parent !== window) window.parent.postMessage({ type:name, detail }, '*'); } catch(e){}
  }

  function flash(msg, ok){
    var hint = document.getElementById('modalfilez-hint');
    if (!hint) { try{ alert(msg); }catch(e){}; return; }
    if (!hint.dataset.orig) hint.dataset.orig = hint.innerHTML;
    hint.innerHTML = msg;
    hint.style.color = ok ? '#059669' : '#dc2626';
    setTimeout(function(){
      hint.style.color = '';
      hint.innerHTML = hint.dataset.orig;
    }, 1600);
  }

  function inject(container, html){
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var body = doc.body;

    var wrap = body.querySelector('#modalfilez-lib-wrap') || body;
    var clone = wrap.cloneNode(true);
    Array.from(clone.querySelectorAll('script')).forEach(s=>s.remove());

    container.innerHTML = clone.outerHTML || clone.innerHTML || '';
  }

  var hostId = 'modalfilez-library-host';
  var lastListUrl = '/adiwira/admin/modal_file/list_modal.php';
  var lastScroll = 0;

  async function loadList(force, url){
    var host = document.getElementById(hostId);
    if (!host) return;

    var loaded = host.getAttribute('data-loaded') === '1';
    if (loaded && !force && !url) return;

    if (url) lastListUrl = url;

    host.dataset.view = 'list';
    host.innerHTML = '<div class="modalfilez-loading">Memuat daftar file…</div>';

    var u = lastListUrl + (lastListUrl.includes('?') ? '&' : '?') + '_ts=' + Date.now();
    var res = await fetch(u, { credentials:'include', cache:'no-store' });
    var html = await res.text();

    inject(host, html);
    host.setAttribute('data-loaded','1');
    host.scrollTop = lastScroll || 0;
  }

  window.modalfilezLoadList = function(force){ return loadList(!!force); };
  window.modalfilezBackToList = function(){ return loadList(true, lastListUrl); };

  async function openSingle(id){
    var host = document.getElementById(hostId);
    if (!host) return;

    lastScroll = host.scrollTop || 0;

    host.dataset.view = 'single';
    host.innerHTML =
      '<div class="modalfilez-singlehead">' +
        '<div class="modalfilez-back" data-modalfilez-action="back">← Kembali</div>' +
        '<div class="modalfilez-singletitle">Detail File</div>' +
      '</div>' +
      '<div class="modalfilez-loading">Memuat detail…</div>';

    var url = '/adiwira/admin/modal_file/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1&_ts=' + Date.now();
    var res = await fetch(url, { credentials:'include', cache:'no-store' });
    var html = await res.text();

    var doc = new DOMParser().parseFromString(html, 'text/html');
    var body = doc.body;

    var single = body.querySelector('#modalfilez-single-wrap') || body;
    var clone = single.cloneNode(true);
    Array.from(clone.querySelectorAll('script')).forEach(s=>s.remove());

    host.innerHTML =
      '<div class="modalfilez-singlehead">' +
        '<div class="modalfilez-back" data-modalfilez-action="back">← Kembali</div>' +
        '<div class="modalfilez-singletitle">Detail File</div>' +
      '</div>' +
      (clone.outerHTML || clone.innerHTML || '');
  }

  function getSingleForm(){
    return document.getElementById('modalfilez-file-edit-form');
  }

  function readSingleDetail(form){
    if (!form) return null;
    var id = form.getAttribute('data-id') || (form.querySelector('input[name="id"]')?.value || '');
    var title = form.querySelector('input[name="title"]')?.value || '';
    return {
      id: id ? parseInt(id,10) : null,
      title: title,
      filename: form.getAttribute('data-filename') || '',
      url: form.getAttribute('data-url') || '',
      mime: form.getAttribute('data-mime') || '',
      size: form.getAttribute('data-size') || ''
    };
  }

  async function singleSave(form){
    var fd = new FormData(form);
    var res = await fetch('/adiwira/admin/modal_file/save.php', { method:'POST', credentials:'include', body: fd });
    var txt = await res.text();
    var j = null; try { j = txt ? JSON.parse(txt) : null; } catch(e){ j = null; }

    if (!res.ok) throw new Error((j && j.error) ? j.error : (txt || ('HTTP '+res.status)));
    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Save failed');

    broadcast('file:updated', j);
    flash('Saved ✔', true);
  }

  async function singleDelete(form){
    var id = form.querySelector('input[name="id"]')?.value || form.getAttribute('data-id') || '';
    var csrf = form.querySelector('input[name="csrf_token"]')?.value || '';
    var fd = new FormData();
    if (id) fd.append('id', id);
    if (csrf) fd.append('csrf_token', csrf);

    var res = await fetch('/adiwira/admin/modal_file/delete.php', { method:'POST', credentials:'include', body: fd });
    var txt = await res.text();
    var j = null; try { j = txt ? JSON.parse(txt) : null; } catch(e){ j = null; }

    if (!res.ok) throw new Error((j && j.error) ? j.error : (txt || ('HTTP '+res.status)));
    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Delete failed');

    broadcast('file:deleted', j);
    flash('Deleted ✔', true);
    await window.modalfilezBackToList();
  }

  function copyText(text){
    text = String(text||'').trim();
    if (!text) return flash('URL kosong', false);

    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function(){ flash('Copied ✔', true); })
        .catch(function(){ fallbackCopy(text); });
      return;
    }
    fallbackCopy(text);

    function fallbackCopy(v){
      var ta = document.createElement('textarea');
      ta.value = v;
      ta.setAttribute('readonly','readonly');
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); flash('Copied ✔', true); }
      catch(e){ flash('Gagal copy', false); }
      document.body.removeChild(ta);
    }
  }

  // Delegation (CAPTURE) untuk aksi list + single
  window.addEventListener('pointerdown', function(ev){
    var r = document.getElementById('modalfilez-root');
    if (!r) return;

    var t = ev.target;
    if (!t || !t.closest) return;
    if (!r.contains(t)) return;

    var pLib = document.getElementById('modalfilez-panel-library');
    if (!pLib || pLib.style.display === 'none') return;

    var host = document.getElementById(hostId);
    if (!host) return;

    var back = t.closest('[data-modalfilez-action="back"]');
    if (back) { ev.preventDefault(); ev.stopImmediatePropagation(); window.modalfilezBackToList(); return; }

var pagEl = t.closest('#modalfilez-pager a[href], #modalfilez-pager button[data-modalfilez-href]');
if (pagEl) {
  ev.preventDefault(); ev.stopImmediatePropagation();

  var href = pagEl.getAttribute('href') || pagEl.getAttribute('data-modalfilez-href') || '';
  if (href) loadList(true, href);

  return;
}

    var sbtn = t.closest('[data-modalfilez-action="search"]');
    if (sbtn) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      var q = (host.querySelector('#modalfilez-search')?.value || '').trim();
      var per = host.querySelector('#modalfilez-lib')?.getAttribute('data-per-page') || '12';
      var url = '/adiwira/admin/modal_file/list_modal.php?q=' + encodeURIComponent(q) + '&page=1&per_page=' + encodeURIComponent(per);
      loadList(true, url);
      return;
    }

    var actionBtn = t.closest('[data-modalfilez-action="insert"],[data-modalfilez-action="detail"]');
    if (actionBtn) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      var card = actionBtn.closest('.modalfilez-card');
      if (!card) return;

      var detail = {
        id: card.getAttribute('data-id') ? parseInt(card.getAttribute('data-id'),10) : null,
        filename: card.getAttribute('data-filename') || '',
        url: card.getAttribute('data-url') || '',
        mime: card.getAttribute('data-mime') || '',
        size: card.getAttribute('data-size') || ''
      };

      var act = actionBtn.getAttribute('data-modalfilez-action');
      if (act === 'insert') {
        broadcast('file:insert', detail);
        try { if (window.parent && window.parent.adamModalClose) window.parent.adamModalClose(); } catch(e){}
        try { if (window.adamModalClose) window.adamModalClose(); } catch(e){}
        return;
      }
      if (act === 'detail') {
        if (detail.id) openSingle(detail.id);
        return;
      }
    }

    var form = getSingleForm();

    var ins = t.closest('#modalfilez-file-insert');
    if (ins && form) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      var d = readSingleDetail(form);
      if (d) {
        broadcast('file:insert', d);
        try { if (window.parent && window.parent.adamModalClose) window.parent.adamModalClose(); } catch(e){}
        try { if (window.adamModalClose) window.adamModalClose(); } catch(e){}
      }
      return;
    }

    var save = t.closest('#modalfilez-file-save');
    if (save && form) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      save.disabled = true;
      singleSave(form).catch(function(err){
        flash('Save gagal: ' + (err.message||err), false);
      }).finally(function(){ save.disabled = false; });
      return;
    }

    var del = t.closest('#modalfilez-file-delete');
    if (del && form) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      if (!confirm('Hapus file ini secara permanen?')) return;
      del.disabled = true;
      singleDelete(form).catch(function(err){
        flash('Delete gagal: ' + (err.message||err), false);
      }).finally(function(){ del.disabled = false; });
      return;
    }

    var copy = t.closest('[data-action="copy-url"]');
    if (copy) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      var input = document.getElementById('modalfilez-file-url');
      copyText(input ? input.value : '');
      return;
    }

  }, true);

  // Enter search
  window.addEventListener('keydown', function(ev){
    var pLib = document.getElementById('modalfilez-panel-library');
    if (!pLib || pLib.style.display === 'none') return;
    if (ev.target && ev.target.id === 'modalfilez-search' && ev.key === 'Enter') {
      ev.preventDefault(); ev.stopImmediatePropagation();
      document.querySelector('[data-modalfilez-action="search"]')?.click();
    }
  }, true);

  // Refresh list hanya kalau view list
  function safeRefresh(){
    var host = document.getElementById(hostId);
    if (!host) return;
    if ((host.dataset.view || 'list') !== 'list') return;
    loadList(true, lastListUrl);
  }
  document.addEventListener('file:added', safeRefresh);
  document.addEventListener('file:deleted', safeRefresh);
  document.addEventListener('file:updated', safeRefresh);

})();
</script>

<?php if (!$embedded): ?>
</body>
</html>
<?php endif; ?>