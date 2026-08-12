<?php
declare(strict_types=1);

// /adiwira/admin/file/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_editorial($pdo, false);

$csrf = csrf_token();
$initialTab = strtolower(trim((string)($_GET['tab'] ?? 'add')));
if (!in_array($initialTab, ['add', 'list'], true)) {
    $initialTab = 'add';
}

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

if (function_exists('adiwira_flash_pull')) {
    $flash = adiwira_flash_pull();
    if (is_array($flash)) {
        foreach ($flash as $f) {
            $type = isset($f['type']) ? (string)$f['type'] : 'info';
            $text = isset($f['message']) ? (string)$f['message'] : (isset($f['text']) ? (string)$f['text'] : '');
            if ($text !== '') {
                $page_toasts[] = [
                    'type' => $type,
                    'message' => $text,
                ];
            }
        }
    }
}
?>
<div id="file-manager-root">
  <h2><?=_e('File Manager')?></h2>

  <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

  <div class="tabs">
    <button type="button" class="tab-btn <?= $initialTab === 'add' ? 'active' : '' ?>" data-target="add"><?=_e('Add')?></button>
    <button type="button" class="tab-btn <?= $initialTab === 'list' ? 'active' : '' ?>" data-target="list"><?=_e('List')?></button>
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

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

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
  let listRequestSequence = 0;
  let listController = null;

  function uiToast(type, title, message, duration) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({
        type: type || 'info',
        title: title || null,
        message: message || '',
        duration: duration
      });
      return;
    }
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
  }

  function uiAsk(variant, opts) {
    if (window.NewNotifConfirm) {
      if (variant === 'danger' && typeof window.NewNotifConfirm.danger === 'function') {
        return window.NewNotifConfirm.danger(opts || {});
      }
      if (typeof window.NewNotifConfirm.warning === 'function') {
        return window.NewNotifConfirm.warning(opts || {});
      }
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message: <?= json_encode(__('Proceed with this action?')) ?>));
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

      const page = url.searchParams.get('page') || '';
      document.querySelectorAll('.adam-nav-sublink').forEach(function(sl){
        const href = sl.getAttribute('href') || '';
        const sp = new URLSearchParams(href.split('?')[1] || '');
        if (sp.get('page') === page) {
          sl.classList.toggle('adam-nav-sublink--active', sp.get('tab') === name);
        }
      });
    } catch (e) {}
  }

  function getCurrentQuery() {
    const qEl = panelList ? panelList.querySelector('#media-search') : null;
    return qEl ? (qEl.value || '').trim() : '';
  }

  function getCurrentVisibility() {
    const vEl = panelList ? panelList.querySelector('#visibility-filter') : null;
    return vEl ? vEl.value : '';
  }

  function getCurrentPerPage() {
    const perPageEl = panelList ? panelList.querySelector('#media-per-page') : null;
    const perPage = perPageEl ? parseInt(perPageEl.value || '20', 10) : 20;
    return [20, 50, 100, 200].includes(perPage) ? perPage : 20;
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

    const v = getCurrentVisibility();
    const perPage = getCurrentPerPage();

    const url = '<?= ADMIN_BASE_PATH ?>/admin/file/list.php'
      + '?q=' + encodeURIComponent(q)
      + '&p=' + encodeURIComponent(p)
      + '&per_page=' + encodeURIComponent(perPage)
      + (v ? '&v=' + encodeURIComponent(v) : '')
      + '&_ts=' + Date.now();

    try {
      const requestId = ++listRequestSequence;
      if (listController) listController.abort();
      listController = typeof AbortController === 'function' ? new AbortController() : null;
      const res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store',
        signal: listController ? listController.signal : undefined
      });

      if (!res.ok) throw new Error('HTTP ' + res.status);

      const html = await res.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      const fresh = doc.querySelector('.media-list');
      if (requestId !== listRequestSequence) return;

      if (fresh) {
        panelList.innerHTML = '';
        panelList.appendChild(fresh);
      } else {
        panelList.innerHTML = html;
      }
    } catch (err) {
      if (err && err.name === 'AbortError') return;
      console.error('refreshFileListPanel error:', err);
      if (!silent) uiToast('error', '<?=__('File')?>', '<?=__('Failed to load files:')?> ' + (err.message || err), 6000);
    }
  }

  window.fileUi = {
    toast: uiToast,
    ask: uiAsk,
    getCsrfToken: getCsrfToken,
    readJsonSafe: readJsonSafe,
    refreshFileListPanel: refreshFileListPanel
  };

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
      uiToast('error', '<?=__('File')?>', '<?=__('Failed to load modal:')?> ' + (err.message || err), 6000);
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
      window.adamModalOpen('<?= ADMIN_BASE_PATH ?>/admin/file/single.php?id=' + encodeURIComponent(id));
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
      const v = pageLink.getAttribute('data-v') || getCurrentVisibility();
      const perPage = parseInt(pageLink.getAttribute('data-per-page') || String(getCurrentPerPage()), 10) || 20;

      try {
        const requestId = ++listRequestSequence;
        if (listController) listController.abort();
        listController = typeof AbortController === 'function' ? new AbortController() : null;
        const url = '<?= ADMIN_BASE_PATH ?>/admin/file/list.php'
          + '?q=' + encodeURIComponent(q)
          + '&p=' + encodeURIComponent(p)
          + '&per_page=' + encodeURIComponent(perPage)
          + (v ? '&v=' + encodeURIComponent(v) : '')
          + '&_ts=' + Date.now();

        const res = await fetch(url, {
          credentials: 'include',
          cache: 'no-store',
          signal: listController ? listController.signal : undefined
        });

        if (!res.ok) throw new Error('HTTP ' + res.status);

        const html = await res.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const fresh = doc.querySelector('.media-list');
        if (requestId !== listRequestSequence) return;

        if (fresh && panelList) {
          panelList.innerHTML = '';
          panelList.appendChild(fresh);
        }
      } catch (err) {
        if (err && err.name === 'AbortError') return;
        uiToast('error', '<?=__('File')?>', '<?=__('Failed to load file page:')?> ' + (err.message || err), 6000);
      }
      return;
    }

    const saveBtn = target.closest('#file-save-btn');
    if (saveBtn) {
      ev.preventDefault();

      const ok = await uiAsk('warning', {
        title: <?= json_encode(__('Save file changes')) ?>,
        message: '<?=__('File metadata changes will be saved. Continue?')?>',
        confirmText: <?= json_encode(__('Yes, save')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      const form = saveBtn.closest('form');
      if (!form) return;

      saveBtn.disabled = true;

      const fd = new FormData(form);
      const csrf = getCsrfToken();
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/file/save.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          uiToast('success', '<?=__('File')?>', '<?=__('File updated successfully.')?>', 3000);
          document.dispatchEvent(new CustomEvent('file:updated', { detail: j.file || j }));
        } else {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        }
      } catch (err) {
        uiToast('error', '<?=__('File')?>', '<?=__('Network error:')?> ' + (err.message || err), 6000);
      } finally {
        saveBtn.disabled = false;
      }
      return;
    }

    const deleteBtn = target.closest('#file-delete-btn');
    if (deleteBtn) {
      ev.preventDefault();

      const ok = await uiAsk('danger', {
        title: <?= json_encode(__('Delete file')) ?>,
        message: <?= json_encode(__('This file will be permanently deleted. Proceed?')) ?>,
        confirmText: <?= json_encode(__('Yes, delete')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      const form = deleteBtn.closest('form');
      if (!form) return;

      deleteBtn.disabled = true;

      const idEl = form.querySelector('input[name="id"]');
      const fd = new FormData();
      if (idEl && idEl.value) fd.append('id', idEl.value);

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          uiToast('success', '<?=__('File')?>', '<?=__('File deleted successfully.')?>', 3000);
          if (j.warning) {
            uiToast('warning', '<?=__('File')?>', j.warning, 6000);
          }
          document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
          window.adamModalClose();
        } else {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        }
      } catch (err) {
        uiToast('error', '<?=__('File')?>', '<?=__('Network error:')?> ' + (err.message || err), 6000);
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
        uiToast('warning', '<?=__('File')?>', '<?=__('URL not found.')?>', 5000);
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
          uiToast('success', '<?=__('File')?>', '<?=__('URL copied successfully.')?>', 2500);
        } catch (e) {
          uiToast('error', '<?=__('File')?>', '<?=__('Failed to copy URL.')?>', 5000);
        }
        document.body.removeChild(ta);
      };

      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(full).then(() => {
          uiToast('success', '<?=__('File')?>', '<?=__('URL copied successfully.')?>', 2500);
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
        uiToast('warning', '<?=__('File')?>', '<?=__('Select at least one file to delete.')?>', 5000);
        return;
      }

      const ok = await uiAsk('danger', {
        title: <?= json_encode(__('Delete selected files')) ?>,
        message: <?= json_encode(__('')) ?> + checked.length + <?= json_encode(__(' file(s) will be permanently deleted. This action cannot be undone.')) ?>,
        confirmText: <?= json_encode(__('Yes, delete')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      const fd = new FormData();
      checked.forEach(id => fd.append('ids[]', id));

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/file/delete_bulk.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          uiToast('success', '<?=__('File')?>', <?= json_encode(__('%d file(s) deleted.')) ?>.replace('%d', j.deleted_count || checked.length), 3000);
          if (Array.isArray(j.warnings) && j.warnings.length) {
            uiToast('warning', '<?=__('File')?>', j.warnings.join('\n'), 6000);
          }
          document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
        } else {
          uiToast('error', '<?=__('File')?>', ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        }
      } catch (err) {
        uiToast('error', '<?=__('File')?>', '<?=__('Network error:')?> ' + (err.message || err), 6000);
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
    if (target && (target.id === 'visibility-filter' || target.id === 'media-per-page')) {
      activateTab('list');
      refreshFileListPanel({ silent: false, forcePage1: true });
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
