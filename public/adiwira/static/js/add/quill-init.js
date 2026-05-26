(function(){
  function loadScript(src){
    return new Promise(function(res,rej){
      if (document.querySelector('script[src="'+src+'"]')) return res();
      var s=document.createElement('script');
      s.src=src;
      s.async=true;
      s.onload=res;
      s.onerror=function(){rej(new Error('Failed load '+src))};
      document.head.appendChild(s);
    });
  }

  function loadCss(href){
    return new Promise(function(res,rej){
      if (document.querySelector('link[href="'+href+'"]')) return res();
      var l=document.createElement('link');
      l.rel='stylesheet';
      l.href=href;
      l.onload=res;
      l.onerror=function(){rej(new Error('Failed load css '+href))};
      document.head.appendChild(l);
    });
  }

  function uiToast(type, title, message, duration) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({
        type: type || 'info',
        title: title || null,
        message: message || '',
        duration: duration
      });
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function escapeXml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function createRemovedMediaSvgDataUrl(opts) {
    opts = opts || {};
    const width = Math.max(320, parseInt(opts.width, 10) || 1200);
    const height = Math.max(180, parseInt(opts.height, 10) || 675);
    const title = String(opts.title || 'Media telah dihapus');
    const subtitle = String(opts.subtitle || 'dari gallery');

    const svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + title + ' ' + subtitle + '">'
      + '<defs>'
      + '<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
      + '<stop offset="0%" stop-color="#fff7f7"/>'
      + '<stop offset="100%" stop-color="#ffe9e9"/>'
      + '</linearGradient>'
      + '</defs>'
      + '<rect width="100%" height="100%" rx="28" fill="url(#bg)"/>'
      + '<rect x="14" y="14" width="' + (width - 28) + '" height="' + (height - 28) + '" rx="22" fill="none" stroke="#f1b5b5" stroke-width="4" stroke-dasharray="16 12"/>'
      + '<g transform="translate(' + (width / 2) + ' ' + (height / 2 - 70) + ')">'
      + '<rect x="-130" y="-82" width="260" height="164" rx="22" fill="#ffffff" stroke="#ef4444" stroke-width="10"/>'
      + '<circle cx="-42" cy="-26" r="22" fill="#fecaca"/>'
      + '<path d="M-98 42l54-54 36 36 28-28 72 72H-98z" fill="#fca5a5"/>'
      + '<path d="M-160 122L160-122" stroke="#dc2626" stroke-width="20" stroke-linecap="round"/>'
      + '</g>'
      + '<text x="50%" y="' + (height - 108) + '" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="44" font-weight="700" fill="#991b1b">' + escapeXml(title) + '</text>'
      + '<text x="50%" y="' + (height - 56) + '" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="30" fill="#7f1d1d">' + escapeXml(subtitle) + '</text>'
      + '</svg>';

    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
  }

  function buildUrlSignature(input) {
    const sig = new Set();
    const raw = String(input || '').trim();
    if (!raw) return sig;

    sig.add(raw);

    try {
      const abs = new URL(raw, window.location.origin).href;
      const urlObj = new URL(abs);

      sig.add(abs);
      sig.add(urlObj.pathname);
      sig.add(urlObj.pathname.replace(/\/+$/, '') || '/');
    } catch (e) {
      if (raw.charAt(0) === '/') {
        sig.add(raw.replace(/\/+$/, '') || '/');
      }
    }

    return sig;
  }

  function hasSignatureMatch(a, b) {
    if (!(a instanceof Set) || !(b instanceof Set) || a.size < 1 || b.size < 1) {
      return false;
    }
    for (const v of a) {
      if (b.has(v)) return true;
    }
    return false;
  }

  const quillReady = (typeof Quill !== 'undefined') ? Promise.resolve() : Promise.all([
    loadCss('https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css'),
    loadScript('https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js')
  ]);

  quillReady.then(function(){
    if (!window.__adam_quill_instance) {
      window.__adam_quill_instance = new Quill('#quill-editor', {
        modules: {
          toolbar: [
            [{ header: [1,2,3,4,5,6,false] }],
            ['bold','italic','underline','strike'],
            [{ color: [] }, { background: [] }],
            [{ script: 'sub' }, { script: 'super' }],
            [{ list: 'ordered' }, { list: 'bullet' }],
            [{ indent: '-1' }, { indent: '+1' }],
            [{ align: [] }],
            ['blockquote','code-block'],
            ['link','image','video'],
            [{ size: ['small', false, 'large', 'huge'] }],
            ['clean']
          ]
        },
        theme: 'snow',
        placeholder: 'Tulis konten artikel di sini...'
      });

      try {
        var initial = document.getElementById('content-input') ? document.getElementById('content-input').value : '';
        if (initial) window.__adam_quill_instance.root.innerHTML = initial;
      } catch(e){
        console.warn('restore quill initial content error', e);
      }
    }

    (function attachQuillFileOnVideo(){
      const quill = window.__adam_quill_instance;
      if (!quill) return;

      let lastRange = null;
      quill.on('selection-change', function(range){
        if (range) lastRange = range;
      });

      function escapeAttr(s){
        return String(s || '')
          .replace(/&/g,'&amp;')
          .replace(/</g,'&lt;')
          .replace(/>/g,'&gt;')
          .replace(/"/g,'&quot;')
          .replace(/'/g,'&#39;');
      }

      async function quillVideoAsFileHandler(){
        lastRange = quill.getSelection(true);

        if (typeof openFileSelector !== 'function') {
          console.warn('openFileSelector not available');
          return;
        }

        const picked = await openFileSelector({
          url: '/adiwira/admin/modal_file/index.php?embedded=1',
          maxWidth: '980px'
        });

        const f = (typeof normalizeFile === 'function') ? normalizeFile(picked) : picked;
        if (!f) return;

        const insertAt = (lastRange && typeof lastRange.index === 'number')
          ? lastRange.index
          : (quill.getLength() - 1);

        const htmlToInsert = (typeof generateFileShortcode === 'function')
          ? generateFileShortcode(f)
          : '';

        if (!htmlToInsert) return;

        try{
          quill.clipboard.dangerouslyPasteHTML(insertAt, htmlToInsert);
          quill.setSelection(insertAt + 2, 0);
        }catch(e){
          console.error('Insert file shortcode failed', e);
        }
      }

      try{
        const toolbar = quill.getModule('toolbar');
        if (toolbar) {
          toolbar.addHandler('video', quillVideoAsFileHandler);
        }
      }catch(e){
        console.warn('Could not attach video handler', e);
      }
    })();

    const quill = window.__adam_quill_instance;
    let lastRange = null;
    quill.on('selection-change', function(range){ if (range) lastRange = range; });

    function escapeAttr(s){
      if (s == null) return '';
      return String(s)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
    }

    function applyRemovedPlaceholderToImage(img, mediaId) {
      if (!img) return;

      const removedSrc = createRemovedMediaSvgDataUrl({
        width: 1200,
        height: 675,
        title: 'Media telah dihapus',
        subtitle: 'dari gallery'
      });

      img.setAttribute('src', removedSrc);
      img.setAttribute('alt', 'Media telah dihapus dari gallery');
      img.setAttribute('title', 'Media telah dihapus dari gallery');
      img.setAttribute('data-media-removed', '1');

      img.removeAttribute('data-caption');
      img.removeAttribute('srcset');
      img.style.background = '#fff7f7';
      img.style.border = '1px solid #f1b5b5';
      img.style.borderRadius = '8px';
    }

    function handleDeletedMedia(detail) {
      if (!detail || !quill || !quill.root) return;

      const deletedUrlSig = new Set();

      const rawUrls = [];
      if (detail.url) rawUrls.push(detail.url);
      if (Array.isArray(detail.deleted_urls)) rawUrls.push.apply(rawUrls, detail.deleted_urls);

      rawUrls.forEach(function(u){
        const sig = buildUrlSignature(u);
        sig.forEach(function(v){ deletedUrlSig.add(v); });
      });

      if (deletedUrlSig.size < 1) return;

      const imgs = Array.from(quill.root.querySelectorAll('img'));
      let changed = false;

      imgs.forEach(function(img){
        if (img.getAttribute('data-media-removed') === '1') return;

        const srcRaw = String(img.getAttribute('src') || img.src || '').trim();
        const srcSig = buildUrlSignature(srcRaw);

        if (!hasSignatureMatch(srcSig, deletedUrlSig)) return;

        changed = true;
        applyRemovedPlaceholderToImage(img, '');
      });

      if (changed) {
        try {
          quill.update('silent');
        } catch (e) {
          console.warn('quill.update silent failed', e);
        }

        const contentInput = document.getElementById('content-input');
        if (contentInput) {
          contentInput.value = quill.root.innerHTML;
        }

        uiToast('warning', 'Editor', 'Gambar yang dihapus dari gallery diubah ke placeholder pada editor.', 3200);
      }
    }

    function quillImageHandler() {
      lastRange = quill.getSelection(true);

      if (typeof openMediaSelector !== 'function') {
        console.warn('openMediaSelector not available');
        return;
      }

      openMediaSelector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
      .then(function(detail){
        const m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : (detail || null);
        if (!m || !m.url) return;

        const insertAt = (lastRange && typeof lastRange.index === 'number') ? lastRange.index : quill.getLength() - 1;

        function _escapeAttr(s) { return escapeAttr(s); }

        const attrs = [];
        if (m.caption) attrs.push('data-caption="' + _escapeAttr(m.caption) + '"');

        const altVal = (m.alt && String(m.alt).trim() !== '') ? String(m.alt) : (m.title ? String(m.title) : '');
        const titleVal = m.title ? String(m.title) : '';

        const imgHtml =
          '<img src="' + _escapeAttr(m.url) + '"'
            + (attrs.length ? ' ' + attrs.join(' ') : '')
            + (altVal ? ' alt="' + _escapeAttr(altVal) + '"' : '')
            + (titleVal ? ' title="' + _escapeAttr(titleVal) + '"' : '')
          + '>';

        let htmlToInsert = imgHtml;
        if (m.caption && String(m.caption).trim() !== '') {
          htmlToInsert = '<figure>' + imgHtml + '<figcaption>' + _escapeAttr(m.caption) + '</figcaption></figure>';
        }

        try {
          quill.clipboard.dangerouslyPasteHTML(insertAt, htmlToInsert);
        } catch (e) {
          console.error('Insert image failed', e);
        }

        setTimeout(() => {
          try {
            const imgs = Array.from(quill.root.querySelectorAll('img'));
            let target = null;

            for (let i = imgs.length - 1; i >= 0; i--) {
              const img = imgs[i];
              const imgSrc = img.getAttribute('src') || img.src || '';
              const imgSig = buildUrlSignature(imgSrc);
              const targetSig = buildUrlSignature(m.url);

              if (hasSignatureMatch(imgSig, targetSig)) {
                target = img;
                break;
              }
            }

            if (!target) {
              const rev = Array.from(quill.root.querySelectorAll('img')).reverse();
              target = rev[0] || null;
            }

            if (target) {
              if (m.title) target.setAttribute('title', String(m.title));
              if (altVal) target.setAttribute('alt', altVal);
              if (m.caption) target.setAttribute('data-caption', String(m.caption));
            }
          } catch (err) {
            console.error('annotate image node error', err);
          }
        }, 60);
      })
      .catch(function(err){
        console.error('media selector error', err);
      });
    }

    try {
      const toolbar = quill.getModule('toolbar');
      if (toolbar) toolbar.addHandler('image', quillImageHandler);
    } catch (e) {
      console.warn('Could not attach image handler to Quill toolbar', e);
    }

    (function attachFormSubmit(){
      const formId = window.ADIWIRA && window.ADIWIRA_FORM_ID ? window.ADIWIRA_FORM_ID : 'post-add-form';
      const form = document.getElementById(formId) || document.querySelector('form#post-add-form') || document.querySelector('form');
      if (!form) return;
      form.addEventListener('submit', function(){
        const contentInput = document.getElementById('content-input');
        if (contentInput) contentInput.value = quill.root.innerHTML;
      });
    })();

    function onDeletedEvent(detail){
      handleDeletedMedia(detail);
    }

    document.addEventListener('media:deleted', function(e){
      onDeletedEvent(e && e.detail ? e.detail : null);
    });

    window.addEventListener('media:deleted', function(e){
      onDeletedEvent(e && e.detail ? e.detail : null);
    });

    window.addEventListener('message', function(ev){
      try {
        if (!ev.data || ev.data.type !== 'media:deleted') return;
        onDeletedEvent(ev.data.detail || null);
      } catch (err) {
        console.error('quill media:deleted message error', err);
      }
    });

  }).catch(function(err){
    console.error('Failed to load quill:', err);
  });
})();