<?php
// /adiwira/admin/modal_img/index.php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';

if (function_exists('adiwira_is_navigate_request') && adiwira_is_navigate_request()) {
    http_response_code(404);
    require __DIR__ . '/../../../frontend_404.php';
    exit;
}

[$uid, $role] = adiwira_require_editorial($pdo, false);

$embedded = isset($_GET['embedded']) && (($_GET['embedded'] === '1') || ($_GET['embedded'] === 'true'));

$csrfToken = '';
try { if (function_exists('csrf_token')) $csrfToken = (string)csrf_token(); } catch (Throwable $e) { $csrfToken = ''; }

if (!$embedded):
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Media Modal</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",Roboto;margin:12px;color:#222}
  .tabs{display:flex;gap:8px;margin-bottom:10px}
  .tab{padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#f7f8fa;cursor:pointer}
  .tab.active{background:#0b80ff;color:#fff;border-color:#0b80ff}
  .panel{min-height:200px;padding:8px;border:1px solid #eee;border-radius:8px;background:#fff}
  .small{font-size:.9rem;color:#666}
  .gallery-thumb{width:120px;border:1px solid #eee;border-radius:8px;overflow:hidden;box-shadow:0 3px 8px rgba(0,0,0,.05);position:relative}
  .gallery-thumb img{width:100%;height:90px;object-fit:cover;display:block}
  .thumb-actions{position:absolute;right:6px;bottom:6px;display:flex;gap:6px}
  .thumb-actions button{padding:6px 8px;border-radius:6px;border:0;cursor:pointer;font-size:.85rem}
  .btn-insert{background:#0b80ff;color:#fff}
  .btn-detail{background:#fff;color:#0b80ff;border:1px solid #cfe4ff;padding:6px 8px;border-radius:6px;cursor:pointer}
</style>
</head>
<body>
<?php endif; ?>

<div id="modal-root-wrapper">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

  <div style="display:flex;align-items:center;gap:8px;justify-content:space-between;margin-bottom:8px">
    <div class="tabs" id="tabs">
      <div class="tab active" data-tab="upload">Upload</div>
      <div class="tab" data-tab="gallery">Gallery</div>
    </div>
    <div class="small">Pilih media untuk insert</div>
  </div>

  <div id="panel-upload" class="panel" style="display:block;">
    <?php include __DIR__ . '/add_modal.php'; ?>
  </div>

  <div id="panel-gallery" class="panel" style="display:none;">
    <?php include __DIR__ . '/list_modal.php'; ?>
  </div>
</div>

<script>
(function(){
  function getToastApi(){
    try {
      if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') return window.NewNotifToast;
      if (window.parent && window.parent !== window && window.parent.NewNotifToast && typeof window.parent.NewNotifToast.show === 'function') return window.parent.NewNotifToast;
    } catch(e){}
    return null;
  }

  function modalToast(type, title, message, duration){
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

  window.modalImgToast = window.modalImgToast || modalToast;
})();

/* tabs */
(function(){
  const tabs = document.querySelectorAll('.tab');
  tabs.forEach(t=>{
    t.addEventListener('click', ()=>{
      tabs.forEach(x=>x.classList.remove('active'));
      t.classList.add('active');
      document.getElementById('panel-upload').style.display = t.dataset.tab === 'upload' ? 'block' : 'none';
      document.getElementById('panel-gallery').style.display = t.dataset.tab === 'gallery' ? 'block' : 'none';
    });
  });
})();

function injectHtmlWithScriptsTo(container, html) {
  try {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    let bodyHtml = '';
    Array.from(doc.body.childNodes).forEach(n => {
      if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
      bodyHtml += n.outerHTML || n.textContent;
    });
    container.innerHTML = bodyHtml;

    const scripts = doc.querySelectorAll('script');
    const externals = [];
    scripts.forEach(s => { if (s.src) externals.push(s.src); });

    externals.forEach(src => {
      try {
        if (!document.querySelector('script[src="' + src + '"]')) {
          const el = document.createElement('script');
          el.src = src;
          el.async = false;
          document.head.appendChild(el);
        }
      } catch (e) {
        console.error('inject external script', e);
      }
    });

    scripts.forEach(s => {
      if (!s.src) {
        try {
          const el = document.createElement('script');
          el.text = s.textContent;
          document.body.appendChild(el);
        } catch (e) {
          console.error('inject inline script', e);
        }
      }
    });

  } catch (err) {
    console.error('injectHtmlWithScriptsTo error', err);
    if (container) container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
  }
}

function openSingleDetailInModal(id) {
  if (!id) return;
  const url = '/adiwira/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1';

  const modalContent =
    document.getElementById('adam-modal-content') ||
    (
      window.parent &&
      window.parent.document &&
      window.parent.document.getElementById &&
      window.parent.document.getElementById('adam-modal-content')
        ? window.parent.document.getElementById('adam-modal-content')
        : null
    );

  if (modalContent) {
    modalContent.innerHTML = '<div style="padding:18px;color:#666;font-style:italic">Memuat detail…</div>';
    fetch(url, { credentials: 'include' })
      .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
      .then(html => injectHtmlWithScriptsTo(modalContent, html))
      .catch(err => {
        console.error('Failed to load single_modal:', err);
        modalContent.innerHTML = '<div style="color:#c00;padding:12px">Gagal memuat detail</div>';
        if (typeof window.modalImgToast === 'function') {
          window.modalImgToast('error', 'Gallery', 'Gagal memuat detail media.');
        }
      });
    return;
  }

  const panel = document.getElementById('panel-gallery');
  if (panel) {
    panel.innerHTML = '<div style="padding:18px;color:#666;font-style:italic">Memuat detail…</div>';
    fetch(url, { credentials: 'include' })
      .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
      .then(html => injectHtmlWithScriptsTo(panel, html))
      .catch(err => {
        console.error('Failed to load single_modal into gallery panel:', err);
        panel.innerHTML = '<div style="color:#c00;padding:12px">Gagal memuat detail</div>';
        if (typeof window.modalImgToast === 'function') {
          window.modalImgToast('error', 'Gallery', 'Gagal memuat detail media.');
        }
      });
    return;
  }

  if (typeof window.adamModalOpen === 'function') {
    try {
      window.adamModalOpen(url, { maxWidth: '800px' });
      return;
    } catch(e) {
      console.warn('adamModalOpen failed', e);
    }
  }

  window.open(url, '_blank');
}

document.addEventListener('click', function(ev){
  const btn = ev.target.closest && ev.target.closest('.btn-detail');
  if (!btn) return;
  ev.preventDefault();
  ev.stopPropagation();

  let id = btn.getAttribute('data-id') || btn.getAttribute('data-media-id') || (btn.dataset ? btn.dataset.id : null);
  if (!id) {
    const tr = btn.closest && btn.closest('tr[data-id]');
    if (tr) id = tr.getAttribute('data-id');
  }
  if (!id) {
    if (typeof window.modalImgToast === 'function') {
      window.modalImgToast('warning', 'Gallery', 'ID media tidak ditemukan.');
    }
    return;
  }

  openSingleDetailInModal(id);
}, false);

(function tryEnhanceGallery(){
  try {
    const panel = document.getElementById('panel-gallery');
    if (!panel) return;

    const rows = panel.querySelectorAll('tr[data-id]');
    if (!rows || rows.length === 0) return;

    rows.forEach(tr=>{
      if (tr.__enhanced) return;
      tr.__enhanced = true;

      const id = tr.getAttribute('data-id');
      const img = tr.querySelector('img');
      if (!img) return;

      const wrapper = document.createElement('div');
      wrapper.className = 'gallery-thumb';

      const cloned = img.cloneNode(true);
      wrapper.appendChild(cloned);

      const actions = document.createElement('div');
      actions.className = 'thumb-actions';

      const detailBtn = document.createElement('button');
      detailBtn.className = 'btn-detail';
      detailBtn.type = 'button';
      detailBtn.textContent = 'Detail';
      detailBtn.setAttribute('data-id', id);

      const insertBtn = document.createElement('button');
      insertBtn.className = 'btn-insert';
      insertBtn.type = 'button';
      insertBtn.textContent = 'Insert';

      const src = cloned.src || '';
      insertBtn.setAttribute('data-url', src);

      const alt = tr.getAttribute('data-alt') || (img.getAttribute('alt') || '');
      const title = tr.getAttribute('data-title') || '';
      const caption = tr.getAttribute('data-caption') || '';
      const credit = tr.getAttribute('data-credit') || '';

      if (alt) insertBtn.setAttribute('data-alt', alt);
      if (title) insertBtn.setAttribute('data-title', title);
      if (caption) insertBtn.setAttribute('data-caption', caption);
      if (credit) insertBtn.setAttribute('data-credit', credit);

      actions.appendChild(detailBtn);
      actions.appendChild(insertBtn);
      wrapper.appendChild(actions);

      const td = img.closest && img.closest('td');
      if (td) {
        td.innerHTML = '';
        td.appendChild(wrapper);
      } else {
        const newTd = document.createElement('td');
        newTd.appendChild(wrapper);
        tr.insertBefore(newTd, tr.firstChild);
      }
    });

    panel.addEventListener('click', function(ev){
      const ins = ev.target.closest && ev.target.closest('.btn-insert');
      if (!ins) return;

      ev.preventDefault();
      ev.stopPropagation();

      const thumb = ins.closest && ins.closest('.gallery-thumb');
      const tr = ins.closest && ins.closest('tr[data-id]');
      const id = ins.getAttribute('data-id') || (tr && tr.getAttribute('data-id')) || (thumb && thumb.getAttribute('data-id')) || null;
      const url = ins.getAttribute('data-url') || (thumb && thumb.getAttribute('data-url')) || (tr && tr.querySelector('img') && tr.querySelector('img').src) || '';
      const title = (ins.getAttribute('data-title') || (thumb && thumb.getAttribute('data-title')) || (tr && tr.getAttribute('data-title')) || '').trim();
      const alt = (ins.getAttribute('data-alt') || (thumb && thumb.getAttribute('data-alt')) || (tr && tr.getAttribute('data-alt')) || '').trim();
      const caption = (ins.getAttribute('data-caption') || (thumb && thumb.getAttribute('data-caption')) || (tr && tr.getAttribute('data-caption')) || '').trim();
      const credit = (ins.getAttribute('data-credit') || (thumb && thumb.getAttribute('data-credit')) || (tr && tr.getAttribute('data-credit')) || '').trim();

      const detail = {
        id: id ? parseInt(id, 10) : null,
        url,
        title,
        alt,
        caption,
        credit
      };

      try { document.dispatchEvent(new CustomEvent('media:insert', { detail })); } catch(e){}
      try {
        if (window.parent && window.parent !== window) {
          window.parent.postMessage({ type: 'media:insert', detail }, '*');
        }
      } catch(e){}
    }, false);

  } catch(e) {}
})();
</script>

<?php if (!$embedded): ?>
</body>
</html>
<?php endif; ?>