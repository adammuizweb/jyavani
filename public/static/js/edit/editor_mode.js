// public/adiwira/static/js/edit/editor_mode.js — improved: programmatic suppression + safe CM canonical guards + ensure CM init/refresh
// plus use 'change' (not click+preventDefault) so radio remains clickable even when content complex.
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
const complexPattern =
/<(script|style|iframe|embed|object|form|svg|canvas|php|link|meta|table|thead|tbody|tfoot|tr|th|td)[\s>]|on[a-z]+\s*=/i;

  // internal programmatic flag — modules should set/unset this to avoid races
  if (!window.ADIWIRA.editor) window.ADIWIRA.editor = {};
  window.ADIWIRA.editor._programmatic = false;

  function syncQuillToCM(){
    // ensure CM has been inited
    try {
      window.ADIWIRA.codemirror && window.ADIWIRA.codemirror.initCM && window.ADIWIRA.codemirror.initCM();
    } catch(e){}

    window.ADIWIRA.codemirror.whenCMReady(()=> {
      try {
        const quill = window.ADIWIRA.quill.getInstance();
        const canonical = document.getElementById('content-textarea');
        const cmApi = window.ADIWIRA.codemirror;
        const cm = cmApi && cmApi.getInstance && cmApi.getInstance();
        const quillHtml = (quill && quill.root && quill.root.innerHTML) ? (quill.root.innerHTML).trim() : '';
        const useQuill = !complexPattern.test((canonical && canonical.value || '').trim()) && quillHtml;
        const qhtml = useQuill ? quillHtml : (canonical && canonical.value || (cm ? cm.getValue() : '') || '');

        // avoid overwriting canonical with empty CM value
        if (cm && typeof cm.getValue === 'function') {
          const currentCm = cm.getValue();
          if ((!currentCm || currentCm.trim() === '') && canonical && canonical.value && canonical.value.trim() !== '') {
            // keep canonical as source-of-truth
            if (cmApi && typeof cmApi.setValueSilent === 'function') {
              // seed CM from canonical silently
              cmApi.setValueSilent(canonical.value);
            } else if (cm && typeof cm.setValue === 'function') {
              cm.setValue(canonical.value);
            }
          } else {
            // normal sync if CM differs from qhtml
            if (cm && typeof cm.getValue === 'function' && cm.getValue() !== qhtml) {
              if (cmApi && typeof cmApi.setValueSilent === 'function') {
                window.ADIWIRA.editor._programmatic = true;
                cmApi.setValueSilent(qhtml);
                setTimeout(()=> { window.ADIWIRA.editor._programmatic = false; }, 70);
              } else {
                cm.setValue(qhtml);
              }
            }
          }
        }

        try { if (cm && typeof cm.refresh === 'function') cm.refresh(); } catch(e){}
        if (canonical && cm) canonical.value = (cm && typeof cm.getValue === 'function') ? cm.getValue() : (canonical.value || '');
      } catch(e){ console.warn('syncQuillToCM', e); }
    });
  }

  function syncCMToQuill(){
    try {
      const cmApi = window.ADIWIRA.codemirror;
      const cm = cmApi && cmApi.getInstance && cmApi.getInstance();
      const canonical = document.getElementById('content-textarea');
      const chtml = (cm ? cm.getValue() : (canonical && canonical.value) || '') || '';

      // if it's complex, persist canonical and don't init quill
      if (complexPattern.test(chtml)) {
        if (canonical) canonical.value = chtml;
        return;
      }

      try { if (window.ADIWIRA && window.ADIWIRA.quill && typeof window.ADIWIRA.quill.initQuill === 'function') window.ADIWIRA.quill.initQuill(); } catch(e){}
      const quillApi = window.ADIWIRA.quill;
      if (!quillApi || typeof quillApi.setHTMLIfDifferent !== 'function') {
        if (canonical) canonical.value = chtml;
        return;
      }

      // protect against loop — declare programmatic during setHTMLIfDifferent
      window.ADIWIRA.editor._programmatic = true;
      quillApi.setHTMLIfDifferent(chtml);
      setTimeout(()=> { window.ADIWIRA.editor._programmatic = false; }, 70);
    } catch(e){ console.warn('syncCMToQuill', e); }
  }

  function showConfirmStripModal(onClear) {
    const dom = document.createElement('div');
    dom.innerHTML = '<div id="__warn_modal" style="position:fixed;inset:0;z-index:30000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45);">'+
      '<div style="background:#fff;padding:1.2rem 1.6rem;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.18);text-align:center;max-width:420px;">'+
      '<div style="font-weight:600;margin-bottom:.4rem;">⚠️ Konten mengandung kode kompleks</div>'+
      '<div style="color:#334;margin-bottom:1rem;text-align:left">Konten mengandung tag seperti &lt;div&gt; atau &lt;section&gt; yang tidak dipertahankan oleh editor Rich (Quill).<br>'+
      'Choose <strong>Clear old code</strong> to remove complex tags and switch to Quill, or <strong>Cancel</strong> to stay in CodeMirror.</div>'+
      '<div style="display:flex;gap:.5rem;justify-content:center">'+
      '<button id="__warn_cancel" style="padding:.45rem .8rem;border:0;background:#666;color:#fff;border-radius:6px;cursor:pointer">Cancel</button>'+
      '<button id="__warn_clear"  style="padding:.45rem .8rem;border:0;background:#a33;color:#fff;border-radius:6px;cursor:pointer">Clear old code</button>'+
      '</div></div></div>';
    document.body.appendChild(dom);

    document.getElementById('__warn_cancel').onclick = () => { dom.remove(); };

    document.getElementById('__warn_clear').onclick = () => {
      dom.remove();
      try {
        // mark programmatic to avoid handlers stomping values
        window.ADIWIRA.editor._programmatic = true;

        if (window.ADIWIRA && window.ADIWIRA.quill && typeof window.ADIWIRA.quill.forceEnableQuillAfterStrip === 'function') {
          window.ADIWIRA.quill.forceEnableQuillAfterStrip();
        }

        // ensure radios reflect intent and apply editor mode after short delay
        try {
          if (document.getElementById('editor-quill')) document.getElementById('editor-quill').checked = true;
          if (document.getElementById('editor-codemirror')) document.getElementById('editor-codemirror').checked = false;

          setTimeout(()=> {
            if (window.ADIWIRA && window.ADIWIRA.editor && typeof window.ADIWIRA.editor.applyEditorMode === 'function') {
              window.ADIWIRA.editor.applyEditorMode();
            }
            setTimeout(()=> { window.ADIWIRA.editor._programmatic = false; }, 120);
          }, 120);
        } catch(e){ console.warn('post-strip UI sync failed', e); }

      } catch(e){ console.warn('forceEnableQuillAfterStrip call failed', e); }
    };
  }

  function applyEditorMode() {
    var radios = document.querySelectorAll('input[name="editor_mode"]');
    var selected = null;
    for (var i = 0; i < radios.length; i++) { if (radios[i].checked) { selected = radios[i].value; break; } }

    // Hide all editor areas (pattern: {value}-area)
    var allAreas = document.querySelectorAll('[id$="-area"]');
    for (var j = 0; j < allAreas.length; j++) { allAreas[j].style.display = 'none'; }

    // Show matching area
    if (selected) {
      var area = document.getElementById(selected + '-area');
      if (area) area.style.display = 'block';
    }

    // Non-quill/codemirror modes (e.g. builder) — no sync needed
    if (selected !== 'quill' && selected !== 'codemirror') return;

    // ensure CM exists early to avoid empty-read races
    try { window.ADIWIRA.codemirror && window.ADIWIRA.codemirror.initCM && window.ADIWIRA.codemirror.initCM(); } catch(e){}

    var mode = selected || 'quill';
    var quillArea = document.getElementById('quill-area');
    var cmArea = document.getElementById('codemirror-area');

    // If a programmatic operation is in progress, only adjust visibility without triggering modals
    if (window.ADIWIRA.editor._programmatic) {
      if (mode === 'codemirror') {
        setTimeout(syncQuillToCM, 20);
      } else {
        try { if (window.ADIWIRA && window.ADIWIRA.quill && typeof window.ADIWIRA.quill.initQuill === 'function') window.ADIWIRA.quill.initQuill(); } catch(e){}
        setTimeout(syncCMToQuill, 40);
      }
      return;
    }

    if (mode === 'codemirror') {
      // ensure CM refreshed when shown
      setTimeout(()=> {
        try {
          window.ADIWIRA.codemirror && window.ADIWIRA.codemirror.whenCMReady && window.ADIWIRA.codemirror.whenCMReady(()=> {
            try { const cm = window.ADIWIRA.codemirror.getInstance(); if (cm && typeof cm.refresh === 'function') cm.refresh(); } catch(e){}
            syncQuillToCM();
          });
        } catch(e){}
      }, 20);
    } else {
      const canonical = document.getElementById('content-textarea');
      if (canonical && complexPattern.test((canonical.value||'').trim())) {
        // Revert to CM — content too complex for Quill
        var cmRadio = document.getElementById('editor-codemirror');
        var qRadio = document.getElementById('editor-quill');
        if (cmRadio) cmRadio.checked = true;
        if (qRadio) qRadio.checked = false;
        if (cmArea) cmArea.style.display = 'block';
        if (quillArea) quillArea.style.display = 'none';
        return;
      }
      try { if (window.ADIWIRA && window.ADIWIRA.quill && typeof window.ADIWIRA.quill.initQuill === 'function') window.ADIWIRA.quill.initQuill(); } catch(e){}
      setTimeout(syncCMToQuill, 40);
    }
  }

  function initEditorMode() {
    const canonical = document.getElementById('content-textarea');
    const quillArea = document.getElementById('quill-area');
    const cmArea = document.getElementById('codemirror-area');

    // ensure CM init early so CM buffer seeded with server content
    try { window.ADIWIRA.codemirror && window.ADIWIRA.codemirror.initCM && window.ADIWIRA.codemirror.initCM(); } catch(e){}

    // Enable all editor_mode radios
    var allRadios = document.querySelectorAll('input[name="editor_mode"]');
    for (var ri = 0; ri < allRadios.length; ri++) {
      allRadios[ri].disabled = false;
      allRadios[ri].removeAttribute && allRadios[ri].removeAttribute('disabled');
      allRadios[ri].style.pointerEvents = 'auto';
    }

    const initialComplex = complexPattern.test((canonical && canonical.value || '').trim());

    // Quill radio — complex content guard
    var editorQuillRadio = document.getElementById('editor-quill');
    if (editorQuillRadio) {
      editorQuillRadio.addEventListener('change', function(e){
        try {
          if (editorQuillRadio.checked && complexPattern.test((canonical && canonical.value || '').trim())) {
            var cmRadio = document.getElementById('editor-codemirror');
            if (cmRadio) cmRadio.checked = true;
            editorQuillRadio.checked = false;
            if (cmArea) cmArea.style.display = 'block';
            if (quillArea) quillArea.style.display = 'none';
            showConfirmStripModal();
          } else {
            applyEditorMode();
          }
        } catch(err){ console.warn('editorQuill change handler', err); }
      });
    }

    // All other radios — generic handler
    for (var gi = 0; gi < allRadios.length; gi++) {
      if (allRadios[gi].value !== 'quill') {
        allRadios[gi].addEventListener('change', applyEditorMode);
      }
    }

    if (initialComplex) {
      var cmRadio = document.getElementById('editor-codemirror');
      if (cmRadio) cmRadio.checked = true;
      if (editorQuillRadio) editorQuillRadio.checked = false;
      if (cmArea) cmArea.style.display = 'block';
      if (quillArea) quillArea.style.display = 'none';

      // ensure CM gets its buffer from canonical (server content) if CM still blank
      setTimeout(()=> {
        try {
          const cmApi = window.ADIWIRA.codemirror;
          if (cmApi && typeof cmApi.setValueSilent === 'function') {
            const cm = cmApi.getInstance && cmApi.getInstance();
            const canonicalValue = canonical && canonical.value ? canonical.value : '';
            if (cm && cm.getValue && (!cm.getValue() || cm.getValue().trim() === '') && canonicalValue.trim() !== '') {
              cmApi.setValueSilent(canonicalValue);
            }
            try { if (cm && typeof cm.refresh === 'function') cm.refresh(); } catch(e){}
          }
        } catch(e){}
      }, 60);

      (function injectHint(){
        const hint = document.createElement('div');
        hint.id = '__complex_hint';
        hint.style.fontSize = '13px';
        hint.style.color = '#8b0000';
        hint.style.marginTop = '6px';
        hint.innerHTML = 'Konten terdeteksi <strong>kompleks</strong>. Disarankan pakai CodeMirror.';
        const radioLabel = editorQuillRadio && editorQuillRadio.parentNode;
        if (radioLabel && radioLabel.parentNode) radioLabel.parentNode.appendChild(hint);
      })();
    } else {
      applyEditorMode();
    }
  }

  window.ADIWIRA.editor = Object.assign(window.ADIWIRA.editor || {}, {
    applyEditorMode,
    initEditorMode,
    syncQuillToCM,
    syncCMToQuill
  });
})();
