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

<div class="modalfilez-uploader">
  <div class="modalfilez-upload-top">
    <div id="modalfilez-dropzone" class="modalfilez-dropzone" role="button" tabindex="0">
      Tarik file ke sini atau klik
      <button id="modalfilez-browse-btn" class="modalfilez-btn modalfilez-btn-primary" type="button">Pilih file</button>
      <div class="modalfilez-note">
        Mendukung pdf, doc/docx, xls/xlsx, ppt/pptx, zip, mp4, webm, mov, txt, rtf, mp3, wav, ogg.
      </div>
    </div>

    <div class="modalfilez-upload-actions">
      <button id="modalfilez-clear-btn" class="modalfilez-btn" type="button" style="display:none;">Bersihkan</button>
    </div>
  </div>

  <div id="modalfilez-upload-progress" class="modalfilez-upload-progress" aria-live="polite"></div>
  <div id="modalfilez-preview-wrap" class="modalfilez-preview-grid" aria-live="polite"></div>
</div>

<input
  id="modalfilez-file-input"
  type="file"
  accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.mp4,.webm,.mov,.txt,.rtf,.mp3,.wav,.ogg"
  multiple
  style="display:none"
>

<script>
(function(){
  const uploadUrl = '/adiwira/admin/upload_file.php';
  const deleteUrl = '/adiwira/admin/modal_file/delete.php';

  const dropzone = document.getElementById('modalfilez-dropzone');
  const fileInput = document.getElementById('modalfilez-file-input');
  const browseBtn = document.getElementById('modalfilez-browse-btn');
  const clearBtn = document.getElementById('modalfilez-clear-btn');
  const progressWrap = document.getElementById('modalfilez-upload-progress');
  const previewWrap = document.getElementById('modalfilez-preview-wrap');

  if (!dropzone || !fileInput || !browseBtn || !clearBtn || !progressWrap || !previewWrap) return;

  function uiToast(type, title, message, duration) {
    if (window.modalfilezUi && typeof window.modalfilezUi.toast === 'function') {
      window.modalfilezUi.toast(type, title, message, duration);
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function uiAsk(variant, opts) {
    if (window.modalfilezUi && typeof window.modalfilezUi.ask === 'function') {
      return window.modalfilezUi.ask(variant, opts || {});
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message : 'Lanjutkan aksi ini?'));
  }

  function getCsrfToken() {
    if (window.modalfilezUi && typeof window.modalfilezUi.getCsrfToken === 'function') {
      return window.modalfilezUi.getCsrfToken();
    }
    return '';
  }

  function readJsonSafe(txt) {
    if (window.modalfilezUi && typeof window.modalfilezUi.readJsonSafe === 'function') {
      return window.modalfilezUi.readJsonSafe(txt);
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

  function addProgressRow(filename) {
    const row = document.createElement('div');
    row.className = 'modalfilez-progress-row';
    row.innerHTML = `
      <div class="modalfilez-progress-name">${escapeHtml(filename)}</div>
      <div class="modalfilez-progress-barwrap"><div class="modalfilez-progress-bar"></div></div>
    `;
    progressWrap.appendChild(row);
    updateClearButton();

    return {
      row: row,
      bar: row.querySelector('.modalfilez-progress-bar')
    };
  }

  function handleFiles(files) {
    if (!files || !files.length) return;
    Array.from(files).forEach(uploadFile);
  }

  function openDetail(id, url) {
    if (id && typeof window.modalfilezOpenSingle === 'function') {
      window.modalfilezOpenSingle(id);
      return;
    }

    const link = id
      ? '/adiwira/admin/modal_file/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1'
      : '/adiwira/admin/modal_file/single_modal.php?url=' + encodeURIComponent(url) + '&embedded=1';

    if (typeof window.modalfilezLoadIntoRoot === 'function') {
      window.modalfilezLoadIntoRoot(link);
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

  function previewCardText(fileObj) {
    const kind = detectKind(fileObj);
    const ext = String((fileObj && fileObj.ext) || '').toUpperCase() || 'FILE';

    if (kind === 'video') return 'VIDEO';
    if (kind === 'audio') return 'AUDIO';
    return ext.substring(0, 4);
  }

  function showPreview(url, fileObj) {
    const box = document.createElement('div');
    box.className = 'modalfilez-preview-card';

    if (fileObj && fileObj.id) {
      box.dataset.fileId = String(fileObj.id);
    }

    const title = (fileObj && (fileObj.title || fileObj.filename)) || '';
    const mime = (fileObj && fileObj.mime) || '';
    const size = (fileObj && fileObj.size_label) || '';

    box.innerHTML = `
      <div class="modalfilez-preview-ico">${escapeHtml(previewCardText(fileObj))}</div>
      <div class="modalfilez-preview-meta">
        <div class="modalfilez-preview-title" title="${escapeHtml(title)}">${escapeHtml(title)}</div>
        <div class="modalfilez-preview-sub">${escapeHtml(mime)}${size ? ' • ' + escapeHtml(size) : ''}</div>
        <div class="modalfilez-preview-actions">
          <button class="modalfilez-btn modalfilez-btn-primary" type="button" data-action="edit">Edit</button>
          <button class="modalfilez-btn modalfilez-btn-danger" type="button" data-action="delete">Del</button>
        </div>
      </div>
    `;

    previewWrap.prepend(box);
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
          title: 'Hapus file',
          message: 'File ini akan dihapus permanen. Lanjutkan?',
          confirmText: 'Ya, hapus',
          cancelText: 'Batal'
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
            uiToast('error', 'Library File', 'Gagal hapus file: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
            return;
          }

          if (j && j.ok) {
            box.remove();
            updateClearButton();
            uiToast('success', 'Library File', 'File berhasil dihapus.', 2200);
            if (j.warning) {
              uiToast('warning', 'Library File', j.warning, 6000);
            }

            const payload = Object.assign({}, j || {}, {
              deleted_urls: (j && Array.isArray(j.deleted_urls))
                ? j.deleted_urls
                : (url ? [String(url)] : [])
            });

            broadcast('file:deleted', payload);
          } else {
            uiToast('error', 'Library File', 'Gagal hapus file: ' + ((j && j.error) ? j.error : 'unknown'), 6000);
          }
        } catch (err) {
          uiToast('error', 'Library File', 'Network error: ' + (err && err.message ? err.message : err), 6000);
        }
      });
    }
  }

  async function uploadFile(file) {
    const progress = addProgressRow(file.name);
    const fd = new FormData();

    fd.append('file', file);
    fd.append('auto_save', '1');
    fd.append('title', file.name);

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
        uiToast('error', 'Library File', 'Upload gagal: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
        progress.row.remove();
        updateClearButton();
        return;
      }

      if (!j || !j.success) {
        uiToast('error', 'Library File', 'Upload gagal: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
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
        ext: (file.name.split('.').pop() || '').toLowerCase()
      };

      showPreview(j.url || '', fileMeta);
      broadcast('file:added', fileMeta);
      uiToast('success', 'Library File', 'Upload berhasil: ' + file.name, 1800);

      setTimeout(function(){
        if (progress.row.parentNode) {
          progress.row.parentNode.removeChild(progress.row);
        }
        updateClearButton();
      }, 700);
    } catch (err) {
      uiToast('error', 'Library File', 'Upload gagal (network): ' + (err && err.message ? err.message : err), 6000);
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
      const thumb = previewWrap.querySelector('.modalfilez-preview-card[data-file-id="' + id + '"]');
      if (thumb) thumb.remove();
    });

    updateClearButton();
  });

  document.addEventListener('file:updated', function(e){
    const d = e && e.detail ? e.detail : null;
    const file = d && d.file ? d.file : d;
    if (!file || !file.id) return;

    const thumb = previewWrap.querySelector('.modalfilez-preview-card[data-file-id="' + file.id + '"]');
    if (!thumb) return;

    const titleEl = thumb.querySelector('.modalfilez-preview-title');
    if (titleEl) {
      const newTitle = file.title || file.filename || titleEl.textContent;
      titleEl.textContent = newTitle;
      titleEl.setAttribute('title', newTitle);
    }
  });

  updateClearButton();
})();
</script>