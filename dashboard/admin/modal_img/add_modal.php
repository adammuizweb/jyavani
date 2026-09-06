<?php
declare(strict_types=1);

// /adiwira/admin/modal_img/add_modal.php
require_once __DIR__ . '/../_guard.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    if (function_exists('adiwira_is_navigate_request') && adiwira_is_navigate_request()) {
        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }

    [$uid, $role] = adiwira_require_editorial($pdo, false);
}

$csrfToken = '';
try {
    if (function_exists('csrf_token')) {
        $csrfToken = (string) csrf_token();
    }
} catch (Throwable $e) {
    $csrfToken = '';
}

?>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

<div class="mdlib-uploader mdlib-uploader--split">
  <div class="mdlib-uploader-left">
    <div class="mdlib-upload-config">
      <label class="mdlib-config-label"><?= _e('Media storage mode') ?></label>
      <select id="mdlib-visibility-select" class="mdlib-select">
        <option value="auto" selected><?= _e('Auto — public image') ?></option>
        <option value="public"><?= _e('Public — direct URL access') ?></option>
        <option value="private"><?= _e('Private internal — via protected URL') ?></option>
      </select>

      <div class="mdlib-private-options" id="mdlib-private-options">
        <label>
          <?= _e('Private access') ?>
          <select id="mdlib-access-scope">
            <option value="editorial" selected><?= _e('Content Team') ?></option>
            <option value="admin"><?= _e('Administrator') ?></option>
          </select>
        </label>
        <label class="mdlib-checkline">
          <input type="checkbox" id="mdlib-is-downloadable" value="1" checked>
          <?= _e('Allow direct download') ?>
        </label>
      </div>
    </div>
  </div>

  <div class="mdlib-uploader-right">
    <div id="mdlib-dropzone" class="mdlib-dropzone" role="button" tabindex="0">
      <?= _e('Drag image here or click') ?>
      <button id="mdlib-browse-btn" class="mdlib-btn mdlib-btn-primary" type="button"><?= _e('Select file') ?></button>
      <div class="mdlib-note"><?= _e('Supports webp/png/jpg/avif.') ?></div>
    </div>

    <div id="mdlib-upload-progress" class="mdlib-upload-progress" aria-live="polite"></div>
    <div id="mdlib-preview-container" class="mdlib-preview-grid" aria-live="polite"></div>
  </div>
</div>

<input id="mdlib-file-input" type="file" accept="image/webp,image/png,image/jpg,image/jpeg" multiple style="display:none">

<script>
(function(){
  const uploadUrl = '<?= ADMIN_BASE_PATH ?>/admin/upload_image.php';
  const deleteUrl = '<?= ADMIN_BASE_PATH ?>/admin/media/delete.php';

  const dropzone = document.getElementById('mdlib-dropzone');
  const fileInput = document.getElementById('mdlib-file-input');
  const browseBtn = document.getElementById('mdlib-browse-btn');
  const progressWrap = document.getElementById('mdlib-upload-progress');
  const previewWrap = document.getElementById('mdlib-preview-container');
  const accessScopeEl = document.getElementById('mdlib-access-scope');
  const downloadableEl = document.getElementById('mdlib-is-downloadable');

  if (!dropzone || !fileInput || !browseBtn || !progressWrap || !previewWrap) return;

  function getVisibilityChoice() {
    const el = document.getElementById('mdlib-visibility-select');
    return el ? el.value : 'auto';
  }

  function updatePrivateOptions() {
    const choice = getVisibilityChoice();
    const box = document.getElementById('mdlib-private-options');
    if (!box) return;
    box.style.display = choice === 'private' ? '' : 'none';
  }

  const visSelect = document.getElementById('mdlib-visibility-select');
  if (visSelect) visSelect.addEventListener('change', updatePrivateOptions);
  updatePrivateOptions();

  function getToastApi(){
    try {
      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') return window.NewNotifToast;
      if (window.parent && window.parent !== window && window.parent.NewNotifToast && typeof window.parent.NewNotifToast.show === 'function') return window.parent.NewNotifToast;
    } catch(e){}
    return null;
  }

  function getConfirmApi(){
    try {
      if (window.NewNotifConfirm) return window.NewNotifConfirm;
      if (window.parent && window.parent !== window && window.parent.NewNotifConfirm) return window.parent.NewNotifConfirm;
    } catch(e){}
    return null;
  }

  function uiToast(type, title, message, duration, action){
    const api = getToastApi();
    if (api) {
      api.show({
        type: type || 'info',
        title: title || null,
        message: message || '',
        duration: duration,
        action: action || null
      });
      return;
    }
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
  }

  function uiAsk(variant, opts){
    const api = getConfirmApi();
    if (api) {
      if (variant === 'danger' && typeof api.danger === 'function') return api.danger(opts || {});
      if (typeof api.warning === 'function') return api.warning(opts || {});
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message: <?= json_encode(__('Proceed with this action?')) ?>));
  }

  browseBtn.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', e => {
    handleFiles(e.target.files);
    fileInput.value = '';
  });

  ['dragenter','dragover'].forEach(ev => {
    dropzone.addEventListener(ev, e => {
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.add('drag');
    });
  });

  ['dragleave','drop'].forEach(ev => {
    dropzone.addEventListener(ev, e => {
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.remove('drag');
    });
  });

  dropzone.addEventListener('drop', e => {
    const dt = e.dataTransfer;
    if (!dt) return;
    handleFiles(dt.files);
  });

  dropzone.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      fileInput.click();
    }
  });

  function broadcast(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: name, detail }, '*');
      }
    } catch(e){}
  }

  function findCsrfToken() {
    let t = null;
    try {
      const el = document.querySelector('input[name="csrf_token"]');
      if (el && el.value) t = el.value;
    } catch(e){}
    if (!t) {
      try {
        const pel = window.parent && window.parent.document
          ? window.parent.document.querySelector('input[name="csrf_token"], #csrf_token')
          : null;
        if (pel && pel.value) t = pel.value;
        if (pel && pel.textContent && !t) t = pel.textContent;
      } catch(e){}
    }
    return t;
  }

  function handleFiles(files) {
    if (!files || files.length === 0) return;
    Array.from(files).forEach(f => {
      if (!f.type || !/^image\//.test(f.type)) return;
      uploadFile(f);
    });
  }

  function addProgressRow(filename, modeLabel) {
    const row = document.createElement('div');
    row.className = 'mdlib-progress-row';
    row.innerHTML = `
      <div class="mdlib-progress-name">${escapeHtml(filename)}${modeLabel ? ' <span class="mdlib-pill">' + escapeHtml(modeLabel) + '</span>' : ''}</div>
      <div class="mdlib-barwrap"><div class="mdlib-bar"></div></div>
    `;
    progressWrap.appendChild(row);
    const bar = row.querySelector('.mdlib-bar');
    return { row, bar };
  }

  function uploadFile(file) {
    const visibility = getVisibilityChoice();
    const modeLabel = visibility === 'private' ? 'PRIVATE' : 'PUBLIC';
    const { row, bar } = addProgressRow(file.name, modeLabel);

    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const fd = new FormData();

      fd.append('image', file);
      fd.append('auto_save', '1');
      fd.append('title', file.name);
      fd.append('visibility', visibility);
      if (accessScopeEl) fd.append('access_scope', accessScopeEl.value);
      if (downloadableEl) fd.append('is_downloadable', downloadableEl && downloadableEl.checked ? '1' : '0');

      const token = findCsrfToken();
      if (token) fd.append('csrf_token', token);

      xhr.open('POST', uploadUrl, true);
      xhr.withCredentials = true;

      xhr.upload.addEventListener('progress', function(e){
        if (!e.lengthComputable) return;
        const pct = Math.round((e.loaded / e.total) * 100);
        bar.style.width = pct + '%';
      });

      xhr.addEventListener('readystatechange', function(){
        if (xhr.readyState !== 4) return;

        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(() => {
            if (row.parentNode) row.parentNode.removeChild(row);
          }, 420);
        }, 800);

        let responseData = null;
        try {
          responseData = xhr.responseText ? JSON.parse(xhr.responseText) : null;
        } catch (e) {}

        if (xhr.status >= 200 && xhr.status < 300) {
          const data = responseData;

          if (!data || (!data.success && !data.url && !data.media)) {
            reject(new Error(data && data.error ? data.error : 'Upload failed'));
            return;
          }

          const media = data.media || {
            id: data.id || null,
            url: data.url || '',
            title: data.title || '',
            alt: data.alt || '',
            caption: data.caption || '',
            credit: data.credit || '',
            visibility: data.visibility || 'public',
            storage_disk: data.storage_disk || 'public',
            access_scope: data.access_scope || 'public',
            is_downloadable: data.is_downloadable != null ? data.is_downloadable : '1'
          };

          bar.style.width = '100%';
          showThumb(media.url || data.url || '', media);

          uiToast('success', '<?=__('Gallery')?>', '<?=__('Upload successful: ')?>' + file.name, 1800);
          broadcast('media:added', media);
          broadcast('media:insert', media);
          resolve(media);
        } else {
          const httpMap = {
            413: 'File terlalu besar. Maksimal 20MB.',
            500: 'Server error' + (responseData?.error ? ': ' + responseData.error : '')
          };
          const errMsg = (responseData && responseData.error)
            ? responseData.error
            : (httpMap[xhr.status] || ('HTTP ' + xhr.status));
          reject(new Error(errMsg));
        }
      });

      xhr.addEventListener('error', function(){
        reject(new Error('Network error'));
      });

      xhr.send(fd);
    }).catch(err => {
      console.error('Upload error', err);
      uiToast('error', '<?=__('Gallery')?>', '<?=__('Upload failed: ')?>' + (err.message || '<?=__('Unknown')?>'), 5000);
    });
  }

  function showThumb(url, media) {
    const box = document.createElement('div');
    box.className = 'mdlib-preview-card';
    if (media && media.id) box.dataset.mediaId = String(media.id);
    if (url) box.dataset.mediaUrl = String(url);

    const visibility = (media && media.visibility) || 'public';
    const scope = (media && media.access_scope) || 'public';

    box.innerHTML = `
      <img src="${escapeHtml(url)}" alt="${escapeHtml(media && (media.alt || media.title) || '')}">
      <div class="mdlib-preview-meta">
        <div class="mdlib-preview-title">${escapeHtml(media && (media.title || media.filename || '') || '')}</div>
        <div class="mdlib-badges">
          <span class="mdlib-pill mdlib-pill-${escapeHtml(visibility)}">${escapeHtml(visibility.toUpperCase())}</span>
          <span class="mdlib-pill">${escapeHtml(scope.toUpperCase())}</span>
        </div>
        <div class="mdlib-preview-actions">
          <button class="mdlib-btn-edit" type="button">Edit</button>
          <button class="mdlib-btn-danger" type="button">Del</button>
        </div>
      </div>
    `;

    previewWrap.prepend(box);
    requestAnimationFrame(() => box.classList.add('mdlib-is-show'));

    box.querySelector('.mdlib-btn-edit').addEventListener('click', function(){
      const id = box.dataset.mediaId || (media && media.id);
      if (!id) return;

      const link = '<?= ADMIN_BASE_PATH ?>/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1';

      try {
        if (window.parent && window.parent !== window && typeof window.parent.adamModalOpen === 'function') {
          window.parent.adamModalOpen(link, { maxWidth: '820px' });
          return;
        }
      } catch(e){}

      if (typeof window.adamModalOpen === 'function') {
        try {
          window.adamModalOpen(link, { maxWidth: '820px' });
          return;
        } catch(e){}
      }

      window.open(link, '_blank');
    });

    box.querySelector('.mdlib-btn-danger').addEventListener('click', async function(){
      const ok = await uiAsk('danger', {
        title: <?= json_encode(__('Move media to trash')) ?>,
        message: '<?=__('This media will be moved to trash. Continue?')?>',
        confirmText: <?= json_encode(__('Yes, move to trash')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>
      });
      if (!ok) return;

      const fd = new FormData();
      if (media && media.id) fd.append('id', media.id);
      else fd.append('url', url);

      const token = findCsrfToken();
      if (token) fd.append('csrf_token', token);

      try {
        const res = await fetch(deleteUrl, {
          method: 'POST',
          credentials: 'include',
          body: fd
        });

        const txt = await res.text();
        let j = null;
        try { j = txt ? JSON.parse(txt) : null; } catch(e){}

        if (!res.ok) {
          uiToast('error', '<?=__('Gallery')?>', '<?=__('Failed to delete: ')?>' + (j && j.error ? j.error : (txt || ('HTTP ' + res.status))), 6000);
          return;
        }

        if (j && j.ok) {
          box.remove();

          const payload = Object.assign({}, j || {}, {
            id: media && media.id ? media.id : (j && j.id ? j.id : null),
            url: url || '',
            deleted_ids: (j && Array.isArray(j.deleted_ids)) ? j.deleted_ids : (media && media.id ? [media.id] : []),
            deleted_urls: url ? [url] : []
          });

          uiToast('success', '<?=__('Gallery')?>', '<?=__('Media moved to trash.')?>', undefined, j.action);
          if (j.warning) uiToast('warning', '<?=__('Gallery')?>', j.warning, 6000);

          broadcast('media:deleted', payload);
        } else {
          uiToast('error', '<?=__('Gallery')?>', '<?=__('Failed to delete: ')?>' + (j && j.error ? j.error : '<?=__('Unknown')?>'), 6000);
        }
      } catch (err) {
        console.error('Delete error', err);
        uiToast('error', '<?=__('Gallery')?>', '<?=__('Network error:')?> ' + (err.message || ''), 6000);
      }
    });
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function(m){
      return {
        '&':'&amp;',
        '<':'&lt;',
        '>':'&gt;',
        '"':'&quot;',
        "'":'&#39;'
      }[m];
    });
  }

  document.addEventListener('media:deleted', function(e){
    const d = (e && e.detail) ? e.detail : null;
    if (!d) return;

    let ids = [];
    if (Array.isArray(d)) ids = d.map(x => parseInt(x, 10)).filter(Boolean);
    else if (d.deleted_ids && Array.isArray(d.deleted_ids)) ids = d.deleted_ids.map(x => parseInt(x, 10)).filter(Boolean);
    else if (d.id) ids = [parseInt(d.id, 10)].filter(Boolean);
    else if (d.ids && Array.isArray(d.ids)) ids = d.ids.map(x => parseInt(x, 10)).filter(Boolean);

    ids.forEach(id => {
      const th = previewWrap.querySelector('.mdlib-preview-card[data-media-id="' + id + '"]');
      if (th) th.remove();
    });

    if (Array.isArray(d.deleted_urls)) {
      d.deleted_urls.forEach(function(u){
        const th = previewWrap.querySelector('.mdlib-preview-card[data-media-url="' + CSS.escape(String(u)) + '"]');
        if (th) th.remove();
      });
    }
  });
})();
</script>
