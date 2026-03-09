// quill.js — Quill init + toolbar handlers + ensure inserted image gets media attributes
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  let quill = null;
  let suppress = false;

  // treat these tags as complex
const complexPattern =
/<(script|style|iframe|embed|object|form|svg|canvas|php|link|meta|table|thead|tbody|tfoot|tr|th|td)[\s>]|on[a-z]+\s*=|style\s*=/i;
  const canonical = document.getElementById('content-textarea');

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

  function isContentComplex() {
    try { return complexPattern.test((canonical && canonical.value || '').trim()); }
    catch (e) { return false; }
  }

  function normalizeHtmlForCompare(html) {
    html = String(html || '').trim();
    html = html.replace(/<p>\s*(?:&nbsp;|\uFEFF|<br\s*\/?>)?\s*<\/p>/gi, '');
    html = html.replace(/\s+/g, ' ').trim();
    return html;
  }

  // helper: get selector function if available
  function getMediaSelector() {
    if (typeof window.openMediaSelector === 'function') return window.openMediaSelector;
    if (window.ADIWIRA && window.ADIWIRA.media && typeof window.ADIWIRA.media.openMediaSelector === 'function') return window.ADIWIRA.media.openMediaSelector;
    return null;
  }
  
  function getFileSelector() {
  if (typeof window.openFileSelector === 'function') return window.openFileSelector;
  if (window.ADIWIRA && window.ADIWIRA.file && typeof window.ADIWIRA.file.openFileSelector === 'function') {
    return window.ADIWIRA.file.openFileSelector;
  }
  return null;
}

  // helper: normalize media object (if not already)
  function normalizeMedia(detail) {
    if (typeof window.normalizeMedia === 'function') return window.normalizeMedia(detail);
    if (!detail) return null;
    const m = detail.media && typeof detail.media === 'object' ? detail.media : detail;
    return {
      id: (m.id != null) ? (parseInt(m.id,10) || null) : null,
      url: (m.url != null) ? String(m.url || '') : '',
      title: (m.title != null) ? String(m.title || '') : '',
      alt: (m.alt != null) ? String(m.alt || '') : '',
      caption: (m.caption != null) ? String(m.caption || '') : '',
      credit: (m.credit != null) ? String(m.credit || '') : ''
    };
  }

  // apply attributes to the most-recently inserted image matching src
  function applyAttributesToInsertedImage(m) {
    try {
      if (!m || !m.url) return;
      const editorEl = document.getElementById('quill-editor');
      if (!editorEl) return;

      // find candidate imgs with matching src
      const imgs = Array.from(editorEl.querySelectorAll('img')).filter(i => {
        try { return i.getAttribute && (i.getAttribute('src') === m.url); } catch(e){ return false; }
      });

      if (!imgs.length) {
        // no direct match — try matching by src endpoint (in case querystrings normalized)
        const srcEnds = m.url.split('/').slice(-1)[0];
        const altc = Array.from(editorEl.querySelectorAll('img')).filter(i => {
          try { return i.getAttribute && String(i.getAttribute('src') || '').endsWith(srcEnds) && !i.dataset.adiwiraApplied; } catch(e){ return false; }
        });
        if (altc.length) imgs.push(...altc);
      }

      // pick last (most recently inserted) that hasn't been marked
      let target = null;
      for (let i = imgs.length - 1; i >= 0; i--) {
        const img = imgs[i];
        if (!img.dataset || !img.dataset.adiwiraApplied) { target = img; break; }
      }
      if (!target && imgs.length) target = imgs[imgs.length - 1];

      if (!target) return;

      // set attributes (only if provided)
      if (m.alt) target.setAttribute('alt', m.alt);
      if (m.title) target.setAttribute('title', m.title);
      if (m.id != null) target.setAttribute('data-media-id', String(m.id));
      if (m.caption) target.setAttribute('data-caption', m.caption);
      if (m.credit) target.setAttribute('data-credit', m.credit);

      // add a marker to prevent double-processing
      target.dataset.adiwiraApplied = '1';
    } catch(e){
      console.warn('[quill] applyAttributesToInsertedImage error', e);
    }
  }

  function attachToolbarHandlers(localQuill) {
    try {
      const tb = localQuill.getModule && localQuill.getModule('toolbar');
      if (!tb || typeof tb.addHandler !== 'function') return;

      // Image handler
      tb.addHandler('image', function() {
        const selector = getMediaSelector();
        if (!selector) {
          // fallback: ask for URL
          const url = prompt('Masukkan URL gambar:');
          if (url) {
            const range = localQuill.getSelection(true) || { index: localQuill.getLength(), length: 0 };
            localQuill.insertEmbed(range.index, 'image', url, 'user');
            // small delay to let Quill render DOM, then attempt to set alt/title if user provided
            setTimeout(()=> {
              applyAttributesToInsertedImage({ url: url });
            }, 40);
            localQuill.setSelection(range.index + 1, 0);
          }
          return;
        }

        selector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
          .then(function(detail){
            const m = normalizeMedia(detail);
            if (!m || !m.url) return;
            const range = localQuill.getSelection(true) || { index: localQuill.getLength(), length: 0 };
            // insert embed
            localQuill.insertEmbed(range.index, 'image', m.url, 'user');
            // ensure DOM is updated then attach attributes
            setTimeout(()=> {
              applyAttributesToInsertedImage(m);
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

  const range = localQuill.getSelection(true) || { index: localQuill.getLength() - 1, length: 0 };

  pickFile({
    url: '/adiwira/admin/modal_file/index.php?embedded=1',
    maxWidth: '980px'
  }).then(function (picked) {
    const f = (typeof window.normalizeFile === 'function') ? window.normalizeFile(picked) : picked;
    if (!f || !f.url) return;

    const label = (f.filename && String(f.filename).trim() !== '')
      ? String(f.filename).trim()
      : (f.id ? ('File #' + f.id) : f.url);

    function esc(s){
      return String(s || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
    }

    const attrs = [];
    if (f.id)   attrs.push('data-file-id="' + esc(f.id) + '"');
    if (f.mime) attrs.push('data-mime="' + esc(f.mime) + '"');
    if (f.size) attrs.push('data-size="' + esc(f.size) + '"');

    const aHtml =
      '<a href="' + esc(f.url) + '" target="_blank" rel="noopener"' +
      (attrs.length ? ' ' + attrs.join(' ') : '') +
      '>' + esc(label) + '</a>';

    const htmlToInsert = '<p>📎 ' + aHtml + '</p>';

    localQuill.clipboard.dangerouslyPasteHTML(range.index, htmlToInsert);
    localQuill.setSelection(range.index + 2, 0);
  }).catch(function (err) {
    console.warn('[file selector] error', err);
  });
});

      console.debug('[quill] toolbar handlers attached');
    } catch(e){ console.warn('[quill] attachToolbarHandlers failed', e); }
  }

  function initQuill() {
    if (quill) return;

    // If content is complex, do NOT initialize Quill.
    if (isContentComplex()) {
      window.ADIWIRA.quillDisabled = true;
      const qRadio = document.getElementById('editor-quill');
      if (qRadio) {
        qRadio.setAttribute('data-quill-disabled', '1');
        qRadio.title = 'Konten mengandung blok kompleks — klik untuk tindakan (Hapus kode lama atau Batalkan).';
      }
      console.info('[quill] init skipped (complex content). radio remains clickable for modal.');
      return;
    }

    if (typeof window.Quill !== 'function') { console.warn('[quill] Quill not loaded'); return; }

    const editorEl = document.getElementById('quill-editor');
    const toolbarEl = document.getElementById('quill-toolbar');
    if (!editorEl) { console.warn('[quill] #quill-editor missing'); return; }

    let toolbarOption = FULL_TOOLBAR;
    try {
      if (toolbarEl && toolbarEl.innerHTML && toolbarEl.innerHTML.trim().length > 8) toolbarOption = '#quill-toolbar';
    } catch(e){ toolbarOption = FULL_TOOLBAR; }

    quill = new Quill(editorEl, {
      theme: 'snow',
      modules: { toolbar: toolbarOption },
      placeholder: 'Tulis konten artikel di sini...'
    });

    // attach toolbar handlers immediately
    attachToolbarHandlers(quill);

    window.__adiwira_quill_for_preview = quill;

    // set initial content via delta (silent)
    suppress = true;
    try {
      const initial = canonical ? (canonical.value || '') : '';
      const delta = quill.clipboard.convert(initial || '');
      quill.setContents(delta, 'silent');
    } catch(e) {
      try { quill.root.innerHTML = canonical ? (canonical.value || '') : ''; } catch(_) {}
    }
    setTimeout(()=> suppress = false, 40);

    quill.on('text-change', function() {
      if (suppress) return;
      try {
        if (canonical) canonical.value = quill.root.innerHTML;
      } catch(e){}
    });

    console.debug('[quill] initialized (toolbarOption=', (toolbarOption === '#quill-toolbar' ? '#quill-toolbar' : 'FULL_TOOLBAR'), ')');
  }

  function setHTMLIfDifferent(html) {
    if (!quill) return;
    html = html || '';
    try {
      const cur = quill.root ? (quill.root.innerHTML || '') : '';
      if (normalizeHtmlForCompare(cur) === normalizeHtmlForCompare(html)) return;
      const delta = quill.clipboard.convert(html || '');
      // perform programmatic update in suppress mode
      suppress = true;
      quill.setContents(delta, 'silent');
      if (canonical) canonical.value = quill.root.innerHTML;
      setTimeout(()=> suppress = false, 30);
    } catch (e) {
      try { quill.root.innerHTML = html; if (canonical) canonical.value = quill.root.innerHTML; } catch(_) {}
    }
  }

  // remove dangerous/complex blocks, but KEEP inner HTML of <div>/<section>
  function stripComplexBlocksAndReturn(html) {
    html = String(html || '');
    // remove heavy blocks (and their inner content)
    html = html.replace(/<(script|style|iframe|embed|form|svg|canvas|table)[\s\S]*?<\/\1>/gi, '');
    // remove php blocks
    html = html.replace(/<\?[\s\S]*?\?>/g, '');
    // remove wrapping tags div/section but keep children
    html = html.replace(/<\/?(div|section)[^>]*>/gi, '');
    return html;
  }

  function forceEnableQuillAfterStrip() {
    if (!canonical) return;
    try {
      const original = canonical.value || '';
      const stripped = stripComplexBlocksAndReturn(original);
      if (window.ADIWIRA && window.ADIWIRA.editor) window.ADIWIRA.editor._programmatic = true;

      canonical.value = stripped;

      // update CodeMirror if present so UI is consistent; use silent setter if available
      try {
        const cmApi = window.ADIWIRA.codemirror;
        const cm = cmApi && cmApi.getInstance && cmApi.getInstance();
        if (cm && typeof cmApi.setValueSilent === 'function') {
          cmApi.setValueSilent(stripped);
        } else if (cm && typeof cm.setValue === 'function') {
          cm.setValue(stripped);
          try { if (typeof cm.refresh === 'function') cm.refresh(); } catch(e){}
        }
      } catch(e){ console.warn('[quill] update CM failed', e); }

      window.ADIWIRA.quillDisabled = false;
      const qRadio = document.getElementById('editor-quill');
      if (qRadio) {
        qRadio.removeAttribute('data-quill-disabled');
        qRadio.title = '';
      }

      // ensure Quill is initialized then set content safely
      initQuill();
      if (quill) {
        suppress = true;
        try {
          const delta = quill.clipboard.convert(stripped || '');
          quill.setContents(delta, 'silent');
        } catch(e) {
          try { quill.root.innerHTML = stripped; } catch(_) {}
        }
        setTimeout(()=> suppress = false, 40);
      }

      // set radios and switch UI (ask editor.applyEditorMode to honor it)
      try {
        if (qRadio) qRadio.checked = true;
        const cmRadio = document.getElementById('editor-codemirror'); if (cmRadio) cmRadio.checked = false;
        if (window.ADIWIRA.editor && typeof window.ADIWIRA.editor.applyEditorMode === 'function') {
          setTimeout(()=> {
            window.ADIWIRA.editor.applyEditorMode();
            setTimeout(()=> { if (window.ADIWIRA && window.ADIWIRA.editor) window.ADIWIRA.editor._programmatic = false; }, 120);
          }, 80);
        } else {
          setTimeout(()=> { if (window.ADIWIRA && window.ADIWIRA.editor) window.ADIWIRA.editor._programmatic = false; }, 120);
        }
      } catch(e){ console.warn('[quill] post-strip UI switch failed', e); }

    } catch (e) {
      if (window.ADIWIRA && window.ADIWIRA.editor) window.ADIWIRA.editor._programmatic = false;
      console.error('[quill] forceEnableQuillAfterStrip error', e);
    }
  }

  window.ADIWIRA.quill = {
    initQuill,
    isContentComplex,
    forceEnableQuillAfterStrip,
    setHTMLIfDifferent,
    getInstance: () => quill
  };
})();
