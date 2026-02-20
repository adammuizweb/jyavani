<?php
// /adiwira/admin/media/index.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// index dapat dipanggil langsung sebagai halaman, atau dipakai dalam modal
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Media Manager</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto; margin:12px; color:#222; }
    .tabs { display:flex; gap:8px; margin-bottom:14px; }
    .tab-btn { padding:8px 12px; border-radius:6px; border:1px solid #ddd; background:#fafafa; cursor:pointer; }
    .tab-btn.active { background:#0b80ff; color:#fff; border-color:#0b80ff; }
    .panel { border:1px solid #eee; padding:12px; border-radius:8px; background:#fff; }
    .toolbar { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
    .search { padding:8px; border-radius:6px; border:1px solid #ccc; }
    .small { font-size:.9rem; color:#666; }
    /* simple modal fallback (if adamModalOpen not present) */
    #adam-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); align-items:center; justify-content:center; z-index:9999; }
    #adam-modal { background:#fff; border-radius:8px; width:90%; max-width:900px; max-height:90vh; overflow:auto; padding:8px; }
  </style>
</head>
<body>

  <h2>Media Manager</h2>

  <div class="tabs">
    <button class="tab-btn active" data-target="panel-add">Add</button>
    <button class="tab-btn" data-target="panel-list">List</button>
  </div>

  <div id="panel-add" class="panel">
    <?php include __DIR__ . '/add.php'; ?>
  </div>

  <div id="panel-list" class="panel" style="display:none;">
    <?php include __DIR__ . '/list.php'; ?>
  </div>

  <!-- fallback modal used if global adamModalOpen not available -->
  <div id="adam-modal-backdrop">
    <div id="adam-modal"></div>
  </div>

<script>
(function(){
  // Tabs
  const tabs = document.querySelectorAll('.tab-btn');
  tabs.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      tabs.forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      document.querySelectorAll('[id^="panel-"]').forEach(p=>p.style.display='none');
      document.getElementById(btn.dataset.target).style.display = 'block';
    });
  });

  // Utility: open modal (integrate with existing adamModalOpen if present)
  window.adamModalOpen = window.adamModalOpen || function(url, opts){
    // simple AJAX load into fallback modal
    const bd = document.getElementById('adam-modal-backdrop');
    const box = document.getElementById('adam-modal');
    fetch(url, { credentials: 'include' })
      .then(r => r.text())
      .then(html => {
        box.innerHTML = html;
        bd.style.display = 'flex';
        bd.onclick = (e) => { if (e.target === bd) { bd.style.display='none'; box.innerHTML=''; } };
      })
      .catch(err => alert('Modal load error: ' + err.message));
  };

function refreshListPanel() {
  const listPanel = document.getElementById('panel-list');
  if (!listPanel) return;
  // gunakan path absolut ke media/list.php supaya tidak tergantung router page=...
  fetch('/adiwira/admin/media/list.php', { credentials:'include' })
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then(html => {
      listPanel.innerHTML = html;
    })
    .catch(err => console.error('Gagal refresh list:', err));
}

  document.addEventListener('media:updated', refreshListPanel);
  document.addEventListener('media:deleted', refreshListPanel);
  document.addEventListener('media:added', refreshListPanel);

  // also auto-refresh list after upload completes (add.php dispatches events via its button)
  // nothing else to do here, listeners above handle it.

})();
</script>


<script>
(function(){
  // container modal fallback (sesuaikan id jika beda)
  const modalBackdropId = 'adam-modal-backdrop';
  const modalBackdrop = document.getElementById(modalBackdropId);

  // utility: try close modal (integrate with existing API)
  function closeModalFallback() {
    if (typeof window.adamModalClose === 'function') {
      try { window.adamModalClose(); return; } catch(e) {}
    }
    if (modalBackdrop) modalBackdrop.style.display = 'none';
  }

  // Delegate click events (works for elements inserted later)
  document.addEventListener('click', async function(ev){
    const target = ev.target;

    // SAVE button
    if (target && target.id === 'media-save-btn') {
      ev.preventDefault();
      const btn = target;
      const form = btn.closest('form');
      if (!form) { console.warn('media-save-btn: form not found'); return; }

      // disable while sending
      btn.disabled = true;
      const fd = new FormData(form);

      try {
        const res = await fetch('/adiwira/admin/media/save.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j.ok) {
          alert('Updated ✔');
          document.dispatchEvent(new CustomEvent('media:updated', { detail: j }));
        } else {
          console.error('Save error', j);
          alert('Error: ' + (j.error || JSON.stringify(j.errors || j)));
        }
      } catch (err) {
        console.error('Network error (save):', err);
        alert('Network error: ' + err.message);
      } finally {
        btn.disabled = false;
      }
      return;
    }

    // DELETE button
    if (target && target.id === 'media-delete-btn') {
      ev.preventDefault();
      if (!confirm('Delete this media?')) return;

      // find id
      const form = target.closest('form');
      let id = null;
      if (form) {
        const el = form.querySelector('input[name="id"]');
        if (el) id = el.value;
      }
      const fd = new FormData();
      if (id) fd.append('id', id);

      try {
        const res = await fetch('/adiwira/admin/media/delete.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j.ok) {
          alert('Deleted ✔');
          document.dispatchEvent(new CustomEvent('media:deleted', { detail: j }));
          // try to close modal
          closeModalFallback();
        } else {
          console.error('Delete error', j);
          alert('Error: ' + (j.error || JSON.stringify(j)));
        }
      } catch (err) {
        console.error('Network error (delete):', err);
        alert('Network error: ' + err.message);
      }
      return;
    }
    
// COPY URL (delegated) — letakkan di handler click global (index.php)
if (target && target.dataset && target.dataset.action === 'copy-url') {
  ev.preventDefault();
  // find prefix & path in same modal/form
  const form = target.closest('form');
  let prefixEl = form ? form.querySelector('#media-url-prefix') : document.getElementById('media-url-prefix');
  let pathEl   = form ? form.querySelector('#media-url-path')   : document.getElementById('media-url-path');

  const prefix = prefixEl ? prefixEl.textContent.trim() : '';
  const path = pathEl ? pathEl.value.trim() : '';

  // If path is already absolute, use it directly
  let full = path;
  try {
    const u = new URL(path, window.location.origin);
    full = u.href;
    // If path was relative (starts with '/'), URL resolves to prefix + path
    // If path already contains scheme, it remains as-is
    if (prefix && !path.match(/^https?:\/\//i)) {
      // ensure prefix is included
      full = prefix.replace(/\/$/, '') + path;
    }
  } catch (e) {
    // fallback combine
    if (prefix && path) full = prefix.replace(/\/$/, '') + path;
  }

  if (!full) return alert('URL tidak ditemukan');

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(full).then(() => {
      alert('Copied: ' + full);
    }).catch(err => {
      console.error('Clipboard error', err);
      alert('Gagal menyalin');
    });
  } else {
    // fallback older browsers
    if (pathEl) {
      pathEl.select && pathEl.select();
      try { document.execCommand('copy'); alert('Copied: ' + full); }
      catch(e){ alert('Gagal menyalin'); }
    } else {
      alert('Clipboard tidak tersedia');
    }
  }
  return;
}


  }, false);

  // Debug helper: show network activity hint if nothing happens
  // (non-intrusive; hapus bila ingin)
  if (!window.__mediaDelegationInstalled) {
    console.log('Media delegation: save/delete handlers installed on document.');
    window.__mediaDelegationInstalled = true;
  }
})();
</script>

<script>
(function(){
  // Tabs
  const tabs = document.querySelectorAll('.tab-btn');
  function activateTabBtn(btn) {
    if (!btn) return;
    tabs.forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('[id^="panel-"]').forEach(p=>p.style.display='none');
    const target = btn.dataset.target;
    if (target) {
      const panel = document.getElementById(target);
      if (panel) panel.style.display = 'block';
    }
  }

  tabs.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      activateTabBtn(btn);
      // update URL (so linkable) without reloading: set ?tab=add or ?tab=list
      try {
        const name = (btn.dataset.target || '').replace(/^panel-/, '');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        history.replaceState(null, '', url.toString());
      } catch(e){}
    });
  });

  // --- new: support ?tab=add or ?tab=list or #panel-add / #panel-list ---
  function pickInitialTab() {
    const params = new URLSearchParams(window.location.search);
    let tab = params.get('tab') || '';
    if (!tab && window.location.hash) {
      tab = window.location.hash.replace('#', '');
      // allow both 'panel-add' and 'add'
      if (tab.indexOf('panel-') === 0) tab = tab.replace(/^panel-/, '');
    }
    // normalize: accept 'add' or 'list'
    tab = (tab || '').toLowerCase();

    let targetName = 'add'; // default
    if (tab === 'list' || tab === 'panel-list') targetName = 'list';
    if (tab === 'add' || tab === 'panel-add') targetName = 'add';

    // find the button with matching data-target
    const desired = Array.from(tabs).find(b => {
      const dt = b.dataset.target || '';
      return dt === ('panel-' + targetName) || b.dataset.target === targetName;
    });
    if (desired) activateTabBtn(desired);
    else {
      // fallback: activate first tab
      activateTabBtn(tabs[0]);
    }
  }

  // run initial selection
  pickInitialTab();

  // expose helper for other scripts if needed
  window.adamMediaRefreshList = window.adamMediaRefreshList || function(){ 
    // dummy; kept for compatibility if some code calls it
    const evt = new Event('media:refresh-request');
    document.dispatchEvent(evt);
  };

  // end IIFE
})();
</script>

</body>
</html>