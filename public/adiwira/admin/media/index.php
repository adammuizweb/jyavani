<?php
// /adiwira/admin/media/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(404);
    require __DIR__ . '/../../../frontend_404.php';
    exit;
}
// index dapat dipanggil langsung sebagai halaman, atau dipakai dalam modal
?>
  <h2>Media Manager</h2>
  
  <?php $csrf = csrf_token(); ?>
  <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

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
  const tabs = document.querySelectorAll('.tab-btn');

  // Utility: open modal fallback
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
    return { q, p: (Number.isFinite(p) && p > 0 ? p : 1) };
  }

  // ✅ Refresh list: replace ONLY .media-list (biar handler JS list.php tetap hidup)
  async function refreshListPanel(opts = {}) {
    const silent = !!opts.silent;
    const forcePage1 = !!opts.forcePage1;

    const panel = document.getElementById('panel-list');
    if (!panel) return;

    const st = getCurrentListState();
    const q = st.q;
    const p = forcePage1 ? 1 : st.p;

    const url = '/adiwira/admin/media/list.php'
      + '?q=' + encodeURIComponent(q)
      + '&p=' + encodeURIComponent(p)
      + '&_ts=' + Date.now(); // ✅ cache buster

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
        // fallback: tetap replace panel (rare)
        panel.innerHTML = html;
      }
    } catch (err) {
      console.error('Gagal refresh list:', err);
      if (!silent) alert('Gagal memuat daftar media: ' + err.message);
    }
  }

  // Tabs (klik list => paksa refresh supaya pasti terbaru)
  tabs.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      tabs.forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');

      document.querySelectorAll('[id^="panel-"]').forEach(p=>p.style.display='none');
      const targetId = btn.dataset.target;
      const targetPanel = document.getElementById(targetId);
      if (targetPanel) targetPanel.style.display = 'block';

      if (targetId === 'panel-list') {
        // ✅ tiap buka tab list, refresh silent
        refreshListPanel({ silent:true, forcePage1:false });
      }
    });
  });

  // ✅ auto refresh saat event dari add/save/delete
  document.addEventListener('media:updated', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('media:deleted', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('media:added',   () => refreshListPanel({ silent:true, forcePage1:true })); // upload baru -> page 1

  // expose kalau kamu butuh panggil manual
  window.refreshListPanel = refreshListPanel;
})();
</script>


<script>
(function(){
  const modalBackdrop = document.getElementById('adam-modal-backdrop');

  function getCsrfToken() {
    const el = document.getElementById('csrf_token');
    return el && el.value ? el.value : '';
  }

  async function readJsonSafe(res) {
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  function closeModalFallback() {
    if (typeof window.adamModalClose === 'function') {
      try { window.adamModalClose(); return; } catch(e) {}
    }
    if (modalBackdrop) modalBackdrop.style.display = 'none';
  }

  document.addEventListener('click', async function(ev){
    const target = ev.target;

    if (target && target.id === 'media-save-btn') {
      ev.preventDefault();
      const btn = target;
      const form = btn.closest('form');
      if (!form) return;

      btn.disabled = true;
      const fd = new FormData(form);
      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/media/save.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          alert('Error: ' + (j?.error || txt || ('HTTP ' + res.status)));
          return;
        }

        if (j && j.ok) {
          alert('Updated ✔');
          document.dispatchEvent(new CustomEvent('media:updated', { detail: j }));
        } else {
          alert('Error: ' + (j?.error || txt || 'unknown'));
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      } finally {
        btn.disabled = false;
      }
      return;
    }

    if (target && target.id === 'media-delete-btn') {
      ev.preventDefault();
      if (!confirm('Delete this media?')) return;

      const form = target.closest('form');
      let id = null;
      if (form) {
        const el = form.querySelector('input[name="id"]');
        if (el) id = el.value;
      }

      const fd = new FormData();
      if (id) fd.append('id', id);

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/media/delete.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          alert('Error: ' + (j?.error || txt || ('HTTP ' + res.status)));
          return;
        }

        if (j && j.ok) {
          alert('Deleted ✔');
          document.dispatchEvent(new CustomEvent('media:deleted', { detail: j }));
          closeModalFallback();
        } else {
          alert('Error: ' + (j?.error || txt || 'unknown'));
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      }
      return;
    }

        // ✅ COPY URL (works inside modal or page)
    const copyBtn = (target && target.closest) ? target.closest('[data-action="copy-url"], .copy-btn') : null;
    if (copyBtn) {
      ev.preventDefault();

      // cari elemen di dalam form/modal yang sama dulu (biar tidak ketukar kalau ada id duplikat)
      const scope = copyBtn.closest('form') || copyBtn.closest('.media-single-wrap') || document;

      const prefixEl = scope.querySelector('#media-url-prefix') || document.getElementById('media-url-prefix');
      const pathEl   = scope.querySelector('#media-url-path')   || document.getElementById('media-url-path');

      const prefix = prefixEl ? (prefixEl.textContent || '').trim() : window.location.origin;
      const path   = pathEl ? (pathEl.value || '').trim() : '';

      if (!path) { alert('URL tidak ditemukan'); return; }

      // kalau path sudah full URL, pakai apa adanya. kalau cuma "/static/..." gabungkan dengan prefix.
      let full = path;
      if (!/^https?:\/\//i.test(path)) {
        full = prefix.replace(/\/$/, '') + path;
      } else {
        try { full = new URL(path).href; } catch(e) {}
      }

      // copy modern
      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(full).then(() => {
          alert('Copied: ' + full);
        }).catch(() => {
          fallbackCopy(full);
        });
      } else {
        fallbackCopy(full);
      }

      function fallbackCopy(text) {
        // fallback aman (tanpa tergantung input readonly)
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
          document.execCommand('copy');
          alert('Copied: ' + text);
        } catch (e) {
          alert('Gagal menyalin');
        }
        document.body.removeChild(ta);
      }

      return;
    }
  }, false);

  if (!window.__mediaDelegationInstalled) {
    window.__mediaDelegationInstalled = true;
  }
})();
</script>


<script>
(function(){
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
      try {
        const name = (btn.dataset.target || '').replace(/^panel-/, '');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        history.replaceState(null, '', url.toString());
      } catch(e){}
    });
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
})();
</script>