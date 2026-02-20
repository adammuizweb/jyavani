<?php
// /adiwira/admin/file/index.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>File Manager</title>
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
    #adam-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); align-items:center; justify-content:center; z-index:9999; }
    #adam-modal { background:#fff; border-radius:8px; width:90%; max-width:900px; max-height:90vh; overflow:auto; padding:8px; }
  </style>
</head>
<body>

  <h2>File Manager</h2>

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

  // fallback modal open
  window.adamModalOpen = window.adamModalOpen || function(url, opts){
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
    fetch('/adiwira/admin/file/list.php', { credentials:'include' })
      .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(html => listPanel.innerHTML = html)
      .catch(err => console.error('Gagal refresh list:', err));
  }

  document.addEventListener('file:updated', refreshListPanel);
  document.addEventListener('file:deleted', refreshListPanel);
  document.addEventListener('file:added', refreshListPanel);

  // also keep legacy media events for compatibility
  document.addEventListener('media:updated', refreshListPanel);
  document.addEventListener('media:deleted', refreshListPanel);
  document.addEventListener('media:added', refreshListPanel);

})();
</script>
<script>
(function(){
  // try close modal (works with existing adamModalClose or fallback)
  function closeModalFallback() {
    if (typeof window.adamModalClose === 'function') {
      try { window.adamModalClose(); return; } catch(e){}
    }
    const bd = document.getElementById('adam-modal-backdrop');
    if (bd) bd.style.display = 'none';
  }

  // Utility: show friendly error
  function showError(err) {
    console.error(err);
    try { alert(typeof err === 'string' ? err : (err.message || JSON.stringify(err))); } catch(e){}
  }

  // Delegated click handler (handles Save / Delete / Copy / Remove)
  document.addEventListener('click', async function(ev){
    const target = ev.target;

    // ---- FILE SAVE (button id="file-save-btn") ----
    const saveBtn = target.closest('#file-save-btn');
    if (saveBtn) {
      ev.preventDefault();
      const btn = saveBtn;
      const form = btn.closest('form');
      if (!form) { return showError('Form not found for save'); }

      btn.disabled = true;
      const fd = new FormData(form);

      try {
        const res = await fetch('/adiwira/admin/file/save.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j && j.ok) {
          // dispatch both file:updated and legacy media:updated for compatibility
          try { document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j })); } catch(e){}
          try { document.dispatchEvent(new CustomEvent('media:updated', { detail: j.file || j })); } catch(e){}
          alert('Saved ✔');
          // close modal (optional)
          closeModalFallback();
        } else {
          showError(j && (j.error || (j.errors && j.errors.join ? j.errors.join(', ') : JSON.stringify(j))) || 'Save failed');
        }
      } catch (err) {
        showError(err);
      } finally {
        btn.disabled = false;
      }
      return;
    }

    // ---- FILE DELETE (button id="file-delete-btn" or preview .remove-btn) ----
    const deleteBtn = target.closest('#file-delete-btn, .remove-btn');
    if (deleteBtn) {
      ev.preventDefault();

      if (!confirm('Hapus file ini secara permanen?')) return;

      // find id from form or data attribute
      let id = null;
      const form = deleteBtn.closest('form');
      if (form) {
        const idEl = form.querySelector('input[name="id"]');
        if (idEl) id = idEl.value;
      }
      if (!id && deleteBtn.dataset && deleteBtn.dataset.id) id = deleteBtn.dataset.id;

      const fd = new FormData();
      if (id) fd.append('id', id);
      else {
        // fallback: maybe remove by url (preview remove button)
        const url = deleteBtn.dataset.url || (form ? (form.querySelector('input[name="url"]') ? form.querySelector('input[name="url"]').value : '') : '');
        if (url) fd.append('url', url);
      }

      try {
        const res = await fetch('/adiwira/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j && j.ok) {
          // dispatch events to refresh UI
          try { document.dispatchEvent(new CustomEvent('file:deleted', { detail: j })); } catch(e){}
          try { document.dispatchEvent(new CustomEvent('media:deleted', { detail: j })); } catch(e){}
          alert('Deleted ✔');
          // try to remove preview if exists (for add panel)
          if (deleteBtn.closest('.thumb')) deleteBtn.closest('.thumb').remove();
          // close modal fallback
          closeModalFallback();
        } else {
          showError(j && (j.error || (j.errors && j.errors.join ? j.errors.join(', ') : JSON.stringify(j))) || 'Delete failed');
        }
      } catch (err) {
        showError(err);
      }
      return;
    }

    // ---- COPY URL (data-action="copy-url") ----
    const copyBtn = target.closest('[data-action="copy-url"], .copy-btn');
    if (copyBtn) {
      ev.preventDefault();
      const form = copyBtn.closest('form');
      let prefixEl = form ? form.querySelector('#media-url-prefix') : document.getElementById('media-url-prefix');
      let pathEl   = form ? form.querySelector('#media-url-path')   : document.getElementById('media-url-path');
      const prefix = prefixEl ? prefixEl.textContent.trim() : '';
      const path = pathEl ? pathEl.value.trim() : '';

      let full = path;
      try {
        const u = new URL(path, window.location.origin);
        full = u.href;
        if (prefix && !path.match(/^https?:\/\//i)) full = prefix.replace(/\/$/, '') + path;
      } catch (e) {
        if (prefix && path) full = prefix.replace(/\/$/, '') + path;
      }

      if (!full) return alert('URL tidak ditemukan');

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(full).then(()=> alert('Copied: ' + full)).catch(err => showError(err));
      } else {
        if (pathEl && pathEl.select) { pathEl.select(); try { document.execCommand('copy'); alert('Copied: ' + full); } catch(e){ showError(e); } }
        else alert('Clipboard tidak tersedia');
      }
      return;
    }

  }, false);

  if (!window.__fileDelegationInstalled) {
    console.log('File manager delegation installed (save/delete/copy).');
    window.__fileDelegationInstalled = true;
  }
})();
</script>

<script>
(function(){
  // Tabs (enhanced): activate tab by ?tab=add|list or #panel-add / #add
  const tabs = document.querySelectorAll('.tab-btn');

  function activateTabBtn(btn, pushUrl = false) {
    if (!btn) return;
    tabs.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('[id^="panel-"]').forEach(p => p.style.display = 'none');
    const target = btn.dataset.target;
    if (target) {
      const panel = document.getElementById(target);
      if (panel) panel.style.display = 'block';
    }
    // update URL (query param) without reloading
    try {
      const name = (btn.dataset.target || '').replace(/^panel-/, '');
      const url = new URL(window.location.href);
      url.searchParams.set('tab', name);
      // keep hash if present? we prefer query param for clarity
      history.replaceState(null, '', url.toString());
    } catch (e) {
      // ignore
    }
  }

  tabs.forEach(btn => {
    btn.addEventListener('click', () => activateTabBtn(btn));
  });

  // read initial tab from ?tab= or #hash
  function pickInitialTab() {
    const params = new URLSearchParams(window.location.search);
    let tab = params.get('tab') || '';
    if (!tab && window.location.hash) {
      tab = window.location.hash.replace('#', '');
      if (tab.indexOf('panel-') === 0) tab = tab.replace(/^panel-/, '');
    }
    tab = (tab || '').toLowerCase();
    let targetName = 'add';
    if (tab === 'list' || tab === 'panel-list') targetName = 'list';
    if (tab === 'add' || tab === 'panel-add') targetName = 'add';

    const desired = Array.from(tabs).find(b => {
      const dt = b.dataset.target || '';
      return dt === ('panel-' + targetName) || b.dataset.target === targetName;
    });
    if (desired) activateTabBtn(desired);
    else activateTabBtn(tabs[0]);
  }

  // fallback modal open (existing)
  window.adamModalOpen = window.adamModalOpen || function(url, opts){
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

  // refresh list fragment (ke /admin/file/list.php)
  function refreshListPanel() {
    const listPanel = document.getElementById('panel-list');
    if (!listPanel) return;
    fetch('/adiwira/admin/file/list.php', { credentials:'include' })
      .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(html => listPanel.innerHTML = html)
      .catch(err => console.error('Gagal refresh list:', err));
  }

  // listen for events to refresh
  document.addEventListener('file:updated', refreshListPanel);
  document.addEventListener('file:deleted', refreshListPanel);
  document.addEventListener('file:added', refreshListPanel);

  // backward compat with media events (if other code emits those)
  document.addEventListener('media:updated', refreshListPanel);
  document.addEventListener('media:deleted', refreshListPanel);
  document.addEventListener('media:added', refreshListPanel);

  // run
  pickInitialTab();

  // debug flag
  if (!window.__fileTabsInstalled) {
    console.log('File tabs: enhanced tab handling installed.');
    window.__fileTabsInstalled = true;
  }

})();
</script>

</body>
</html>
