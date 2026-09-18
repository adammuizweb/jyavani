(function () {
  'use strict';

  if (window.JyavaniEditor) return;

  var VERSION = 2;
  var listeners = new Map();
  var actions = new Map();
  var readyResolvers = [];
  var handle = null;
  var revision = 0;
  var mutating = false;
  var lastQuillSelection = null;
  var actionSelection = null;
  var boundQuill = null;
  var boundCodeMirror = null;
  var boundForm = null;
  var boundModeInputs = new WeakSet();
  var readyAnnounced = false;
  var announcedMode = null;
  var mountedEditors = new WeakMap();

  function editorConfig() {
    var supplied = window.JyavaniEditorContext;
    return supplied && typeof supplied === 'object' ? supplied : {};
  }

  function resolveForm() {
    var config = editorConfig();
    return (config.formId && document.getElementById(config.formId))
      || document.querySelector('form[data-content-editor]')
      || null;
  }

  function canonicalField() {
    var form = resolveForm();
    return (form && form.querySelector('[data-editor-canonical], [name="content"]'))
      || document.getElementById('content-textarea')
      || document.getElementById('content-input');
  }

  function getMode() {
    var form = resolveForm();
    var selected = form && form.querySelector('input[name="editor_mode"]:checked');
    if (selected) return selected.value;
    return document.getElementById('cm-textarea') ? 'codemirror' : 'quill';
  }

  function quillInstance() {
    var api = window.ADIWIRA && window.ADIWIRA.quill;
    if (api && typeof api.getInstance === 'function') return api.getInstance();
    return window.__adam_quill_instance || null;
  }

  function codeMirrorInstance() {
    var api = window.ADIWIRA && window.ADIWIRA.codemirror;
    return api && typeof api.getInstance === 'function' ? api.getInstance() : null;
  }

  function context() {
    var config = editorConfig();
    var form = resolveForm();
    var csrf = form && form.querySelector('[name="csrf_token"]');
    return {
      schema: 1,
      resourceType: config.resourceType === 'page' ? 'page' : 'article',
      operation: config.operation === 'edit' ? 'edit' : 'add',
      resourceId: Number.isInteger(config.resourceId) && config.resourceId > 0 ? config.resourceId : null,
      formId: form ? form.id : String(config.formId || ''),
      canUpdate: config.canUpdate !== false,
      canUseUnfilteredHtml: config.canUseUnfilteredHtml === true,
      csrfToken: csrf ? String(csrf.value || '') : '',
      adminBasePath: String(config.adminBasePath || window.ADMIN_PATH || '/dashboard')
    };
  }

  function emit(name, detail) {
    var callbacks = listeners.get(name);
    if (callbacks) callbacks.forEach(function (callback) {
      try { callback(detail); } catch (error) { console.error('[JyavaniEditor:' + name + ']', error); }
    });
    try {
      document.dispatchEvent(new CustomEvent('jyavani:editor:' + name, { detail: detail }));
    } catch (error) {}
  }

  function sourceName(value) {
    value = String(value || 'api').trim().toLowerCase();
    return /^[a-z0-9]+(?:[._-][a-z0-9]+)*$/.test(value) ? value : 'api';
  }

  function notifyChange(source) {
    revision++;
    emit('change', {
      editor: handle,
      mode: getMode(),
      content: getContent(),
      revision: revision,
      source: sourceName(source),
      dirty: isDirty()
    });
  }

  function announceModeChange() {
    var mode = getMode();
    if (announcedMode === null) {
      announcedMode = mode;
      return false;
    }
    if (announcedMode === mode) return false;
    announcedMode = mode;
    lastQuillSelection = null;
    actionSelection = null;
    revision++;
    emit('modechange', { editor: handle, mode: mode, revision: revision });
    return true;
  }

  function getContent() {
    var mode = getMode();
    var value = '';
    if (mode === 'codemirror') {
      var cm = codeMirrorInstance();
      var cmSource = document.getElementById('cm-textarea');
      value = cm && typeof cm.getValue === 'function'
        ? cm.getValue()
        : (cmSource ? cmSource.value : '');
    } else if (mode === 'quill') {
      var quill = quillInstance();
      value = quill && quill.root ? String(quill.root.innerHTML || '') : '';
      var quillApi = window.ADIWIRA && window.ADIWIRA.quill;
      if (quillApi && typeof quillApi.cleanExtraBreaks === 'function') {
        value = quillApi.cleanExtraBreaks(value);
      }
    } else {
      var extensionCanonical = canonicalField();
      value = extensionCanonical ? String(extensionCanonical.value || '') : '';
    }
    var canonical = canonicalField();
    if (canonical) canonical.value = value;
    return value;
  }

  function sync() {
    return getContent();
  }

  function getSelection() {
    var mode = getMode();
    if (mode !== 'quill' && mode !== 'codemirror') return null;
    if (mode === 'codemirror') {
      var cm = codeMirrorInstance();
      if (!cm || typeof cm.getCursor !== 'function' || typeof cm.indexFromPos !== 'function') return null;
      var fromPosition = cm.getCursor('from');
      var toPosition = cm.getCursor('to');
      var from = cm.indexFromPos(fromPosition);
      var to = cm.indexFromPos(toPosition);
      return {
        mode: mode,
        from: from,
        to: to,
        text: typeof cm.getRange === 'function' ? cm.getRange(fromPosition, toPosition) : '',
        revision: revision
      };
    }

    var quill = quillInstance();
    if (!quill) return null;
    var range = quill.getSelection && quill.getSelection();
    if (range) lastQuillSelection = range;
    range = range || lastQuillSelection;
    if (!range) return null;
    return {
      mode: mode,
      from: range.index,
      to: range.index + range.length,
      text: typeof quill.getText === 'function' ? quill.getText(range.index, range.length) : '',
      revision: revision
    };
  }

  function assertRevision(options) {
    if (!options || options.ifRevision == null) return;
    if (Number(options.ifRevision) === revision) return;
    var error = new Error('Editor content changed while the operation was running.');
    error.code = 'EDITOR_REVISION_CONFLICT';
    throw error;
  }

  function setSelection(selection) {
    if (!selection || selection.mode !== getMode()) return false;
    var from = Math.max(0, Number(selection.from) || 0);
    var to = Math.max(from, Number(selection.to) || from);
    if (selection.mode === 'codemirror') {
      var cm = codeMirrorInstance();
      if (!cm || typeof cm.posFromIndex !== 'function') return false;
      cm.setSelection(cm.posFromIndex(from), cm.posFromIndex(to));
      return true;
    }
    var quill = quillInstance();
    if (!quill || typeof quill.setSelection !== 'function') return false;
    quill.setSelection(from, to - from, 'silent');
    lastQuillSelection = { index: from, length: to - from };
    return true;
  }

  function setContent(value, options) {
    options = options || {};
    assertRevision(options);
    value = String(value == null ? '' : value);
    if (getContent() === value) return;

    var previous = options.selection === 'preserve' ? getSelection() : null;
    mutating = true;
    try {
      if (getMode() === 'codemirror') {
        var cmApi = window.ADIWIRA && window.ADIWIRA.codemirror;
        var cm = codeMirrorInstance();
        if (cmApi && typeof cmApi.setValueSilent === 'function') cmApi.setValueSilent(value);
        else if (cm && typeof cm.setValue === 'function') cm.setValue(value);
        else {
          var cmSource = document.getElementById('cm-textarea');
          if (cmSource) cmSource.value = value;
        }
      } else if (getMode() === 'quill') {
        var quillApi = window.ADIWIRA && window.ADIWIRA.quill;
        var quill = quillInstance();
        if (quillApi && typeof quillApi.setHTMLIfDifferent === 'function') quillApi.setHTMLIfDifferent(value);
        else if (quill && quill.clipboard) quill.setContents(quill.clipboard.convert(value), 'silent');
      } else {
        var extensionCanonical = canonicalField();
        if (extensionCanonical) extensionCanonical.value = value;
      }
      sync();
    } finally {
      mutating = false;
    }

    if (options.selection === 'start') setSelection({ mode: getMode(), from: 0, to: 0 });
    else if (options.selection === 'end') {
      var length = getMode() === 'codemirror'
        ? value.length
        : Math.max(0, (quillInstance() ? quillInstance().getLength() : 1) - 1);
      setSelection({ mode: getMode(), from: length, to: length });
    } else if (previous) setSelection(previous);
    notifyChange(options.source || 'api');
  }

  function insert(value, options) {
    options = options || {};
    value = String(value == null ? '' : value);
    var mode = getMode();
    if (mode !== 'quill' && mode !== 'codemirror') {
      var unsupported = new Error('Selection insertion is unavailable for this editor mode.');
      unsupported.code = 'EDITOR_UNSUPPORTED_MODE';
      throw unsupported;
    }
    var selection = options.range || getSelection();
    if (options.ifRevision == null && selection && selection.revision != null) {
      options.ifRevision = selection.revision;
    }
    assertRevision(options);
    if (selection && selection.mode !== mode) {
      var modeError = new Error('Editor selection belongs to another mode.');
      modeError.code = 'EDITOR_SELECTION_MODE_MISMATCH';
      throw modeError;
    }
    selection = selection || { mode: mode, from: getContent().length, to: getContent().length, revision: revision };
    var from = Math.max(0, Number(selection.from) || 0);
    var to = Math.max(from, Number(selection.to) || from);
    mutating = true;
    var end = from;
    try {
      if (mode === 'codemirror') {
        var cm = codeMirrorInstance();
        if (!cm || typeof cm.posFromIndex !== 'function') throw new Error('CodeMirror is not ready.');
        cm.replaceRange(value, cm.posFromIndex(from), cm.posFromIndex(to), '+jyavani-editor');
        end = from + value.length;
      } else {
        var quill = quillInstance();
        if (!quill) throw new Error('Quill is not ready.');
        if (to > from) quill.deleteText(from, to - from, 'api');
        var beforeLength = quill.getLength();
        if (options.format === 'text') quill.insertText(from, value, 'api');
        else quill.clipboard.dangerouslyPasteHTML(from, value, 'api');
        end = from + Math.max(0, quill.getLength() - beforeLength);
      }
      sync();
    } finally {
      mutating = false;
    }
    setSelection({ mode: mode, from: end, to: end });
    notifyChange(options.source || 'api');
    return getSelection();
  }

  function isDirty() {
    var guard = window.ADIWIRA && window.ADIWIRA.unsavedGuard;
    return !!(guard && typeof guard.isDirty === 'function' && guard.isDirty(resolveForm()));
  }

  function focus() {
    var editor = getMode() === 'codemirror' ? codeMirrorInstance() : quillInstance();
    if (editor && typeof editor.focus === 'function') editor.focus();
  }

  function waitForMode(mode) {
    return new Promise(function (resolve, reject) {
      var attempts = 0;
      var timer = setInterval(function () {
        bindEngines();
        var instance = mode === 'codemirror' ? codeMirrorInstance() : quillInstance();
        if (getMode() === mode && instance) {
          clearInterval(timer);
          resolve();
        } else if (++attempts > 100) {
          clearInterval(timer);
          reject(new Error('Editor mode did not become ready.'));
        }
      }, 50);
    });
  }

  function setMode(mode) {
    mode = String(mode || '');
    if (mode !== 'quill' && mode !== 'codemirror') return Promise.reject(new Error('Unsupported editor mode.'));
    if (mode === getMode()) return Promise.resolve();
    sync();
    if (mode === 'quill') {
      var quillApi = window.ADIWIRA && window.ADIWIRA.quill;
      if (quillApi && typeof quillApi.isContentComplex === 'function' && quillApi.isContentComplex()) {
        var error = new Error('Complex HTML cannot be represented safely in Quill.');
        error.code = 'EDITOR_LOSSY_MODE_CHANGE';
        return Promise.reject(error);
      }
    }
    var target = document.querySelector('input[name="editor_mode"][value="' + mode + '"]');
    if (!target) return Promise.reject(new Error('Editor mode is unavailable.'));
    target.checked = true;
    target.dispatchEvent(new Event('change', { bubbles: true }));
    return waitForMode(mode).then(function () {
      announceModeChange();
    });
  }

  function bindEngines() {
    var quill = quillInstance();
    if (quill && quill !== boundQuill) {
      boundQuill = quill;
      quill.on('selection-change', function (range) { if (range) lastQuillSelection = range; });
      quill.on('text-change', function (delta, oldDelta, source) {
        if (!mutating && source !== 'silent') notifyChange(source === 'user' ? 'user' : 'api');
      });
    }
    var cm = codeMirrorInstance();
    if (cm && cm !== boundCodeMirror) {
      boundCodeMirror = cm;
      cm.on('change', function (instance, change) {
        if (!mutating) notifyChange(change && change.origin === '+input' ? 'user' : 'api');
      });
    }
  }

  function renderActions() {
    var container = document.querySelector('[data-jyavani-editor-actions]');
    if (!container) return;
    container.innerHTML = '';
    Array.from(actions.values()).sort(function (a, b) {
      return (a.priority || 10) - (b.priority || 10) || a.id.localeCompare(b.id);
    }).forEach(function (action) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'jy-editor-action';
      button.setAttribute('data-editor-action', action.id);
      button.textContent = action.label;
      if (action.title) button.title = action.title;
      button.addEventListener('pointerdown', function () {
        var captured = actionSelection = getSelection();
        var clearCaptured = function () {
          if (actionSelection === captured) actionSelection = null;
        };
        document.addEventListener('pointercancel', clearCaptured, { once: true });
        document.addEventListener('pointerup', function () { setTimeout(clearCaptured, 0); }, { once: true });
      });
      button.addEventListener('click', function () {
        var capturedSelection = actionSelection || getSelection();
        actionSelection = null;
        var state = { editor: handle, context: context(), selection: capturedSelection };
        if (typeof action.enabled === 'function' && action.enabled(state) === false) return;
        button.disabled = true;
        Promise.resolve(action.onActivate(state)).catch(function (error) {
          console.error('[JyavaniEditor action ' + action.id + ']', error);
        }).finally(function () { button.disabled = false; });
      });
      container.appendChild(button);
    });
    container.hidden = actions.size === 0;
  }

  function registerButton(action) {
    if (!action || !/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/.test(String(action.id || ''))
        || !String(action.label || '').trim() || typeof action.onActivate !== 'function') {
      throw new TypeError('Editor actions require a namespaced id, label, and onActivate callback.');
    }
    actions.set(action.id, {
      id: String(action.id),
      label: String(action.label),
      title: action.title ? String(action.title) : '',
      priority: Number.isFinite(Number(action.priority)) ? Number(action.priority) : 10,
      enabled: action.enabled,
      onActivate: action.onActivate
    });
    renderActions();
    return function () { actions.delete(action.id); renderActions(); };
  }

  function on(name, listener) {
    if (typeof listener !== 'function') throw new TypeError('Editor event listener must be callable.');
    if (!listeners.has(name)) listeners.set(name, new Set());
    listeners.get(name).add(listener);
    return function () { listeners.get(name).delete(listener); };
  }

  function mountElement(target) {
    if (typeof target === 'string') return document.querySelector(target);
    return target && target.nodeType === 1 ? target : null;
  }

  function mountEscape(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function mountEditor(target, options) {
    var root = mountElement(target);
    if (!root || !root.matches('[data-jyavani-editor-mount]')) throw new TypeError('A Jyavani editor mount root is required.');
    if (mountedEditors.has(root)) return mountedEditors.get(root);
    options = options && typeof options === 'object' ? options : {};
    if (typeof window.Quill !== 'function' || typeof window.CodeMirror !== 'function') {
      throw new Error('Jyavani editor vendor engines are unavailable.');
    }
    var policy = window.ADIWIRA && window.ADIWIRA.quill;
    if (!policy || typeof policy.isHtmlComplex !== 'function' || typeof policy.stripComplexHtml !== 'function') {
      throw new Error('Jyavani editor policy is unavailable.');
    }

    var canonical = root.querySelector('[data-editor-canonical]');
    var quillElement = root.querySelector('[data-editor-quill]');
    var codeElement = root.querySelector('[data-editor-codemirror]');
    var quillArea = root.querySelector('[data-editor-area="quill"]');
    var codeArea = root.querySelector('[data-editor-area="codemirror"]');
    var hint = root.querySelector('[data-editor-complex-hint]');
    var actionContainer = root.querySelector('[data-editor-actions]');
    var modeInputs = Array.from(root.querySelectorAll('[data-editor-mode]'));
    if (!canonical || !quillElement || !codeElement || !quillArea || !codeArea || modeInputs.length !== 2) {
      throw new Error('Jyavani editor mount markup is incomplete.');
    }

    var mountContext = Object.assign({}, options.context && typeof options.context === 'object' ? options.context : {});
    mountContext.schema = 2;
    var adapters = options.adapters && typeof options.adapters === 'object' ? options.adapters : {};
    var mountListeners = new Map();
    var mountActions = new Map();
    var revision = 0;
    var suppress = true;
    var destroyed = false;
    var lastQuillSelection = null;
    var currentMode = (modeInputs.find(function (input) { return input.checked; }) || {}).value === 'codemirror' ? 'codemirror' : 'quill';
    var initialContent = String(canonical.value || '');
    if (currentMode === 'quill' && policy.isHtmlComplex(initialContent)) currentMode = 'codemirror';
    var savedContent = initialContent;
    var savedMode = currentMode;
    var form = root.closest('form');
    var toolbar = typeof policy.toolbarConfig === 'function' ? policy.toolbarConfig() : true;
    var quill = new window.Quill(quillElement, {
      theme: 'snow',
      modules: { toolbar: toolbar },
      placeholder: String(options.placeholder || window.QUILL_PLACEHOLDER || 'Write article content here...')
    });
    if (window.JyavaniQuillTable && typeof window.JyavaniQuillTable.configure === 'function') {
      window.JyavaniQuillTable.configure(quill);
    }
    var codeMirror = window.CodeMirror.fromTextArea(codeElement, {
      mode: 'htmlmixed', lineNumbers: true, styleActiveLine: true, matchBrackets: true,
      autoCloseBrackets: true, autoCloseTags: true, indentUnit: 2, lineWrapping: true,
      viewportMargin: Infinity, theme: 'dracula', foldGutter: true,
      gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter']
    });
    codeMirror.setSize('100%', String(options.codeHeight || '60vh'));

    function mountAssertActive() {
      if (!destroyed) return;
      var error = new Error('Editor mount has been destroyed.');
      error.code = 'EDITOR_DESTROYED';
      throw error;
    }

    function restoreMountedImageAttributes(html) {
      var source = document.createElement('template');
      source.innerHTML = String(html || '');
      var available = Array.from(quill.root.querySelectorAll('img'));
      Array.from(source.content.querySelectorAll('img')).forEach(function (original) {
        var src = original.getAttribute('src');
        var target = available.find(function (image) { return image.getAttribute('src') === src; });
        if (!target) return;
        available.splice(available.indexOf(target), 1);
        ['alt', 'title', 'width', 'height', 'data-caption', 'data-media-id', 'data-media-removed'].forEach(function (attribute) {
          if (original.hasAttribute(attribute)) target.setAttribute(attribute, original.getAttribute(attribute));
        });
      });
    }

    function mountEmit(name, detail) {
      if (destroyed) return;
      detail = Object.assign({ editor: mountedHandle, context: Object.assign({}, mountContext), revision: revision }, detail || {});
      var callbacks = mountListeners.get(name);
      if (callbacks) callbacks.forEach(function (callback) {
        try { callback(detail); } catch (error) { console.error('[JyavaniEditor mount:' + name + ']', error); }
      });
      try { root.dispatchEvent(new CustomEvent('jyavani:editor:' + name, { bubbles: true, detail: detail })); } catch (error) {}
    }

    function setQuillHtml(value) {
      mountAssertActive();
      suppress = true;
      try {
        var cleaned = typeof policy.cleanExtraBreaks === 'function' ? policy.cleanExtraBreaks(value) : String(value || '');
        quill.setContents(quill.clipboard.convert(cleaned), 'silent');
        restoreMountedImageAttributes(cleaned);
      } finally {
        suppress = false;
      }
    }

    function setCodeHtml(value) {
      mountAssertActive();
      suppress = true;
      try { codeMirror.setValue(String(value || '')); }
      finally { suppress = false; }
    }

    function updateModeUi() {
      modeInputs.forEach(function (input) { input.checked = input.value === currentMode; });
      quillArea.hidden = currentMode !== 'quill';
      codeArea.hidden = currentMode !== 'codemirror';
      if (hint) hint.hidden = !(currentMode === 'codemirror' && policy.isHtmlComplex(String(canonical.value || '')));
      if (currentMode === 'codemirror') setTimeout(function () { if (!destroyed) codeMirror.refresh(); }, 0);
    }

    function mountedContent() {
      mountAssertActive();
      var value = currentMode === 'codemirror'
        ? codeMirror.getValue()
        : (typeof policy.cleanExtraBreaks === 'function' ? policy.cleanExtraBreaks(quill.root.innerHTML || '') : String(quill.root.innerHTML || ''));
      canonical.value = value;
      return value;
    }

    function mountAssertRevision(expected) {
      mountAssertActive();
      if (expected == null || Number(expected) === revision) return;
      var error = new Error('Editor content changed while the operation was running.');
      error.code = 'EDITOR_REVISION_CONFLICT';
      throw error;
    }

    function mountedSelection() {
      mountAssertActive();
      if (currentMode === 'codemirror') {
        var fromPosition = codeMirror.getCursor('from');
        var toPosition = codeMirror.getCursor('to');
        return {
          mode: currentMode,
          from: codeMirror.indexFromPos(fromPosition),
          to: codeMirror.indexFromPos(toPosition),
          text: codeMirror.getRange(fromPosition, toPosition),
          revision: revision
        };
      }
      var range = quill.getSelection && quill.getSelection();
      if (range) lastQuillSelection = range;
      range = range || lastQuillSelection;
      return range ? {
        mode: currentMode, from: range.index, to: range.index + range.length,
        text: quill.getText(range.index, range.length), revision: revision
      } : null;
    }

    function mountedSetSelection(selection) {
      mountAssertActive();
      if (!selection || selection.mode !== currentMode) return false;
      var from = Math.max(0, Number(selection.from) || 0);
      var to = Math.max(from, Number(selection.to) || from);
      if (currentMode === 'codemirror') {
        codeMirror.setSelection(codeMirror.posFromIndex(from), codeMirror.posFromIndex(to));
      } else {
        quill.setSelection(from, to - from, 'silent');
        lastQuillSelection = { index: from, length: to - from };
      }
      return true;
    }

    function mountedSetMode(mode, modeOptions) {
      modeOptions = modeOptions || {};
      if (mode !== 'quill' && mode !== 'codemirror') return Promise.reject(new Error('Unsupported editor mode.'));
      if (mode === currentMode) return Promise.resolve(mountedHandle);
      var value = mountedContent();
      if (mode === 'quill' && policy.isHtmlComplex(value)) {
        if (modeOptions.stripComplex === true) {
          value = policy.stripComplexHtml(value);
        } else if (typeof options.confirmLossy === 'function') {
          var confirmationRevision = revision;
          return Promise.resolve(options.confirmLossy({ editor: mountedHandle, content: value, context: Object.assign({}, mountContext) }))
            .then(function (confirmed) {
              if (!confirmed) {
                var denied = new Error('Complex HTML cannot be represented safely in Quill.');
                denied.code = 'EDITOR_LOSSY_MODE_CHANGE';
                throw denied;
              }
              mountAssertRevision(confirmationRevision);
              return mountedSetMode(mode, { stripComplex: true });
            });
        } else {
          var error = new Error('Complex HTML cannot be represented safely in Quill.');
          error.code = 'EDITOR_LOSSY_MODE_CHANGE';
          return Promise.reject(error);
        }
      }
      if (mode === 'quill') setQuillHtml(value);
      else setCodeHtml(value);
      canonical.value = value;
      currentMode = mode;
      updateModeUi();
      revision++;
      mountEmit('modechange', { mode: currentMode, content: value });
      return Promise.resolve(mountedHandle);
    }

    function mountedSetContent(value, setOptions) {
      setOptions = setOptions || {};
      mountAssertRevision(setOptions.ifRevision);
      value = String(value == null ? '' : value);
      if (currentMode === 'quill' && policy.isHtmlComplex(value)) {
        var error = new Error('Complex HTML cannot be represented safely in Quill.');
        error.code = 'EDITOR_LOSSY_MODE_CHANGE';
        throw error;
      }
      if (currentMode === 'quill') setQuillHtml(value);
      else setCodeHtml(value);
      canonical.value = value;
      revision++;
      mountEmit('change', { mode: currentMode, content: value, source: sourceName(setOptions.source) });
    }

    function mountedInsert(value, insertOptions) {
      insertOptions = insertOptions || {};
      var selection = insertOptions.range || mountedSelection();
      mountAssertRevision(insertOptions.ifRevision == null && selection ? selection.revision : insertOptions.ifRevision);
      if (selection && selection.mode !== currentMode) {
        var mismatch = new Error('Editor selection belongs to another mode.');
        mismatch.code = 'EDITOR_SELECTION_MODE_MISMATCH';
        throw mismatch;
      }
      selection = selection || { mode: currentMode, from: mountedContent().length, to: mountedContent().length };
      var from = Math.max(0, Number(selection.from) || 0);
      var to = Math.max(from, Number(selection.to) || from);
      suppress = true;
      var end = from;
      try {
        if (currentMode === 'codemirror') {
          value = String(value || '');
          codeMirror.replaceRange(value, codeMirror.posFromIndex(from), codeMirror.posFromIndex(to), '+jyavani-editor');
          end = from + value.length;
        } else {
          var beforeLength = quill.getLength();
          if (to > from) quill.deleteText(from, to - from, 'api');
          if (insertOptions.format === 'text') quill.insertText(from, String(value || ''), 'api');
          else quill.clipboard.dangerouslyPasteHTML(from, String(value || ''), 'api');
          restoreMountedImageAttributes(value);
          end = from + Math.max(0, quill.getLength() - beforeLength + (to - from));
        }
      } finally { suppress = false; }
      revision++;
      var content = mountedContent();
      mountedSetSelection({ mode: currentMode, from: end, to: end });
      mountEmit('change', { mode: currentMode, content: content, source: sourceName(insertOptions.source) });
      return mountedSelection();
    }

    function defaultPickMedia(request) {
      if (typeof window.openMediaSelector !== 'function') return Promise.reject(new Error('Media selector is unavailable.'));
      return window.openMediaSelector({
        url: String(options.mediaUrl || mountContext.adminBasePath || window.ADMIN_PATH || '/dashboard') + (options.mediaUrl ? '' : '/admin/modal_img/index.php?embedded=1'),
        context: request.pickerContext || {}, maxWidth: '980px'
      });
    }

    function defaultPickFile() {
      if (typeof window.openFileSelector !== 'function') return Promise.reject(new Error('File selector is unavailable.'));
      return window.openFileSelector({
        url: String(options.fileUrl || (String(mountContext.adminBasePath || window.ADMIN_PATH || '/dashboard') + '/admin/modal_file/index.php?embedded=1')),
        maxWidth: '980px'
      }).then(function (detail) {
        var file = typeof window.normalizeFile === 'function' ? window.normalizeFile(detail) : detail;
        var html = typeof window.generateFileShortcode === 'function' ? window.generateFileShortcode(file) : '';
        return html ? { html: html } : null;
      });
    }

    function mountToolbarHandlers() {
      var toolbarModule = quill.getModule && quill.getModule('toolbar');
      if (!toolbarModule || typeof toolbarModule.addHandler !== 'function') return;
      toolbarModule.addHandler('image', function () {
        var selection = mountedSelection() || { mode: 'quill', from: Math.max(0, quill.getLength() - 1), to: Math.max(0, quill.getLength() - 1), revision: revision };
        var request = { editor: mountedHandle, context: Object.assign({}, mountContext), pickerContext: Object.assign({}, options.mediaContext || {}), selection: selection };
        request.defaults = { pickMedia: defaultPickMedia, pickFile: defaultPickFile };
        var picker = typeof adapters.pickMedia === 'function' ? adapters.pickMedia : defaultPickMedia;
        Promise.resolve(picker(request)).then(function (detail) {
          if (!detail) return;
          mountAssertRevision(selection.revision);
          var media = detail.media && typeof detail.media === 'object' ? detail.media : detail;
          var url = String(media.protected_url || media.url || '');
          if (!url) return;
          var alt = String(media.alt || media.title || '');
          var image = '<img src="' + mountEscape(url) + '"'
            + (alt ? ' alt="' + mountEscape(alt) + '"' : '')
            + (media.title ? ' title="' + mountEscape(media.title) + '"' : '')
            + (media.caption ? ' data-caption="' + mountEscape(media.caption) + '"' : '')
            + (Number(media.id) > 0 ? ' data-media-id="' + Number(media.id) + '"' : '') + '>';
          mountedInsert(image, { range: selection, ifRevision: selection.revision, source: 'media-picker' });
        }).catch(function (error) { mountEmit('error', { error: error }); });
      });
      toolbarModule.addHandler('video', function () {
        var selection = mountedSelection() || { mode: 'quill', from: Math.max(0, quill.getLength() - 1), to: Math.max(0, quill.getLength() - 1), revision: revision };
        var request = { editor: mountedHandle, context: Object.assign({}, mountContext), pickerContext: Object.assign({}, options.fileContext || {}), selection: selection };
        request.defaults = { pickMedia: defaultPickMedia, pickFile: defaultPickFile };
        var picker = typeof adapters.pickFile === 'function' ? adapters.pickFile : defaultPickFile;
        Promise.resolve(picker(request)).then(function (result) {
          if (!result) return;
          mountAssertRevision(selection.revision);
          var html = typeof result.html === 'string' ? result.html : '';
          if (html) mountedInsert(html, { range: selection, ifRevision: selection.revision, source: 'file-picker' });
        }).catch(function (error) { mountEmit('error', { error: error }); });
      });
    }

    function renderMountActions() {
      if (!actionContainer) return;
      actionContainer.innerHTML = '';
      Array.from(mountActions.values()).sort(function (a, b) { return a.priority - b.priority || a.id.localeCompare(b.id); }).forEach(function (action) {
        var button = document.createElement('button');
        button.type = 'button'; button.className = 'jy-editor-action'; button.textContent = action.label;
        if (action.title) button.title = action.title;
        button.addEventListener('click', function () {
          var state = { editor: mountedHandle, context: Object.assign({}, mountContext), selection: mountedSelection() };
          if (typeof action.enabled === 'function' && action.enabled(state) === false) return;
          Promise.resolve(action.onActivate(state)).catch(function (error) { mountEmit('error', { error: error }); });
        });
        actionContainer.appendChild(button);
      });
      actionContainer.hidden = mountActions.size === 0;
    }

    function handleMountedFormReset(event) {
      setTimeout(function () {
        if (destroyed || event.defaultPrevented) return;
        var value = String(canonical.value || '');
        var selected = modeInputs.find(function (input) { return input.checked; });
        var nextMode = selected && selected.value === 'codemirror' ? 'codemirror' : 'quill';
        if (nextMode === 'quill' && policy.isHtmlComplex(value)) nextMode = 'codemirror';
        setCodeHtml(value);
        if (nextMode === 'quill') setQuillHtml(value);
        else setQuillHtml('');
        var modeChanged = currentMode !== nextMode;
        currentMode = nextMode;
        canonical.value = value;
        revision++;
        updateModeUi();
        mountEmit('change', { mode: currentMode, content: value, source: 'reset' });
        if (modeChanged) mountEmit('modechange', { mode: currentMode, content: value, source: 'reset' });
      }, 0);
    }

    var mountedHandle = {
      context: function () { return Object.assign({}, mountContext); },
      getMode: function () { return currentMode; },
      setMode: mountedSetMode,
      getContent: mountedContent,
      setContent: mountedSetContent,
      getSelection: mountedSelection,
      setSelection: mountedSetSelection,
      insert: mountedInsert,
      sync: mountedContent,
      focus: function () { mountAssertActive(); (currentMode === 'quill' ? quill : codeMirror).focus(); },
      isDirty: function () { return mountedContent() !== savedContent || currentMode !== savedMode; },
      isComplex: function () { return policy.isHtmlComplex(mountedContent()); },
      getRevision: function () { return revision; },
      snapshot: function () { return { schema: 1, content: mountedContent(), mode: currentMode, revision: revision }; },
      markSaved: function (snapshot) {
        mountAssertActive();
        if (!snapshot || snapshot.schema !== 1 || typeof snapshot.content !== 'string') return false;
        savedContent = snapshot.content; savedMode = snapshot.mode === 'codemirror' ? 'codemirror' : 'quill';
        mountEmit('saved', { snapshot: Object.assign({}, snapshot), dirty: mountedHandle.isDirty() });
        return true;
      },
      registerButton: function (action) {
        mountAssertActive();
        if (!action || !/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/.test(String(action.id || '')) || !String(action.label || '').trim() || typeof action.onActivate !== 'function') {
          throw new TypeError('Editor actions require a namespaced id, label, and onActivate callback.');
        }
        mountActions.set(String(action.id), Object.assign({ priority: 10, title: '' }, action)); renderMountActions();
        return function () { mountActions.delete(String(action.id)); if (!destroyed) renderMountActions(); };
      },
      on: function (name, listener) {
        mountAssertActive();
        if (typeof listener !== 'function') throw new TypeError('Editor event listener must be callable.');
        if (!mountListeners.has(name)) mountListeners.set(name, new Set());
        mountListeners.get(name).add(listener);
        return function () {
          var callbacks = mountListeners.get(name);
          if (callbacks) callbacks.delete(listener);
        };
      },
      destroy: function () {
        if (destroyed) return;
        mountedContent();
        modeInputs.forEach(function (input) { input.removeEventListener('change', handleMountedModeChange); });
        if (form) {
          form.removeEventListener('submit', mountedContent, true);
          form.removeEventListener('reset', handleMountedFormReset);
        }
        quill.off('selection-change', handleMountedSelectionChange);
        quill.off('text-change', handleMountedTextChange);
        if (typeof codeMirror.off === 'function') codeMirror.off('change', handleMountedCodeChange);
        if (window.JyavaniQuillTable && typeof window.JyavaniQuillTable.destroy === 'function') {
          window.JyavaniQuillTable.destroy(quill);
        }
        var toolbarModule = quill.getModule && quill.getModule('toolbar');
        if (toolbarModule && toolbarModule.container && root.contains(toolbarModule.container)) toolbarModule.container.remove();
        try { quill.disable(); } catch (error) {}
        try { codeMirror.toTextArea(); } catch (error) {}
        quillElement.innerHTML = '';
        quillElement.classList.remove('ql-container', 'ql-snow');
        if (actionContainer) { actionContainer.innerHTML = ''; actionContainer.hidden = true; }
        destroyed = true; mountedEditors.delete(root); root.removeAttribute('data-editor-mounted');
        mountListeners.clear(); mountActions.clear();
      }
    };

    if (currentMode === 'quill') setQuillHtml(initialContent);
    else {
      setQuillHtml('');
      if (codeMirror.getValue() !== initialContent) setCodeHtml(initialContent);
    }
    suppress = false;
    updateModeUi();
    function handleMountedSelectionChange(range) {
      if (!destroyed && range) lastQuillSelection = range;
    }
    function handleMountedTextChange(delta, oldDelta, source) {
      if (destroyed || suppress || currentMode !== 'quill' || source === 'silent') return;
      revision++; canonical.value = mountedContent();
      mountEmit('change', { mode: currentMode, content: canonical.value, source: source === 'user' ? 'user' : 'api' });
    }
    function handleMountedCodeChange(instance, change) {
      if (destroyed || suppress || currentMode !== 'codemirror') return;
      revision++; canonical.value = mountedContent();
      mountEmit('change', { mode: currentMode, content: canonical.value, source: change && change.origin === '+input' ? 'user' : 'api' });
    }
    function handleMountedModeChange(event) {
      var input = event.currentTarget;
      if (!input.checked || destroyed) return;
      mountedSetMode(input.value).catch(function (error) { updateModeUi(); mountEmit('error', { error: error }); });
    }
    quill.on('selection-change', handleMountedSelectionChange);
    quill.on('text-change', handleMountedTextChange);
    codeMirror.on('change', handleMountedCodeChange);
    mountToolbarHandlers();
    modeInputs.forEach(function (input) {
      input.addEventListener('change', handleMountedModeChange);
    });
    if (form) {
      form.addEventListener('submit', mountedContent, true);
      form.addEventListener('reset', handleMountedFormReset);
    }
    root.setAttribute('data-editor-mounted', '1');
    mountedEditors.set(root, mountedHandle);
    setTimeout(function () { if (!destroyed) mountEmit('ready', { mode: currentMode }); }, 0);
    return mountedHandle;
  }

  function mountedEditor(target) {
    var root = mountElement(target);
    return root ? mountedEditors.get(root) || null : null;
  }

  function setup() {
    var form = resolveForm();
    if (!form || !canonicalField()) return false;
    bindEngines();
    var mode = getMode();
    if ((mode === 'quill' && !quillInstance()) || (mode === 'codemirror' && !codeMirrorInstance())) return false;
    if (announcedMode === null) announcedMode = mode;

    if (!handle) {
      handle = {
        context: context,
        getMode: getMode,
        setMode: setMode,
        getContent: getContent,
        setContent: setContent,
        getSelection: getSelection,
        setSelection: setSelection,
        insert: insert,
        sync: sync,
        isDirty: isDirty,
        focus: focus,
        getRevision: function () { return revision; }
      };
    }
    if (form !== boundForm) {
      boundForm = form;
      form.addEventListener('submit', sync, true);
    }
    form.querySelectorAll('input[name="editor_mode"]').forEach(function (input) {
      if (boundModeInputs.has(input)) return;
      boundModeInputs.add(input);
      input.addEventListener('change', function () {
        setTimeout(function () {
          bindEngines();
          announceModeChange();
        }, 80);
      });
    });
    renderActions();
    while (readyResolvers.length) readyResolvers.shift()(handle);
    if (!readyAnnounced) {
      readyAnnounced = true;
      emit('ready', { editor: handle, context: context() });
    }
    return true;
  }

  function ready() {
    if (handle) return Promise.resolve(handle);
    return new Promise(function (resolve) {
      readyResolvers.push(resolve);
      setup();
    });
  }

  window.JyavaniEditor = {
    version: VERSION,
    capabilities: { mount: true, scopedActions: true, snapshots: true, contextualAdapters: true },
    ready: ready,
    current: function () { return handle; },
    mount: mountEditor,
    get: mountedEditor,
    registerButton: registerButton,
    on: on
  };

  var attempts = 0;
  var discovery = setInterval(function () {
    if (setup()) {
      clearInterval(discovery);
    } else if (++attempts > 200) {
      clearInterval(discovery);
      while (readyResolvers.length) readyResolvers.shift()(null);
    }
  }, 50);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup);
  else setup();
})();
