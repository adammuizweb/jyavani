// public/adiwira/static/js/edit/quill.js
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  let quill = null;
  let suppress = false;

  const complexPattern =
    /<(script|style|iframe|embed|object|form|svg|canvas|php|link|meta|table|thead|tbody|tfoot|tr|th|td)[\s>]|on[a-z]+\s*=/i;

  const canonical = document.getElementById('content-textarea');
  const EDITOR_IMG_MAX_WIDTH = 560;
  const EDITOR_IMG_MAX_HEIGHT = 460;

  const FULL_TOOLBAR = [
    [{ header: [1,2,3,4,5,6, false] }],
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
  ];

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

  function isContentComplex() {
    try {
      return complexPattern.test((canonical && canonical.value || '').trim());
    } catch (e) {
      return false;
    }
  }

  function normalizeHtmlForCompare(html) {
    html = String(html || '').trim();
    html = html.replace(/<p>\s*(?:&nbsp;|\uFEFF|<br\s*\/?>)?\s*<\/p>/gi, '');
    html = html.replace(/\s+/g, ' ').trim();
    return html;
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

  function getMediaSelector() {
    if (typeof window.openMediaSelector === 'function') return window.openMediaSelector;
    if (window.ADIWIRA && window.ADIWIRA.media && typeof window.ADIWIRA.media.openMediaSelector === 'function') {
      return window.ADIWIRA.media.openMediaSelector;
    }
    return null;
  }

  function getFileSelector() {
    if (typeof window.openFileSelector === 'function') return window.openFileSelector;
    if (window.ADIWIRA && window.ADIWIRA.file && typeof window.ADIWIRA.file.openFileSelector === 'function') {
      return window.ADIWIRA.file.openFileSelector;
    }
    return null;
  }

  function normalizeMedia(detail) {
    if (typeof window.normalizeMedia === 'function') return window.normalizeMedia(detail);
    if (!detail) return null;

    const m = detail.media && typeof detail.media === 'object' ? detail.media : detail;
    return {
      id: (m.id != null) ? (parseInt(m.id, 10) || null) : null,
      url: (m.url != null) ? String(m.url || '') : '',
      title: (m.title != null) ? String(m.title || '') : '',
      alt: (m.alt != null) ? String(m.alt || '') : '',
      caption: (m.caption != null) ? String(m.caption || '') : '',
      credit: (m.credit != null) ? String(m.credit || '') : ''
    };
  }

  function applyEditorImageDisplay(img) {
    // no-op: display is handled by CSS (.adam-quill .ql-editor img)
  }

  function normalizeEditorImages(rootEl) {
    if (!rootEl) return;
    const imgs = Array.from(rootEl.querySelectorAll('img'));
    imgs.forEach(function(img){
      applyEditorImageDisplay(img);
    });
  }

  function applyAttributesToInsertedImage(m) {
    try {
      if (!m || !m.url) return;
      const editorEl = document.getElementById('quill-editor');
      if (!editorEl) return;

      const imgs = Array.from(editorEl.querySelectorAll('img')).filter(function(i){
        try {
          return i.getAttribute && (i.getAttribute('src') === m.url);
        } catch (e) {
          return false;
        }
      });

      if (!imgs.length) {
        const srcEnds = m.url.split('/').slice(-1)[0];
        const altc = Array.from(editorEl.querySelectorAll('img')).filter(function(i){
          try {
            return i.getAttribute
              && String(i.getAttribute('src') || '').endsWith(srcEnds)
              && !i.dataset.adiwiraApplied;
          } catch (e) {
            return false;
          }
        });
        if (altc.length) imgs.push.apply(imgs, altc);
      }

      let target = null;
      for (let i = imgs.length - 1; i >= 0; i--) {
        const img = imgs[i];
        if (!img.dataset || !img.dataset.adiwiraApplied) {
          target = img;
          break;
        }
      }
      if (!target && imgs.length) target = imgs[imgs.length - 1];
      if (!target) return;

      if (m.alt) target.setAttribute('alt', m.alt);
      if (m.title) target.setAttribute('title', m.title);
      if (m.id != null) target.setAttribute('data-media-id', String(m.id));
      if (m.url) target.setAttribute('data-media-url', String(m.url));
      if (m.caption) target.setAttribute('data-caption', m.caption);
      if (m.credit) target.setAttribute('data-credit', m.credit);

      target.dataset.adiwiraApplied = '1';
      applyEditorImageDisplay(target);
    } catch (e) {
      console.warn('[quill] applyAttributesToInsertedImage error', e);
    }
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

    if (mediaId) {
      img.setAttribute('data-removed-media-id', String(mediaId));
    }

    img.removeAttribute('data-media-id');
    img.removeAttribute('data-media-url');
    img.removeAttribute('data-caption');
    img.removeAttribute('data-credit');
    img.removeAttribute('srcset');

    img.style.background = '#fff7f7';
    img.style.border = '1px solid #f1b5b5';
    img.style.borderRadius = '8px';

    applyEditorImageDisplay(img);

    const figure = img.closest && img.closest('figure');
    if (figure) {
      const cap = figure.querySelector('figcaption');
      if (cap) {
        cap.textContent = 'Media telah dihapus dari gallery';
      }
    }
  }

  function handleDeletedMedia(detail) {
    if (!detail || !quill || !quill.root) return;

    const ids = new Set();
    const deletedUrlSig = new Set();

    if (detail.id != null) {
      const n = parseInt(detail.id, 10);
      if (Number.isFinite(n) && n > 0) ids.add(String(n));
    }

    if (Array.isArray(detail.deleted_ids)) {
      detail.deleted_ids.forEach(function(v){
        const n = parseInt(v, 10);
        if (Number.isFinite(n) && n > 0) ids.add(String(n));
      });
    }

    const rawUrls = [];
    if (detail.url) rawUrls.push(detail.url);
    if (Array.isArray(detail.deleted_urls)) rawUrls.push.apply(rawUrls, detail.deleted_urls);

    rawUrls.forEach(function(u){
      const sig = buildUrlSignature(u);
      sig.forEach(function(v){ deletedUrlSig.add(v); });
    });

    if (ids.size < 1 && deletedUrlSig.size < 1) return;

    const editorEl = document.getElementById('quill-editor');
    if (!editorEl) return;

    const imgs = Array.from(editorEl.querySelectorAll('img'));
    let changed = false;

    imgs.forEach(function(img){
      if (img.getAttribute('data-media-removed') === '1') return;

      const mediaId = String(img.getAttribute('data-media-id') || '').trim();
      const mediaUrl = String(img.getAttribute('data-media-url') || '').trim();
      const srcRaw = String(img.getAttribute('src') || img.src || '').trim();

      const sigA = buildUrlSignature(mediaUrl || srcRaw);
      const sigB = buildUrlSignature(srcRaw);

      const matchById = mediaId !== '' && ids.has(mediaId);
      const matchByUrl = hasSignatureMatch(sigA, deletedUrlSig) || hasSignatureMatch(sigB, deletedUrlSig);

      if (!matchById && !matchByUrl) return;

      changed = true;
      applyRemovedPlaceholderToImage(img, mediaId || '');
    });

    if (changed) {
      normalizeEditorImages(editorEl);

      try {
        quill.update('silent');
      } catch (e) {}

      if (canonical) {
        canonical.value = quill.root.innerHTML;
      }

      uiToast(
        'warning',
        'Editor',
        'Gambar yang dihapus dari gallery diubah ke placeholder pada editor.',
        3200
      );
    }
  }

  function attachToolbarHandlers(localQuill) {
    try {
      const tb = localQuill.getModule && localQuill.getModule('toolbar');
      if (!tb || typeof tb.addHandler !== 'function') return;

      tb.addHandler('image', function() {
        const selector = getMediaSelector();

        if (!selector) {
          const url = prompt('Masukkan URL gambar:');
          if (url) {
            const range = localQuill.getSelection(true) || {
              index: localQuill.getLength(),
              length: 0
            };

            localQuill.insertEmbed(range.index, 'image', url, 'user');

            setTimeout(function(){
              applyAttributesToInsertedImage({ url: url });
              normalizeEditorImages(document.getElementById('quill-editor'));
            }, 40);

            localQuill.setSelection(range.index + 1, 0);
          }
          return;
        }

        selector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
          .then(function(detail){
            const m = normalizeMedia(detail);
            if (!m || !m.url) return;

            const range = localQuill.getSelection(true) || {
              index: localQuill.getLength(),
              length: 0
            };

            localQuill.insertEmbed(range.index, 'image', m.url, 'user');

            setTimeout(function(){
              applyAttributesToInsertedImage(m);
              normalizeEditorImages(document.getElementById('quill-editor'));
            }, 40);

            localQuill.setSelection(range.index + 1, 0);
          })
          .catch(function(err){
            console.warn('[quill:image handler] selector error', err);
          });
      });

      tb.addHandler('video', function () {
        const pickFile = getFileSelector();
        if (!pickFile) {
          alert('File selector belum dimuat. Pastikan /adiwira/static/js/add/file-selector.js di-include di edit.php');
          return;
        }

        const range = localQuill.getSelection(true) || {
          index: localQuill.getLength() - 1,
          length: 0
        };

        pickFile({
          url: '/adiwira/admin/modal_file/index.php?embedded=1',
          maxWidth: '980px'
        }).then(function (picked) {
          const f = (typeof window.normalizeFile === 'function') ? window.normalizeFile(picked) : picked;
          if (!f) return;

          const htmlToInsert = (typeof window.generateFileShortcode === 'function')
            ? window.generateFileShortcode(f)
            : '';

          if (!htmlToInsert) return;

          localQuill.clipboard.dangerouslyPasteHTML(range.index, htmlToInsert);
          localQuill.setSelection(range.index + 2, 0);
        }).catch(function (err) {
          console.warn('[file selector] error', err);
        });
      });

      console.debug('[quill] toolbar handlers attached');
    } catch (e) {
      console.warn('[quill] attachToolbarHandlers failed', e);
    }
  }

  function initQuill() {
    if (quill) return;

    if (isContentComplex()) {
      window.ADIWIRA.quillDisabled = true;
      const qRadio = document.getElementById('editor-quill');
      if (qRadio) {
        qRadio.setAttribute('data-quill-disabled', '1');
        qRadio.title = 'Konten mengandung blok kompleks — klik untuk tindakan (Hapus kode lama atau Batalkan).';
      }
      console.info('[quill] init skipped (complex content).');
      return;
    }

    if (typeof window.Quill !== 'function') {
      console.warn('[quill] Quill not loaded');
      return;
    }

    const editorEl = document.getElementById('quill-editor');
    const toolbarEl = document.getElementById('quill-toolbar');
    if (!editorEl) {
      console.warn('[quill] #quill-editor missing');
      return;
    }

    let toolbarOption = FULL_TOOLBAR;
    try {
      if (toolbarEl && toolbarEl.innerHTML && toolbarEl.innerHTML.trim().length > 8) {
        toolbarOption = '#quill-toolbar';
      }
    } catch (e) {
      toolbarOption = FULL_TOOLBAR;
    }

    quill = new Quill(editorEl, {
      theme: 'snow',
      modules: { toolbar: toolbarOption },
      placeholder: 'Tulis konten artikel di sini...'
    });

    attachToolbarHandlers(quill);

    if (!window.ADIWIRA.__editQuillMediaDeletedBound) {
      window.ADIWIRA.__editQuillMediaDeletedBound = true;

      document.addEventListener('media:deleted', function(e){
        handleDeletedMedia(e && e.detail ? e.detail : null);
      });

      window.addEventListener('media:deleted', function(e){
        handleDeletedMedia(e && e.detail ? e.detail : null);
      });

      window.addEventListener('message', function(ev){
        try {
          if (!ev.data || ev.data.type !== 'media:deleted') return;
          handleDeletedMedia(ev.data.detail || null);
        } catch (err) {
          console.error('[quill] media:deleted message error', err);
        }
      });
    }

    window.__adiwira_quill_for_preview = quill;

    suppress = true;
    try {
      const initial = canonical ? (canonical.value || '') : '';
      const delta = quill.clipboard.convert(initial || '');
      quill.setContents(delta, 'silent');
      restoreImageDataAttributes(initial);
    } catch (e) {
      try {
        quill.root.innerHTML = canonical ? (canonical.value || '') : '';
        restoreImageDataAttributes(canonical ? canonical.value : '');
      } catch (_) {}
    }

    setTimeout(function(){
      suppress = false;
      normalizeEditorImages(editorEl);
    }, 60);

    quill.on('text-change', function() {
      if (suppress) return;
      try {
        if (canonical) canonical.value = quill.root.innerHTML;
      } catch (e) {}
    });

    console.debug(
      '[quill] initialized (toolbarOption=',
      (toolbarOption === '#quill-toolbar' ? '#quill-toolbar' : 'FULL_TOOLBAR'),
      ')'
    );
  }

  function setHTMLIfDifferent(html) {
    if (!quill) return;
    html = html || '';

    try {
      const cur = quill.root ? (quill.root.innerHTML || '') : '';
      if (normalizeHtmlForCompare(cur) === normalizeHtmlForCompare(html)) return;

      const delta = quill.clipboard.convert(html || '');
      suppress = true;
      quill.setContents(delta, 'silent');
      restoreImageDataAttributes(html);

      if (canonical) canonical.value = quill.root.innerHTML;

      setTimeout(function(){
        suppress = false;
        normalizeEditorImages(document.getElementById('quill-editor'));
      }, 40);
    } catch (e) {
      try {
        quill.root.innerHTML = html;
        restoreImageDataAttributes(html);
        if (canonical) canonical.value = quill.root.innerHTML;
        normalizeEditorImages(document.getElementById('quill-editor'));
      } catch (_) {}
    }
  }

  function restoreImageDataAttributes(originalHtml) {
    if (!originalHtml || !quill || !quill.root) return;
    try {
      var div = document.createElement('div');
      div.innerHTML = originalHtml;
      var originals = div.querySelectorAll('img[data-media-id]');
      if (!originals.length) return;

      var attrMap = {};
      Array.from(originals).forEach(function(img){
        var src = img.getAttribute('src');
        if (!src) return;
        attrMap[src] = {
          'data-media-id': img.getAttribute('data-media-id'),
          'data-media-url': img.getAttribute('data-media-url'),
          'data-caption': img.getAttribute('data-caption'),
          'data-credit': img.getAttribute('data-credit'),
          'data-adiwira-applied': img.getAttribute('data-adiwira-applied')
        };
      });

      if (Object.keys(attrMap).length === 0) return;

      Array.from(quill.root.querySelectorAll('img')).forEach(function(img){
        var src = img.getAttribute('src');
        if (!src) return;
        var data = attrMap[src];
        if (!data) return;
        Object.keys(data).forEach(function(attr){
          var val = data[attr];
          if (val != null) img.setAttribute(attr, val);
        });
      });
    } catch(e) {
      console.warn('[quill] restoreImageDataAttributes', e);
    }
  }

  function stripComplexBlocksAndReturn(html) {
    html = String(html || '');
    html = html.replace(/<(script|style|iframe|embed|form|svg|canvas|table)[\s\S]*?<\/\1>/gi, '');
    html = html.replace(/<\?[\s\S]*?\?>/g, '');
    html = html.replace(/<\/?(div|section)[^>]*>/gi, '');
    html = html.replace(/\sstyle\s*=\s*(?:"[^"]*"|'[^']*')/gi, '');
    return html;
  }

  function forceEnableQuillAfterStrip() {
    if (!canonical) return;

    try {
      const original = canonical.value || '';
      const stripped = stripComplexBlocksAndReturn(original);

      if (window.ADIWIRA && window.ADIWIRA.editor) {
        window.ADIWIRA.editor._programmatic = true;
      }

      canonical.value = stripped;

      try {
        const cmApi = window.ADIWIRA.codemirror;
        const cm = cmApi && cmApi.getInstance && cmApi.getInstance();

        if (cm && typeof cmApi.setValueSilent === 'function') {
          cmApi.setValueSilent(stripped);
        } else if (cm && typeof cm.setValue === 'function') {
          cm.setValue(stripped);
          try {
            if (typeof cm.refresh === 'function') cm.refresh();
          } catch (e) {}
        }
      } catch (e) {
        console.warn('[quill] update CM failed', e);
      }

      window.ADIWIRA.quillDisabled = false;

      const qRadio = document.getElementById('editor-quill');
      if (qRadio) {
        qRadio.removeAttribute('data-quill-disabled');
        qRadio.title = '';
      }

      initQuill();

      if (quill) {
        suppress = true;
        try {
          const delta = quill.clipboard.convert(stripped || '');
          quill.setContents(delta, 'silent');
          restoreImageDataAttributes(stripped);
          normalizeEditorImages(document.getElementById('quill-editor'));
        } catch (e) {
          try {
            quill.root.innerHTML = stripped;
            restoreImageDataAttributes(stripped);
            normalizeEditorImages(document.getElementById('quill-editor'));
          } catch (_) {}
        }
        setTimeout(function(){ suppress = false; }, 40);
      }

      try {
        if (qRadio) qRadio.checked = true;

        const cmRadio = document.getElementById('editor-codemirror');
        if (cmRadio) cmRadio.checked = false;

        if (window.ADIWIRA.editor && typeof window.ADIWIRA.editor.applyEditorMode === 'function') {
          setTimeout(function(){
            window.ADIWIRA.editor.applyEditorMode();
            setTimeout(function(){
              if (window.ADIWIRA && window.ADIWIRA.editor) {
                window.ADIWIRA.editor._programmatic = false;
              }
            }, 120);
          }, 80);
        } else {
          setTimeout(function(){
            if (window.ADIWIRA && window.ADIWIRA.editor) {
              window.ADIWIRA.editor._programmatic = false;
            }
          }, 120);
        }
      } catch (e) {
        console.warn('[quill] post-strip UI switch failed', e);
      }
    } catch (e) {
      if (window.ADIWIRA && window.ADIWIRA.editor) {
        window.ADIWIRA.editor._programmatic = false;
      }
      console.error('[quill] forceEnableQuillAfterStrip error', e);
    }
  }

  window.ADIWIRA.quill = {
    initQuill,
    isContentComplex,
    forceEnableQuillAfterStrip,
    setHTMLIfDifferent,
    getInstance: function(){ return quill; }
  };
})();