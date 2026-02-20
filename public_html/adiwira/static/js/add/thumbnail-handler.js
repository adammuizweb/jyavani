/* thumbnail-handler.js */
(function(){
  const btn = document.getElementById('btn-open-media-for-thumb');
  const thumbInput = document.getElementById('thumbnail-input');
  const thumbPreview = document.getElementById('thumbnail-preview');
  const thumbClear = document.getElementById('thumbnail-clear');

  if (!btn) return;

  btn.addEventListener('click', function(){
    if (typeof openMediaSelector !== 'function') {
      console.warn('openMediaSelector not available');
      return;
    }
    openMediaSelector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
      .then(function(detail){
        const m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : (detail || null);
        if (!m || !m.url) return;
        if (thumbInput) thumbInput.value = m.url;
        if (thumbPreview) thumbPreview.innerHTML = '<img src="' + String(m.url).replace(/"/g,'&quot;') + '" alt="' + (m.alt || m.title || '') + '" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem;background:#fff">';
      })
      .catch(function(err){ console.error('thumbnail selector error', err); });
  });

  if (thumbClear) thumbClear.addEventListener('click', function(){
    if (thumbInput) thumbInput.value = '';
    if (thumbPreview) thumbPreview.innerHTML = '';
  });
})();
