var ADMIN_PATH = window.ADMIN_PATH || '/adiwira';
/* public/adiwira/static/js/add/media-selector.js */
(function(){
  function normalizeMedia(detail) {
    if (!detail) return null;
    let m = (detail.media && typeof detail.media === 'object') ? detail.media : detail;
    const context = (detail.context && typeof detail.context === 'object' && !Array.isArray(detail.context))
      ? detail.context
      : ((m.context && typeof m.context === 'object' && !Array.isArray(m.context)) ? m.context : {});
    return {
      id: (m.id != null) ? (parseInt(m.id,10) || null) : null,
      url: (m.url != null) ? String(m.url || '') : '',
      title: (m.title != null) ? String(m.title || '') : '',
      alt: (m.alt != null) ? String(m.alt || '') : '',
      caption: (m.caption != null) ? String(m.caption || '') : '',
      credit: (m.credit != null) ? String(m.credit || '') : '',
      extensions: (m.extensions && typeof m.extensions === 'object' && !Array.isArray(m.extensions)) ? m.extensions : {},
      context: context
    };
  }

  if (!window.normalizeMedia) window.normalizeMedia = normalizeMedia;

  function openMediaSelector(opts) {
    opts = opts || {};
    let url = opts.url || ADMIN_PATH + '/admin/modal_img/index.php?embedded=1';
    const context = opts.context && typeof opts.context === 'object' ? opts.context : null;
    const pickerId = (window.crypto && typeof window.crypto.randomUUID === 'function')
      ? window.crypto.randomUUID()
      : String(Date.now()) + '-' + Math.random().toString(16).slice(2);
    if (context) {
      const params = new URLSearchParams();
      ['surface', 'consumer', 'resource_id', 'field', 'content_locale', 'selection_mode'].forEach(function(key){
        if (context[key] != null && String(context[key]).trim() !== '') params.set('media_' + key, String(context[key]));
      });
      const query = params.toString();
      if (query) url += (url.indexOf('?') >= 0 ? '&' : '?') + query;
    }
    url += (url.indexOf('?') >= 0 ? '&' : '?') + 'media_picker_id=' + encodeURIComponent(pickerId);
    return new Promise(function(resolve, reject){
      let resolved = false;
      let openedWindow = null;

      function cleanup() {
        document.removeEventListener('media:insert', onInsert);
        window.removeEventListener('message', onMessage);
      }

      function onInsert(e) {
        if (resolved) return;
        if (!e || !e.detail || e.detail.picker_id !== pickerId) return;
        resolved = true;
        cleanup();
        try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(e){}
        resolve(e && e.detail ? e.detail : null);
      }

      function onMessage(ev) {
        try {
          if (ev.origin !== window.location.origin || !ev.data || ev.data.picker_id !== pickerId) return;
          let sourceAccepted = ev.source === openedWindow || ev.source === window;
          if (!sourceAccepted) {
            document.querySelectorAll('iframe').forEach(function(frame){
              if (frame.contentWindow === ev.source) sourceAccepted = true;
            });
          }
          if (!sourceAccepted) return;
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
        window.adamModalOpen(url, opts);
      } catch(e){
        openedWindow = window.open(url, '_blank');
        console.warn('modal open fallback to new tab');
      }

      const iv = setInterval(function(){
        const closed = openedWindow
          ? openedWindow.closed
          : !document.getElementById('adam-modal-backdrop');
        if (closed) {
          clearInterval(iv);
          if (!resolved) { cleanup(); resolve(null); }
        }
      }, 200);
    });
  }

  if (!window.openMediaSelector) window.openMediaSelector = openMediaSelector;
})();
