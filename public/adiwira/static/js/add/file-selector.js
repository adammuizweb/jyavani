// /adiwira/static/js/add/file-selector.js
(function(){
  function normalizeFile(detail){
    if (!detail) return null;
    const f = (detail.file && typeof detail.file === 'object') ? detail.file : detail;

    return {
      id: (f.id != null) ? (parseInt(f.id, 10) || null) : null,
      url: (f.url != null) ? String(f.url || '') : '',
      filename: String(f.filename || f.title || f.name || f.original_name || ''),
      mime: String(f.mime || ''),
      size: String(f.size || '')
    };
  }

  if (!window.normalizeFile) window.normalizeFile = normalizeFile;

  function openFileSelector(opts){
    opts = opts || {};
    const url = opts.url || '/adiwira/admin/modal_file/index.php?embedded=1';

    return new Promise(function(resolve){
      let done = false;

      function cleanup(){
        document.removeEventListener('file:insert', onInsert, true);
        window.removeEventListener('message', onMsg, true);
      }

      function finish(payload){
        if (done) return;
        done = true;
        cleanup();
        try { if (typeof window.adamModalClose === 'function') window.adamModalClose(); } catch(e){}
        resolve(payload || null);
      }

      function onInsert(e){
        finish(e && e.detail ? e.detail : null);
      }

      function onMsg(ev){
        try{
          if (!ev || !ev.data) return;
          if (ev.data.type === 'file:insert') finish(ev.data.detail || null);
        }catch(e){}
      }

      // CAPTURE supaya tahan bentrok stopPropagation dari script lain
      document.addEventListener('file:insert', onInsert, true);
      window.addEventListener('message', onMsg, true);

      try{
        window.adamModalOpen(url, opts);
      }catch(e){
        // fallback new tab (catatan: event tidak balik otomatis)
        window.open(url, '_blank');
      }

      // kalau modal ditutup manual, resolve null
      const iv = setInterval(function(){
        const bd = document.getElementById('adam-modal-backdrop');
        if (!bd){
          clearInterval(iv);
          if (!done) finish(null);
        }
      }, 200);
    });
  }

  if (!window.openFileSelector) window.openFileSelector = openFileSelector;
})();