<?php
declare(strict_types=1);

// /adiwira/admin/modal_img/add_modal.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    require_once __DIR__ . '/../_guard.php';

    if (function_exists('adiwira_is_navigate_request') && adiwira_is_navigate_request()) {
        http_response_code(404);
        require __DIR__ . '/../../../frontend_404.php';
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

<div class="media-uploader">
  <div class="uploader-top">
    <div style="display:flex;gap:8px;align-items:center;flex:1">
      <div id="dropzone" class="dropzone" role="button" tabindex="0">
        Tarik gambar ke sini atau klik
        <button id="browse-btn" class="adam-btn ghost" type="button">Pilih file</button>
        <div class="note small">Mendukung webp/png/jpg/jpeg. Upload otomatis setelah pilih.</div>
      </div>
    </div>
  </div>

  <div id="upload-progress" class="upload-progress" aria-live="polite"></div>
  <div id="preview-container" class="preview-grid" aria-live="polite"></div>
</div>

<input id="file-input" type="file" accept="image/webp,image/png,image/jpg,image/jpeg" multiple style="display:none">

<script>
(function(){
  const uploadUrl = '/adiwira/admin/upload_image.php';
  const deleteUrl = '/adiwira/admin/media/delete.php';

  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('file-input');
  const browseBtn = document.getElementById('browse-btn');
  const progressWrap = document.getElementById('upload-progress');
  const previewWrap = document.getElementById('preview-container');

  if (!dropzone || !fileInput || !browseBtn || !progressWrap || !previewWrap) return;

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

  function uiToast(type, title, message, duration){
    const api = getToastApi();
    if (api) {
      api.show({
        type: type || 'info',
        title: title || null,
        message: message || '',
        duration: duration
      });
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function uiAsk(variant, opts){
    const api = getConfirmApi();
    if (api) {
      if (variant === 'danger' && typeof api.danger === 'function') return api.danger(opts || {});
      if (typeof api.warning === 'function') return api.warning(opts || {});
    }
    return Promise.resolve(window.confirm((opts && opts.message) ? opts.message : 'Lanjutkan aksi ini?'));
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

  function addProgressRow(filename) {
    const row = document.createElement('div');
    row.className = 'progress-row';
    row.innerHTML = `
      <div style="font-size:.9rem;font-weight:600;min-width:120px">${escapeHtml(filename)}</div>
      <div class="bar-wrap"><div class="bar"></div></div>
    `;
    progressWrap.appendChild(row);
    const bar = row.querySelector('.bar');
    return { row, bar };
  }

  function uploadFile(file) {
    const { row, bar } = addProgressRow(file.name);

    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const fd = new FormData();

      fd.append('image', file);
      fd.append('auto_save', '1');
      fd.append('title', file.name);

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

        if (xhr.status >= 200 && xhr.status < 300) {
          let data = null;
          try {
            data = xhr.responseText ? JSON.parse(xhr.responseText) : null;
          } catch (e) {
            reject(new Error('Invalid JSON response'));
            return;
          }

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
            credit: data.credit || ''
          };

          bar.style.width = '100%';
          showThumb(media.url || data.url || '', media);

          uiToast('success', 'Gallery', 'Upload berhasil: ' + file.name, 1800);
          broadcast('media:added', media);
          broadcast('media:insert', media);
          resolve(media);
        } else {
          reject(new Error('HTTP ' + xhr.status));
        }
      });

      xhr.addEventListener('error', function(){
        reject(new Error('Network error'));
      });

      xhr.send(fd);
    }).catch(err => {
      console.error('Upload error', err);
      uiToast('error', 'Gallery', 'Upload gagal: ' + (err.message || 'Unknown'), 5000);
    });
  }

  function showThumb(url, media) {
    const box = document.createElement('div');
    box.className = 'thumb';
    if (media && media.id) box.dataset.mediaId = String(media.id);
    if (url) box.dataset.mediaUrl = String(url);

    box.innerHTML = `
      <img src="${escapeHtml(url)}" alt="${escapeHtml(media && (media.alt || media.title) || '')}">
      <div class="meta">
        <div class="title">${escapeHtml(media && (media.title || media.filename || '') || '')}</div>
        <div class="actions">
          <button class="edit-btn" type="button">Edit</button>
          <button class="del-btn" type="button">Del</button>
        </div>
      </div>
    `;

    previewWrap.prepend(box);
    requestAnimationFrame(() => box.classList.add('show'));

    box.querySelector('.edit-btn').addEventListener('click', function(){
      const id = box.dataset.mediaId || (media && media.id);
      if (!id) return;

      const link = '/adiwira/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1';

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

    box.querySelector('.del-btn').addEventListener('click', async function(){
      const ok = await uiAsk('danger', {
        title: 'Hapus media',
        message: 'Media ini akan dihapus permanen dari gallery. Lanjutkan?',
        confirmText: 'Ya, hapus',
        cancelText: 'Batal'
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
          uiToast('error', 'Gallery', 'Gagal hapus: ' + (j && j.error ? j.error : (txt || ('HTTP ' + res.status))), 6000);
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

          uiToast('success', 'Gallery', 'Media berhasil dihapus.', 2500);
          if (j.warning) uiToast('warning', 'Gallery', j.warning, 6000);

          broadcast('media:deleted', payload);
        } else {
          uiToast('error', 'Gallery', 'Gagal hapus: ' + (j && j.error ? j.error : 'unknown'), 6000);
        }
      } catch (err) {
        console.error('Delete error', err);
        uiToast('error', 'Gallery', 'Network error: ' + (err.message || ''), 6000);
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
      const th = previewWrap.querySelector('.thumb[data-media-id="' + id + '"]');
      if (th) th.remove();
    });

    if (Array.isArray(d.deleted_urls)) {
      d.deleted_urls.forEach(function(u){
        const th = previewWrap.querySelector('.thumb[data-media-url="' + CSS.escape(String(u)) + '"]');
        if (th) th.remove();
      });
    }
  });
})();
</script>