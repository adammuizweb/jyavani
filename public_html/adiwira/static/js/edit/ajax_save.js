// ajax_save.js (generic, works for posts AND pages)
// Expects window.ADIWIRA_FORM_ID and window.ADIWIRA_SAVE_URL to be set by the page templates.
// Falls back to sensible defaults if not present.

(function () {
  'use strict';
  window.ADIWIRA = window.ADIWIRA || {};

  // Resolve save URL (prefer window.ADIWIRA_SAVE_URL, then global ADIWIRA_SAVE_URL, else fallback)
  const saveUrl = (window.ADIWIRA_SAVE_URL || (typeof ADIWIRA_SAVE_URL !== 'undefined' ? ADIWIRA_SAVE_URL : null))
    || (window.location.origin + '/adiwira/admin/posts/save.php');

  // Resolve form id: prefer ADIWIRA_FORM_ID, otherwise try common ids
  const formId = (window.ADIWIRA && window.ADIWIRA_FORM_ID) || 'post-edit-form' || 'page-edit-form';
  const form = document.getElementById(formId) || document.getElementById('post-edit-form') || document.getElementById('page-edit-form');

  // If form not found, try to find any form with id containing 'edit-form'
  let resolvedForm = form;
  if (!resolvedForm) {
    resolvedForm = document.querySelector('form[id$="edit-form"], form[id*="edit-form"]');
  }

  const btnSave = resolvedForm ? (resolvedForm.querySelector('#btn-save') || document.getElementById('btn-save')) : document.getElementById('btn-save');

  // utility to get canonical textarea
  function getCanonicalTextarea() {
    return document.getElementById('content-textarea');
  }

  // determine editor mode (look inside the form first)
  function detectEditorMode() {
    const editorCMRadio = (resolvedForm && resolvedForm.querySelector('#editor-codemirror')) || document.getElementById('editor-codemirror');
    return (editorCMRadio && editorCMRadio.checked) ? 'codemirror' : 'quill';
  }

  async function ajaxSave() {
    if (!resolvedForm) {
      console.warn('[ajax_save] form not found; aborting save');
      return;
    }

    const canonicalTextarea = getCanonicalTextarea();
    if (!canonicalTextarea) {
      console.warn('[ajax_save] canonical textarea (#content-textarea) not found; continuing (form data may include content field)');
    }

    const mode = detectEditorMode();

    // Extract editor content into canonical textarea if present
    try {
      if (canonicalTextarea) {
        if (mode === 'codemirror' && window.ADIWIRA && window.ADIWIRA.codemirror && typeof window.ADIWIRA.codemirror.getInstance === 'function') {
          const cm = window.ADIWIRA.codemirror.getInstance();
          if (cm && typeof cm.getValue === 'function') canonicalTextarea.value = cm.getValue();
        } else if (window.ADIWIRA && window.ADIWIRA.quill && typeof window.ADIWIRA.quill.getInstance === 'function') {
          const quill = window.ADIWIRA.quill.getInstance();
          if (quill && quill.root) canonicalTextarea.value = quill.root.innerHTML;
        } else {
          // fallback: if canonical already has value, leave it; else try to read visible textarea
          if ((!canonicalTextarea.value || canonicalTextarea.value.trim() === '')) {
            const visibleTextarea = resolvedForm.querySelector('textarea[name="content"], #cm-textarea');
            if (visibleTextarea && visibleTextarea.value) canonicalTextarea.value = visibleTextarea.value;
          }
        }
      }
    } catch (err) {
      console.warn('[ajax_save] error extracting editor content', err);
    }

    const fd = new FormData(resolvedForm);
    // ensure ajax flag for server-side handling
    fd.set('ajax', '1');

    // ensure content field in FormData is canonical value (if canonical exists)
    if (canonicalTextarea) {
      fd.set('content', canonicalTextarea.value);
    }

    // UI: disable save button and show saving state
    let origHtml = null;
    try {
      if (btnSave) {
        btnSave.disabled = true;
        origHtml = btnSave.innerHTML;
        btnSave.innerHTML = 'Menyimpan...';
      }
    } catch (err) { /* ignore */ }

    // do fetch
    try {
      const res = await fetch(saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      });

      // handle non-JSON responses gracefully
      let json;
      try {
        json = await res.json();
      } catch (e) {
        // Not JSON — fallback
        const txt = await res.text();
        throw new Error('Server returned non-JSON response: ' + txt);
      }

      // success indicator
      if (json && json.ok) {
        // if server returned a post/page with slug, update slug input
        if (json.post && json.post.slug) {
          const slugEl = resolvedForm.querySelector('input[name="slug"]') || document.querySelector('input[name="slug"]');
          if (slugEl) slugEl.value = json.post.slug;
        }

        // show notification via app util if available; else alert
        if (window.ADIWIRA && window.ADIWIRA.utils && typeof window.ADIWIRA.utils.showNotif === 'function') {
          window.ADIWIRA.utils.showNotif('Berhasil', json.message || 'Perubahan berhasil disimpan.');
        } else {
          try { window.alert(json.message || 'Perubahan berhasil disimpan.'); } catch (e) {}
        }
      } else {
        const message = (json && (json.message || (json.errors || []).join ? (json.errors || []).join("\n") : json.errors)) || 'Gagal menyimpan.';
        if (window.ADIWIRA && window.ADIWIRA.utils && typeof window.ADIWIRA.utils.showNotif === 'function') {
          window.ADIWIRA.utils.showNotif('Gagal', message, 4000);
        } else {
          try { window.alert(message); } catch (e) {}
        }
      }
    } catch (err) {
      console.error('[ajax_save] save error', err);
      try {
        window.alert('Error saat menyimpan: ' + (err && err.message ? err.message : err));
      } catch (e) {}
    } finally {
      if (btnSave) {
        try { btnSave.disabled = false; if (origHtml !== null) btnSave.innerHTML = origHtml; } catch (e) {}
      }
    }
  } // end ajaxSave

  // Attach submit handler to resolvedForm
  if (resolvedForm) {
    resolvedForm.addEventListener('submit', function (e) {
      e.preventDefault();
      ajaxSave();
    });
  } else {
    console.warn('[ajax_save] no form found to attach submit handler to');
  }

  // expose API
  window.ADIWIRA.save = Object.assign(window.ADIWIRA.save || {}, {
    ajaxSave: ajaxSave
  });

})();
