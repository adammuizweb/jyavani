(function(){
  const btn = document.getElementById('btn-open-media-for-thumb');
  const thumbInput = document.getElementById('thumbnail-input');
  const thumbPreview = document.getElementById('thumbnail-preview');
  const thumbClear = document.getElementById('thumbnail-clear');

  if (!btn) return;

  window.__ADIWIRA_THUMBNAIL_MEDIA = window.__ADIWIRA_THUMBNAIL_MEDIA || null;

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

  function escapeXml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
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

  function setThumbFromMedia(m) {
    if (!m || !m.url) return;

    const media = {
      id: m.id != null ? (parseInt(m.id, 10) || null) : null,
      url: normalizeUrl(m.url),
      title: String(m.title || ''),
      alt: String(m.alt || ''),
      caption: String(m.caption || ''),
      credit: String(m.credit || '')
    };

    window.__ADIWIRA_THUMBNAIL_MEDIA = media;

    if (thumbInput) thumbInput.value = media.url;
    if (thumbPreview) {
      thumbPreview.innerHTML =
        '<img src="' + escapeAttr(media.url) + '"'
        + ' alt="' + escapeAttr(media.alt || media.title || '') + '"'
        + ' style="max-width:220px;max-height:140px;border:1px solid #eee;padding:.3rem;background:#fff">';
    }
  }

  function setRemovedThumbPlaceholder(detail) {
    const svgUrl = createRemovedMediaSvgDataUrl({
      width: 440,
      height: 280,
      title: 'Media telah dihapus',
      subtitle: 'dari gallery'
    });

    window.__ADIWIRA_THUMBNAIL_MEDIA = null;

    if (thumbInput) thumbInput.value = '';
    if (thumbPreview) {
      thumbPreview.innerHTML =
        '<img src="' + escapeAttr(svgUrl) + '"'
        + ' data-removed-media="1"'
        + ' alt="Media telah dihapus dari gallery"'
        + ' style="max-width:220px;max-height:140px;border:1px solid #f1b5b5;padding:.3rem;background:#fff7f7">';
    }
  }

  function clearThumb(showToast) {
    window.__ADIWIRA_THUMBNAIL_MEDIA = null;
    if (thumbInput) thumbInput.value = '';
    if (thumbPreview) thumbPreview.innerHTML = '';
    if (showToast) {
      uiToast('info', 'Thumbnail', 'Thumbnail dibersihkan.', 1800);
    }
  }

  function initExistingThumb() {
    const currentUrl = thumbInput ? normalizeUrl(thumbInput.value) : '';
    if (!currentUrl) {
      window.__ADIWIRA_THUMBNAIL_MEDIA = null;
      return;
    }

    const img = thumbPreview ? thumbPreview.querySelector('img') : null;
    const currentId = img ? (parseInt(img.getAttribute('data-media-id') || '0', 10) || null) : null;
    const imgUrl = img
      ? normalizeUrl(img.getAttribute('data-media-url') || img.getAttribute('src') || currentUrl)
      : currentUrl;

    window.__ADIWIRA_THUMBNAIL_MEDIA = {
      id: currentId,
      url: imgUrl,
      title: img ? String(img.getAttribute('title') || '') : '',
      alt: img ? String(img.getAttribute('alt') || '') : '',
      caption: '',
      credit: ''
    };
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

  function handleDeleted(detail) {
    const current = window.__ADIWIRA_THUMBNAIL_MEDIA;
    if (!current || !current.url) return;

    const payload = extractDeletedPayload(detail);
    const currentId = current.id != null ? String(current.id) : '';
    const currentSig = buildUrlSignature(current.url);

    const matchById = currentId !== '' && payload.ids.has(currentId);
    const matchByUrl = hasSignatureMatch(currentSig, payload.urlSig);

    if (matchById || matchByUrl) {
      setRemovedThumbPlaceholder(detail || null);
      uiToast('warning', 'Thumbnail', 'Thumbnail diubah ke placeholder karena medianya sudah dihapus dari gallery.', 3200);
    }
  }

  btn.addEventListener('click', function(){
    if (typeof openMediaSelector !== 'function') {
      console.warn('openMediaSelector not available');
      return;
    }
    openMediaSelector({ url: '/adiwira/admin/modal_img/index.php?embedded=1' })
      .then(function(detail){
        const m = (typeof normalizeMedia === 'function') ? normalizeMedia(detail) : (detail || null);
        if (!m || !m.url) return;
        setThumbFromMedia(m);
      })
      .catch(function(err){
        console.error('thumbnail selector error', err);
      });
  });

  if (thumbClear) {
    thumbClear.addEventListener('click', function(){
      clearThumb(false);
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

  initExistingThumb();
})();