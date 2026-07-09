var ADMIN_PATH = window.ADMIN_PATH || '/adiwira';
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  const STATE_KEY = '__ADIWIRA_EDIT_THUMBNAIL_MEDIA';

  function uiToast(type, title, message, duration) {
    if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
      window.NewNotifToast.show({
        type: type || 'info',
        title: title || null,
        message: message || '',
        duration: duration
      });
      return;
    }
    alert(message || title || 'Terjadi sesuatu.');
  }

  function normalizeUrl(u) {
    return String(u || '').trim();
  }

  function escapeAttr(s){
    return String(s || '')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  function escapeXml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function createRemovedMediaSvgDataUrl(opts) {
    opts = opts || {};
    const width = Math.max(220, parseInt(opts.width, 10) || 440);
    const height = Math.max(120, parseInt(opts.height, 10) || 280);
    const title = String(opts.title || 'Media telah dihapus');
    const subtitle = String(opts.subtitle || 'dari gallery');

    const svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + title + ' ' + subtitle + '">'
      + '<defs>'
      + '<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
      + '<stop offset="0%" stop-color="#fff7f7"/>'
      + '<stop offset="100%" stop-color="#ffe9e9"/>'
      + '</linearGradient>'
      + '</defs>'
      + '<rect width="100%" height="100%" rx="18" fill="url(#bg)"/>'
      + '<rect x="8" y="8" width="' + (width - 16) + '" height="' + (height - 16) + '" rx="14" fill="none" stroke="#f1b5b5" stroke-width="2" stroke-dasharray="10 8"/>'
      + '<g transform="translate(' + (width / 2) + ' ' + (height / 2 - 26) + ')">'
      + '<rect x="-52" y="-34" width="104" height="68" rx="10" fill="#ffffff" stroke="#ef4444" stroke-width="4"/>'
      + '<circle cx="-18" cy="-10" r="9" fill="#fecaca"/>'
      + '<path d="M-40 20l20-20 16 16 12-12 24 24H-40z" fill="#fca5a5"/>'
      + '<path d="M-64 48L64-48" stroke="#dc2626" stroke-width="10" stroke-linecap="round"/>'
      + '</g>'
      + '<text x="50%" y="' + (height - 54) + '" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="20" font-weight="700" fill="#991b1b">' + escapeXml(title) + '</text>'
      + '<text x="50%" y="' + (height - 26) + '" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="15" fill="#7f1d1d">' + escapeXml(subtitle) + '</text>'
      + '</svg>';

    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
  }

  function buildUrlSignature(input) {
    const sig = new Set();
    const raw = normalizeUrl(input);
    if (!raw) return sig;

    sig.add(raw);

    try {
      const abs = new URL(raw, window.location.origin).href;
      const urlObj = new URL(abs);

      sig.add(abs);
      sig.add(urlObj.pathname);
      sig.add(urlObj.pathname.replace(/\/+$/, '') || '/');
    } catch (e) {
      if (raw.charAt(0) === '/') {
        sig.add(raw.replace(/\/+$/, '') || '/');
      }
    }

    return sig;
  }

  function hasSignatureMatch(a, b) {
    if (!(a instanceof Set) || !(b instanceof Set) || a.size < 1 || b.size < 1) {
      return false;
    }
    for (const v of a) {
      if (b.has(v)) return true;
    }
    return false;
  }

  function setThumbFromMedia(m, thumbnailInput, thumbnailPreview, thumbnailClear) {
    if (!m || !m.url) return;

    const media = {
      id: m.id != null ? (parseInt(m.id, 10) || null) : null,
      url: normalizeUrl(m.url),
      title: String(m.title || ''),
      alt: String(m.alt || ''),
      caption: String(m.caption || ''),
      credit: String(m.credit || '')
    };

    window[STATE_KEY] = media;

    if (thumbnailInput) {
      thumbnailInput.value = media.url;
      try { thumbnailInput.dispatchEvent(new Event('input', { bubbles: true })); } catch(e){}
      try { thumbnailInput.dispatchEvent(new Event('change', { bubbles: true })); } catch(e){}
    }

    if (thumbnailPreview) {
      thumbnailPreview.innerHTML =
        '<img src="' + escapeAttr(media.url) + '"'
        + ' alt="' + escapeAttr(media.alt || media.title || '') + '"'
        + ' style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem;background:#fff">';
    }

    updateClearBtn(thumbnailInput, thumbnailClear);
  }

  function setRemovedThumbPlaceholder(detail, thumbnailInput, thumbnailPreview, thumbnailClear) {
    const svgUrl = createRemovedMediaSvgDataUrl({
      width: 440,
      height: 280,
      title: 'Media telah dihapus',
      subtitle: 'dari gallery'
    });

    window[STATE_KEY] = null;

    if (thumbnailInput) {
      thumbnailInput.value = '';
      try { thumbnailInput.dispatchEvent(new Event('input', { bubbles: true })); } catch(e){}
      try { thumbnailInput.dispatchEvent(new Event('change', { bubbles: true })); } catch(e){}
    }

    if (thumbnailPreview) {
      thumbnailPreview.innerHTML =
        '<img src="' + escapeAttr(svgUrl) + '"'
        + ' data-removed-media="1"'
        + ' alt="Media was deleted from the gallery"'
        + ' style="max-width:220px;max-height:140px;border:1px solid #f1b5b5;padding:.3rem;background:#fff7f7">';
    }

    updateClearBtn(thumbnailInput, thumbnailClear);
  }

  function updateClearBtn(input, btn) {
    if (!btn) return;
    var hasValue = input ? !!normalizeUrl(input.value) : false;
    btn.style.display = hasValue ? '' : 'none';
  }

  function clearThumb(thumbnailInput, thumbnailPreview, thumbnailClear) {
    window[STATE_KEY] = null;

    if (thumbnailInput) {
      thumbnailInput.value = '';
      try { thumbnailInput.dispatchEvent(new Event('input', { bubbles: true })); } catch(e){}
      try { thumbnailInput.dispatchEvent(new Event('change', { bubbles: true })); } catch(e){}
    }

    if (thumbnailPreview) {
      thumbnailPreview.innerHTML = '';
    }

    updateClearBtn(thumbnailInput, thumbnailClear);
  }

  function initExistingThumb(thumbnailInput, thumbnailPreview, thumbnailClear) {
    const currentUrl = thumbnailInput ? normalizeUrl(thumbnailInput.value) : '';
    if (!currentUrl) {
      window[STATE_KEY] = null;
      updateClearBtn(thumbnailInput, thumbnailClear);
      return;
    }

    const img = thumbnailPreview ? thumbnailPreview.querySelector('img') : null;
    const currentId = img ? (parseInt(img.getAttribute('data-media-id') || '0', 10) || null) : null;
    const imgUrl = img
      ? normalizeUrl(img.getAttribute('data-media-url') || img.getAttribute('src') || currentUrl)
      : currentUrl;

    window[STATE_KEY] = {
      id: currentId,
      url: imgUrl,
      title: img ? String(img.getAttribute('title') || '') : '',
      alt: img ? String(img.getAttribute('alt') || '') : '',
      caption: '',
      credit: ''
    };

    updateClearBtn(thumbnailInput, thumbnailClear);
  }

  function extractDeletedPayload(detail) {
    const ids = new Set();
    const urlSig = new Set();

    if (!detail || typeof detail !== 'object') {
      return { ids, urlSig };
    }

    if (detail.id != null) {
      const n = parseInt(detail.id, 10);
      if (Number.isFinite(n) && n > 0) ids.add(String(n));
    }

    if (Array.isArray(detail.deleted_ids)) {
      detail.deleted_ids.forEach(function(v){
        const n = parseInt(v, 10);
        if (Number.isFinite(n) && n > 0) ids.add(String(n));
      });
    }

    const rawUrls = [];
    if (detail.url) rawUrls.push(detail.url);
    if (Array.isArray(detail.deleted_urls)) rawUrls.push.apply(rawUrls, detail.deleted_urls);

    rawUrls.forEach(function(u){
      const sig = buildUrlSignature(u);
      sig.forEach(function(v){ urlSig.add(v); });
    });

    return { ids, urlSig };
  }

  function initThumbnail() {
    const thumbBtn = document.getElementById('btn-open-media-for-thumb');
    const thumbnailInput = document.getElementById('thumbnail-input');
    const thumbnailPreview = document.getElementById('thumbnail-preview');
    const thumbnailClear = document.getElementById('thumbnail-clear');

    if (!thumbBtn) return;
    if (thumbBtn.__adiwiraThumbBound) return;
    thumbBtn.__adiwiraThumbBound = true;

    function handleDeleted(detail) {
      const current = window[STATE_KEY];
      if (!current || !current.url) return;

      const payload = extractDeletedPayload(detail);
      const currentId = current.id != null ? String(current.id) : '';
      const currentSig = buildUrlSignature(current.url);

      const matchById = currentId !== '' && payload.ids.has(currentId);
      const matchByUrl = hasSignatureMatch(currentSig, payload.urlSig);

      if (matchById || matchByUrl) {
        setRemovedThumbPlaceholder(detail || null, thumbnailInput, thumbnailPreview, thumbnailClear);
        uiToast('warning', 'Thumbnail', 'Thumbnail changed to placeholder because the media was deleted from the gallery.', 3200);
      }
    }

    thumbBtn.addEventListener('click', function(){
      if (typeof window.openMediaSelector !== 'function') {
        console.warn('openMediaSelector not available - fallback to modal page');
        try {
          if (typeof window.adamModalOpen === 'function') {
            window.adamModalOpen(ADMIN_PATH + '/admin/modal_img/index.php?embedded=1');
          } else {
            window.open(ADMIN_PATH + '/admin/modal_img/index.php?embedded=1', '_blank');
          }
        } catch(e) {
          window.open(ADMIN_PATH + '/admin/modal_img/index.php?embedded=1', '_blank');
        }
        return;
      }

      window.openMediaSelector({ url: ADMIN_PATH + '/admin/modal_img/index.php?embedded=1' })
        .then(function(detail){
          const m = (typeof window.normalizeMedia === 'function')
            ? window.normalizeMedia(detail)
            : (detail || null);

          if (!m || !m.url) return;
          setThumbFromMedia(m, thumbnailInput, thumbnailPreview, thumbnailClear);
        })
        .catch(function(err){
          console.error('thumbnail selector error', err);
        });
    });

    if (thumbnailClear && !thumbnailClear.__adiwiraThumbBound) {
      thumbnailClear.__adiwiraThumbBound = true;
      thumbnailClear.addEventListener('click', function(){
        clearThumb(thumbnailInput, thumbnailPreview, thumbnailClear);
      });
    }

    document.addEventListener('media:deleted', function(e){
      handleDeleted(e && e.detail ? e.detail : null);
    });

    window.addEventListener('media:deleted', function(e){
      handleDeleted(e && e.detail ? e.detail : null);
    });

    window.addEventListener('message', function(ev){
      try {
        if (!ev.data || ev.data.type !== 'media:deleted') return;
        handleDeleted(ev.data.detail || null);
      } catch (err) {
        console.error('thumbnail media:deleted message error', err);
      }
    });

    initExistingThumb(thumbnailInput, thumbnailPreview, thumbnailClear);

    if (thumbnailInput) {
      thumbnailInput.addEventListener('input', function(){
        updateClearBtn(thumbnailInput, thumbnailClear);
      });
    }

    var toggleUrlBtn = document.getElementById('btn-toggle-url-input');
    if (toggleUrlBtn && thumbnailInput) {
      toggleUrlBtn.addEventListener('click', function(){
        if (thumbnailInput.style.display === 'none') {
          thumbnailInput.style.display = '';
          toggleUrlBtn.textContent = 'Hide URL input';
        } else {
          thumbnailInput.style.display = 'none';
          toggleUrlBtn.textContent = 'Insert via URL';
        }
      });
    }
  }

  window.ADIWIRA.thumbnail = Object.assign({}, window.ADIWIRA.thumbnail || {}, {
    initThumbnail: initThumbnail
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initThumbnail);
  } else {
    initThumbnail();
  }
})();