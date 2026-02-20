/* quill-init.js */
(function(){
  function loadScript(src){ return new Promise(function(res,rej){ if (document.querySelector('script[src="'+src+'"]')) return res(); var s=document.createElement('script'); s.src=src; s.async=true; s.onload=res; s.onerror=function(){rej(new Error('Failed load '+src))}; document.head.appendChild(s); }); }
  function loadCss(href){ return new Promise(function(res,rej){ if (document.querySelector('link[href="'+href+'"]')) return res(); var l=document.createElement('link'); l.rel='stylesheet'; l.href=href; l.onload=res; l.onerror=function(){rej(new Error('Failed load css '+href))}; document.head.appendChild(l); }); }

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

      // restore initial content from hidden input
      try {
        var initial = document.getElementById('content-input') ? document.getElementById('content-input').value : '';
        if (initial) window.__adam_quill_instance.root.innerHTML = initial;
      } catch(e){ console.warn('restore quill initial content error', e); }
    }

    const quill = window.__adam_quill_instance;
    let lastRange = null;
    quill.on('selection-change', function(range){ if (range) lastRange = range; });

    function escapeAttr(s){ if (s==null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

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
        if (m.id) attrs.push('data-media-id="' + _escapeAttr(m.id) + '"');
        if (m.caption) attrs.push('data-caption="' + _escapeAttr(m.caption) + '"');
        if (m.credit) attrs.push('data-credit="' + _escapeAttr(m.credit) + '"');

        const altVal = (m.alt && String(m.alt).trim() !== '') ? String(m.alt) : (m.title ? String(m.title) : '');
        const titleVal = m.title ? String(m.title) : '';

        const imgHtml =
          '<img src="' + _escapeAttr(m.url) + '"' +
            (attrs.length ? ' ' + attrs.join(' ') : '') +
            (altVal ? ' alt="' + _escapeAttr(altVal) + '"' : '') +
            (titleVal ? ' title="' + _escapeAttr(titleVal) + '"' : '') +
          '>';

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
              const imgSrc = img.getAttribute('src') || '';
              if (imgSrc === m.url || img.src === m.url || (m.url && img.src && img.src.endsWith(m.url))) {
                if (!img.dataset.adamMediaAnnotated) { target = img; break; }
              }
            }
            if (!target) {
              const rev = Array.from(quill.root.querySelectorAll('img')).reverse();
              target = rev.find(im => !im.dataset.adamMediaAnnotated) || rev[0] || null;
            }
            if (target) {
              if (m.id) target.setAttribute('data-media-id', String(m.id));
              if (m.title) target.setAttribute('title', String(m.title));
              if (altVal) target.setAttribute('alt', altVal);
              if (m.credit) target.setAttribute('data-credit', String(m.credit));
              if (m.caption) target.setAttribute('data-caption', String(m.caption));
              target.dataset.adamMediaAnnotated = '1';
            }
          } catch (err) {
            console.error('annotate image node error', err);
          }
        }, 60);
      })
      .catch(function(err){ console.error('media selector error', err); });
    }

    try {
      const toolbar = quill.getModule('toolbar');
      if (toolbar) toolbar.addHandler('image', quillImageHandler);
    } catch (e) { console.warn('Could not attach image handler to Quill toolbar', e); }

    // ensure form submit populates hidden content input
    (function attachFormSubmit(){
      const formId = window.ADIWIRA && window.ADIWIRA_FORM_ID ? window.ADIWIRA_FORM_ID : 'post-add-form';
      const form = document.getElementById(formId) || document.querySelector('form#post-add-form') || document.querySelector('form');
      if (!form) return;
      form.addEventListener('submit', function(){
        const contentInput = document.getElementById('content-input');
        if (contentInput) contentInput.value = quill.root.innerHTML;
      });
    })();

  }).catch(function(err){
    console.error('Failed to load quill:', err);
  });
})();
