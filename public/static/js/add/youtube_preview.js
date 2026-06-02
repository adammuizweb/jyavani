/**
 * lokasi /adiwira/static/js/add/youtube_preview.js
 * --------------------------------------------------
 * Preview YouTube video dari URL input
 * Dipakai di posts/edit dan pages/edit
 * Tidak bergantung live preview realtime
 */
(function(){
  // safe small module, compatible with edit's utils
  window.ADIWIRA = window.ADIWIRA || {};

  function safeGetYouTubeId(url){
    if (!url) return null;
    // prefer ADIWIRA.utils.getYouTubeId if present
    try {
      if (window.ADIWIRA && window.ADIWIRA.utils && typeof window.ADIWIRA.utils.getYouTubeId === 'function') {
        return window.ADIWIRA.utils.getYouTubeId(url);
      }
    } catch(e){}
    // fallback tiny extractor (cover common forms)
    var m;
    if ((m = url.match(/youtu\.be\/([a-zA-Z0-9_-]{6,})/))) return m[1];
    if ((m = url.match(/[?&]v=([a-zA-Z0-9_-]{6,})/))) return m[1];
    if ((m = url.match(/youtube\.com\/embed\/([a-zA-Z0-9_-]{6,})/))) return m[1];
    return null;
  }

  function renderPreviewFor(url){
    var wrap = document.getElementById('youtube-preview');
    if (!wrap) return;
    var id = safeGetYouTubeId(url);
    if (!id) { wrap.innerHTML = ''; return; }
    var src = 'https://www.youtube.com/embed/' + encodeURIComponent(id);
    wrap.innerHTML = '<iframe width="400" height="225" src="' + src + '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
  }

  function init(){
    // support both ids used across templates
    var input = document.getElementById('youtube-input') || document.getElementById('youtube');
    if (!input) return;
    // immediate render if value present
    renderPreviewFor(input.value || '');
    // simple debounce
    var t = null;
    input.addEventListener('input', function(){
      clearTimeout(t);
      t = setTimeout(function(){ renderPreviewFor(input.value || ''); }, 300);
    });
  }

  // try init now or on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // expose (optional)
  window.ADIWIRA.youtube = window.ADIWIRA.youtube || {};
  window.ADIWIRA.youtube.render = renderPreviewFor;
})();
