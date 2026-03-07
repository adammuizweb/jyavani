// youtube_preview.js
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  const getYouTubeId = window.ADIWIRA.utils.getYouTubeId;

  function renderYouTubePreview(url) {
    const youtubePreview = document.getElementById('youtube-preview');
    if (!youtubePreview) return;
    const id = getYouTubeId(url);
    if (!id) { youtubePreview.innerHTML = ''; return; }
    const src = 'https://www.youtube.com/embed/' + encodeURIComponent(id);
    youtubePreview.innerHTML = '<iframe width="400" height="225" src="' + src + '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
  }

  function initYouTubePreview() {
    const youtubeInput = document.getElementById('youtube-input');
    if (youtubeInput) {
      youtubeInput.addEventListener('input', function() { renderYouTubePreview(this.value); });
      renderYouTubePreview(youtubeInput.value || '');
    }
  }

  window.ADIWIRA.youtube = { initYouTubePreview, renderYouTubePreview };
})();
