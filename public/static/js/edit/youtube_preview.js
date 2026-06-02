// lokasi public/adiwira/static/js/edit/youtube_preview.js
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  const utils = window.ADIWIRA.utils || {};
  const getYouTubeId = utils.getYouTubeId || function(url){
    url = String(url || '').trim();
    if (!url) return '';
    let m = url.match(/youtu\.be\/([A-Za-z0-9_-]+)/i);
    if (m) return m[1];
    m = url.match(/[?&]v=([A-Za-z0-9_-]+)/i);
    if (m) return m[1];
    m = url.match(/\/embed\/([A-Za-z0-9_-]+)/i);
    if (m) return m[1];
    return '';
  };

  function getInput() {
    return document.getElementById('youtube-input') || document.getElementById('youtube');
  }

  function renderYouTubePreview(url) {
    const youtubePreview = document.getElementById('youtube-preview');
    if (!youtubePreview) return;

    const id = getYouTubeId(url);
    if (!id) {
      youtubePreview.innerHTML = '';
      return;
    }

    const thumb = 'https://img.youtube.com/vi/' + encodeURIComponent(id) + '/hqdefault.jpg';
    const watch = 'https://www.youtube.com/watch?v=' + encodeURIComponent(id);

    youtubePreview.innerHTML =
      '<a href="' + watch + '" target="_blank" rel="noopener noreferrer" style="display:inline-block;text-decoration:none;">' +
        '<img src="' + thumb + '" alt="YouTube Preview" style="max-width:320px;border:1px solid #ddd;border-radius:8px;display:block;">' +
      '</a>';
  }

  function initYouTubePreview() {
    const youtubeInput = getInput();
    if (!youtubeInput) return;

    if (youtubeInput.__adiwiraYoutubeBound) return;
    youtubeInput.__adiwiraYoutubeBound = true;

    youtubeInput.addEventListener('input', function() {
      renderYouTubePreview(this.value);
    });

    renderYouTubePreview(youtubeInput.value || '');
  }

  window.ADIWIRA.youtube = {
    initYouTubePreview,
    renderYouTubePreview
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initYouTubePreview);
  } else {
    initYouTubePreview();
  }
})();