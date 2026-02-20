<?php
// /adiwira/admin/modal_img/index.php
if (!defined('DASHBOARD_CONTEXT')) define('DASHBOARD_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// This page is a convenience standalone wrapper for the modal fragments.
// When embedding into your modal, prefer to fetch list_modal.php?embedded=1 etc.
$embedded = isset($_GET['embedded']) && ($_GET['embedded'] === '1' || $_GET['embedded'] === 'true');

if (!$embedded):
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Media Modal</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{font-family:system-ui, -apple-system, "Segoe UI", Roboto; margin:12px; color:#222}
  .tabs{display:flex;gap:8px;margin-bottom:10px}
  .tab{padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#f7f8fa;cursor:pointer}
  .tab.active{background:#0b80ff;color:#fff;border-color:#0b80ff}
  .panel{min-height:200px;padding:8px;border:1px solid #eee;border-radius:8px;background:#fff}
  .small{font-size:.9rem;color:#666}
  /* gallery thumb helpers (if list_modal outputs table, we don't override their styles) */
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
/**
 * modal_img/index.js (inline)
 *
 * - injectHtmlWithScriptsTo(container, html): inject & execute scripts from fetched HTML
 * - openSingleDetailInModal(id): fetch single_modal.php and inject into modal content (best effort)
 * - delegated click handler: .btn-detail now opens single modal using the helper
 */

// simple tabs for standalone index.php
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

// helper: inject HTML fragment into a container and execute scripts (external & inline)
function injectHtmlWithScriptsTo(container, html) {
  try {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    // build inner html excluding <script>
    let bodyHtml = '';
    Array.from(doc.body.childNodes).forEach(n => {
      if (n.nodeName && n.nodeName.toLowerCase() === 'script') return;
      bodyHtml += n.outerHTML || n.textContent;
    });
    container.innerHTML = bodyHtml;

    // execute scripts preserving order: external (append to head), inline (append to body)
    const scripts = doc.querySelectorAll('script');
    // We'll append external scripts sequentially to preserve order
    const externals = [];
    scripts.forEach(s => { if (s.src) externals.push(s.src); });

    // Append externals synchronously (not awaiting load to avoid blocking UI heavily)
    externals.forEach(src => {
      try {
        // avoid duplicate loads
        if (!document.querySelector('script[src="' + src + '"]')) {
          const el = document.createElement('script');
          el.src = src;
          el.async = false;
          document.head.appendChild(el);
        }
      } catch (e) { console.error('inject external script', e); }
    });

    // Append inline scripts
    scripts.forEach(s => {
      if (!s.src) {
        try {
          const el = document.createElement('script');
          el.text = s.textContent;
          document.body.appendChild(el);
        } catch (e) { console.error('inject inline script', e); }
      }
    });

  } catch (err) {
    console.error('injectHtmlWithScriptsTo error', err);
    if (container) container.innerHTML = '<div style="color:#c00">Gagal memuat konten.</div>';
  }
}

// helper: open single detail inside existing modal-content (if available) or inside our panel
function openSingleDetailInModal(id) {
  if (!id) return;
  const url = '/adiwira/admin/modal_img/single_modal.php?id=' + encodeURIComponent(id) + '&embedded=1';

  // prefer to inject into parent modal container if present
  const modalContent = document.getElementById('adam-modal-content') || (window.parent && window.parent.document && window.parent.document.getElementById && window.parent.document.getElementById('adam-modal-content') ? window.parent.document.getElementById('adam-modal-content') : null);

  if (modalContent) {
    // show loading state
    modalContent.innerHTML = '<div style="padding:18px;color:#666;font-style:italic">Memuat detail…</div>';
    fetch(url, { credentials: 'include' })
      .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
      .then(html => injectHtmlWithScriptsTo(modalContent, html))
      .catch(err => {
        console.error('Failed to load single_modal:', err);
        modalContent.innerHTML = '<div style="color:#c00;padding:12px">Gagal memuat detail</div>';
      });
    return;
  }

  // else try to inject into our local panel-gallery (replace contents)
  const panel = document.getElementById('panel-gallery');
  if (panel) {
    panel.innerHTML = '<div style="padding:18px;color:#666;font-style:italic">Memuat detail…</div>';
    fetch(url, { credentials: 'include' })
      .then(res => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
      .then(html => injectHtmlWithScriptsTo(panel, html))
      .catch(err => {
        console.error('Failed to load single_modal into gallery panel:', err);
        panel.innerHTML = '<div style="color:#c00;padding:12px">Gagal memuat detail</div>';
      });
    return;
  }

  // fallback: open via adamModalOpen if available
  if (typeof window.adamModalOpen === 'function') {
    try { window.adamModalOpen(url, { maxWidth: '800px' }); return; } catch(e){ console.warn('adamModalOpen failed', e); }
  }

  // last fallback: open in new tab
  window.open(url, '_blank');
}

// Delegated click handler: intercept .btn-detail anywhere inside this injected fragment
document.addEventListener('click', function(ev){
  const btn = ev.target.closest && ev.target.closest('.btn-detail');
  if (!btn) return;
  ev.preventDefault();
  ev.stopPropagation();

  // try to read data-id or data-media-id or data-id
  let id = btn.getAttribute('data-id') || btn.getAttribute('data-media-id') || btn.dataset && btn.dataset.id;
  if (!id) {
    // maybe the button sits in a <tr data-id>
    const tr = btn.closest && btn.closest('tr[data-id]');
    if (tr) id = tr.getAttribute('data-id');
  }
  if (!id) {
    console.warn('Detail button clicked but no id found');
    return;
  }

  // open single in modal/panel
  openSingleDetailInModal(id);
}, false);

// Enhance gallery visuals: convert table rows into thumb cards with Insert & Detail buttons
// (Optional helper — if list_modal already contains markup with .btn-insert/.btn-detail this will be skipped)
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
      // carry through useful data attributes if present on original row/thumb
      const src = cloned.src || '';
      insertBtn.setAttribute('data-url', src);

      // try to read data-* from the original row or from cells
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

    // delegated handler for Insert (dispatch media:insert) - enriched payload
    panel.addEventListener('click', function(ev){
      const ins = ev.target.closest && ev.target.closest('.btn-insert');
      if (!ins) return;
      ev.preventDefault();
      ev.stopPropagation();
      // prefer attributes from the button, fallback to closest .gallery-thumb or tr
      const thumb = ins.closest && ins.closest('.gallery-thumb');
      const tr = ins.closest && ins.closest('tr[data-id]');
      const id = ins.getAttribute('data-id') || (tr && tr.getAttribute('data-id')) || (thumb && thumb.getAttribute('data-id')) || null;
      const url = ins.getAttribute('data-url') || (thumb && thumb.getAttribute('data-url')) || (tr && tr.querySelector('img') && tr.querySelector('img').src) || '';
      const title = (ins.getAttribute('data-title') || (thumb && thumb.getAttribute('data-title')) || (tr && tr.getAttribute('data-title')) || '').trim();
      const alt = (ins.getAttribute('data-alt') || (thumb && thumb.getAttribute('data-alt')) || (tr && tr.getAttribute('data-alt')) || '').trim();
      const caption = (ins.getAttribute('data-caption') || (thumb && thumb.getAttribute('data-caption')) || (tr && tr.getAttribute('data-caption')) || '').trim();
      const credit = (ins.getAttribute('data-credit') || (thumb && thumb.getAttribute('data-credit')) || (tr && tr.getAttribute('data-credit')) || '').trim();

      const detail = {
        id: id ? parseInt(id,10) : null,
        url: url,
        title: title,
        alt: alt,
        caption: caption,
        credit: credit
      };
      try { document.dispatchEvent(new CustomEvent('media:insert', { detail: detail })); } catch(e){ console.warn('dispatch media:insert', e); }
      try { if (window.parent && window.parent !== window) window.parent.postMessage({ type: 'media:insert', detail: detail }, '*'); } catch(e){}
    }, false);

  } catch(e) { /* ignore enhancement errors */ }
})();
</script>

<?php if (!$embedded): ?>
</body>
</html>
<?php endif; ?>
