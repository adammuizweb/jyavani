<?php
// /adiwira/admin/modal_img/add_modal.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/html; charset=utf-8');
// Note: Upload endpoint expected: /adiwira/admin/upload_image.php
?>
<div class="media-uploader">
  <div class="uploader-top">
    <div style="display:flex;gap:8px;align-items:center;flex:1">
      <div id="dropzone" class="dropzone" role="button" tabindex="0">
        Tarik gambar ke sini atau klik <button id="browse-btn" class="adam-btn ghost" type="button">Pilih file</button>
        <div class="note small">Mendukung webp/png/jpg/jpeg. Upload otomatis setelah pilih.</div>
      </div>
    </div>

    <div class="uploader-controls" style="min-width:150px;justify-content:flex-end">
      <button id="btn-upload-clear" class="adam-btn" type="button">Bersihkan</button>
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
  const clearBtn = document.getElementById('btn-upload-clear');

  browseBtn.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', e => handleFiles(e.target.files));

  ['dragenter','dragover'].forEach(ev => {
    dropzone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropzone.classList.add('drag'); });
  });
  ['dragleave','drop'].forEach(ev => {
    dropzone.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('drag'); });
  });
  dropzone.addEventListener('drop', e => {
    const dt = e.dataTransfer;
    if (!dt) return;
    handleFiles(dt.files);
  });

  // allow keyboard activation
  dropzone.addEventListener('keydown', function(e){
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
  });

  clearBtn.addEventListener('click', function(){ progressWrap.innerHTML=''; previewWrap.innerHTML=''; });

  // Broadcast helper
  function broadcast(name, detail) {
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { if (window.parent && window.parent !== window) window.parent.postMessage({ type: name, detail }, '*'); } catch(e){}
  }

  // try get CSRF token from current or parent document (optional)
  function findCsrfToken() {
    let t = null;
    try {
      const el = document.querySelector('input[name="csrf_token"]');
      if (el && el.value) t = el.value;
    } catch(e){}
    if (!t) {
      try {
        const pel = window.parent && window.parent.document && window.parent.document.querySelector('input[name="csrf_token"]');
        if (pel && pel.value) t = pel.value;
      } catch(e){}
    }
    return t;
  }

  // handle multiple files
  function handleFiles(files) {
    if (!files || files.length === 0) return;
    Array.from(files).forEach(f => {
      // only images
      if (!f.type || !f.type.match(/^image\//)) return;
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

  // XHR upload to get progress events
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
        // fade out + remove row later
        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(()=>{ if (row.parentNode) row.parentNode.removeChild(row); }, 420);
        }, 800);
        if (xhr.status >= 200 && xhr.status < 300) {
          let data = null;
          try { data = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch (e) { return reject(new Error('Invalid JSON response')); }
          if (!data || (!data.success && !data.url && !data.media)) {
            return reject(new Error(data && data.error ? data.error : 'Upload failed'));
          }
          // normalize media object
          const media = data.media || (data.id ? { id: data.id, url: data.url || data.path, title: data.title || '' } : { id: data.id || null, url: data.url || data.path || '', title: data.title || '' });
          // Ensure bar is full
          bar.style.width = '100%';
          // show thumb
          showThumb(media.url || data.url || '', media);
          // fire events
          try { broadcast('media:added', media); } catch(e){}
          try { broadcast('media:insert', media); } catch(e){}
          return resolve(media);
        } else {
          return reject(new Error('HTTP ' + xhr.status));
        }
      });

      xhr.addEventListener('error', function(){ reject(new Error('Network error')); });
      xhr.send(fd);
    })
    .catch(err => {
      console.error('Upload error', err);
      alert('Upload gagal: ' + (err.message || 'Unknown'));
    });
  }

  // show thumbnail card
  function showThumb(url, media) {
    const box = document.createElement('div');
    box.className = 'thumb';
    if (media && media.id) box.dataset.mediaId = media.id;

    box.innerHTML = `
      <img src="${escapeHtml(url)}" alt="${escapeHtml(media && (media.alt || media.title) || '')}">
      <div class="meta">
        <div class="title">${escapeHtml(media && (media.title || media.filename || '') || '')}</div>
        <div class="actions">
          <button class="edit-btn" title="Edit">Edit</button>
          <button class="del-btn" title="Hapus">Del</button>
        </div>
      </div>
    `;

    previewWrap.prepend(box);
    // animate in
    requestAnimationFrame(() => box.classList.add('show'));

    // edit -> open single modal (try modal_img/single_modal.php embedded)
    box.querySelector('.edit-btn').addEventListener('click', function(){
      const id = box.dataset.mediaId || (media && media.id);
      const link = '/adiwira/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1';
      // if parent modal system exists, try open in same modal content; otherwise open using our modal API or new tab
      try {
        if (window.parent && window.parent !== window && typeof window.parent.adamModalOpen === 'function') {
          window.parent.adamModalOpen(link, { maxWidth: '820px' });
          return;
        }
      } catch(e){}
      if (typeof window.adamModalOpen === 'function') { try { window.adamModalOpen(link, { maxWidth: '820px' }); return; } catch(e){} }
      window.open(link, '_blank');
    });

    // delete -> call server delete endpoint then remove
    box.querySelector('.del-btn').addEventListener('click', async function(){
      if (!confirm('Hapus media ini secara permanen?')) return;
      const fd = new FormData();
      if (media && media.id) fd.append('id', media.id);
      else fd.append('url', url);
      try {
        const res = await fetch(deleteUrl, { method:'POST', credentials:'include', body: fd });
        const j = await res.json();
        if (j.ok) {
          box.remove();
          try { broadcast('media:deleted', j); } catch(e){}
        } else {
          alert('Gagal hapus: ' + (j.error || 'unknown'));
        }
      } catch (err) {
        console.error('Delete error', err);
        alert('Network error: ' + (err.message || ''));
      }
    });
  }

  // helper escape
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
  }

  // handle external events: if other part deletes media, remove preview
  document.addEventListener('media:deleted', function(e){
    const d = (e && e.detail) ? e.detail : null;
    if (!d) return;
    let ids = [];
    if (Array.isArray(d)) ids = d.map(x=>parseInt(x,10)).filter(Boolean);
    else if (d.deleted_ids && Array.isArray(d.deleted_ids)) ids = d.deleted_ids.map(x=>parseInt(x,10)).filter(Boolean);
    else if (d.id) ids = [parseInt(d.id,10)].filter(Boolean);
    else if (d.ids && Array.isArray(d.ids)) ids = d.ids.map(x=>parseInt(x,10)).filter(Boolean);
    if (!ids.length) return;
    ids.forEach(id => {
      const th = previewWrap.querySelector('.thumb[data-media-id="'+id+'"]');
      if (th) th.remove();
    });
  });

})();
</script>