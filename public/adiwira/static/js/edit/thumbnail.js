// thumbnail.js
(function(){
  window.ADIWIRA = window.ADIWIRA || {};

  function initThumbnail() {
    const thumbBtn = document.getElementById('btn-open-media-for-thumb');
    const thumbnailInput = document.getElementById('thumbnail-input');
    const thumbnailPreview = document.getElementById('thumbnail-preview');
    const thumbnailClear = document.getElementById('thumbnail-clear');

    if (thumbBtn) {
      thumbBtn.addEventListener('click', function(){
        if (typeof window.openMediaSelector !== 'function') {
          console.warn('openMediaSelector not available - fallback to modal page');
          try { if (typeof window.adamModalOpen === 'function') window.adamModalOpen('/adiwira/admin/modal_img/index.php?embedded=1'); else window.open('/adiwira/admin/modal_img/index.php?embedded=1','_blank'); } catch(e){ window.open('/adiwira/admin/modal_img/index.php?embedded=1','_blank'); }
          return;
        }

        window.openMediaSelector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
          .then(function(detail){
            const m = (typeof window.normalizeMedia === 'function') ? window.normalizeMedia(detail) : (detail || null);
            if (!m || !m.url) return;

            if (thumbnailInput) thumbnailInput.value = m.url;
            if (thumbnailPreview) thumbnailPreview.innerHTML = '<img src="'+String(m.url).replace(/"/g,"&quot;")+'" alt="'+(m.alt || m.title || '')+'" style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem;background:#fff">';
            try { thumbnailInput.dispatchEvent(new Event('input', { bubbles: true })); } catch(e){}
            try { thumbnailInput.dispatchEvent(new Event('change', { bubbles: true })); } catch(e){}
            try { if (typeof window.ADIWIRA.live !== 'undefined' && typeof window.ADIWIRA.live.debSend === 'function') window.ADIWIRA.live.debSend(); } catch(e){}
          })
          .catch(function(err){
            console.error('thumbnail selector error', err);
            alert('Gagal memilih thumbnail: ' + (err && err.message ? err.message : err));
          });
      });
    }

    if (thumbnailClear) thumbnailClear.addEventListener('click', function(){
      if (thumbnailInput) {
        thumbnailInput.value = '';
        try { thumbnailInput.dispatchEvent(new Event('input', { bubbles: true })); } catch(e){}
        try { thumbnailInput.dispatchEvent(new Event('change', { bubbles: true })); } catch(e){}
      }
      if (thumbnailPreview) thumbnailPreview.innerHTML = '';
      try { if (typeof window.ADIWIRA.live !== 'undefined' && typeof window.ADIWIRA.live.debSend === 'function') window.ADIWIRA.live.debSend(); } catch(e){}
    });
  }

  window.ADIWIRA.thumbnail = { initThumbnail };
})();
