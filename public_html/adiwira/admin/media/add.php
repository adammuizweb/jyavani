<?php
// /adiwira/admin/media/add.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
?>

<style>
.media-uploader {
  font-family: system-ui, -apple-system, "Segoe UI", Roboto;
}
.dropzone {
  padding: 20px;
  border: 2px dashed #ccc;
  border-radius: 10px;
  text-align: center;
  background: #fafafa;
  transition: .2s;
}
.dropzone.drag {
  background: #eaf8ff;
  border-color: #62b5ff;
}
.adam-btn {
  padding: 6px 14px;
  border-radius: 6px;
  background: #2d8cf0;
  color: #fff;
  cursor: pointer;
  border: none;
}
.upload-progress {
  margin-top: 14px;
}
/* fade out progress row */
.progress-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  opacity: 1;
  transform: translateY(0);
  transition: opacity .35s ease, transform .35s ease;
}
.progress-row.fade {
  opacity: 0;
  transform: translateY(-6px);
}

.progress-row .bar-wrap {
  flex: 1;
  background: #eee;
  height: 8px;
  border-radius: 5px;
  overflow: hidden;
}
.progress-row .bar {
  height: 100%;
  width: 0%;
  background: linear-gradient(90deg,#4caf50,#42a5f5);
  transition: width .3s linear;
}

.preview-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 18px;
}
.thumb {
  width: 130px;
  border-radius: 10px;
  overflow: hidden;
  opacity: 0;
  transform: translateY(6px);
  background: #fff;
  border: 1px solid #eee;
  box-shadow: 0 3px 10px rgba(0,0,0,0.06);
  transition: .25s;
}
.thumb.show {
  opacity: 1;
  transform: none;
}
.thumb img {
  width: 100%;
  height: 90px;
  object-fit: cover;
}
.thumb .meta {
  padding: 8px;
  font-size: .85rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.thumb button {
  background: none;
  border: none;
  color: #2d8cf0;
  cursor: pointer;
  padding: 3px 5px;
}
</style>

<section class="media-uploader">
  <div class="dropzone" id="dropzone">
    <p>Tarik gambar ke sini atau <button class="adam-btn" id="browse-btn">Pilih file</button></p>
    <div id="upload-progress" class="upload-progress"></div>
  </div>

  <div id="preview-container" class="preview-grid"></div>
</section>

<input type="file" id="file-input" accept="image/*" multiple hidden />

<script>
(() => {
  const dz = document.getElementById('dropzone');
  const fileInput = document.getElementById('file-input');
  const browseBtn = document.getElementById('browse-btn');
  const progressWrap = document.getElementById('upload-progress');
  const previewWrap = document.getElementById('preview-container');

  browseBtn.onclick = () => fileInput.click();
  fileInput.onchange = e => handleFiles(e.target.files);

  ['dragenter','dragover'].forEach(ev =>
    dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); })
  );
  ['dragleave','drop'].forEach(ev =>
    dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); })
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

  let j;
  try {
    const up = await fetch('/adiwira/admin/upload_image.php', {
      method: 'POST',
      body: fd
    });
    j = await up.json();
  } catch (err) {
    // network error — show error and keep row (or remove)
    alert('Upload gagal (network): ' + err.message);
    // optionally remove row after a short time
    setTimeout(() => {
      row.classList.add('fade');
      setTimeout(()=>row.remove(), 400);
    }, 2000);
    return;
  }

  if (!j || !j.success) {
    alert('Upload gagal: ' + (j && j.error ? j.error : 'unknown'));
    // keep or remove the row; here we'll fade out after 2s to avoid clutter
    setTimeout(() => {
      row.classList.add('fade');
      setTimeout(()=>row.remove(), 400);
    }, 2000);
    return;
  }

  // mark progress complete
  bar.style.width = '100%';

  // tampilkan thumb di panel Add
  showThumb(j.url, j.media);

  // dispatch event supaya list ter-refresh (index.php mendengarkan)
  try {
    document.dispatchEvent(new CustomEvent('media:added', { detail: j.media }));
  } catch (err) {
    if (typeof window.refreshListPanel === 'function') {
      try { window.refreshListPanel(); } catch(e){/*ignore*/ }
    }
  }

  // setelah sukses, sembunyikan progress row dengan transisi setelah 2 detik
  setTimeout(() => {
    row.classList.add('fade');
    // hapus sepenuhnya setelah animasi selesai
    setTimeout(() => {
      if (row.parentNode) row.parentNode.removeChild(row);
    }, 420); // sedikit lebih dari transition .35s
  }, 2000);
}

  // ---------------------
  // showThumb diperbarui supaya menyimpan data-media-id
  // dan menambahkan tombol remove lokal (opsional)
  // ---------------------
  function showThumb(url, media) {
    const box = document.createElement('div');
    box.className = 'thumb';
    // simpan id media agar bisa di-remove ketika file dihapus di tempat lain
    if (media && media.id) box.dataset.mediaId = media.id;

    box.innerHTML = `
      <img src="${url}" />
      <div class="meta">
        <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:86px">${escapeHtml(media.title || media.filename || '')}</div>
        <div style="display:flex; gap:6px; align-items:center">
          <button class="edit-btn">Edit</button>
          <button class="remove-btn" title="Hapus dari server">Del</button>
        </div>
      </div>
    `;
    previewWrap.prepend(box);
    requestAnimationFrame(() => box.classList.add('show'));

    // Edit -> open single modal
    box.querySelector('.edit-btn').onclick = () => {
      const link = "/adiwira/admin/media/single.php?id=" + encodeURIComponent(media.id);
      if (window.adamModalOpen) adamModalOpen(link, {maxWidth: '800px'});
      else window.open(link);
    };

    // Remove (hapus langsung dari panel Add) => panggil delete endpoint
    box.querySelector('.remove-btn').onclick = async (e) => {
      e.preventDefault();
      if (!confirm('Hapus media ini secara permanen?')) return;
      const fd = new FormData();
      if (media && media.id) fd.append('id', media.id);
      else fd.append('url', url);

      try {
        const res = await fetch('/adiwira/admin/media/delete.php', {
          method: 'POST',
          credentials: 'include',
          body: fd
        });
        const j = await res.json();
        if (j.ok) {
          // remove from preview list
          box.remove();
          // dispatch global event so list and other UI update
          document.dispatchEvent(new CustomEvent('media:deleted', { detail: (j.deleted_ids || j) }));
        } else {
          alert('Error hapus: ' + (j.error || 'unknown'));
        }
      } catch (err) {
        alert('Network error: ' + err.message);
      }
    };
  }

  // small helper untuk escape teks sebelum dimasukkan ke innerHTML
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; });
  }

  // ---------------------
  // Listener global: ketika media dihapus di tempat lain, hapus thumb yang relevan di Add
  // Event detail bisa berbentuk: { id: 123 } atau { deleted_ids: [1,2,3] } atau { ids: [...] }
  // ---------------------
  document.addEventListener('media:deleted', function(e){
    const d = e && e.detail ? e.detail : null;
    if (!d) return;
    // normalize to array of ids
    let ids = [];
    if (Array.isArray(d)) ids = d.map(x => parseInt(x,10)).filter(Boolean);
    else if (d.deleted_ids && Array.isArray(d.deleted_ids)) ids = d.deleted_ids.map(x=>parseInt(x,10)).filter(Boolean);
    else if (d.id) ids = [parseInt(d.id, 10)].filter(Boolean);
    else if (d.ids && Array.isArray(d.ids)) ids = d.ids.map(x=>parseInt(x,10)).filter(Boolean);

    if (ids.length === 0) {
      // maybe server returned a plain ok:true (no id). nothing to remove
      return;
    }

    ids.forEach(id => {
      // remove any preview thumb with matching dataset.mediaId
      const thumb = previewWrap.querySelector('.thumb[data-media-id="'+id+'"]');
      if (thumb) thumb.remove();
    });
  });

  // Optional: when media updated, we may want to update preview titles
  document.addEventListener('media:updated', function(e){
    const d = e && e.detail ? e.detail : null;
    if (!d || !d.id) return;
    const thumb = previewWrap.querySelector('.thumb[data-media-id="'+d.id+'"]');
    if (thumb) {
      const titleEl = thumb.querySelector('.meta div');
      if (titleEl && d.title) titleEl.textContent = d.title;
    }
  });

})();
</script>