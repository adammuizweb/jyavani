(function () {
  'use strict';

  window.ADIWIRA = window.ADIWIRA || {};
  if (window.ADIWIRA.unsavedGuard) return;

  const ignoredNames = new Set(['csrf_token', 'save_nonce', 'return_to', 'id', 'ajax', 'content']);
  const state = {
    form: null,
    baseline: '',
    forms: [],
    baselines: new WeakMap(),
    bypass: false,
    confirming: false
  };

  function codeMirrorValue() {
    const helper = window.ADIWIRA && window.ADIWIRA.codemirror;
    const instance = helper && typeof helper.getInstance === 'function'
      ? helper.getInstance()
      : null;
    if (instance && typeof instance.getValue === 'function') return instance.getValue();

    const wrapper = document.querySelector('#codemirror-area .CodeMirror, .adam-cm-wrap .CodeMirror');
    if (wrapper && wrapper.CodeMirror && typeof wrapper.CodeMirror.getValue === 'function') {
      return wrapper.CodeMirror.getValue();
    }

    const textarea = document.getElementById('cm-textarea');
    return textarea ? textarea.value : '';
  }

  function quillValue() {
    const editQuill = window.ADIWIRA && window.ADIWIRA.quill;
    if (editQuill && typeof editQuill.getInstance === 'function') {
      const instance = editQuill.getInstance();
      if (instance && instance.root) return instance.root.innerHTML;
    }
    if (editQuill && editQuill.root) return editQuill.root.innerHTML;
    if (window.__adam_quill_instance && window.__adam_quill_instance.root) {
      return window.__adam_quill_instance.root.innerHTML;
    }
    if (window.quill && window.quill.root) return window.quill.root.innerHTML;

    const editor = document.querySelector('#quill-editor .ql-editor, #quill-editor.ql-container .ql-editor');
    if (editor) return editor.innerHTML;

    const input = document.getElementById('content-input');
    const textarea = document.getElementById('content-textarea');
    return input ? input.value : (textarea ? textarea.value : '');
  }

  function editorValue(form) {
    const canonical = form.querySelector('[name="content"]');
    if (!canonical) return '';
    const selectedMode = form.querySelector('input[name="editor_mode"]:checked');
    if (selectedMode && selectedMode.value === 'quill') return quillValue();
    if (selectedMode && selectedMode.value === 'codemirror') return codeMirrorValue();
    if (selectedMode) return String(canonical.value);
    if (document.getElementById('cm-textarea')) return codeMirrorValue();
    return quillValue();
  }

  function controlValues(control) {
    const type = String(control.type || '').toLowerCase();
    if (type === 'checkbox' || type === 'radio') {
      return control.checked ? [String(control.value)] : [];
    }
    if (type === 'select-multiple') {
      return Array.from(control.options)
        .filter(function (option) { return option.selected; })
        .map(function (option) { return String(option.value); });
    }
    if (type === 'file') {
      return Array.from(control.files || []).map(function (file) {
        return [file.name, file.size, file.type, file.lastModified].join(':');
      });
    }
    return [String(control.value)];
  }

  function snapshot(form) {
    if (!form) return '';
    const fields = [];
    Array.from(form.elements || []).forEach(function (control) {
      const name = String(control.name || '');
      const type = String(control.type || '').toLowerCase();
      if (!name || control.disabled || ignoredNames.has(name)
          || type === 'submit' || type === 'button' || type === 'reset' || type === 'image') return;
      controlValues(control).forEach(function (value) {
        fields.push([name, value]);
      });
    });
    return JSON.stringify({ fields: fields, content: editorValue(form) });
  }

  function activate(form) {
    if (!form) return;
    if (state.form && state.form !== form) {
      state.baselines.set(state.form, state.baseline);
    }
    state.form = form;
    state.baseline = state.baselines.has(form) ? state.baselines.get(form) : snapshot(form);
    state.baselines.set(form, state.baseline);
  }

  function register(form) {
    if (!form || !form.matches('form[data-unsaved-guard]')) return;
    if (!state.forms.includes(form)) {
      state.forms.push(form);
      form.addEventListener('submit', handleNativeSubmit);
    }
    activate(form);
  }

  function unregister(form) {
    if (!form) return;
    state.forms = state.forms.filter(function (candidate) { return candidate !== form; });
    state.baselines.delete(form);
    if (state.form !== form) return;
    state.form = null;
    state.baseline = '';
    const previous = state.forms.filter(function (candidate) { return candidate.isConnected; }).pop();
    if (previous) activate(previous);
  }

  function pruneForms() {
    state.forms = state.forms.filter(function (candidate) { return candidate.isConnected; });
    if (!state.form || state.form.isConnected) return;
    state.form = null;
    state.baseline = '';
    const previous = state.forms[state.forms.length - 1];
    if (previous) activate(previous);
  }

  function formIsDirty(form) {
    if (!form || !form.isConnected) return false;
    const baseline = form === state.form ? state.baseline : state.baselines.get(form);
    return typeof baseline === 'string' && snapshot(form) !== baseline;
  }

  function isDirty(form) {
    pruneForms();
    if (form) return formIsDirty(form);
    return state.forms.some(formIsDirty);
  }

  function rebaseSnapshot(snapshotValue, canonicalValues) {
    if (!canonicalValues || typeof snapshotValue !== 'string') return snapshotValue;
    let parsed;
    try { parsed = JSON.parse(snapshotValue); } catch (error) { return snapshotValue; }
    if (!parsed || !Array.isArray(parsed.fields)) return snapshotValue;

    Object.keys(canonicalValues).forEach(function (name) {
      const value = String(canonicalValues[name]);
      let replaced = false;
      parsed.fields = parsed.fields.filter(function (field) {
        if (!Array.isArray(field) || field[0] !== name) return true;
        if (replaced) return false;
        field[1] = value;
        replaced = true;
        return true;
      });
      if (!replaced) parsed.fields.push([name, value]);
    });
    return JSON.stringify(parsed);
  }

  function markSaved(snapshotValue, canonicalValues, form) {
    const target = form || state.form;
    if (!target) return;
    const baseline = typeof snapshotValue === 'string'
      ? rebaseSnapshot(snapshotValue, canonicalValues)
      : snapshot(target);
    state.baselines.set(target, baseline);
    if (target === state.form) state.baseline = baseline;
    state.bypass = false;
  }

  function allowNavigation() {
    state.bypass = true;
  }

  function handleNativeSubmit(event) {
    queueMicrotask(function () {
      if (event.defaultPrevented) return;
      allowNavigation();
      setTimeout(function () { state.bypass = false; }, 1000);
    });
  }

  function confirmDiscard() {
    const labels = window.adiwiraUnsavedI18n || {};
    const options = {
      badgeText: labels.badge || 'Confirmation required',
      title: labels.title || 'Unsaved changes',
      message: labels.message || 'Discard unsaved changes?',
      confirmText: labels.confirm || 'Discard changes',
      cancelText: labels.cancel || 'Keep editing',
      focus: 'cancel'
    };
    if (window.NewNotifConfirm && typeof window.NewNotifConfirm.warning === 'function') {
      return window.NewNotifConfirm.warning(options);
    }
    return Promise.resolve(window.confirm(options.message));
  }

  function confirmDiscardForm(form) {
    if (!isDirty(form)) return Promise.resolve(true);
    if (state.confirming) return Promise.resolve(false);
    state.confirming = true;
    return confirmDiscard().then(function (confirmed) {
      state.confirming = false;
      return confirmed;
    }, function () {
      state.confirming = false;
      return false;
    });
  }

  function currentTabLink(event) {
    if (event.defaultPrevented || event.button !== 0
        || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return null;
    const link = event.target && typeof event.target.closest === 'function'
      ? event.target.closest('a[href]')
      : null;
    if (!link || link.hasAttribute('download') || link.hasAttribute('data-unsaved-guard-ignore')) return null;
    if (String(link.getAttribute('href') || '').startsWith('#')) return null;
    const target = String(link.getAttribute('target') || '').toLowerCase();
    if (target && target !== '_self') return null;

    let url;
    try { url = new URL(link.href, window.location.href); } catch (error) { return null; }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
    if (url.origin === window.location.origin && url.pathname === window.location.pathname
        && url.search === window.location.search && url.hash) return null;
    return url;
  }

  function handleClick(event) {
    if (state.bypass || !isDirty()) return;
    const url = currentTabLink(event);
    if (!url) return;

    event.preventDefault();
    confirmDiscardForm().then(function (confirmed) {
      if (!confirmed) return;
      allowNavigation();
      window.location.assign(url.href);
    });
  }

  function handleBeforeUnload(event) {
    if (state.bypass || !isDirty()) return;
    event.preventDefault();
    event.returnValue = '';
  }

  function mount() {
    document.querySelectorAll('form[data-unsaved-guard]').forEach(register);
    document.addEventListener('click', handleClick);
    window.addEventListener('beforeunload', handleBeforeUnload);
    new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          if (!(node instanceof Element)) return;
          if (node.matches('form[data-unsaved-guard]')) register(node);
          node.querySelectorAll('form[data-unsaved-guard]').forEach(register);
        });
      });
      pruneForms();
    }).observe(document.body, { childList: true, subtree: true });
  }

  window.ADIWIRA.unsavedGuard = {
    isDirty: isDirty,
    capture: function (form) { pruneForms(); return snapshot(form || state.form); },
    markSaved: markSaved,
    allowNavigation: allowNavigation,
    confirmDiscard: confirmDiscard,
    confirmDiscardForm: confirmDiscardForm,
    register: register,
    unregister: unregister,
    snapshot: function (form) { return snapshot(form || state.form); }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    setTimeout(mount, 0);
  }
})();
