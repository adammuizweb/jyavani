var ADMIN_PATH = window.ADMIN_PATH || '/adiwira';
// ajax_save.js — robust generic save for posts/pages/themes
// - Supports form: post-edit-form, page-edit-form, theme-edit-form
// - Uses window.ADIWIRA_FORM_ID + window.ADIWIRA_SAVE_URL if provided
// - Handles Ctrl+S / Cmd+S
// - Syncs CodeMirror/Quill into canonical textarea (#content-textarea)
// - Updates theme save_nonce (new_save_nonce) when returned by server

(function () {
  'use strict';

  window.ADIWIRA = window.ADIWIRA || {};

  // ---------- helpers ----------
  function log(...args){ try { console.debug('[ajax_save]', ...args); } catch(e){} }
  function warn(...args){ try { console.warn('[ajax_save]', ...args); } catch(e){} }

function showNotif(title, msg, ms, forcedType){
  const type =
    forcedType ||
    (/gagal|error/i.test(String(title || '')) ? 'error' : 'success');

  if (window.NewNotifToast && typeof window.NewNotifToast.show === 'function') {
    return window.NewNotifToast.show({
      type: type,
      title: title || 'Informasi',
      message: msg || '',
      duration: (typeof ms === 'number' && ms > 0) ? ms : 2000
    });
  }

  const u = window.ADIWIRA && window.ADIWIRA.utils;
  if (u && typeof u.showNotif === 'function') return u.showNotif(title, msg, ms || 2000);

  try { window.alert((title ? (title + ': ') : '') + (msg || '')); } catch(e){}
}

  function getCanonicalTextarea() {
    return document.getElementById('content-textarea');
  }

  function detectEditorMode(formEl) {
    const selected = formEl && formEl.querySelector('input[name="editor_mode"]:checked');
    if (selected) return selected.value;
    // Theme editors use a hidden CodeMirror marker instead of mode radios.
    const cmRadio = (formEl && formEl.querySelector('#editor-codemirror')) || document.getElementById('editor-codemirror');
    if (cmRadio && cmRadio.checked) return 'codemirror';
    // if quill area exists, assume quill; else codemirror
    const quillArea = document.getElementById('quill-area') || (formEl && formEl.querySelector('#quill-area'));
    if (quillArea) return 'quill';
    return 'codemirror';
  }

  function getCodeMirrorValueFallback(formEl) {
    // CodeMirror v5 attaches instance to .CodeMirror DOM element
    const wrap = (formEl && formEl.querySelector('.CodeMirror')) || document.querySelector('.CodeMirror');
    if (wrap && wrap.CodeMirror && typeof wrap.CodeMirror.getValue === 'function') {
      return wrap.CodeMirror.getValue();
    }
    const visible = (formEl && formEl.querySelector('#cm-textarea')) || document.getElementById('cm-textarea');
    return visible ? (visible.value || '') : '';
  }

  function getCodeMirrorValue(formEl) {
    // Prefer app API if present
    try {
      const api = window.ADIWIRA && window.ADIWIRA.codemirror;
      if (api && typeof api.getInstance === 'function') {
        const cm = api.getInstance();
        if (cm && typeof cm.getValue === 'function') return cm.getValue();
      }
    } catch(e){}
    return getCodeMirrorValueFallback(formEl);
  }

  function getQuillHTML() {
    try {
      const qApi = window.ADIWIRA && window.ADIWIRA.quill;
      if (qApi && typeof qApi.getInstance === 'function') {
        const q = qApi.getInstance();
        if (q && q.root) {
          const raw = q.root.innerHTML || '';
          if (typeof qApi.cleanExtraBreaks === 'function') return qApi.cleanExtraBreaks(raw);
          return raw;
        }
      }
    } catch(e){}
    // fallback: try #quill-editor innerHTML
    const el = document.getElementById('quill-editor');
    return el ? (el.innerHTML || '') : '';
  }

  function resolveForm() {
    const hintedId = window.ADIWIRA_FORM_ID || (window.ADIWIRA && window.ADIWIRA_FORM_ID) || null;

    const byId = (id) => (id ? document.getElementById(id) : null);

    let f =
      byId(hintedId) ||
      byId('post-edit-form') ||
      byId('page-edit-form') ||
      byId('theme-edit-form');

    if (!f) {
      f = document.querySelector('form#post-edit-form, form#page-edit-form, form#theme-edit-form')
        || document.querySelector('form[id$="edit-form"], form[id*="edit-form"]');
    }
    return f;
  }

  function inferTypeFromForm(formEl) {
    const id = (formEl && formEl.id) ? formEl.id : '';
    if (id.indexOf('theme') !== -1) return 'theme';
    if (id.indexOf('page') !== -1) return 'page';
    return 'post';
  }

  function resolveSaveUrl(formEl) {
    // highest priority: explicitly provided
    const explicit =
      window.ADIWIRA_SAVE_URL ||
      (typeof ADIWIRA_SAVE_URL !== 'undefined' ? ADIWIRA_SAVE_URL : null) ||
      (window.ADIWIRA && window.ADIWIRA.config && window.ADIWIRA.config.themeSaveUrl) || // legacy
      null;

    if (explicit) return explicit;

    // next: form action if set
    const action = formEl ? (formEl.getAttribute('action') || '') : '';
    if (action) return action;

    // fallback by type
    const t = inferTypeFromForm(formEl);
    const base = (window.ADIWIRA_BASE || window.ADIWIRA_BASE === '' ? window.ADIWIRA_BASE : null)
      || (window.ADIWIRA && window.ADIWIRA_BASE ? window.ADIWIRA_BASE : null)
      || ADMIN_PATH;

    if (t === 'theme') return base.replace(/\/$/, '') + '/admin/themes/save.php';
    if (t === 'page')  return base.replace(/\/$/, '') + '/admin/pages/save.php';
    return base.replace(/\/$/, '') + '/admin/posts/save.php';
  }

  async function parseJsonSafe(res) {
    const txt = await res.text();
    let j = null;
    try { j = txt ? JSON.parse(txt) : null; } catch(e) {}
    return { txt, j };
  }

  function updateNonceIfAny(json) {
    // themes/save returns new_save_nonce
    if (json && json.new_save_nonce) {
      const sn = document.getElementById('save_nonce');
      if (sn) sn.value = json.new_save_nonce;
    }
  }

  function updateSlugIfAny(formEl, json, submittedSlug) {
    const newSlug =
      (json && json.post && json.post.slug) ||
      (json && json.page && json.page.slug) ||
      (json && json.theme && json.theme.slug) ||
      null;

    if (!newSlug) return null;

    const slugEl = (formEl && formEl.querySelector('input[name="slug"]')) || document.querySelector('input[name="slug"]');
    if (slugEl && (submittedSlug === undefined || slugEl.value === submittedSlug)) slugEl.value = newSlug;
    return newSlug;
  }

  function updateUpdatedAtIfAny(json) {
    if (json && json.updated_at) {
      const ua = document.getElementById('updated-at');
      if (ua) ua.textContent = json.updated_at;
    }
  }

  // ---------- core save ----------
  let saving = false;

  async function ajaxSave() {
    const formEl = resolveForm();
    if (!formEl) {
      warn('form not found; abort');
      return;
    }
    if (saving) {
      warn('save in progress; ignore');
      return;
    }

    const mode = detectEditorMode(formEl);
    if (mode !== 'quill' && mode !== 'codemirror') {
      formEl.requestSubmit();
      return;
    }

    const saveUrl = resolveSaveUrl(formEl);
    if (!saveUrl) {
      showNotif('Gagal', 'Save URL tidak ditemukan (ADIWIRA_SAVE_URL/action kosong).');
      return;
    }

    const btnSave =
      (formEl.querySelector('#btn-save')) ||
      (formEl.querySelector('[type="submit"]')) ||
      document.getElementById('btn-save');

    // sync editor -> canonical textarea
    const canonical = getCanonicalTextarea();
    try {
      if (canonical) {
        if (mode === 'codemirror') canonical.value = getCodeMirrorValue(formEl);
        else if (mode === 'quill') canonical.value = getQuillHTML();
      }
    } catch (e) {
      warn('sync editor failed', e);
    }

    const fd = new FormData(formEl);
    fd.set('ajax', '1');
    const unsavedGuard = window.ADIWIRA && window.ADIWIRA.unsavedGuard;
    const submittedSnapshot = unsavedGuard && typeof unsavedGuard.capture === 'function'
      ? unsavedGuard.capture()
      : null;
    const slugField = formEl.querySelector('input[name="slug"]');
    const submittedSlug = slugField ? slugField.value : undefined;

    // ensure content present
    if (canonical) fd.set('content', canonical.value);
    else {
      // ensure something is sent (fallback)
      const contentField = formEl.querySelector('textarea[name="content"]');
      if (!contentField) {
        fd.set('content', mode === 'codemirror' ? getCodeMirrorValue(formEl) : getQuillHTML());
      }
    }

    // UI state
    let origHtml = null;
    try {
      if (btnSave) {
        btnSave.disabled = true;
        origHtml = btnSave.innerHTML;
        btnSave.innerHTML = 'Menyimpan...';
      }
    } catch(e){}

    saving = true;

    try {
      const res = await fetch(saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: fd,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });

      const { txt, j } = await parseJsonSafe(res);

      if (!res.ok) {
        const msg = (j && (j.message || j.error)) ||
          (j && j.errors && Array.isArray(j.errors) ? j.errors.join('\n') : null) ||
          txt || ('HTTP ' + res.status);
        showNotif('Gagal', msg, 5000);
        return;
      }

      if (!j) {
        showNotif('Gagal', 'Server mengembalikan non-JSON: ' + (txt || ''), 6000);
        return;
      }

if (j.ok) {
  const canonicalSlug = updateSlugIfAny(formEl, j, submittedSlug);
  updateUpdatedAtIfAny(j);
  updateNonceIfAny(j);

  if (unsavedGuard && typeof unsavedGuard.markSaved === 'function') {
    unsavedGuard.markSaved(submittedSnapshot, canonicalSlug ? { slug: canonicalSlug } : null);
  }

  showNotif('Success', j.message || 'Changes saved successfully.', 2000, 'success');
} else {
  const msg =
    (j.errors && Array.isArray(j.errors)) ? j.errors.join('\n') :
    (j.message || j.error || 'Gagal menyimpan.');
  showNotif('Gagal', msg, 2000, 'error');
}
    } catch (err) {
      console.error('[ajax_save] save error', err);
      showNotif('Error', 'Error saat menyimpan: ' + (err && err.message ? err.message : err), 2000, 'error');
    } finally {
      saving = false;
      if (btnSave) {
        try { btnSave.disabled = false; if (origHtml !== null) btnSave.innerHTML = origHtml; } catch(e){}
      }
    }
  }

  // ---------- attach handlers once ----------
  function attachOnce() {
    const formEl = resolveForm();
    if (!formEl) {
      warn('no form found to attach handlers');
      return;
    }
    if (formEl.__adiwiraSaveBound) return;
    formEl.__adiwiraSaveBound = true;

    formEl.addEventListener('submit', function (e) {
      const mode = detectEditorMode(formEl);
      if (mode !== 'quill' && mode !== 'codemirror') return;
      e.preventDefault();
      e.stopPropagation();
      ajaxSave();
    }, true);

    document.addEventListener('keydown', function (e) {
      const isSave = (e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S');
      if (!isSave) return;
      const formEl = resolveForm();
      const mode = detectEditorMode(formEl);
      if (mode !== 'quill' && mode !== 'codemirror') return;
      e.preventDefault();
      e.stopPropagation();
      ajaxSave();
    }, true);

    log('handlers attached; form=', formEl.id || '(no-id)', 'url=', resolveSaveUrl(formEl));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachOnce);
  } else {
    setTimeout(attachOnce, 0);
  }

  // expose API
  window.ADIWIRA.save = Object.assign(window.ADIWIRA.save || {}, { ajaxSave });

})();
