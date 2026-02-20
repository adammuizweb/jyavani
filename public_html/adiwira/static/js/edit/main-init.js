// main-init.js — robust initialization + retries for hidden / complex content cases
(function(){
  // helper for safe-calls
  function safe(fn){
    try { fn(); } catch(e) { console.warn('[main-init] safe error', e); }
  }

  // attempt counters & timeouts
  const MAX_RETRIES = 6;
  const RETRY_DELAY = 120; // ms between retries

  function seedCMFromCanonicalIfEmpty(){
    try {
      const cmApi = window.ADIWIRA && window.ADIWIRA.codemirror;
      const canonical = document.getElementById('content-textarea');
      if (!canonical) return;
      const serverVal = canonical.value || '';
      if (!cmApi) return;
      cmApi.whenCMReady(()=> {
        const cm = cmApi.getInstance && cmApi.getInstance();
        if (!cm) return;
        try {
          const cur = (typeof cm.getValue === 'function') ? (cm.getValue() || '') : '';
          if ((!cur || cur.trim() === '') && serverVal && serverVal.trim() !== '') {
            if (typeof cmApi.setValueSilent === 'function') {
              cmApi.setValueSilent(serverVal);
              console.debug('[main-init] seeded CM from canonical via setValueSilent');
            } else {
              cm.setValue(serverVal);
              console.debug('[main-init] seeded CM from canonical via setValue');
            }
          }
        } catch(e){ console.warn('[main-init] seedCM failed', e); }
      });
    } catch(e){ console.warn('[main-init] seedCMFromCanonicalIfEmpty error', e); }
  }

  function ensureCMVisibleRenderThenSync(retriesLeft, onDone){
    try {
      const cmApi = window.ADIWIRA && window.ADIWIRA.codemirror;
      const wrap = document.getElementById('codemirror-area');
      const canonical = document.getElementById('content-textarea');
      if (!cmApi || !wrap) {
        if (typeof onDone === 'function') onDone(false);
        return;
      }
      cmApi.whenCMReady(()=> {
        const cm = cmApi.getInstance && cmApi.getInstance();
        if (!cm) { if (typeof onDone === 'function') onDone(false); return; }

        try { cm.refresh(); } catch(e){}

        try {
          const cur = (typeof cm.getValue === 'function') ? (cm.getValue() || '') : '';
          const serverVal = canonical && canonical.value ? canonical.value : '';
          if ((!cur || cur.trim() === '') && serverVal && serverVal.trim() !== '') {
            if (typeof cmApi.setValueSilent === 'function') {
              cmApi.setValueSilent(serverVal);
            } else {
              cm.setValue(serverVal);
            }
            try { cm.focus(); cm.execCommand && cm.execCommand('indentAuto'); cm.getInputField && cm.getInputField().blur && cm.getInputField().blur(); } catch(e){}
            console.debug('[main-init] CM seeded and focused to force render');
          }
        } catch(e){ console.warn('[main-init] CM seed attempt failed', e); }

        setTimeout(()=> {
          try {
            const cur2 = (typeof cm.getValue === 'function') ? (cm.getValue() || '') : '';
            if (cur2 && cur2.trim() !== '') {
              console.debug('[main-init] CM has content after refresh/seed');
              if (typeof onDone === 'function') onDone(true);
              return;
            } else {
              if (retriesLeft > 0) {
                console.debug('[main-init] CM still blank, retries left', retriesLeft - 1);
                setTimeout(()=> ensureCMVisibleRenderThenSync(retriesLeft - 1, onDone), RETRY_DELAY);
                return;
              } else {
                console.warn('[main-init] CM remains blank after retries');
                if (typeof onDone === 'function') onDone(false);
                return;
              }
            }
          } catch(e){ console.warn('[main-init] after-seed check failed', e); if (typeof onDone === 'function') onDone(false); }
        }, 60);
      });
    } catch(e){ console.warn('[main-init] ensureCMVisibleRenderThenSync err', e); if (typeof onDone === 'function') onDone(false); }
  }

  // Central boot sequence (defensive)
  function boot(){
    safe(()=> {
      // 1. Init CM early (if available)
      if (window.ADIWIRA && window.ADIWIRA.codemirror && typeof window.ADIWIRA.codemirror.initCM === 'function') {
        try {
          window.ADIWIRA.codemirror.initCM();
          console.debug('[main-init] codemirror.initCM() called');
        } catch(e){ console.warn('[main-init] codemirror.initCM err', e); }
      }

      // 2. Init editor mode (attaches listeners and decides initial UI)
      if (window.ADIWIRA && window.ADIWIRA.editor && typeof window.ADIWIRA.editor.initEditorMode === 'function') {
        try {
          window.ADIWIRA.editor.initEditorMode();
          console.debug('[main-init] editor.initEditorMode() called');
        } catch(e){ console.warn('[main-init] editor.initEditorMode err', e); }
      }

      // 3. Ensure CM seeded from canonical if empty
      seedCMFromCanonicalIfEmpty();

      // 4. Init Quill (Quill will not initialize if content complex)
      if (window.ADIWIRA && window.ADIWIRA.quill && typeof window.ADIWIRA.quill.initQuill === 'function') {
        try {
          window.ADIWIRA.quill.initQuill();
          console.debug('[main-init] quill.initQuill() called');
        } catch(e){ console.warn('[main-init] quill.initQuill err', e); }
      }

      // 4.1 Ensure media/thumbnail helpers are initialized so gallery modal works
      try {
        if (window.ADIWIRA && window.ADIWIRA.thumbnail && typeof window.ADIWIRA.thumbnail.initThumbnail === 'function') {
          window.ADIWIRA.thumbnail.initThumbnail();
          console.debug('[main-init] thumbnail.initThumbnail() called');
        }
      } catch(e){ console.warn('[main-init] thumbnail.initThumbnail err', e); }

      // Optional: ensure a stub openMediaSelector exists (defensive)
      if (!window.openMediaSelector && window.ADIWIRA && window.ADIWIRA.media && typeof window.ADIWIRA.media.openMediaSelector === 'function') {
        window.openMediaSelector = window.ADIWIRA.media.openMediaSelector;
        window.normalizeMedia = window.ADIWIRA.media.normalizeMedia;
      }

      // 5. After everything attempted, ensure visibility/render and then apply editor mode to make UI consistent.
      ensureCMVisibleRenderThenSync(MAX_RETRIES, function(cmReady){
        try {
          if (window.ADIWIRA && window.ADIWIRA.editor && typeof window.ADIWIRA.editor.applyEditorMode === 'function') {
            window.ADIWIRA.editor.applyEditorMode();
            console.debug('[main-init] editor.applyEditorMode() called (post-check)');
          }
        } catch(e){ console.warn('[main-init] applyEditorMode post-check err', e); }
      });

      // 6. Expose debug helpers to re-run init steps from console if needed
      window.ADIWIRA._init = Object.assign(window.ADIWIRA._init || {}, {
        fullBoot: boot,
        ensureCMReady: (cb) => ensureCMVisibleRenderThenSync(MAX_RETRIES, cb),
        seedCM: seedCMFromCanonicalIfEmpty
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    setTimeout(boot, 0);
  }
})();
