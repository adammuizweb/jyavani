// public/adiwira/static/js/edit/codemirror.js — init early, setValueSilent, visibility observer, safe canonical writes
(function(){
  window.ADIWIRA = window.ADIWIRA || {};
  if (window.ADIWIRA.codemirror) return;
  let cm = null;
  let cmSilent = false;
  let observer = null;
  let referenceConfig = null;
  let referenceMarks = [];
  let referenceTimer = null;
  let referencePopover = null;
  let referenceHideTimer = null;
  let referenceListenersBound = false;

  function safeReferenceUrl(value){
    if (!referenceConfig || typeof value !== 'string' || value === '') return '';
    try {
      const url = new URL(value, window.location.href);
      const base = String(referenceConfig.admin_base_path || '').replace(/\/+$/, '');
      if (url.origin !== window.location.origin || base === ''
        || (url.pathname !== base && !url.pathname.startsWith(base + '/'))) return '';
      return url.pathname + url.search + url.hash;
    } catch(e) {
      return '';
    }
  }

  function normalizeReferenceConfig(input){
    if (!input || input.schema !== 1 || !Array.isArray(input.providers)) return null;
    const providers = input.providers.filter(function(provider){
      if (!provider || provider.syntax !== 'widget' || typeof provider.shortcode !== 'string'
        || typeof provider.attribute !== 'string' || typeof provider.value_pattern !== 'string') return false;
      try { provider._valuePattern = new RegExp(provider.value_pattern); } catch(e) { return false; }
      return true;
    });
    if (!providers.length) return null;
    return {
      schema: 1,
      admin_base_path: String(input.admin_base_path || ''),
      providers: providers,
      strings: input.strings && typeof input.strings === 'object' ? input.strings : {}
    };
  }

  function hideReferencePopover(immediate){
    if (referenceHideTimer) clearTimeout(referenceHideTimer);
    const hide = function(){ if (referencePopover) referencePopover.hidden = true; };
    if (immediate) hide();
    else referenceHideTimer = setTimeout(hide, 180);
  }

  function clearReferenceMarks(){
    referenceMarks.forEach(function(marker){
      try { marker.clear(); } catch(e) {}
    });
    referenceMarks = [];
    hideReferencePopover(true);
  }

  function trimReferenceValue(value){
    return value.replace(/^[\u0000\u0009-\u000B\u000D\u0020]+|[\u0000\u0009-\u000B\u000D\u0020]+$/g, '');
  }

  function normalizeReferenceValue(provider, value){
    value = String(value || '');
    if (provider.trim === true) value = trimReferenceValue(value);
    return provider.normalize === 'lowercase' ? value.toLowerCase() : value;
  }

  function referenceByteLength(value){
    if (typeof TextEncoder === 'function') return new TextEncoder().encode(value).length;
    try { return encodeURIComponent(value).replace(/%[0-9A-F]{2}|./gi, 'x').length; }
    catch(e) { return Number.MAX_SAFE_INTEGER; }
  }

  function referenceDescriptor(provider, value){
    const key = normalizeReferenceValue(provider, value);
    const maxBytes = Number(provider.max_bytes || 0);
    if ((maxBytes > 0 && referenceByteLength(key) > maxBytes) || !provider._valuePattern.test(key)) return null;
    const entries = provider.entries && typeof provider.entries === 'object' ? provider.entries : {};
    const existing = entries[key];
    if (existing && typeof existing === 'object') return { key: key, data: existing };
    const fallback = provider.fallback;
    const parameter = String(provider.fallback_parameter || '');
    if (!fallback || typeof fallback !== 'object' || parameter === '') return null;
    const base = safeReferenceUrl(String(fallback.url || ''));
    if (base === '') return null;
    try {
      const target = new URL(base, window.location.origin);
      target.searchParams.set(parameter, key);
      return {
        key: key,
        data: Object.assign({}, fallback, { url: target.pathname + target.search + target.hash })
      };
    } catch(e) {
      return null;
    }
  }

  function widgetReferenceRanges(value, provider){
    const ranges = [];
    const shortcodePattern = /\[\[[\x09-\x0D\x20]*widget:([a-z0-9_-]+)[\x09-\x0D\x20]*([^\]]*)\]\]/gi;
    let shortcodeMatch;
    while ((shortcodeMatch = shortcodePattern.exec(value)) !== null) {
      if (String(shortcodeMatch[1]).toLowerCase() !== provider.shortcode) continue;
      const attributeText = String(shortcodeMatch[2] || '');
      const attributeStart = shortcodeMatch.index + shortcodeMatch[0].lastIndexOf(attributeText);
      const attributePattern = /([a-zA-Z_][a-zA-Z0-9_-]*)[\x09-\x0D\x20]*=[\x09-\x0D\x20]*(?:"([^"]*)"|'([^']*)'|([^\x09-\x0D\x20]+))/g;
      let attributeMatch;
      let selected = null;
      while ((attributeMatch = attributePattern.exec(attributeText)) !== null) {
        if (attributeMatch[1] !== provider.attribute) continue;
        const full = attributeMatch[0];
        const equalsAt = full.indexOf('=');
        const afterEquals = full.slice(equalsAt + 1);
        const leadingSpace = (afterEquals.match(/^[\x09-\x0D\x20]*/) || [''])[0].length;
        const tokenAt = equalsAt + 1 + leadingSpace;
        const quoteOffset = full[tokenAt] === '"' || full[tokenAt] === "'" ? 1 : 0;
        const rawValue = attributeMatch[2] !== undefined
          ? attributeMatch[2]
          : (attributeMatch[3] !== undefined ? attributeMatch[3] : attributeMatch[4]);
        selected = {
          value: String(rawValue || ''),
          from: attributeStart + attributeMatch.index + tokenAt + quoteOffset
        };
      }
      if (selected && selected.value !== '') {
        selected.to = selected.from + selected.value.length;
        ranges.push(selected);
      }
    }
    return ranges;
  }

  function rebuildReferences(){
    clearReferenceMarks();
    if (!cm || !referenceConfig || typeof cm.markText !== 'function') return;
    const value = cm.getValue();
    let sequence = 0;
    referenceConfig.providers.forEach(function(provider){
      widgetReferenceRanges(value, provider).forEach(function(range){
        const resolved = referenceDescriptor(provider, range.value);
        if (!resolved) return;
        let markFrom = range.from;
        let markTo = range.to;
        if (provider.trim === true) {
          const trimmed = trimReferenceValue(range.value);
          const leadingAt = range.value.indexOf(trimmed);
          markFrom += Math.max(0, leadingAt);
          markTo = markFrom + trimmed.length;
        }
        if (markTo <= markFrom) return;
        const id = 'jy-editor-reference-' + (++sequence);
        const descriptor = {
          id: id,
          key: resolved.key,
          label: String(provider.label || provider.shortcode),
          title: String(resolved.data.title || resolved.key),
          source: String(resolved.data.source || ''),
          actionLabel: String(resolved.data.action_label || ''),
          url: safeReferenceUrl(String(resolved.data.url || ''))
        };
        const shortcut = String(referenceConfig.strings.shortcut || '');
        const accessibleDetails = [descriptor.label + ': ' + descriptor.key, descriptor.title, descriptor.source,
          descriptor.actionLabel, shortcut].filter(Boolean).join('. ');
        const marker = cm.markText(cm.posFromIndex(markFrom), cm.posFromIndex(markTo), {
          className: 'jy-editor-reference' + (descriptor.url ? ' jy-editor-reference--actionable' : ''),
          attributes: {
            'data-jy-editor-reference-id': id,
            'tabindex': '0',
            'role': descriptor.url ? 'link' : 'note',
            'aria-label': accessibleDetails,
            'aria-haspopup': 'dialog',
            'aria-controls': 'jy-editor-reference-popover'
          }
        });
        marker.jyEditorReference = descriptor;
        referenceMarks.push(marker);
      });
    });
  }

  function scheduleReferenceRefresh(){
    if (referenceTimer) clearTimeout(referenceTimer);
    referenceTimer = setTimeout(rebuildReferences, 120);
  }

  function setReferences(config){
    referenceConfig = normalizeReferenceConfig(config);
    clearReferenceMarks();
    scheduleReferenceRefresh();
  }

  function referenceById(id){
    for (const marker of referenceMarks) {
      if (marker.jyEditorReference && marker.jyEditorReference.id === id) return marker.jyEditorReference;
    }
    return null;
  }

  function ensureReferencePopover(){
    if (referencePopover) return referencePopover;
    referencePopover = document.createElement('div');
    referencePopover.id = 'jy-editor-reference-popover';
    referencePopover.className = 'jy-editor-reference-popover';
    referencePopover.setAttribute('role', 'dialog');
    referencePopover.hidden = true;
    referencePopover.addEventListener('mouseenter', function(){
      if (referenceHideTimer) clearTimeout(referenceHideTimer);
    });
    referencePopover.addEventListener('mouseleave', function(){ hideReferencePopover(); });
    document.body.appendChild(referencePopover);
    return referencePopover;
  }

  function showReferencePopover(descriptor, target){
    if (!descriptor || !target) return;
    if (referenceHideTimer) clearTimeout(referenceHideTimer);
    const popover = ensureReferencePopover();
    popover.replaceChildren();
    popover.setAttribute('aria-label', descriptor.label + ': ' + descriptor.key);

    const heading = document.createElement('strong');
    heading.textContent = descriptor.label + ': ' + descriptor.key;
    popover.appendChild(heading);
    if (descriptor.title && descriptor.title !== descriptor.key) {
      const title = document.createElement('span');
      title.className = 'jy-editor-reference-popover__title';
      title.textContent = descriptor.title;
      popover.appendChild(title);
    }
    if (descriptor.source) {
      const source = document.createElement('span');
      source.className = 'jy-editor-reference-popover__source';
      source.textContent = String(referenceConfig.strings.source || 'Source:') + ' ' + descriptor.source;
      popover.appendChild(source);
    }
    if (descriptor.url) {
      const link = document.createElement('a');
      link.href = descriptor.url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = descriptor.actionLabel || String(referenceConfig.strings.open_new_tab || 'Open in new tab');
      popover.appendChild(link);
      const shortcut = document.createElement('small');
      shortcut.textContent = String(referenceConfig.strings.shortcut || '');
      popover.appendChild(shortcut);
    }

    popover.hidden = false;
    const rect = target.getBoundingClientRect();
    const width = Math.min(360, Math.max(220, popover.offsetWidth));
    const left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.left));
    const below = rect.bottom + 8;
    const top = below + popover.offsetHeight <= window.innerHeight - 8
      ? below
      : Math.max(8, rect.top - popover.offsetHeight - 8);
    popover.style.left = left + 'px';
    popover.style.top = top + 'px';
  }

  function openReference(descriptor){
    const url = descriptor ? safeReferenceUrl(descriptor.url) : '';
    if (url === '') return false;
    const opened = window.open(url, '_blank', 'noopener');
    if (opened) opened.opener = null;
    return true;
  }

  function referenceAtCursor(instance){
    if (!instance || typeof instance.findMarksAt !== 'function') return null;
    const cursor = instance.getCursor();
    let marks = instance.findMarksAt(cursor);
    if (!marks.length && cursor.ch > 0) marks = instance.findMarksAt({ line: cursor.line, ch: cursor.ch - 1 });
    const marker = marks.find(function(candidate){ return candidate.jyEditorReference; });
    return marker ? marker.jyEditorReference : null;
  }

  function bindReferenceListeners(){
    if (!cm || referenceListenersBound) return;
    referenceListenersBound = true;
    const wrapper = cm.getWrapperElement();
    const markerElement = function(target){
      return target && typeof target.closest === 'function'
        ? target.closest('[data-jy-editor-reference-id]')
        : null;
    };
    const showFromEvent = function(event){
      const element = markerElement(event.target);
      if (!element) return;
      showReferencePopover(referenceById(element.dataset.jyEditorReferenceId), element);
    };
    wrapper.addEventListener('mouseover', showFromEvent);
    wrapper.addEventListener('focusin', showFromEvent);
    wrapper.addEventListener('mouseout', function(event){
      if (markerElement(event.target) && !markerElement(event.relatedTarget)) hideReferencePopover();
    });
    wrapper.addEventListener('focusout', function(event){
      if (markerElement(event.target)) hideReferencePopover();
    });
    wrapper.addEventListener('mousedown', function(event){
      const element = markerElement(event.target);
      if (!element || (!event.ctrlKey && !event.metaKey)) return;
      const descriptor = referenceById(element.dataset.jyEditorReferenceId);
      if (openReference(descriptor)) event.preventDefault();
    });
    wrapper.addEventListener('keydown', function(event){
      if (event.key !== 'Enter') return;
      const element = markerElement(event.target);
      const descriptor = element ? referenceById(element.dataset.jyEditorReferenceId) : null;
      if (openReference(descriptor)) event.preventDefault();
    });
    window.addEventListener('scroll', function(){ hideReferencePopover(true); }, true);
  }

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
      cm.on('change', function(){
        clearReferenceMarks();
        scheduleReferenceRefresh();
      });
      bindReferenceListeners();
      setReferences(window.ADIWIRA_EDITOR_REFERENCES || null);

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
    'Ctrl-Y': function(cm) { cm.redo(); },
    'F12': function(cm) {
      if (!openReference(referenceAtCursor(cm)) && window.CodeMirror && window.CodeMirror.Pass) {
        return window.CodeMirror.Pass;
      }
    }
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
    setReferences,
    refreshReferences: rebuildReferences,
    _internal: { observeVisibility }
  };
})();
