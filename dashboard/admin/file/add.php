<?php
declare(strict_types=1);

// /adiwira/admin/file/add.php
require_once __DIR__ . '/../_guard.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    if (adiwira_is_navigate_request()) {
        http_response_code(404);
        require FRONTEND_404_PATH;
        exit;
    }

    adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);
}

$fileCsrf = csrf_token();
if (!function_exists('mdlib_has_column')) {
    function mdlib_has_column(string $col): bool {
        try {
            $st = $GLOBALS['pdo']->prepare("SELECT {$col} FROM `file` LIMIT 0");
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
  <input type="hidden" id="file-csrf-token" value="<?= htmlspecialchars($fileCsrf, ENT_QUOTES, 'UTF-8') ?>">

  <?php if ($hasVisibility): ?>
  <div style="margin-bottom:8px;display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;padding:4px 0">
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:2px">Visibility</label>
      <select id="file-visibility" style="padding:3px 6px;font-size:12px">
        <option value="auto">Auto</option>
        <option value="public">Public</option>
        <option value="private">Private</option>
      </select>
    </div>
    <div id="file-private-options" style="display:none">
      <div style="display:inline-block;margin-right:8px">
        <label style="font-size:11px;font-weight:600;display:block;margin-bottom:2px">Access Scope</label>
        <select id="file-access-scope" style="padding:3px 6px;font-size:12px">
          <option value="editorial">Editorial</option>
          <option value="admin">Admin Only</option>
        </select>
      </div>
      <div style="display:inline-block">
        <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:11px;font-weight:600">
          <input type="checkbox" id="file-is-downloadable" value="1" checked>
          Downloadable
        </label>
      </div>
    </div>
    <div style="align-self:flex-end" class="small" style="color:#888">Private files stored outside public_html</div>
  </div>
  <?php endif; ?>

  <div class="dropzone" id="dropzone">
    <p>Tarik file (dokumen / excel / powerpoint / video / audio) ke sini atau
      <button class="adam-btn" id="browse-btn" type="button"><?= _e('Select file') ?></button>
    </p>
    <div id="upload-progress" class="upload-progress"></div>
    <div class="small" style="margin-top:8px">
      Maks ukuran per file: 30MB.
      Tipe: pdf, doc/docx, xls/xlsx, ppt/pptx, zip, mp4, webm, mov, txt, rtf, mp3, wav, ogg
    </div>
  </div>

  <div id="preview-container" class="preview-grid"></div>
</section>

<input
  type="file"
  id="file-input"
  accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.mp4,.webm,.mov,.txt,.rtf,.mp3,.wav,.ogg"
  multiple
  hidden
>

<script>
(() => {
  const dz = document.getElementById('dropzone');
  const fileInput = document.getElementById('file-input');
  const browseBtn = document.getElementById('browse-btn');
  const progressWrap = document.getElementById('upload-progress');
  const previewWrap = document.getElementById('preview-container');

  if (!dz || !fileInput || !browseBtn || !progressWrap || !previewWrap) return;

  (function(){
    const visEl = document.getElementById('file-visibility');
    const privOpts = document.getElementById('file-private-options');
    if (visEl && privOpts) {
      visEl.addEventListener('change', function(){
        privOpts.style.display = this.value === 'private' ? '' : 'none';
      });
    }
  })();

  function uiToast(type, title, message, duration) {
    if (window.fileUi && typeof window.fileUi.toast === 'function') {
      window.fileUi.toast(type, title, message, duration);
      return;
    }
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({ type, title, message, duration });
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function uiAsk(variant, opts) {
    if (window.fileUi && typeof window.fileUi.ask === 'function') {
      return window.fileUi.ask(variant, opts || {});
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

  function getCsrf() {
    const local = document.getElementById('file-csrf-token');
    if (local && local.value) return local.value;

    const global = document.getElementById('csrf_token');
    if (global && global.value) return global.value;

    return '';
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, m => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#39;'
    }[m]));
  }

  async function readJsonSafe(res) {
    if (window.fileUi && typeof window.fileUi.readJsonSafe === 'function') {
      return window.fileUi.readJsonSafe(res);
    }
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
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
    Array.from(files || []).forEach(uploadFile);
  }

  function addProgressRow(filename) {
    const row = document.createElement('div');
    row.className = 'progress-row';
    row.innerHTML = `
      <div style="font-size:.9rem;font-weight:600;min-width:140px">${escapeHtml(filename)}</div>
      <div class="bar-wrap"><div class="bar"></div></div>
    `;
    progressWrap.appendChild(row);
    const bar = row.querySelector('.bar');
    return { row, bar };
  }

  function detectKind(fileObj) {
    const mime = (fileObj && fileObj.mime) ? String(fileObj.mime) : '';
    if (mime.indexOf('video/') === 0) return 'video';
    if (mime.indexOf('audio/') === 0) return 'audio';
    return 'file';
  }

  async function uploadFile(file) {
    const { row, bar } = addProgressRow(file.name);

    const fd = new FormData();
    fd.append('file', file);
    fd.append('auto_save', '1');
    fd.append('title', file.name);

    const visEl = document.getElementById('file-visibility');
    const scopeEl = document.getElementById('file-access-scope');
    const dlEl = document.getElementById('file-is-downloadable');
    if (visEl) fd.append('visibility', visEl.value);
    if (scopeEl) fd.append('access_scope', scopeEl.value);
    if (dlEl) fd.append('is_downloadable', dlEl.checked ? '1' : '0');

    const csrf = getCsrf();
    if (csrf) fd.append('csrf_token', csrf);

    try {
      const up = await fetch('<?= ADMIN_BASE_PATH ?>/admin/upload_file.php', {
        method: 'POST',
        body: fd,
        credentials: 'include',
        cache: 'no-store'
      });

        const { txt, j } = await readJsonSafe(up);

      if (!up.ok) {
        const httpMap = { 413: 'File terlalu besar. Maksimal 20MB.' };
        const msg = (j && j.error) ? j.error : (httpMap[up.status] || txt?.replace(/<[^>]+>/g, '').trim() || ('HTTP ' + up.status));
        uiToast('error', 'File', 'Upload gagal: ' + msg, 6000);
        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(() => row.remove(), 420);
        }, 1200);
        return;
      }

      if (!j || !j.success) {
        uiToast('error', 'File', 'Upload gagal: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(() => row.remove(), 420);
        }, 1200);
        return;
      }

      bar.style.width = '100%';

      const meta = j.file || {
        id: j.file?.id ?? null,
        title: j.file?.title ?? file.name,
        filename: j.file?.filename ?? file.name,
        mime: j.file?.mime ?? '',
        ext: j.file?.ext ?? ''
      };

      showThumb(j.url, meta);

      uiToast('success', 'File', 'Upload berhasil: ' + file.name, 1800);
      document.dispatchEvent(new CustomEvent('file:added', { detail: meta }));

      setTimeout(() => {
        row.classList.add('fade');
        setTimeout(() => row.remove(), 420);
      }, 900);
    } catch (err) {
      uiToast('error', 'File', 'Upload gagal (network): ' + (err && err.message ? err.message : err), 6000);
      setTimeout(() => {
        row.classList.add('fade');
        setTimeout(() => row.remove(), 420);
      }, 1200);
    }
  }

  function showThumb(url, fileObj) {
    const box = document.createElement('div');
    box.className = 'thumb';
    if (fileObj && fileObj.id) box.dataset.fileId = String(fileObj.id);

    const kind = detectKind(fileObj);
    const ext = (fileObj && fileObj.ext)
      ? String(fileObj.ext).toUpperCase()
      : (String(url).split('.').pop() || '').toUpperCase();

    let inner = '';
    if (kind === 'video') {
      inner = `<div style="height:92px;display:flex;align-items:center;justify-content:center;background:#000;color:#fff;font-weight:700">VIDEO</div>`;
    } else if (kind === 'audio') {
      inner = `<div style="height:92px;display:flex;align-items:center;justify-content:center;background:#111;color:#fff;font-weight:700">AUDIO</div>`;
    } else {
      inner = `<div style="height:92px;display:flex;align-items:center;justify-content:center;background:#f7f7f7;color:#333;font-weight:800">${escapeHtml(ext || 'FILE')}</div>`;
    }

    box.innerHTML = `
      ${inner}
      <div class="meta">
        <div style="min-width:0">
          <div class="thumb-title" style="font-weight:700;max-width:155px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            ${escapeHtml(fileObj.title || fileObj.filename || '')}
          </div>
          <div class="badge">${escapeHtml(kind)}</div>
          <pre class="path">${escapeHtml(url)}</pre>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
          <button class="edit-btn" type="button">Edit</button>
          <button class="remove-btn" type="button" title="Hapus dari server">Del</button>
        </div>
      </div>
    `;
    previewWrap.prepend(box);
    requestAnimationFrame(() => box.classList.add('show'));

    const editBtn = box.querySelector('.edit-btn');
    const removeBtn = box.querySelector('.remove-btn');

    if (editBtn) {
      editBtn.onclick = () => {
        const id = fileObj && fileObj.id ? fileObj.id : null;
        const link = id
          ? ("<?= ADMIN_BASE_PATH ?>/admin/file/single.php?id=" + encodeURIComponent(id))
          : ("<?= ADMIN_BASE_PATH ?>/admin/file/single.php?url=" + encodeURIComponent(url));
        if (window.adamModalOpen) window.adamModalOpen(link, {maxWidth: '800px'});
        else window.open(link);
      };
    }

    if (removeBtn) {
      removeBtn.onclick = async (e) => {
        e.preventDefault();

        const ok = await uiAsk('danger', {
          title: <?= json_encode(__('Delete file')) ?>,
          message: 'File ini akan dihapus permanen dari server. Lanjutkan?',
          confirmText: <?= json_encode(__('Yes, delete')) ?>,
          cancelText: <?= json_encode(__('Cancel')) ?>
        });
        if (!ok) return;

        const fd = new FormData();
        if (fileObj && fileObj.id) fd.append('id', fileObj.id);
        else fd.append('url', url);

        const csrf = getCsrf();
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
            uiToast('error', 'File', 'Gagal hapus: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))), 6000);
            return;
          }

          if (j && j.ok) {
            box.remove();
            uiToast('success', 'File', 'File berhasil dihapus.', 3000);
            if (j.warning) {
              uiToast('warning', 'File', j.warning, 6000);
            }
            document.dispatchEvent(new CustomEvent('file:deleted', { detail: j }));
          } else {
            uiToast('error', 'File', 'Gagal hapus: ' + ((j && j.error) ? j.error : (txt || 'unknown')), 6000);
          }
        } catch (err) {
          uiToast('error', 'File', 'Network error: ' + (err && err.message ? err.message : err), 6000);
        }
      };
    }
  }

  function extractDeletedFileIds(detail) {
    const ids = new Set();
    if (!detail || typeof detail !== 'object') return [];

    if (detail.id != null) {
      const n = parseInt(detail.id, 10);
      if (Number.isFinite(n) && n > 0) ids.add(String(n));
    }

    if (Array.isArray(detail.deleted_ids)) {
      detail.deleted_ids.forEach(function(id){
        const n = parseInt(id, 10);
        if (Number.isFinite(n) && n > 0) ids.add(String(n));
      });
    }

    if (Array.isArray(detail.ids)) {
      detail.ids.forEach(function(id){
        const n = parseInt(id, 10);
        if (Number.isFinite(n) && n > 0) ids.add(String(n));
      });
    }

    return Array.from(ids);
  }

  document.addEventListener('file:deleted', function(e){
    const ids = extractDeletedFileIds(e && e.detail ? e.detail : null);
    ids.forEach(id => {
      const thumb = previewWrap.querySelector('.thumb[data-file-id="' + id + '"]');
      if (thumb) thumb.remove();
    });
  });

  document.addEventListener('file:updated', function(e){
    const file = e && e.detail ? e.detail : null;
    if (!file || !file.id) return;

    const thumb = previewWrap.querySelector('.thumb[data-file-id="' + String(file.id) + '"]');
    if (thumb) {
      const titleEl = thumb.querySelector('.thumb-title');
      if (titleEl && file.title) titleEl.textContent = file.title;
    }
  });
})();
</script>