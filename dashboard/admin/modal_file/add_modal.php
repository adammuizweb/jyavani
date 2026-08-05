<?php
declare(strict_types=1);

// /adiwira/admin/modal_file/add_modal.php
require_once __DIR__ . '/../_guard.php';

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    adiwira_cosmetic_404_on_direct_open();
}

[$uid, $role] = adiwira_require_editorial($pdo, false);

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
      <label class="mdlib-config-label"><?= _e('File storage mode') ?></label>
      <select id="mdlib-visibility-select" class="mdlib-select">
        <option value="auto" selected><?= _e('Auto — public') ?></option>
        <option value="public"><?= _e('Public — direct URL access') ?></option>
        <option value="private"><?= _e('Private internal — via protected URL') ?></option>
      </select>

      <div class="mdlib-private-options" id="mdlib-private-options">
        <label>
          <?= _e('Private access') ?>
          <select id="mdlib-access-scope">
            <option value="editorial" selected><?= _e('Editorial') ?></option>
            <option value="admin"><?= _e('Admin Only') ?></option>
          </select>
        </label>
        <label class="mdlib-checkline">
          <input type="checkbox" id="mdlib-is-downloadable" value="1">
          <?= _e('Allow direct download') ?>
        </label>
      </div>
    </div>
  </div>

  <div class="mdlib-uploader-right">
    <div id="mdlib-dropzone" class="mdlib-dropzone" role="button" tabindex="0">
      <?= _e('Drag file here or click') ?>
      <button id="mdlib-browse-btn" class="mdlib-btn mdlib-btn-primary" type="button"><?= _e('Select file') ?></button>
      <div class="mdlib-note">
        pdf, doc/docx, xls/xlsx, ppt/pptx, zip, mp4, webm, mov, txt, rtf, mp3, wav, ogg
      </div>
    </div>

    <div id="mdlib-upload-progress" class="mdlib-upload-progress" aria-live="polite"></div>
    <div id="mdlib-preview-wrap" class="mdlib-preview-grid" aria-live="polite"></div>

    <div class="mdlib-upload-actions" style="margin-top:8px">
      <button id="mdlib-clear-btn" class="mdlib-btn" type="button" style="display:none;"><?= _e('Clear') ?></button>
    </div>
  </div>
</div>

<input
  id="mdlib-file-input"
  type="file"
  accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.mp4,.webm,.mov,.txt,.rtf,.mp3,.wav,.ogg"
  multiple
  style="display:none"
>

<script>
(function(){
  const uploadUrl = '<?= ADMIN_BASE_PATH ?>/admin/upload_file.php';
  const deleteUrl = '<?= ADMIN_BASE_PATH ?>/admin/modal_file/delete.php';

  const dropzone = document.getElementById('mdlib-dropzone');
  const fileInput = document.getElementById('mdlib-file-input');
  const browseBtn = document.getElementById('mdlib-browse-btn');
  const clearBtn = document.getElementById('mdlib-clear-btn');
  const progressWrap = document.getElementById('mdlib-upload-progress');
  const previewWrap = document.getElementById('mdlib-preview-wrap');
  const accessScopeEl = document.getElementById('mdlib-access-scope');
  const downloadableEl = document.getElementById('mdlib-is-downloadable');

  if (!dropzone || !fileInput || !browseBtn || !clearBtn || !progressWrap || !previewWrap) return;

  function getVisibilityChoice() {
    const el = document.getElementById('mdlib-visibility-select');
    return el ? el.value : 'auto';
  }

  function detectFinalVisibility(file) {
    const choice = getVisibilityChoice();
    if (choice !== 'auto') return choice;
    return 'public';
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

  function uiToast(type, title, message, duration) {
    if (window.mdlibUi && typeof window.mdlibUi.toast === 'function') {
      window.mdlibUi.toast(type, title, message, duration);
      return;
    }
    alert(message || title || <?= json_encode(__('Something happened.')) ?>);
  }

  function uiAsk(variant, opts) {
    if (window.mdlibUi && typeof window.mdlibUi.ask === 'function') {
      return window.mdlibUi.ask(variant, opts || {});
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message: <?= json_encode(__('Proceed with this action?')) ?>));
  }

  function getCsrfToken() {
    if (window.mdlibUi && typeof window.mdlibUi.getCsrfToken === 'function') {
      return window.mdlibUi.getCsrfToken();
    }
    return '';
  }

  function readJsonSafe(txt) {
    if (window.mdlibUi && typeof window.mdlibUi.readJsonSafe === 'function') {
      return window.mdlibUi.readJsonSafe(txt);
    }
    try { return txt ? JSON.parse(txt) : null; }
    catch(e) { return null; }
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
  }

  function broadcast(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: name, detail: detail }, '*');
      }
    } catch(e){}
  }

  function detectKind(fileObj) {
    const mime = String((fileObj && fileObj.mime) || '').toLowerCase();
    if (mime.indexOf('video/') === 0) return 'video';
    if (mime.indexOf('audio/') === 0) return 'audio';
    return 'file';
  }

  function updateClearButton() {
    const hasProgress = progressWrap.children.length > 0;
    const hasPreview = previewWrap.children.length > 0;
    clearBtn.style.display = (hasProgress || hasPreview) ? 'inline-flex' : 'none';
  }

  function addProgressRow(filename, modeLabel) {
    const row = document.createElement('div');
    row.className = 'mdlib-progress-row';
    row.innerHTML = `
      <div class="mdlib-progress-name">${escapeHtml(filename)}${modeLabel ? ' <span class="mdlib-pill">' + escapeHtml(modeLabel) + '</span>' : ''}</div>
      <div class="mdlib-progress-barwrap"><div class="mdlib-progress-bar"></div></div>
    `;
    progressWrap.appendChild(row);
    updateClearButton();

    return {
      row: row,
      bar: row.querySelector('.mdlib-progress-bar')
    };
  }

  function handleFiles(files) {
    if (!files || !files.length) return;
    Array.from(files).forEach(uploadFile);
  }

  function openDetail(id, url) {
    if (id && typeof window.mdlibOpenSingle === 'function') {
      window.mdlibOpenSingle(id);
      return;
    }

    const link = id
      ? '<?= ADMIN_BASE_PATH ?>/admin/modal_file/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1'
      : '<?= ADMIN_BASE_PATH ?>/admin/modal_file/single_modal.php?url=' + encodeURIComponent(url) + '&embedded=1';

    if (typeof window.mdlibLoadIntoRoot === 'function') {
      window.mdlibLoadIntoRoot(link);
      return;
    }

    try {
      if (window.parent && window.parent !== window && typeof window.parent.adamModalOpen === 'function') {
        window.parent.adamModalOpen(link, { maxWidth: '860px' });
        return;
      }
    } catch(e){}

    if (typeof window.adamModalOpen === 'function') {
      window.adamModalOpen(link, { maxWidth: '860px' });
      return;
    }

    window.open(link, '_blank');
  }

  function fileSvgIcon(ext) {
    const e = (ext || '').toLowerCase();
    const doc = '<path d="M17 18a2 2 0 012-2h12l8 8v18a2 2 0 01-2 2H19a2 2 0 01-2-2V18z" fill="#fff" opacity=".9"/><path d="M31 16v8h8M23 30h14M23 35h10" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>';
    if (e === 'pdf') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#ef4444"/>' + doc + '</svg>';
    if (e === 'doc' || e === 'docx') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#3b82f6"/>' + doc + '</svg>';
    if (e === 'xls' || e === 'xlsx') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#22c55e"/><path d="M17 18a2 2 0 012-2h12l8 8v18a2 2 0 01-2 2H19a2 2 0 01-2-2V18z" fill="#fff" opacity=".9"/><path d="M31 16v8h8M22 34l6-6M28 34l-6-6" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>';
    if (e === 'ppt' || e === 'pptx') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#f97316"/>' + doc + '</svg>';
    if (e === 'zip') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#a855f7"/><path d="M17 18a2 2 0 012-2h12l8 8v18a2 2 0 01-2 2H19a2 2 0 01-2-2V18z" fill="#fff" opacity=".9"/><path d="M31 16v8h8M23 30l-2 6h6l-2 6" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    if (e === 'mp4' || e === 'webm' || e === 'mov') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#14b8a6"/><circle cx="29" cy="29" r="11" fill="#fff"/><path d="M25 24l8 5-8 5V24z" fill="#14b8a6"/></svg>';
    if (e === 'mp3' || e === 'wav' || e === 'ogg') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#ec4899"/><path d="M24 20v14a4 4 0 01-4 4v0a4 4 0 01-4-4v-2" stroke="#fff" stroke-width="2" stroke-linecap="round"/><path d="M24 20l12-2v16" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="30" cy="32" r="4" fill="#fff"/></svg>';
    if (e === 'txt' || e === 'rtf') return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#64748b"/>' + doc + '</svg>';
    return '<svg viewBox="0 0 58 58" fill="none"><rect width="58" height="58" rx="16" fill="#2563eb"/>' + doc + '</svg>';
  }

  function previewCardIconHtml(fileObj) {
    const kind = detectKind(fileObj);
    let ext = String((fileObj && fileObj.ext) || '').toLowerCase();
    if (!ext) {
      const fn = String((fileObj && (fileObj.filename || fileObj.name)) || '');
      const p = fn.lastIndexOf('.');
      if (p > 0) ext = fn.substring(p + 1).toLowerCase();
    }
    if (kind === 'video') ext = 'mp4';
    if (kind === 'audio') ext = 'mp3';
    return fileSvgIcon(ext);
  }

  function showPreview(url, fileObj) {
    const box = document.createElement('div');
    box.className = 'mdlib-preview-card';

    if (fileObj && fileObj.id) {
      box.dataset.fileId = String(fileObj.id);
    }

    const title = (fileObj && (fileObj.title || fileObj.filename)) || '';
    const mime = (fileObj && fileObj.mime) || '';
    const size = (fileObj && (fileObj.size_label || fileObj.size)) || '';
    const visibility = (fileObj && fileObj.visibility) || 'public';
    const scope = (fileObj && fileObj.access_scope) || 'public';

    box.innerHTML = `
      <div class="mdlib-preview-ico">${previewCardIconHtml(fileObj)}</div>
      <div class="mdlib-preview-meta">
        <div class="mdlib-preview-title" title="${escapeHtml(title)}">${escapeHtml(title)}</div>
        <div class="mdlib-preview-sub">${escapeHtml(mime)}${size ? ' • ' + escapeHtml(size) : ''}</div>
        <div class="mdlib-badges">
          <span class="mdlib-pill mdlib-pill-${escapeHtml(visibility)}">${escapeHtml(visibility.toUpperCase())}</span>
          <span class="mdlib-pill">${escapeHtml(scope.toUpperCase())}</span>
        </div>
        <div class="mdlib-preview-actions">
          <button class="mdlib-btn mdlib-btn-primary" type="button" data-action="edit">Edit</button>
          <button class="mdlib-btn mdlib-btn-danger" type="button" data-action="delete">Del</button>
        </div>
      </div>
    `;

    previewWrap.prepend(box);
    requestAnimationFrame(function(){ box.classList.add('mdlib-is-show'); });
    updateClearButton();

    const editBtn = box.querySelector('[data-action="edit"]');
    const deleteBtn = box.querySelector('[data-action="delete"]');

    if (editBtn) {
      editBtn.addEventListener('click', function(){
        openDetail(fileObj && fileObj.id ? fileObj.id : null, url);
      });
    }

    if (deleteBtn) {
      deleteBtn.addEventListener('click', async function(){
        const ok = await uiAsk('danger', {
          title: <?= json_encode(__('Delete file')) ?>,
          message: <?= json_encode(__('This file will be permanently deleted. Proceed?')) ?>,
          confirmText: <?= json_encode(__('Yes, delete')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        });
        if (!ok) return;

        const fd = new FormData();
        if (fileObj && fileObj.id) fd.append('id', String(fileObj.id));
        else fd.append('url', String(url || ''));

        const csrf = getCsrfToken();
        if (csrf) fd.append('csrf_token', csrf);

        try {
          const res = await fetch(deleteUrl, {
            method: 'POST',
            credentials: 'include',
            body: fd
          });

          const txt = await res.text();
          const j = readJsonSafe(txt);

          if (!res.ok) {
            uiToast('error', '<?=__('Library File')?>', '<?=__('Failed to delete file: ')?>' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
            return;
          }

          if (j && j.ok) {
            box.remove();
            updateClearButton();
            uiToast('success', '<?=__('Library File')?>', '<?=__('File deleted successfully.')?>', 2200);
            if (j.warning) {
              uiToast('warning', '<?=__('Library File')?>', j.warning, 6000);
            }

            const payload = Object.assign({}, j || {}, {
              deleted_urls: (j && Array.isArray(j.deleted_urls))
                ? j.deleted_urls
                : (url ? [String(url)] : [])
            });

            broadcast('file:deleted', payload);
          } else {
            uiToast('error', '<?=__('Library File')?>', '<?=__('Failed to delete file: ')?>' + ((j && j.error) ? j.error : 'unknown'), 6000);
          }
        } catch (err) {
          uiToast('error', '<?=__('Library File')?>', '<?=__('Network error:')?> ' + (err && err.message ? err.message : err), 6000);
        }
      });
    }
  }

  async function uploadFile(file) {
    const finalVisibility = detectFinalVisibility(file);
    const modeLabel = finalVisibility === 'private' ? 'PRIVATE' : 'PUBLIC';
    const progress = addProgressRow(file.name, modeLabel);
    const fd = new FormData();

    fd.append('file', file);
    fd.append('auto_save', '1');
    fd.append('title', file.name);
    fd.append('visibility', getVisibilityChoice());
    if (accessScopeEl) fd.append('access_scope', accessScopeEl.value);
    if (downloadableEl) fd.append('is_downloadable', downloadableEl && downloadableEl.checked ? '1' : '0');

    const csrf = getCsrfToken();
    if (csrf) fd.append('csrf_token', csrf);

    try {
      const res = await fetch(uploadUrl, {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        body: fd
      });

      const txt = await res.text();
      const j = readJsonSafe(txt);

      if (!res.ok) {
        uiToast('error', '<?=__('Library File')?>', '<?=__('Upload failed: ')?>' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
        progress.row.remove();
        updateClearButton();
        return;
      }

      if (!j || !j.success) {
        uiToast('error', '<?=__('Library File')?>', '<?=__('Upload failed: ')?>' + ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        progress.row.remove();
        updateClearButton();
        return;
      }

      progress.bar.style.width = '100%';

      const fileMeta = j.file || {
        id: null,
        title: file.name,
        filename: file.name,
        mime: file.type || '',
        ext: (file.name.split('.').pop() || '').toLowerCase(),
        visibility: j.visibility || finalVisibility,
        access_scope: j.access_scope || 'public',
        storage_disk: j.storage_disk || finalVisibility
      };

      showPreview(j.url || fileMeta.url || '', fileMeta);
      broadcast('file:added', fileMeta);
      uiToast('success', '<?=__('Library File')?>', '<?=__('Upload successful: ')?>' + file.name, 1800);

      setTimeout(function(){
        if (progress.row.parentNode) {
          progress.row.parentNode.removeChild(progress.row);
        }
        updateClearButton();
      }, 700);
    } catch (err) {
      uiToast('error', '<?=__('Library File')?>', '<?=__('Upload failed (network): ')?>' + (err && err.message ? err.message : err), 6000);
      if (progress.row.parentNode) {
        progress.row.parentNode.removeChild(progress.row);
      }
      updateClearButton();
    }
  }

  browseBtn.addEventListener('click', function(){
    fileInput.click();
  });

  fileInput.addEventListener('change', function(e){
    handleFiles(e.target.files);
    fileInput.value = '';
  });

  ['dragenter', 'dragover'].forEach(function(ev){
    dropzone.addEventListener(ev, function(e){
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.add('is-drag');
    });
  });

  ['dragleave', 'drop'].forEach(function(ev){
    dropzone.addEventListener(ev, function(e){
      e.preventDefault();
      e.stopPropagation();
      dropzone.classList.remove('is-drag');
    });
  });

  dropzone.addEventListener('drop', function(e){
    const dt = e.dataTransfer;
    if (dt && dt.files) handleFiles(dt.files);
  });

  dropzone.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      fileInput.click();
    }
  });

  clearBtn.addEventListener('click', function(){
    progressWrap.innerHTML = '';
    previewWrap.innerHTML = '';
    fileInput.value = '';
    updateClearButton();
  });

  document.addEventListener('file:deleted', function(e){
    const d = e && e.detail ? e.detail : null;
    if (!d) return;

    let ids = [];
    if (Array.isArray(d)) ids = d.map(function(x){ return parseInt(x, 10); }).filter(Boolean);
    else if (d.deleted_ids && Array.isArray(d.deleted_ids)) ids = d.deleted_ids.map(function(x){ return parseInt(x, 10); }).filter(Boolean);
    else if (d.id) ids = [parseInt(d.id, 10)].filter(Boolean);

    ids.forEach(function(id){
      const thumb = previewWrap.querySelector('.mdlib-preview-card[data-file-id="' + id + '"]');
      if (thumb) thumb.remove();
    });

    updateClearButton();
  });

  document.addEventListener('file:updated', function(e){
    const d = e && e.detail ? e.detail : null;
    const file = d && d.file ? d.file : d;
    if (!file || !file.id) return;

    const thumb = previewWrap.querySelector('.mdlib-preview-card[data-file-id="' + file.id + '"]');
    if (!thumb) return;

    const titleEl = thumb.querySelector('.mdlib-preview-title');
    if (titleEl) {
      const newTitle = file.title || file.filename || titleEl.textContent;
      titleEl.textContent = newTitle;
      titleEl.setAttribute('title', newTitle);
    }
  });

  updateClearButton();
})();
</script>
