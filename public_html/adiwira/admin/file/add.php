<?php
// /adiwira/admin/file/add.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
?>

<style>
.media-uploader { font-family: system-ui, -apple-system, "Segoe UI", Roboto; }
.dropzone { padding: 20px; border: 2px dashed #ccc; border-radius: 10px; text-align: center; background: #fafafa; transition: .2s; }
.dropzone.drag { background: #eaf8ff; border-color: #62b5ff; }
.adam-btn { padding: 6px 14px; border-radius: 6px; background: #2d8cf0; color: #fff; cursor: pointer; border: none; }
.upload-progress { margin-top: 14px; }
.progress-row { display:flex; align-items:center; gap:10px; margin-bottom:12px; opacity:1; transform:translateY(0); transition: opacity .35s, transform .35s; }
.progress-row.fade { opacity:0; transform:translateY(-6px); }
.progress-row .bar-wrap { flex:1; background:#eee; height:8px; border-radius:5px; overflow:hidden; }
.progress-row .bar { height:100%; width:0%; background:linear-gradient(90deg,#4caf50,#42a5f5); transition: width .3s linear; }
.preview-grid { display:flex; flex-wrap:wrap; gap:12px; margin-top:18px; }
.thumb { width:160px; border-radius:10px; overflow:hidden; opacity:0; transform:translateY(6px); background:#fff; border:1px solid #eee; box-shadow:0 3px 10px rgba(0,0,0,0.06); transition:.25s; }
.thumb.show { opacity:1; transform:none; }
.thumb .meta { padding:8px; font-size:.85rem; display:flex; justify-content:space-between; align-items:center; }
.thumb button { background:none; border:none; color:#2d8cf0; cursor:pointer; padding:3px 5px; }
.thumb pre.path { font-size:.75rem; color:#666; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }
</style>

<section class="media-uploader">
  <div class="dropzone" id="dropzone">
    <p>Tarik file (dokumen / excel / powerpoint / video) ke sini atau <button class="adam-btn" id="browse-btn">Pilih file</button></p>
    <div id="upload-progress" class="upload-progress"></div>
    <div class="small" style="margin-top:8px">Maks ukuran per file: 30MB. Tipe: pdf, doc/docx, xls/xlsx, ppt/pptx, zip, mp4, webm, mov, txt, rtf</div>
  </div>

  <div id="preview-container" class="preview-grid"></div>
</section>

<!-- allow wide set of types; accept will be advisory only -->
<input type="file" id="file-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.mp4,.webm,.mov,.txt,.rtf" multiple hidden />

<script>
(() => {
  const dz = document.getElementById('dropzone');
  const fileInput = document.getElementById('file-input');
  const browseBtn = document.getElementById('browse-btn');
  const progressWrap = document.getElementById('upload-progress');
  const previewWrap = document.getElementById('preview-container');

  browseBtn.onclick = () => fileInput.click();
  fileInput.onchange = e => handleFiles(e.target.files);

  ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); }));
  ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); }));
  dz.addEventListener('drop', e => handleFiles(e.dataTransfer.files));

  function handleFiles(files) { [...files].forEach(uploadFile); }

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
    fd.append('file', file);
    fd.append('auto_save', '1');
    fd.append('title', file.name);

    let j;
    try {
      const up = await fetch('/adiwira/admin/upload_file.php', {
        method: 'POST',
        body: fd,
        credentials: 'include'
      });
      j = await up.json();
    } catch (err) {
      alert('Upload gagal (network): ' + err.message);
      setTimeout(() => { row.classList.add('fade'); setTimeout(()=>row.remove(), 400); }, 2000);
      return;
    }

    if (!j || !j.success) {
      alert('Upload gagal: ' + (j && j.error ? j.error : 'unknown'));
      setTimeout(() => { row.classList.add('fade'); setTimeout(()=>row.remove(), 400); }, 2000);
      return;
    }

    bar.style.width = '100%';

    // show preview (for files show icon + filename; for video show poster if available)
    showThumb(j.url, j.file || j.media || { id: j.file && j.file.id ? j.file.id : null, title: j.file && j.file.title ? j.file.title : file.name, filename: file.name, mime: j.file && j.file.mime ? j.file.mime : '' });

    // dispatch both file:added and legacy media:added
    try { document.dispatchEvent(new CustomEvent('file:added', { detail: j.file })); } catch(e){}
    try { document.dispatchEvent(new CustomEvent('media:added', { detail: j.file })); } catch(e){}

    setTimeout(() => { row.classList.add('fade'); setTimeout(()=>{ if (row.parentNode) row.parentNode.removeChild(row); }, 420); }, 1500);
  }

  function showThumb(url, file) {
    const box = document.createElement('div');
    box.className = 'thumb';
    if (file && file.id) box.dataset.fileId = file.id;

    // Simple icon for non-image
    const isVideo = file && file.mime && file.mime.indexOf('video/') === 0;
    let inner;
    if (isVideo) {
      inner = `<div style="height:90px;display:flex;align-items:center;justify-content:center;background:#000;color:#fff">Video</div>`;
    } else {
      inner = `<div style="height:90px;display:flex;align-items:center;justify-content:center;background:#f7f7f7;color:#333;font-weight:600">${escapeHtml((file && file.ext) ? file.ext.toUpperCase() : '')}</div>`;
    }

    box.innerHTML = `
      ${inner}
      <div class="meta">
        <div>
          <div style="font-weight:600;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(file.title || file.filename || '')}</div>
          <pre class="path">${escapeHtml(url)}</pre>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="edit-btn">Edit</button>
          <button class="remove-btn" title="Hapus dari server">Del</button>
        </div>
      </div>
    `;
    previewWrap.prepend(box);
    requestAnimationFrame(()=>box.classList.add('show'));

    box.querySelector('.edit-btn').onclick = () => {
      const id = file && file.id ? file.id : null;
      const link = id ? ("/adiwira/admin/file/single.php?id=" + encodeURIComponent(id)) : ("/adiwira/admin/file/single.php?url=" + encodeURIComponent(url));
      if (window.adamModalOpen) adamModalOpen(link, {maxWidth: '800px'});
      else window.open(link);
    };

    box.querySelector('.remove-btn').onclick = async (e) => {
      e.preventDefault();
      if (!confirm('Hapus file ini secara permanen?')) return;
      const fd = new FormData();
      if (file && file.id) fd.append('id', file.id);
      else fd.append('url', url);

      try {
        const res = await fetch('/adiwira/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j.ok) {
          box.remove();
          try { document.dispatchEvent(new CustomEvent('file:deleted', { detail: j })); } catch(e){}
          try { document.dispatchEvent(new CustomEvent('media:deleted', { detail: j })); } catch(e){}
        } else {
          alert('Error hapus: ' + (j.error || JSON.stringify(j.errors || j)));
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      }
    };
  }

  function escapeHtml(s) { return String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  // remove preview on external deletes
  document.addEventListener('file:deleted', function(e){
    const d = e && e.detail ? e.detail : null;
    let ids = [];
    if (!d) return;
    if (Array.isArray(d)) ids = d.map(x=>parseInt(x,10)).filter(Boolean);
    else if (d.deleted_ids && Array.isArray(d.deleted_ids)) ids = d.deleted_ids.map(x=>parseInt(x,10)).filter(Boolean);
    else if (d.id) ids = [parseInt(d.id,10)].filter(Boolean);
    else if (d.ids && Array.isArray(d.ids)) ids = d.ids.map(x=>parseInt(x,10)).filter(Boolean);

    ids.forEach(id => {
      const thumb = previewWrap.querySelector('.thumb[data-file-id="'+id+'"]');
      if (thumb) thumb.remove();
    });
  });

  document.addEventListener('file:updated', function(e){
    const d = e && e.detail ? e.detail : null;
    if (!d || !d.id) return;
    const thumb = previewWrap.querySelector('.thumb[data-file-id="'+d.id+'"]');
    if (thumb) {
      const titleEl = thumb.querySelector('.meta div div');
      if (titleEl && d.title) titleEl.textContent = d.title;
    }
  });

})();
</script>
