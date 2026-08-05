<?php
declare(strict_types=1);

// /adiwira/admin/media/add.php
require_once __DIR__ . '/../_guard.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    if (adiwira_is_navigate_request()) {
        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }

    adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);
}

$mediaCsrf = csrf_token();
if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(string $col): bool {
        try {
            $st = $GLOBALS['pdo']->prepare("SELECT {$col} FROM media LIMIT 0");
            $st->execute();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
$hasVisibility = mdlib_has_column('visibility');
?>
<section class="media-uploader">
  <input type="hidden" id="media-csrf-token" value="<?= htmlspecialchars($mediaCsrf, ENT_QUOTES, 'UTF-8') ?>">

  <?php if ($hasVisibility): ?>
  <div style="margin-bottom:8px;display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;padding:4px 0">
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:2px"><?=_e('Visibility')?></label>
      <select id="media-visibility" style="padding:3px 6px;font-size:12px">
        <option value="auto"><?=_e('Auto')?></option>
        <option value="public"><?=_e('Public')?></option>
        <option value="private"><?=_e('Private')?></option>
      </select>
    </div>
    <div id="media-private-options" style="display:none">
      <div style="display:inline-block;margin-right:8px">
        <label style="font-size:11px;font-weight:600;display:block;margin-bottom:2px"><?=_e('Access Scope')?></label>
        <select id="media-access-scope" style="padding:3px 6px;font-size:12px">
          <option value="editorial"><?=_e('Editorial')?></option>
          <option value="admin"><?=_e('Admin Only')?></option>
        </select>
      </div>
      <div style="display:inline-block">
        <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:11px;font-weight:600">
          <input type="checkbox" id="media-is-downloadable" value="1" checked>
          <?=_e('Downloadable')?>
        </label>
      </div>
    </div>
    <div style="align-self:flex-end" class="small" style="color:#888"><?=_e('Private images stored outside public_html')?></div>
  </div>
  <?php endif; ?>

  <div class="dropzone" id="dropzone">
    <p><?=_e('Drag image here or')?> <button class="adam-btn" id="browse-btn" type="button"><?= _e('Select file') ?></button></p>
    <div id="upload-progress" class="upload-progress"></div>
  </div>

  <div id="preview-container" class="preview-grid"></div>
</section>

<input type="file" id="file-input" accept="image/*" multiple hidden>

<script>
(() => {
  const dz = document.getElementById('dropzone');
  const fileInput = document.getElementById('file-input');
  const browseBtn = document.getElementById('browse-btn');
  const progressWrap = document.getElementById('upload-progress');
  const previewWrap = document.getElementById('preview-container');

  if (!dz || !fileInput || !browseBtn || !progressWrap || !previewWrap) return;

  (function(){
    const visEl = document.getElementById('media-visibility');
    const privOpts = document.getElementById('media-private-options');
    if (visEl && privOpts) {
      visEl.addEventListener('change', function(){
        privOpts.style.display = this.value === 'private' ? '' : 'none';
      });
    }
  })();

  function uiToast(type, title, message, duration) {
    if (window.mediaUi && typeof window.mediaUi.toast === 'function') {
      window.mediaUi.toast(type, title, message, duration);
      return;
    }
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type: type, title: title, message: message, duration: duration });
      return;
    }
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
  }

  function uiAsk(variant, opts) {
    if (window.mediaUi && typeof window.mediaUi.ask === 'function') {
      return window.mediaUi.ask(variant, opts || {});
    }
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
    const local = document.getElementById('media-csrf-token');
    if (local && local.value) return local.value;

    const global = document.getElementById('csrf_token');
    if (global && global.value) return global.value;

    return '';
  }

  browseBtn.onclick = () => fileInput.click();
  fileInput.onchange = e => {
    handleFiles(e.target.files);
    fileInput.value = '';
  };

  ['dragenter','dragover'].forEach(ev =>
    dz.addEventListener(ev, e => {
      e.preventDefault();
      dz.classList.add('drag');
    })
  );
  ['dragleave','drop'].forEach(ev =>
    dz.addEventListener(ev, e => {
      e.preventDefault();
      dz.classList.remove('drag');
    })
  );
  dz.addEventListener('drop', e => handleFiles(e.dataTransfer.files));

  function handleFiles(files) {
    [...files].forEach(uploadFile);
  }

  function addProgressRow(filename) {
    const row = document.createElement('div');
    row.className = 'progress-row';
    row.innerHTML = `
      <div style="font-size:.9rem;font-weight:600">${escapeHtml(filename)}</div>
      <div class="bar-wrap"><div class="bar"></div></div>
    `;
    progressWrap.appendChild(row);
    const bar = row.querySelector('.bar');
    return { row, bar };
  }

  async function uploadFile(file) {
    const { row, bar } = addProgressRow(file.name);

    const fd = new FormData();
    fd.append('image', file);
    fd.append('auto_save', '1');
    fd.append('title', file.name);

    const visEl = document.getElementById('media-visibility');
    const scopeEl = document.getElementById('media-access-scope');
    const dlEl = document.getElementById('media-is-downloadable');
    if (visEl) fd.append('visibility', visEl.value);
    if (scopeEl) fd.append('access_scope', scopeEl.value);
    if (dlEl) fd.append('is_downloadable', dlEl.checked ? '1' : '0');

    const csrf = getCsrfToken();
    if (csrf) fd.append('csrf_token', csrf);

    try {
      const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/upload_image.php', {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        body: fd
      });

      const txt = await res.text();
      let j = null;
      try { j = txt ? JSON.parse(txt) : null; } catch(e) {}

      if (!res.ok) {
        const httpMap = {
          413: '<?=__('File too large. Max 20MB.')?>'
        };
        const msg = j?.error || httpMap[res.status] || txt?.replace(/<[^>]+>/g, '').trim() || ('HTTP ' + res.status);
        uiToast('error', '<?=__('Media')?>', '<?=__('Upload failed: ')?>' + msg);
        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(() => row.remove(), 400);
        }, 1200);
        return;
      }

      if (!j || !j.success) {
        uiToast('error', '<?=__('Media')?>', '<?=__('Upload failed: ')?>' + (j?.error || txt || 'unknown'));
        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(() => row.remove(), 400);
        }, 1200);
        return;
      }

      bar.style.width = '100%';
      showThumb(j.url, j.media);

      uiToast('success', '<?=__('Media')?>', '<?=__('Upload successful: ')?>' + file.name, 1800);
      document.dispatchEvent(new CustomEvent('media:added', { detail: j.media }));

      setTimeout(() => {
        row.classList.add('fade');
        setTimeout(() => {
          if (row.parentNode) row.parentNode.removeChild(row);
        }, 420);
      }, 900);

    } catch (err) {
      uiToast('error', '<?=__('Media')?>', '<?=__('Upload failed (network): ')?>' + (err.message || err));
      setTimeout(() => {
        row.classList.add('fade');
        setTimeout(() => row.remove(), 400);
      }, 1200);
    }
  }

  function showThumb(url, media) {
    const box = document.createElement('div');
    box.className = 'thumb';

    const mediaId = media && media.id ? String(media.id) : '';
    if (mediaId) box.dataset.mediaId = mediaId;

    box.innerHTML = `
      <img src="${escapeHtml(url)}" alt="">
      <div class="meta">
        <div class="thumb-title" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:86px">
          ${escapeHtml((media && (media.title || media.filename)) || '')}
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="edit-btn" type="button">Edit</button>
          <button class="remove-btn" type="button" title="<?=_e('Delete from server')?>">Del</button>
        </div>
      </div>
    `;
    previewWrap.prepend(box);
    requestAnimationFrame(() => box.classList.add('show'));

    const editBtn = box.querySelector('.edit-btn');
    const removeBtn = box.querySelector('.remove-btn');

    if (editBtn) {
      editBtn.onclick = () => {
        const id = media && media.id ? media.id : '';
        const link = "<?= ADMIN_BASE_PATH ?>/admin/media/single.php?id=" + encodeURIComponent(id);
        if (window.adamModalOpen) window.adamModalOpen(link, {maxWidth:'800px'});
        else window.open(link);
      };
    }

    if (removeBtn) {
      removeBtn.onclick = async (e) => {
        e.preventDefault();

        const ok = await uiAsk('danger', {
          title: <?= json_encode(__('Delete media')) ?>,
          message: '<?=__('This media will be permanently deleted from the server. Continue?')?>',
          confirmText: <?= json_encode(__('Yes, delete')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        });
        if (!ok) return;

        const fd = new FormData();
        if (media && media.id) fd.append('id', media.id);
        else fd.append('url', url);

        const csrf = getCsrfToken();
        if (csrf) fd.append('csrf_token', csrf);

        try {
          const res = await fetch('<?= ADMIN_BASE_PATH ?>/admin/media/delete.php', {
            method: 'POST',
            credentials: 'include',
            body: fd
          });

          const txt = await res.text();
          let j = null;
          try { j = txt ? JSON.parse(txt) : null; } catch(e) {}

          if (!res.ok) {
            uiToast('error', '<?=__('Media')?>', '<?=__('Failed to delete: ')?>' + (j?.error || txt || ('HTTP ' + res.status)));
            return;
          }

          if (j && j.ok) {
            box.remove();
            uiToast('success', '<?=__('Media')?>', '<?=__('Media deleted successfully.')?>');
            if (j.warning) {
              uiToast('warning', '<?=__('Media')?>', j.warning);
            }
            document.dispatchEvent(new CustomEvent('media:deleted', { detail: j }));
          } else {
            uiToast('error', '<?=__('Media')?>', '<?=__('Failed to delete: ')?>' + (j?.error || txt || 'unknown'));
          }
        } catch (err) {
          uiToast('error', '<?=__('Media')?>', '<?=__('Network error:')?> ' + (err.message || err));
        }
      };
    }
  }

  function extractDeletedMediaIds(detail) {
    const ids = new Set();

    if (!detail || typeof detail !== 'object') {
      return [];
    }

    if (detail.id != null) {
      const n = parseInt(detail.id, 10);
      if (Number.isFinite(n) && n > 0) ids.add(String(n));
    }

    if (Array.isArray(detail.ids)) {
      detail.ids.forEach(function(id){
        const n = parseInt(id, 10);
        if (Number.isFinite(n) && n > 0) ids.add(String(n));
      });
    }

    if (Array.isArray(detail.deleted_ids)) {
      detail.deleted_ids.forEach(function(id){
        const n = parseInt(id, 10);
        if (Number.isFinite(n) && n > 0) ids.add(String(n));
      });
    }

    if (detail.result && typeof detail.result === 'object') {
      if (detail.result.id != null) {
        const n = parseInt(detail.result.id, 10);
        if (Number.isFinite(n) && n > 0) ids.add(String(n));
      }

      if (Array.isArray(detail.result.deleted_ids)) {
        detail.result.deleted_ids.forEach(function(id){
          const n = parseInt(id, 10);
          if (Number.isFinite(n) && n > 0) ids.add(String(n));
        });
      }
    }

    return Array.from(ids);
  }

  function removePreviewByIds(ids) {
    if (!Array.isArray(ids) || !ids.length) return;

    ids.forEach(function(id){
      const box = previewWrap.querySelector('.thumb[data-media-id="' + CSS.escape(String(id)) + '"]');
      if (!box) return;

      box.classList.add('fade');
      setTimeout(function(){
        if (box.parentNode) box.parentNode.removeChild(box);
      }, 220);
    });
  }

  function syncUpdatedPreview(detail) {
    if (!detail || typeof detail !== 'object' || detail.id == null) return;

    const mediaId = String(detail.id);
    const box = previewWrap.querySelector('.thumb[data-media-id="' + CSS.escape(mediaId) + '"]');
    if (!box) return;

    const titleEl = box.querySelector('.thumb-title');
    if (titleEl && detail.updated && typeof detail.updated === 'object') {
      if (detail.updated.title !== undefined && String(detail.updated.title).trim() !== '') {
        titleEl.textContent = String(detail.updated.title);
      }
    }
  }

  document.addEventListener('media:deleted', function(ev){
    const ids = extractDeletedMediaIds(ev.detail);
    removePreviewByIds(ids);
  });

  document.addEventListener('media:updated', function(ev){
    syncUpdatedPreview(ev.detail);
  });

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, m => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#39;'
    }[m]));
  }
})();
</script>