// /adiwira/static/js/edit/main-init.js
(function(){
  function safe(fn){ try { fn(); } catch(e){ console.warn('[main-init]', e); } }

  function boot(){
    safe(()=> {
      if (window.ADIWIRA?.codemirror?.initCM) window.ADIWIRA.codemirror.initCM();

      if (window.ADIWIRA?.editor?.initEditorMode) window.ADIWIRA.editor.initEditorMode();

      const quillRadio = document.getElementById('editor-quill');
      if (quillRadio && quillRadio.checked) {
        if (window.ADIWIRA?.quill?.initQuill) window.ADIWIRA.quill.initQuill();
      }

      if (window.ADIWIRA?.thumbnail?.initThumbnail) {
        window.ADIWIRA.thumbnail.initThumbnail();
      }

      if (window.ADIWIRA?.youtube?.initYouTubePreview) {
        window.ADIWIRA.youtube.initYouTubePreview();
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else setTimeout(boot, 0);
})();