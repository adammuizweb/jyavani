<?php
declare(strict_types=1);

// /adiwira/admin/media/?
require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid, $role] = adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);

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
<h2><?=_e('Media Manager')?></h2>

<input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

<div class="tabs">
  <button class="tab-btn <?= $initialTab === 'add' ? 'active' : '' ?>" data-target="panel-add"><?=_e('Add')?></button>
  <button class="tab-btn <?= $initialTab === 'list' ? 'active' : '' ?>" data-target="panel-list"><?=_e('List')?></button>
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

<?php
if (!empty($page_toasts) && function_exists('adiwira_bootstrap_toasts_script')) {
    echo adiwira_bootstrap_toasts_script($page_toasts);
}
?>

<script>
(function(){
  const tabs = document.querySelectorAll('.tab-btn');
  const modalBackdrop = document.getElementById('adam-modal-backdrop');
  const modalBox = document.getElementById('adam-modal');

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
    alert(message || title || 'Terjadi sesuatu.');
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
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
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

      const page = url.searchParams.get('page') || '';
      document.querySelectorAll('.adam-nav-sublink').forEach(function(sl){
        const href = sl.getAttribute('href') || '';
        const sp = new URLSearchParams(href.split('?')[1] || '');
        if (sp.get('page') === page) {
          sl.classList.toggle('adam-nav-sublink--active', sp.get('tab') === targetName);
        }
      });
    } catch(e){}
  }

  async function refreshListPanel(opts = {}) {
    const silent = !!opts.silent;
    const forcePage1 = !!opts.forcePage1;

    const panel = document.getElementById('panel-list');
    if (!panel) return;

    const qEl = document.getElementById('media-search');
    const q = qEl ? (qEl.value || '').trim() : '';

    const vEl = document.getElementById('visibility-filter');
    const v = vEl ? vEl.value : '';

    const activePageEl = panel.querySelector('.media-pagination strong');
    const p = forcePage1 ? 1 : (activePageEl ? parseInt(activePageEl.textContent || '1', 10) : 1);

    const url = '<?= ADMIN_BASE_PATH ?>/admin/media/list.php?q='
      + encodeURIComponent(q)
      + '&p=' + encodeURIComponent((Number.isFinite(p) && p > 0) ? p : 1)
      + (v ? '&v=' + encodeURIComponent(v) : '')
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
      if (!silent) {
        uiToast('error', 'Media', 'Gagal memuat daftar media: ' + (err.message || err));
      }
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
      uiToast('error', 'Media', 'Gagal memuat modal: ' + (err.message || err));
    }
  };

  window.adamModalClose = function(){
    if (!modalBackdrop || !modalBox) return;
    modalBackdrop.style.display = 'none';
    modalBox.innerHTML = '';
  };

  window.mediaUi = {
    toast: uiToast,
    ask: uiAsk,
    getCsrfToken: getCsrfToken,
    readJsonSafe: readJsonSafe,
    refreshListPanel: refreshListPanel
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
      uiToast('success', 'Media', '<?=__('URL copied successfully.')?>');
    } catch (e) {
      uiToast('error', 'Media', '<?=__('Failed to copy URL.')?>');
    }
    document.body.removeChild(ta);
  }

  document.addEventListener('click', async function(ev){
    const target = ev.target;

    if (target && target.id === 'media-save-btn') {
      ev.preventDefault();

      const ok = await uiAsk('warning', {
        title: <?= json_encode(__('Save media changes')) ?>,
        message: '<?=__('Media metadata changes will be saved. Continue?')?>',
        confirmText: <?= json_encode(__('Yes, save')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      const btn = target;
      const form = btn.closest('form');
      if (!form) return;

      btn.disabled = true;
      const fd = new FormData(form);
      const csrf = getCsrfToken();
      if (csrf && !fd.get('csrf_token')) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/media/save.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          const msg = (j?.errors && Array.isArray(j.errors) && j.errors.length)
            ? j.errors.join('\n')
            : (j?.error || txt || ('HTTP ' + res.status));
          uiToast('error', 'Media', msg);
          return;
        }

        if (j && j.ok) {
          uiToast('success', 'Media', '<?=__('Media updated successfully.')?>');
          document.dispatchEvent(new CustomEvent('media:updated', { detail: j }));
        } else {
          uiToast('error', 'Media', j?.error || txt || 'Terjadi kesalahan.');
        }
      } catch (err) {
        uiToast('error', 'Media', 'Network error: ' + (err.message || err));
      } finally {
        btn.disabled = false;
      }
      return;
    }

    if (target && target.id === 'media-delete-btn') {
      ev.preventDefault();

      const ok = await uiAsk('danger', {
        title: <?= json_encode(__('Delete media')) ?>,
        message: <?= json_encode(__('This media will be permanently deleted. Proceed?')) ?>,
        confirmText: <?= json_encode(__('Yes, delete')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      const form = target.closest('form');
      const idEl = form ? form.querySelector('input[name="id"]') : null;
      const id = idEl ? idEl.value : '';

      const fd = new FormData();
      if (id) fd.append('id', id);

      const csrf = getCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/media/delete.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          uiToast('error', 'Media', j?.error || txt || ('HTTP ' + res.status));
          return;
        }

        if (j && j.ok) {
          uiToast('success', 'Media', '<?=__('Media deleted successfully.')?>');
          if (j.warning) {
            uiToast('warning', 'Media', j.warning);
          }
          document.dispatchEvent(new CustomEvent('media:deleted', { detail: j }));
          window.adamModalClose();
        } else {
          uiToast('error', 'Media', j?.error || txt || 'Terjadi kesalahan.');
        }
      } catch (err) {
        uiToast('error', 'Media', 'Network error: ' + (err.message || err));
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
        uiToast('warning', '<?=__('Media')?>', '<?=__('URL not found.')?>', 5000);
        return;
      }

      let full = path;
      if (!/^https?:\/\//i.test(path)) {
        full = prefix.replace(/\/$/, '') + path;
      }

      if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(full).then(() => {
          uiToast('success', 'Media', '<?=__('URL copied successfully.')?>');
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