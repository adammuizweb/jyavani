<?php
declare(strict_types=1);

// /adiwira/admin/media/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

$csrf = csrf_token();
$initialTab = strtolower(trim((string)($_GET['tab'] ?? 'add')));
if (!in_array($initialTab, ['add', 'list'], true)) {
    $initialTab = 'add';
}
?>
<h2>Media Manager</h2>

<input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

<div class="tabs">
  <button class="tab-btn <?= $initialTab === 'add' ? 'active' : '' ?>" data-target="panel-add">Add</button>
  <button class="tab-btn <?= $initialTab === 'list' ? 'active' : '' ?>" data-target="panel-list">List</button>
</div>

<div id="panel-add" class="panel" style="<?= $initialTab === 'add' ? '' : 'display:none;' ?>">
  <?php include __DIR__ . '/add.php'; ?>
</div>

<div id="panel-list" class="panel" style="<?= $initialTab === 'list' ? '' : 'display:none;' ?>">
  <?php include __DIR__ . '/list.php'; ?>
</div>

<div id="adam-modal-backdrop" style="display:none;">
  <div id="adam-modal"></div>
</div>

<script>
(function(){
  const tabs = document.querySelectorAll('.tab-btn');
  const modalBackdrop = document.getElementById('adam-modal-backdrop');
  const modalBox = document.getElementById('adam-modal');

  function getCsrfToken() {
    const el = document.getElementById('csrf_token');
    return el && el.value ? el.value : '';
  }

  function activateTab(targetName) {
    tabs.forEach(btn => {
      const isActive = btn.dataset.target === 'panel-' + targetName;
      btn.classList.toggle('active', isActive);
    });

    document.querySelectorAll('[id^="panel-"]').forEach(p => p.style.display = 'none');
    const targetPanel = document.getElementById('panel-' + targetName);
    if (targetPanel) targetPanel.style.display = 'block';

    try {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', targetName);
      history.replaceState(null, '', url.toString());
    } catch(e){}
  }

  async function refreshListPanel(opts = {}) {
    const silent = !!opts.silent;
    const forcePage1 = !!opts.forcePage1;

    const panel = document.getElementById('panel-list');
    if (!panel) return;

    const qEl = document.getElementById('media-search');
    const q = qEl ? (qEl.value || '').trim() : '';

    const activePageEl = panel.querySelector('.media-pagination strong');
    const p = forcePage1 ? 1 : (activePageEl ? parseInt(activePageEl.textContent || '1', 10) : 1);

    const url = '/adiwira/admin/media/list.php?q='
      + encodeURIComponent(q)
      + '&p=' + encodeURIComponent((Number.isFinite(p) && p > 0) ? p : 1)
      + '&_ts=' + Date.now();

    try {
      const res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store'
      });
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
      console.error('refreshListPanel error:', err);
      if (!silent) alert('Gagal memuat daftar media: ' + (err.message || err));
    }
  }

  window.adamModalOpen = window.adamModalOpen || async function(url){
    try {
      const res = await fetch(url, {
        credentials: 'include',
        cache:'no-store'
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();

      if (!modalBackdrop || !modalBox) {
        window.open(url, '_blank');
        return;
      }

      modalBox.innerHTML = html;
      modalBackdrop.style.display = 'flex';
      modalBackdrop.onclick = function(e){
        if (e.target === modalBackdrop) {
          modalBackdrop.style.display = 'none';
          modalBox.innerHTML = '';
        }
      };
    } catch (err) {
      alert('Modal load error: ' + (err.message || err));
    }
  };

  window.adamModalClose = function(){
    if (!modalBackdrop || !modalBox) return;
    modalBackdrop.style.display = 'none';
    modalBox.innerHTML = '';
  };

  tabs.forEach(btn => {
    btn.addEventListener('click', async function(){
      const target = (btn.dataset.target || '').replace(/^panel-/, '');
      activateTab(target);
      if (target === 'list') {
        await refreshListPanel({ silent:true, forcePage1:false });
      }
    });
  });

  document.addEventListener('media:updated', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('media:deleted', () => refreshListPanel({ silent:true, forcePage1:false }));
  document.addEventListener('media:added',   () => refreshListPanel({ silent:true, forcePage1:true  }));

  async function readJsonSafe(res) {
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  function fallbackCopy(text) {
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
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

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
        alert('Network error: ' + (err.message || err));
      } finally {
        btn.disabled = false;
      }
      return;
    }

    if (target && target.id === 'media-delete-btn') {
      ev.preventDefault();
      if (!confirm('Delete this media?')) return;

      const form = target.closest('form');
      const idEl = form ? form.querySelector('input[name="id"]') : null;
      const id = idEl ? idEl.value : '';

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
          window.adamModalClose();
        } else {
          alert('Error: ' + (j?.error || txt || 'unknown'));
        }
      } catch (err) {
        alert('Network error: ' + (err.message || err));
      }
      return;
    }

    const copyBtn = (target && target.closest) ? target.closest('[data-action="copy-url"], .copy-btn') : null;
    if (copyBtn) {
      ev.preventDefault();

      const scope = copyBtn.closest('form') || copyBtn.closest('.media-single-wrap') || document;
      const prefixEl = scope.querySelector('#media-url-prefix') || document.getElementById('media-url-prefix');
      const pathEl   = scope.querySelector('#media-url-path') || document.getElementById('media-url-path');

      const prefix = prefixEl ? (prefixEl.textContent || '').trim() : window.location.origin;
      const path   = pathEl ? (pathEl.value || '').trim() : '';

      if (!path) {
        alert('URL tidak ditemukan');
        return;
      }

      let full = path;
      if (!/^https?:\/\//i.test(path)) {
        full = prefix.replace(/\/$/, '') + path;
      }

      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(full).then(() => {
          alert('Copied: ' + full);
        }).catch(() => {
          fallbackCopy(full);
        });
      } else {
        fallbackCopy(full);
      }
      return;
    }
  }, false);
})();
</script>