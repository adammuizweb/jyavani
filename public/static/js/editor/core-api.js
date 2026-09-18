(function () {
  'use strict';

  if (window.JyavaniEditor) return;

  var VERSION = 1;
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
    ready: ready,
    current: function () { return handle; },
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
