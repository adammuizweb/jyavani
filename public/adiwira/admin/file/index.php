<?php
// /adiwira/admin/file/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(404);
    require __DIR__ . '/../../../frontend_404.php';
    exit;
}
?>
  <h2>File Manager</h2>

  <?php $csrf = function_exists('csrf_token') ? csrf_token() : ''; ?>
  <input type="hidden" id="csrf_token" value="<?= htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') ?>">

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
      const target = document.getElementById(btn.dataset.target);
      if (target) target.style.display = 'block';

      // ✅ kalau buka tab list, refresh supaya selalu latest
      if (btn.dataset.target === 'panel-list') {
        refreshListPanel({ silent:true, forcePage1:false });
      }
    });
  });

  // fallback modal open
  window.adamModalOpen = window.adamModalOpen || function(url, opts){
    const bd = document.getElementById('adam-modal-backdrop');
    const box = document.getElementById('adam-modal');
    fetch(url, { credentials: 'include', cache:'no-store' })
      .then(r => r.text())
      .then(html => {
        box.innerHTML = html;
        bd.style.display = 'flex';
        bd.onclick = (e) => { if (e.target === bd) { bd.style.display='none'; box.innerHTML=''; } };
      })
      .catch(err => alert('Modal load error: ' + err.message));
  };

  function getCurrentListState() {
    const qEl = document.getElementById('media-search');
    const q = qEl ? (qEl.value || '').trim() : '';
    const pEl = document.querySelector('.media-pagination strong');
    const p = pEl ? parseInt((pEl.textContent || '1'), 10) : 1;
    return { q, p: (isFinite(p) && p > 0 ? p : 1) };
  }

  // ✅ refresh list: replace ONLY .media-list
  async function refreshListPanel(opts = {}) {
    const silent = !!opts.silent;
    const forcePage1 = !!opts.forcePage1;

    const panel = document.getElementById('panel-list');
    if (!panel) return;

    const st = getCurrentListState();
    const q = st.q;
    const p = forcePage1 ? 1 : st.p;

    const url = '/adiwira/admin/file/list.php'
      + '?q=' + encodeURIComponent(q)
      + '&p=' + encodeURIComponent(p)
      + '&_ts=' + Date.now();

    try {
      const res = await fetch(url, { credentials:'include', cache:'no-store' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();

      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      const fresh = doc.querySelector('.media-list');
      const cur = panel.querySelector('.media-list');

      if (fresh && cur) {
        cur.replaceWith(fresh);
      } else if (fresh && !cur) {
        panel.innerHTML = '';
        panel.appendChild(fresh);
      } else {
        panel.innerHTML = html;
      }
    } catch (err) {
      console.error('Gagal refresh list:', err);
      if (!silent) alert('Gagal memuat daftar file: ' + err.message);
    }
  }

  // listen for events to refresh
  document.addEventListener('file:updated', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('file:deleted', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('file:added',   () => refreshListPanel({ silent:true, forcePage1:true }));

  // legacy compatibility
  document.addEventListener('media:updated', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('media:deleted', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('media:added',   () => refreshListPanel({ silent:true, forcePage1:true }));

  // expose
  window.refreshFileListPanel = refreshListPanel;
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

      // (opsional) kirim csrf kalau ada
      const csrf = document.getElementById('csrf_token') && document.getElementById('csrf_token').value ? document.getElementById('csrf_token').value : '';
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/save.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j && j.ok) {
          try { document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j })); } catch(e){}
          try { document.dispatchEvent(new CustomEvent('media:updated', { detail: j.file || j })); } catch(e){}
          alert('Saved ✔');
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
        const url = deleteBtn.dataset.url || (form ? (form.querySelector('input[name="url"]') ? form.querySelector('input[name="url"]').value : '') : '');
        if (url) fd.append('url', url);
      }

      const csrf = document.getElementById('csrf_token') && document.getElementById('csrf_token').value ? document.getElementById('csrf_token').value : '';
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j && j.ok) {
          try { document.dispatchEvent(new CustomEvent('file:deleted', { detail: j })); } catch(e){}
          try { document.dispatchEvent(new CustomEvent('media:deleted', { detail: j })); } catch(e){}
          alert('Deleted ✔');
          if (deleteBtn.closest('.thumb')) deleteBtn.closest('.thumb').remove();
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

  function activateTabBtn(btn) {
    if (!btn) return;
    tabs.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('[id^="panel-"]').forEach(p => p.style.display = 'none');
    const target = btn.dataset.target;
    if (target) {
      const panel = document.getElementById(target);
      if (panel) panel.style.display = 'block';
    }
    try {
      const name = (btn.dataset.target || '').replace(/^panel-/, '');
      const url = new URL(window.location.href);
      url.searchParams.set('tab', name);
      history.replaceState(null, '', url.toString());
    } catch (e) {}
  }

  tabs.forEach(btn => {
    btn.addEventListener('click', () => activateTabBtn(btn));
  });

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

  pickInitialTab();

  if (!window.__fileTabsInstalled) {
    console.log('File tabs: enhanced tab handling installed.');
    window.__fileTabsInstalled = true;
  }
})();
</script>