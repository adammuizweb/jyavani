<?php
// /adiwira/admin/modal_file/add_modal.php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
adiwira_require_admin(false);

header('Content-Type: text/html; charset=utf-8');

$csrfToken = '';
try { if (function_exists('csrf_token')) $csrfToken = (string)csrf_token(); } catch (Throwable $e) { $csrfToken = ''; }
?>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

<div class="media-uploader">
  <div id="modalfilez-u-dropzone" class="modalfilez-dropzone" role="button" tabindex="0">
    Tarik file ke sini atau klik
    <button id="modalfilez-u-browse" class="modalfilez-u-btn ghost" type="button">Pilih file</button>
    <div class="modalfilez-small" style="margin-top:6px">
      Tipe: pdf, doc/docx, xls/xlsx, ppt/pptx, zip, txt, rtf, mp4/webm/mov, mp3/wav/ogg • Max 30MB
    </div>
  </div>

  <div id="modalfilez-u-progress" class="modalfilez-progress" aria-live="polite"></div>
  <div id="modalfilez-u-preview" class="modalfilez-preview" aria-live="polite"></div>
</div>

<input id="modalfilez-u-file-input" type="file"
  accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.rtf,.mp4,.webm,.mov,.mp3,.wav,.ogg"
  multiple style="display:none">

<script>
(function(){
  const uploadUrl = '/adiwira/admin/upload_file.php';
  const deleteUrl = '/adiwira/admin/file/delete.php';

  const dropzone = document.getElementById('modalfilez-u-dropzone');
  const fileInput = document.getElementById('modalfilez-u-file-input');
  const browseBtn = document.getElementById('modalfilez-u-browse');
  const progressWrap = document.getElementById('modalfilez-u-progress');
  const previewWrap = document.getElementById('modalfilez-u-preview');

  if (!dropzone || !fileInput || !browseBtn || !progressWrap || !previewWrap) return;

  function broadcast(name, detail){
    try { document.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { window.dispatchEvent(new CustomEvent(name, { detail })); } catch(e){}
    try { if (window.parent && window.parent !== window) window.parent.postMessage({ type: name, detail }, '*'); } catch(e){}
  }

  function findCsrfToken(){
    const el = document.querySelector('#modalfilez-csrf, input[name="csrf_token"]');
    return el && el.value ? String(el.value) : '';
  }

  browseBtn.addEventListener('click', ()=> fileInput.click());
  fileInput.addEventListener('change', e => handleFiles(e.target.files));

  ['dragenter','dragover'].forEach(ev=>{
    dropzone.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); dropzone.classList.add('drag'); });
  });
  ['dragleave','drop'].forEach(ev=>{
    dropzone.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('drag'); });
  });
  dropzone.addEventListener('drop', e=>{
    const dt = e.dataTransfer;
    if (!dt) return;
    handleFiles(dt.files);
  });
  dropzone.addEventListener('keydown', e=>{
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
  });

  function handleFiles(files){
    if (!files || !files.length) return;
    Array.from(files).forEach(uploadFile);
  }

  function addProgressRow(filename){
    const row = document.createElement('div');
    row.className = 'modalfilez-progress-row';
    row.innerHTML = `
      <div style="font-size:.9rem;font-weight:800;min-width:120px">${escapeHtml(filename)}</div>
      <div class="bar-wrap"><div class="bar"></div></div>
    `;
    progressWrap.appendChild(row);
    const bar = row.querySelector('.bar');
    return { row, bar };
  }

  function uploadFile(file){
    const { row, bar } = addProgressRow(file.name);

    return new Promise((resolve, reject)=>{
      const xhr = new XMLHttpRequest();
      const fd = new FormData();
      fd.append('file', file);
      fd.append('auto_save', '1');
      fd.append('title', file.name);

      const csrf = findCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      xhr.open('POST', uploadUrl, true);
      xhr.withCredentials = true;

      xhr.upload.addEventListener('progress', function(e){
        if (!e.lengthComputable) return;
        bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
      });

      xhr.onreadystatechange = function(){
        if (xhr.readyState !== 4) return;

        setTimeout(()=>{
          row.classList.add('fade');
          setTimeout(()=>{ if(row.parentNode) row.parentNode.removeChild(row); }, 420);
        }, 900);

        if (xhr.status >= 200 && xhr.status < 300){
          let j = null;
          try { j = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch(e){ j = null; }
          if (!j || !j.success){
            return reject(new Error(j && j.error ? j.error : 'Upload failed'));
          }

          bar.style.width = '100%';

          const f = j.file || j.media || {};
          const detail = {
            id: f.id || null,
            filename: f.filename || file.name,
            url: j.url || f.url || '',
            mime: f.mime || '',
            size: f.size || ''
          };

          showThumb(detail);
          broadcast('file:added', detail);
          broadcast('file:insert', detail); // optional auto-insert
          resolve(detail);
          return;
        }
        reject(new Error('HTTP ' + xhr.status));
      };

      xhr.onerror = ()=> reject(new Error('Network error'));
      xhr.send(fd);
    }).catch(err=>{
      console.error('Upload error', err);
      alert('Upload gagal: ' + (err.message || 'Unknown'));
    });
  }

  function showThumb(detail){
    const ext = pickExt(detail.filename);
    const ico = ext ? ext.toUpperCase().slice(0,3) : 'FILE';

    const box = document.createElement('div');
    box.className = 'modalfilez-thumb';
    if (detail.id) box.dataset.fileId = String(detail.id);

    box.innerHTML = `
      <div class="top">
        <div class="modalfilez-ico">${escapeHtml(ico)}</div>
        <div class="modalfilez-meta">
          <div class="modalfilez-name" title="${escapeHtml(detail.filename)}">${escapeHtml(detail.filename)}</div>
          <div class="modalfilez-sub" title="${escapeHtml(detail.url)}">${escapeHtml(detail.mime || '—')}</div>
        </div>
      </div>
      <div class="modalfilez-actions">
        <button class="modalfilez-actbtn insert" type="button">Insert</button>
        <button class="modalfilez-actbtn del" type="button">Del</button>
      </div>
    `;

    previewWrap.prepend(box);
    requestAnimationFrame(()=> box.classList.add('show'));

    box.querySelector('.insert').addEventListener('click', function(){
      broadcast('file:insert', detail);
      try { if (window.parent && window.parent.adamModalClose) window.parent.adamModalClose(); } catch(e){}
      try { if (window.adamModalClose) window.adamModalClose(); } catch(e){}
    });

    box.querySelector('.del').addEventListener('click', async function(){
      if (!confirm('Hapus file ini secara permanen?')) return;

      const fd = new FormData();
      if (detail.id) fd.append('id', String(detail.id));
      else if (detail.url) fd.append('url', detail.url);

      const csrf = findCsrfToken();
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch(deleteUrl, { method:'POST', credentials:'include', body: fd });
        const txt = await res.text();
        let j = null; try { j = txt ? JSON.parse(txt) : null; } catch(e){}
        if (!res.ok) {
          alert('Gagal hapus: ' + (j && j.error ? j.error : (txt || ('HTTP '+res.status))));
          return;
        }
        if (j && j.ok) {
          box.remove();
          broadcast('file:deleted', j);
        } else {
          alert('Gagal hapus: ' + (j && j.error ? j.error : 'unknown'));
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      }
    });
  }

  function pickExt(name){
    name = String(name || '');
    const i = name.lastIndexOf('.');
    if (i < 0) return '';
    return name.slice(i+1).toLowerCase();
  }

  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }
})();
</script>