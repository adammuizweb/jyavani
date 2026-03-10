<?php
declare(strict_types=1);

// /adiwira/admin/file/index.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../_guard.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);

$csrf = csrf_token();
$initialTab = strtolower(trim((string)($_GET['tab'] ?? 'add')));
if (!in_array($initialTab, ['add', 'list'], true)) {
    $initialTab = 'add';
}
?>
<div id="file-manager-root">
  <h2>File Manager</h2>

  <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

  <div class="tabs">
    <button type="button" class="tab-btn <?= $initialTab === 'add' ? 'active' : '' ?>" data-target="add">Add</button>
    <button type="button" class="tab-btn <?= $initialTab === 'list' ? 'active' : '' ?>" data-target="list">List</button>
  </div>

  <div id="file-panel-add" class="panel" style="<?= $initialTab === 'add' ? '' : 'display:none;' ?>">
    <?php include __DIR__ . '/add.php'; ?>
  </div>

  <div id="file-panel-list" class="panel" style="<?= $initialTab === 'list' ? '' : 'display:none;' ?>">
    <?php include __DIR__ . '/list.php'; ?>
  </div>

  <div id="adam-modal-backdrop" style="display:none;">
    <div id="adam-modal"></div>
  </div>
</div>

<script>
(function(){
  if (window.__ADIWIRA_FILE_INDEX_INIT__) return;
  window.__ADIWIRA_FILE_INDEX_INIT__ = true;

  const root = document.getElementById('file-manager-root');
  if (!root) return;

  const tabs = root.querySelectorAll('.tab-btn');
  const panelAdd = document.getElementById('file-panel-add');
  const panelList = document.getElementById('file-panel-list');
  const modalBackdrop = document.getElementById('adam-modal-backdrop');
  const modalBox = document.getElementById('adam-modal');

  function notify(message, type = 'info', duration = 4000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
      return;
    }
    alert(message);
  }

  function getCsrfToken() {
    const el = document.getElementById('csrf_token');
    return el && el.value ? el.value : '';
  }

  async function readJsonSafe(res) {
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch (e) {}
    return { txt, j };
  }

  function activateTab(name) {
    tabs.forEach(btn => {
      btn.classList.toggle('active', btn.dataset.target === name);
    });

    if (panelAdd) panelAdd.style.display = (name === 'add') ? '' : 'none';
    if (panelList) panelList.style.display = (name === 'list') ? '' : 'none';

    try {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', name);
      history.replaceState(null, '', url.toString());
    } catch (e) {}
  }

  function getCurrentQuery() {
    const qEl = panelList ? panelList.querySelector('#media-search') : null;
    return qEl ? (qEl.value || '').trim() : '';
  }

  function getCurrentPage() {
    const activeEl = panelList ? panelList.querySelector('.media-pagination strong[data-page]') : null;
    if (!activeEl) return 1;
    const p = parseInt(activeEl.getAttribute('data-page') || activeEl.textContent || '1', 10);
    return (Number.isFinite(p) && p > 0) ? p : 1;
  }

  function injectHtmlWithScripts(container, html) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    let bodyHtml = '';
    Array.from(doc.body.childNodes).forEach(node => {
      if (node.nodeName && node.nodeName.toLowerCase() === 'script') return;
      bodyHtml += node.outerHTML || node.textContent || '';
    });

    container.innerHTML = bodyHtml;

    Array.from(doc.querySelectorAll('script')).forEach(oldScript => {
      const s = document.createElement('script');
      if (oldScript.src) {
        s.src = oldScript.src;
        s.async = false;
      } else {
        s.textContent = oldScript.textContent || '';
      }
      document.body.appendChild(s);
    });
  }

  async function refreshFileListPanel(opts = {}) {
    if (!panelList) return;

    const silent = !!opts.silent;
    const forcePage1 = !!opts.forcePage1;

    const q = getCurrentQuery();
    const p = forcePage1 ? 1 : getCurrentPage();

    const url = '/adiwira/admin/file/list.php'
      + '?q=' + encodeURIComponent(q)
      + '&p=' + encodeURIComponent(p)
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

      if (fresh) {
        panelList.innerHTML = '';
        panelList.appendChild(fresh);
      } else {
        panelList.innerHTML = html;
      }
    } catch (err) {
      console.error('refreshFileListPanel error:', err);
      if (!silent) notify('Gagal memuat daftar file: ' + (err.message || err), 'error', 6000);
    }
  }

  window.refreshFileListPanel = refreshFileListPanel;

  window.adamModalOpen = window.adamModalOpen || async function(url){
    try {
      const res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store'
      });

      if (!res.ok) throw new Error('HTTP ' + res.status);

      const html = await res.text();

      if (!modalBackdrop || !modalBox) {
        window.open(url, '_blank');
        return;
      }

      injectHtmlWithScripts(modalBox, html);
      modalBackdrop.style.display = 'flex';
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
    } catch (err) {
      notify('Modal load error: ' + (err.message || err), 'error', 6000);
    }
  };

  window.adamModalClose = function(){
    if (!modalBackdrop || !modalBox) return;
    modalBackdrop.style.display = 'none';
    modalBox.innerHTML = '';
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
  };

  if (modalBackdrop) {
    modalBackdrop.addEventListener('click', function(e){
      if (e.target === modalBackdrop) {
        window.adamModalClose();
      }
    });
  }

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && modalBackdrop && modalBackdrop.style.display === 'flex') {
      window.adamModalClose();
    }
  });

  tabs.forEach(btn => {
    btn.addEventListener('click', async function(){
      const target = btn.dataset.target || 'add';
      activateTab(target);

      if (target === 'list') {
        await refreshFileListPanel({ silent: true, forcePage1: false });
      }
    });
  });

  document.addEventListener('click', async function(ev){
    const target = ev.target;

    const openBtn = target.closest('.btn-open');
    if (openBtn) {
      ev.preventDefault();
      const id = openBtn.getAttribute('data-id') || '';
      if (!id) return;
      window.adamModalOpen('/adiwira/admin/file/single.php?id=' + encodeURIComponent(id));
      return;
    }

    const searchBtn = target.closest('#media-search-btn');
    if (searchBtn) {
      ev.preventDefault();
      activateTab('list');
      await refreshFileListPanel({ silent: false, forcePage1: true });
      return;
    }

    const pageLink = target.closest('.media-page-link');
    if (pageLink) {
      ev.preventDefault();

      const q = pageLink.getAttribute('data-q') || getCurrentQuery();
      const p = parseInt(pageLink.getAttribute('data-page') || '1', 10) || 1;

      try {
        const url = '/adiwira/admin/file/list.php'
          + '?q=' + encodeURIComponent(q)
          + '&p=' + encodeURIComponent(p)
          + '&_ts=' + Date.now();

        const res = await fetch(url, {
          credentials: 'include',
          cache: 'no-store'
        });

        if (!res.ok) throw new Error('HTTP ' + res.status);

        const html = await res.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const fresh = doc.querySelector('.media-list');

        if (fresh && panelList) {
          panelList.innerHTML = '';
          panelList.appendChild(fresh);
        }
      } catch (err) {
        notify('Gagal memuat halaman file: ' + (err.message || err), 'error', 6000);
      }
      return;
    }

    const saveBtn = target.closest('#file-save-btn');
    if (saveBtn) {
      ev.preventDefault();

      const form = saveBtn.closest('form');
      if (!form) return;

      saveBtn.disabled = true;

      const fd = new FormData(form);
      const csrf = getCsrfToken();
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/save.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 'error', 6000);
          return;
        }

        if (j && j.ok) {
          notify('Saved ✔', 'success', 3500);
          document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j }));
        } else {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 'error', 6000);
        }
      } catch (err) {
        notify('Network error: ' + (err.message || err), 'error', 6000);
      } finally {
        saveBtn.disabled = false;
      }
      return;
    }

    const deleteBtn = target.closest('#file-delete-btn');
    if (deleteBtn) {
      ev.preventDefault();

      if (!confirm('Hapus file ini secara permanen?')) return;

      const form = deleteBtn.closest('form');
      if (!form) return;

      deleteBtn.disabled = true;

      const idEl = form.querySelector('input[name="id"]');
      const fd = new FormData();
      if (idEl && idEl.value) fd.append('id', idEl.value);

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 'error', 6000);
          return;
        }

        if (j && j.ok) {
          notify('Deleted ✔', 'success', 3500);
          document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
          window.adamModalClose();
        } else {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 'error', 6000);
        }
      } catch (err) {
        notify('Network error: ' + (err.message || err), 'error', 6000);
      } finally {
        deleteBtn.disabled = false;
      }
      return;
    }

    const copyBtn = target.closest('[data-action="copy-url"], .copy-btn');
    if (copyBtn) {
      ev.preventDefault();

      const scope = copyBtn.closest('form') || copyBtn.closest('.single-file') || document;
      const prefixEl = scope.querySelector('#file-url-prefix') || document.getElementById('file-url-prefix');
      const pathEl = scope.querySelector('#file-url-path') || document.getElementById('file-url-path');

      const prefix = prefixEl ? (prefixEl.textContent || '').trim() : window.location.origin;
      const path = pathEl ? (pathEl.value || '').trim() : '';

      if (!path) {
        notify('URL tidak ditemukan', 'error', 5000);
        return;
      }

      let full = path;
      if (!/^https?:\/\//i.test(path)) {
        full = prefix.replace(/\/$/, '') + path;
      }

      const fallbackCopy = (text) => {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
          document.execCommand('copy');
          notify('Copied: ' + text, 'success', 3000);
        } catch (e) {
          notify('Gagal menyalin', 'error', 5000);
        }
        document.body.removeChild(ta);
      };

      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(full).then(() => {
          notify('Copied: ' + full, 'success', 3000);
        }).catch(() => fallbackCopy(full));
      } else {
        fallbackCopy(full);
      }
      return;
    }

    const bulkBtn = target.closest('#delete-bulk-btn');
    if (bulkBtn) {
      ev.preventDefault();

      const checked = Array.from(root.querySelectorAll('.row-checkbox:checked')).map(cb => cb.value);
      if (checked.length < 1) {
        notify('Pilih minimal satu file untuk dihapus.', 'error', 5000);
        return;
      }

      if (!confirm('Hapus ' + checked.length + ' file?')) return;

      const fd = new FormData();
      checked.forEach(id => fd.append('ids[]', id));

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/delete_bulk.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 'error', 6000);
          return;
        }

        if (j && j.ok) {
          notify('Deleted: ' + (j.deleted_count || checked.length), 'success', 3500);
          document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
        } else {
          notify('Error: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 'error', 6000);
        }
      } catch (err) {
        notify('Network error: ' + (err.message || err), 'error', 6000);
      }
      return;
    }
  }, false);

  document.addEventListener('change', function(ev){
    const target = ev.target;
    if (target && target.id === 'select-all') {
      const checked = !!target.checked;
      root.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.checked = checked;
      });
    }
  });

  document.addEventListener('file:updated', () => refreshFileListPanel({ silent: true, forcePage1: false }));
  document.addEventListener('file:deleted', () => refreshFileListPanel({ silent: true, forcePage1: false }));
  document.addEventListener('file:added',   () => refreshFileListPanel({ silent: true, forcePage1: true }));

  if ('<?= $initialTab ?>' === 'list') {
    refreshFileListPanel({ silent: true, forcePage1: false });
  }
})();
</script>