var ADMIN_PATH = window.ADMIN_PATH || '/adiwira';
// media_selector.js
(function(){
  window.ADIWIRA = window.ADIWIRA || {};

  function normalizeMedia(detail) {
    if (!detail) return null;
    let m = (detail.media && typeof detail.media === 'object') ? detail.media : detail;
    return {
      id: (m.id != null) ? (parseInt(m.id,10) || null) : null,
      url: (m.url != null) ? String(m.url || '') : '',
      title: (m.title != null) ? String(m.title || '') : '',
      alt: (m.alt != null) ? String(m.alt || '') : '',
      caption: (m.caption != null) ? String(m.caption || '') : '',
      credit: (m.credit != null) ? String(m.credit || '') : ''
    };
  }

  function openMediaSelector(opts) {
    opts = opts || {};
    const url = opts.url || ADMIN_PATH + '/admin/modal_img/index.php?embedded=1';
    return new Promise(function(resolve, reject){
      let resolved = false;
      function cleanup() {
        document.removeEventListener('media:insert', onInsert);
        window.removeEventListener('message', onMessage);
      }
      function onInsert(e) {
        if (resolved) return;
        resolved = true;
        cleanup();
        try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(e){}
        resolve(e && e.detail ? e.detail : null);
      }
      function onMessage(ev) {
        try {
          if (!ev.data) return;
          if (ev.data.type === 'media:insert') {
            if (resolved) return;
            resolved = true;
            cleanup();
            try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(e){}
            resolve(ev.data.detail || null);
          }
        } catch(e){}
      }
      document.addEventListener('media:insert', onInsert);
      window.addEventListener('message', onMessage);

      try {
        if (typeof window.adamModalOpen === 'function') {
          window.adamModalOpen(url, opts);
        } else {
          window.open(url, '_blank');
        }
      } catch(e){
        try { window.open(url, '_blank'); } catch(e){}
      }

      const iv = setInterval(function(){
        const b = document.getElementById('adam-modal-backdrop');
        if (!b) {
          clearInterval(iv);
          if (!resolved) { cleanup(); resolve(null); }
        }
      }, 200);
    });
  }

  window.normalizeMedia = normalizeMedia;
  window.openMediaSelector = openMediaSelector;
  window.ADIWIRA.media = { normalizeMedia, openMediaSelector };
})();
