<?php
declare(strict_types=1);

// /adiwira/admin/media/add.php
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    require_once __DIR__ . '/../_guard.php';

    if (adiwira_is_navigate_request()) {
        http_response_code(404);
        require __DIR__ . '/../../../frontend_404.php';
        exit;
    }

    adiwira_require_role($pdo, ['author', 'editor', 'admin'], false);
}

$mediaCsrf = csrf_token();
?>
<section class="media-uploader">
  <input type="hidden" id="media-csrf-token" value="<?= htmlspecialchars($mediaCsrf, ENT_QUOTES, 'UTF-8') ?>">

  <div class="dropzone" id="dropzone">
    <p>Tarik gambar ke sini atau <button class="adam-btn" id="browse-btn" type="button">Pilih file</button></p>
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

    const csrf = getCsrfToken();
    if (csrf) fd.append('csrf_token', csrf);

    try {
      const res = await fetch('/adiwira/admin/upload_image.php', {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        body: fd
      });

      const txt = await res.text();
      let j = null;
      try { j = txt ? JSON.parse(txt) : null; } catch(e) {}

      if (!res.ok) {
        alert('Upload gagal: ' + (j?.error || txt || ('HTTP ' + res.status)));
        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(() => row.remove(), 400);
        }, 1200);
        return;
      }

      if (!j || !j.success) {
        alert('Upload gagal: ' + (j?.error || txt || 'unknown'));
        setTimeout(() => {
          row.classList.add('fade');
          setTimeout(() => row.remove(), 400);
        }, 1200);
        return;
      }

      bar.style.width = '100%';
      showThumb(j.url, j.media);

      document.dispatchEvent(new CustomEvent('media:added', { detail: j.media }));

      setTimeout(() => {
        row.classList.add('fade');
        setTimeout(() => {
          if (row.parentNode) row.parentNode.removeChild(row);
        }, 420);
      }, 900);

    } catch (err) {
      alert('Upload gagal (network): ' + (err.message || err));
      setTimeout(() => {
        row.classList.add('fade');
        setTimeout(() => row.remove(), 400);
      }, 1200);
    }
  }

  function showThumb(url, media) {
    const box = document.createElement('div');
    box.className = 'thumb';
    if (media && media.id) box.dataset.mediaId = media.id;

    box.innerHTML = `
      <img src="${escapeHtml(url)}" alt="">
      <div class="meta">
        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:86px">
          ${escapeHtml((media && (media.title || media.filename)) || '')}
        </div>
        <div style="display:flex;gap:6px;align-items:center">
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
        const id = media && media.id ? media.id : '';
        const link = "/adiwira/admin/media/single.php?id=" + encodeURIComponent(id);
        if (window.adamModalOpen) window.adamModalOpen(link, {maxWidth:'800px'});
        else window.open(link);
      };
    }

    if (removeBtn) {
      removeBtn.onclick = async (e) => {
        e.preventDefault();
        if (!confirm('Hapus media ini secara permanen?')) return;

        const fd = new FormData();
        if (media && media.id) fd.append('id', media.id);
        else fd.append('url', url);

        const csrf = getCsrfToken();
        if (csrf) fd.append('csrf_token', csrf);

        try {
          const res = await fetch('/adiwira/admin/media/delete.php', {
            method: 'POST',
            credentials: 'include',
            body: fd
          });

          const txt = await res.text();
          let j = null;
          try { j = txt ? JSON.parse(txt) : null; } catch(e) {}

          if (!res.ok) {
            alert('Gagal hapus: ' + (j?.error || txt || ('HTTP ' + res.status)));
            return;
          }
          if (j && j.ok) {
            box.remove();
            document.dispatchEvent(new CustomEvent('media:deleted', { detail: j }));
          } else {
            alert('Gagal hapus: ' + (j?.error || txt || 'unknown'));
          }
        } catch (err) {
          alert('Network error: ' + (err.message || err));
        }
      };
    }
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
})();
</script>