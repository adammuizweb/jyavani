<?php
// /adiwira/admin/file/add.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    http_response_code(404);
    require __DIR__ . '/../../../frontend_404.php';
    exit;
}
?>
<section class="media-uploader">
  <div class="dropzone" id="dropzone">
    <p>Tarik file (dokumen / excel / powerpoint / video / audio) ke sini atau
      <button class="adam-btn" id="browse-btn" type="button">Pilih file</button>
    </p>
    <div id="upload-progress" class="upload-progress"></div>
    <div class="small" style="margin-top:8px">
      Maks ukuran per file: 30MB.
      Tipe: pdf, doc/docx, xls/xlsx, ppt/pptx, zip, mp4, webm, mov, txt, rtf, mp3, wav, ogg
    </div>
  </div>

  <div id="preview-container" class="preview-grid"></div>
</section>

<input type="file"
       id="file-input"
       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.mp4,.webm,.mov,.txt,.rtf,.mp3,.wav,.ogg"
       multiple hidden />

<script>
(() => {
  const dz = document.getElementById('dropzone');
  const fileInput = document.getElementById('file-input');
  const browseBtn = document.getElementById('browse-btn');
  const progressWrap = document.getElementById('upload-progress');
  const previewWrap = document.getElementById('preview-container');

  function getCsrf() {
    const el = document.getElementById('csrf_token');
    return el && el.value ? el.value : '';
  }

  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  async function readJsonSafe(res) {
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  browseBtn.onclick = () => fileInput.click();
  fileInput.onchange = e => handleFiles(e.target.files);

  ['dragenter','dragover'].forEach(ev =>
    dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); })
  );
  ['dragleave','drop'].forEach(ev =>
    dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); })
  );
  dz.addEventListener('drop', e => handleFiles(e.dataTransfer.files));

  function handleFiles(files) { Array.from(files || []).forEach(uploadFile); }

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

    const csrf = getCsrf();
    if (csrf) fd.append('csrf_token', csrf);

    try {
      const up = await fetch('/adiwira/admin/upload_file.php', {
        method: 'POST',
        body: fd,
        credentials: 'include',
        cache: 'no-store'
      });

      const { txt, j } = await readJsonSafe(up);

      if (!up.ok) {
        alert('Upload gagal: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + up.status))));
        setTimeout(() => { row.classList.add('fade'); setTimeout(()=>row.remove(), 420); }, 1200);
        return;
      }

      if (!j || !j.success) {
        alert('Upload gagal: ' + ((j && j.error) ? j.error : (txt || 'unknown')));
        setTimeout(() => { row.classList.add('fade'); setTimeout(()=>row.remove(), 420); }, 1200);
        return;
      }

      bar.style.width = '100%';

      const meta = j.file || j.media || {
        id: (j.file && j.file.id) ? j.file.id : null,
        title: (j.file && j.file.title) ? j.file.title : file.name,
        filename: file.name,
        mime: (j.file && j.file.mime) ? j.file.mime : '',
        ext: (j.file && j.file.ext) ? j.file.ext : ''
      };

      showThumb(j.url, meta);

      try { document.dispatchEvent(new CustomEvent('file:added', { detail: meta })); } catch(e){}
      try { document.dispatchEvent(new CustomEvent('media:added', { detail: meta })); } catch(e){}

      setTimeout(() => { row.classList.add('fade'); setTimeout(()=>{ row.remove(); }, 420); }, 900);
    } catch (err) {
      alert('Upload gagal (network): ' + (err && err.message ? err.message : err));
      setTimeout(() => { row.classList.add('fade'); setTimeout(()=>row.remove(), 420); }, 1200);
    }
  }

  function showThumb(url, fileObj) {
    const box = document.createElement('div');
    box.className = 'thumb';
    if (fileObj && fileObj.id) box.dataset.fileId = fileObj.id;

    const kind = detectKind(fileObj);
    const ext = (fileObj && fileObj.ext) ? String(fileObj.ext).toUpperCase() : (String(url).split('.').pop() || '').toUpperCase();

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
          <div style="font-weight:700;max-width:155px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
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
    requestAnimationFrame(()=>box.classList.add('show'));

    box.querySelector('.edit-btn').onclick = () => {
      const id = fileObj && fileObj.id ? fileObj.id : null;
      const link = id
        ? ("/adiwira/admin/file/single.php?id=" + encodeURIComponent(id))
        : ("/adiwira/admin/file/single.php?url=" + encodeURIComponent(url));
      if (window.adamModalOpen) window.adamModalOpen(link, {maxWidth: '800px'});
      else window.open(link);
    };

    box.querySelector('.remove-btn').onclick = async (e) => {
      e.preventDefault();
      if (!confirm('Hapus file ini secara permanen?')) return;

      const fd = new FormData();
      if (fileObj && fileObj.id) fd.append('id', fileObj.id);
      else fd.append('url', url);

      const csrf = getCsrf();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch('/adiwira/admin/file/delete.php', {
          method: 'POST',
          credentials: 'include',
          cache: 'no-store',
          body: fd
        });

        const { txt, j } = await readJsonSafe(res);

        if (!res.ok) {
          alert('Gagal hapus: ' + ((j && j.error) ? j.error : (txt || ('HTTP ' + res.status))));
          return;
        }

        if (j && j.ok) {
          box.remove();
          try { document.dispatchEvent(new CustomEvent('file:deleted', { detail: j })); } catch(e){}
          try { document.dispatchEvent(new CustomEvent('media:deleted', { detail: j })); } catch(e){}
        } else {
          alert('Gagal hapus: ' + ((j && j.error) ? j.error : (txt || 'unknown')));
        }
      } catch (err) {
        alert('Network error: ' + (err && err.message ? err.message : err));
      }
    };
  }

  // remove preview on external deletes
  document.addEventListener('file:deleted', function(e){
    const d = e && e.detail ? e.detail : null;
    if (!d) return;

    let ids = [];
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