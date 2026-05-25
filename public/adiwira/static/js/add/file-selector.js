// /adiwira/static/js/add/file-selector.js
(function(){
  function normalizeFile(detail){
    if (!detail) return null;
    const f = (detail.file && typeof detail.file === 'object') ? detail.file : detail;

    const id = (f.id != null) ? (parseInt(f.id, 10) || null) : null;
    const protectedUrl = String(f.protected_url || f.protectedUrl || '');
    const rawUrl = String(f.url || '');
    const url = rawUrl || protectedUrl;

    const out = {
      id: id,
      url: url,
      protected_url: protectedUrl || url,
      filename: String(f.filename || f.title || f.name || f.original_name || ''),
      title: String(f.title || ''),
      caption: String(f.caption || ''),
      credit: String(f.credit || ''),
      mime: String(f.mime || ''),
      ext: String(f.ext || ''),
      size: String(f.size || ''),
      visibility: String(f.visibility || 'public').toLowerCase(),
      storage_disk: String(f.storage_disk || f.storageDisk || 'public').toLowerCase(),
      access_scope: String(f.access_scope || f.accessScope || 'public').toLowerCase(),
      is_downloadable: String(f.is_downloadable != null ? f.is_downloadable : '1')
    };

    const isPdf = /pdf/i.test(String(out.mime || '')) || /\.pdf(?:$|\?)/i.test(String(out.filename || out.url || out.protected_url || ''));
    const isPrivate = out.visibility === 'private' || out.storage_disk === 'private';
    if (out.id && isPdf && isPrivate) {
      out.url = '/private/pdf/view/?id=' + encodeURIComponent(out.id);
      out.protected_url = out.url;
    }
    return out;
  }

  function generateFileShortcode(f) {
    if (!f) return '';

    const isVideo = /^video\//i.test(String(f.mime || '')) || /\.(mp4|webm|mov|avi|mkv|ogg|3gp)(?:$|\?)/i.test(String(f.filename || f.url || ''));
    const isPdf = /pdf/i.test(String(f.mime || '')) || /\.(pdf)(?:$|\?)/i.test(String(f.filename || f.url || ''));
    const isPrivate = String(f.visibility || '').toLowerCase() === 'private' || String(f.storage_disk || '').toLowerCase() === 'private';

    function esc(s) {
      return String(s || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
    }

    if (isVideo) {
      const attrs = [];
      if (f.id) attrs.push('id="' + esc(f.id) + '"');
      if (f.url) attrs.push('url="' + esc(f.url) + '"');
      if (f.mime) attrs.push('mime="' + esc(f.mime) + '"');
      return '<p>[video ' + attrs.join(' ') + ']</p>';
    }

    if (f.id && isPdf && isPrivate) {
      return '<p>[private_pdf id="' + esc(f.id) + '" mode="embed"]</p>';
    }

    if (f.id) {
      var mode = isPdf ? 'embed' : 'card';
      return '<p>[private_file id="' + esc(f.id) + '" mode="' + mode + '"]</p>';
    }

    if (f.url) {
      var label = (f.filename && String(f.filename).trim() !== '') ? String(f.filename).trim() : f.url;
      return '<p><a href="' + esc(f.url) + '" target="_blank" rel="noopener">' + esc(label) + '</a></p>';
    }

    return '';
  }

  if (!window.normalizeFile) window.normalizeFile = normalizeFile;
  if (!window.generateFileShortcode) window.generateFileShortcode = generateFileShortcode;

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