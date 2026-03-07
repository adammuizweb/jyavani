// /adiwira/static/js/edit/main-init.js
(function(){
  function safe(fn){ try { fn(); } catch(e){ console.warn('[main-init]', e); } }

  function boot(){
    safe(()=> {
      // 1) init CodeMirror selalu (aman)
      if (window.ADIWIRA?.codemirror?.initCM) window.ADIWIRA.codemirror.initCM();

      // 2) init editor mode (yang menentukan area mana tampil)
      if (window.ADIWIRA?.editor?.initEditorMode) window.ADIWIRA.editor.initEditorMode();

      // 3) IMPORTANT: Quill hanya init kalau radio Quill sedang dipilih
      const quillRadio = document.getElementById('editor-quill');
      if (quillRadio && quillRadio.checked) {
        if (window.ADIWIRA?.quill?.initQuill) window.ADIWIRA.quill.initQuill();
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else setTimeout(boot, 0);
})();