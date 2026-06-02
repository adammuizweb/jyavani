// public/adiwira/static/js/edit/codemirror.js — init early, setValueSilent, visibility observer, safe canonical writes
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  let cm = null;
  let cmSilent = false;
  let observer = null;

  function initCM(){
    if (cm) return;
    const ta = document.getElementById('cm-textarea');
    if (!ta || typeof window.CodeMirror !== 'function') return;
    try {
      cm = CodeMirror.fromTextArea(ta, {
        mode: 'htmlmixed',
        lineNumbers: true,
        styleActiveLine: true,
        matchBrackets: true,
        autoCloseBrackets: true,
        autoCloseTags: true,
        indentUnit: 2,
        lineWrapping: true,
        viewportMargin: Infinity,
        theme: 'dracula',
        foldGutter: true,
        gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"]
      });
      cm.setSize('100%', '60vh');

      // ensure refresh when it becomes visible
      setTimeout(()=> cm.refresh(), 120);

      cm.on('change', function() {
        try {
          if (cmSilent) return;

          const canonical = document.getElementById('content-textarea');
          if (!canonical) return;

          // If editor module is performing programmatic work, skip writing canonical here.
          if (window.ADIWIRA && window.ADIWIRA.editor && window.ADIWIRA.editor._programmatic) {
            return;
          }

          // Don't overwrite canonical with an empty CM value if canonical already has server content.
          const val = cm.getValue();
          if (typeof val === 'string') {
            const valTrim = val.trim();
            if (valTrim === '') {
              // If canonical is already non-empty, keep it.
              if (canonical.value && canonical.value.trim() !== '') return;
            }
            canonical.value = val;
          }
        } catch(e){
          console.warn('[codemirror:onchange]', e);
        }
      });

try {
  cm.addKeyMap({
    'Ctrl-S': function() {
      if (window.ADIWIRA && window.ADIWIRA.save && typeof window.ADIWIRA.save.ajaxSave === 'function') {
        window.ADIWIRA.save.ajaxSave();
      }
    },
    'Cmd-S': function() {
      if (window.ADIWIRA && window.ADIWIRA.save && typeof window.ADIWIRA.save.ajaxSave === 'function') {
        window.ADIWIRA.save.ajaxSave();
      }
    },

    'Ctrl-Z': function(cm) { cm.undo(); },
    'Cmd-Z': function(cm) { cm.undo(); },

    'Shift-Ctrl-Z': function(cm) { cm.redo(); },
    'Cmd-Shift-Z': function(cm) { cm.redo(); },
    'Ctrl-Y': function(cm) { cm.redo(); }
  });
} catch(e){}
    } catch(e){ console.warn('[initCM]', e); }
  }

  function whenCMReady(cb){
    if (cm) return cb();
    let tries = 0;
    const t = setInterval(()=> {
      if (cm || tries>60) { clearInterval(t); return cb(); }
      tries++;
    }, 50);
  }

  // Programmatic setValue that does not trigger canonical write via change handler
  function setValueSilent(val){
    // ensure cm exists; if not, write to underlying textarea as fallback
    const ta = document.getElementById('cm-textarea');
    if (!cm) {
      if (ta) ta.value = val;
      // ensure canonical updated
      const canonical = document.getElementById('content-textarea');
      if (canonical) canonical.value = val;
      return;
    }
    cmSilent = true;
    try {
      cm.setValue(val);
      // ensure canonical reflects the programmatic set
      const canonical = document.getElementById('content-textarea');
      if (canonical) canonical.value = val;
    } catch(e){ console.warn('[cm.setValueSilent]', e); }
    setTimeout(()=> { cmSilent = false; try { if (typeof cm.refresh === 'function') cm.refresh(); } catch(e){} }, 50);
  }

  // Observe visibility changes of cm wrapper to refresh CM when it becomes visible
  function observeVisibility(){
    const wrap = document.getElementById('codemirror-area');
    if (!wrap || typeof MutationObserver === 'undefined' || observer) return;
    observer = new MutationObserver((mutations)=> {
      for (const m of mutations) {
        if (m.type === 'attributes' && (m.attributeName === 'style' || m.attributeName === 'class')) {
          const displayed = window.getComputedStyle(wrap).display !== 'none';
          if (displayed && cm) {
            try { cm.refresh(); } catch(e){}
            // also ensure CM buffer seeded from canonical if needed
            const canonical = document.getElementById('content-textarea');
            if (canonical && cm.getValue().trim() === '' && canonical.value && canonical.value.trim() !== '') {
              setValueSilent(canonical.value);
            }
          }
        }
      }
    });
    observer.observe(wrap, { attributes: true, attributeFilter: ['style', 'class'] });
  }

  // auto-init when DOM ready if area present
  function autoInit(){
    try { initCM(); observeVisibility(); } catch(e){}
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInit);
  } else {
    setTimeout(autoInit, 0);
  }

  window.ADIWIRA.codemirror = {
    initCM,
    whenCMReady,
    getInstance: () => cm,
    setValueSilent,
    _internal: { observeVisibility }
  };
})();
