// lokasi file public/adiwira/static/components/toast/toast.js

(function(){
  'use strict';

  let root = null;
  let stack = null;

  function ensureRoot(){
    root = document.getElementById('newnotif-toast-root');
    if (!root) {
      root = document.createElement('div');
      root.id = 'newnotif-toast-root';
      root.setAttribute('aria-live', 'polite');
      root.setAttribute('aria-atomic', 'true');
      document.body.appendChild(root);
    }

    stack = root.querySelector('.newnotif-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'newnotif-toast-stack';
      root.appendChild(stack);
    }
  }

  function escapeHtml(str){
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function runRequestAction(request){
    const body = new URLSearchParams();
    Object.keys(request.body).forEach(function(key){
      body.append(key, String(request.body[key]));
    });

    return window.fetch(request.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
      },
      body: body.toString()
    }).then(function(response){
      return response.json().catch(function(){ return {}; }).then(function(data){
        if (!response.ok || data.ok !== true) {
          const error = new Error(String(data.error || request.errorMessage));
          error.terminal = response.status === 409;
          throw error;
        }
        if (data.reload === true) {
          window.location.reload();
          return false;
        }
        if (data.message) {
          show({ type: data.type || 'success', title: data.title, message: data.message });
        }
        return true;
      });
    }).catch(function(error){
      if (error instanceof Error && error.message && error.message !== 'Failed to fetch') throw error;
      throw new Error(request.errorMessage);
    });
  }

  function normalize(input, type, duration){
    let opts = {};

    if (typeof input === 'string') {
      opts.message = input;
      if (type) opts.type = type;
      if (duration != null) opts.duration = duration;
    } else {
      opts = Object.assign({}, input || {});
    }

    const finalType = ['success', 'warning', 'info', 'error'].includes(String(opts.type || '').toLowerCase())
      ? String(opts.type).toLowerCase()
      : 'info';

    const defaults = {
      success: { title: 'Berhasil', duration: 2000 },
      warning: { title: 'Perhatian', duration: 2000 },
      info:    { title: 'Informasi', duration: 2000 },
      error:   { title: 'Terjadi masalah', duration: 2000 }
    };
    const rawAction = opts.action;
    const rawRequest = rawAction && typeof rawAction.request === 'object' ? rawAction.request : null;
    const request = rawRequest
      && String(rawRequest.url || '').trim()
      && String(rawRequest.errorMessage || '').trim()
      ? {
          url: String(rawRequest.url).trim(),
          body: rawRequest.body && typeof rawRequest.body === 'object' ? rawRequest.body : {},
          errorMessage: String(rawRequest.errorMessage).trim()
        }
      : null;
    const handler = rawAction && typeof rawAction.onClick === 'function'
      ? rawAction.onClick
      : (request ? function(){ return runRequestAction(request); } : null);
    const action = rawAction && typeof rawAction === 'object'
      && String(rawAction.label || '').trim()
      && handler
      ? {
          label: String(rawAction.label).trim(),
          onClick: handler,
          dismiss: rawAction.dismiss !== false
        }
      : null;
    const rawDuration = opts.duration;
    let finalDuration = action ? 0 : defaults[finalType].duration;

    if (
      rawDuration !== null &&
      rawDuration !== undefined &&
      rawDuration !== '' &&
      Number.isFinite(Number(rawDuration)) &&
      Number(rawDuration) >= 0
    ) {
      finalDuration = Number(rawDuration);
    }

    return {
      type: finalType,
      title: String(opts.title || defaults[finalType].title),
      message: String(opts.message || opts.text || ''),
      duration: finalDuration,
      action: action
    };
  }

  function icon(type){
    if (type === 'success') {
      return '<svg class="lucide lucide-circle-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>';
    }
    if (type === 'warning') {
      return '<svg class="lucide lucide-alert-triangle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
    }
    if (type === 'error') {
      return '<svg class="lucide lucide-circle-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
    }
    return '<svg class="lucide lucide-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
  }

  function undoIcon(){
    return '<svg class="lucide lucide-undo-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5 5.5 5.5 0 0 1-5.5 5.5H11"/></svg>';
  }

  function removeToast(el){
    if (!el || el.dataset.leaving === '1') return;
    el.dataset.leaving = '1';
    el.classList.add('is-leaving');
    setTimeout(function(){
      if (el.parentNode) {
        el.parentNode.removeChild(el);
      }
    }, 180);
  }

  function show(input, type, duration){
    ensureRoot();

    const opts = normalize(input, type, duration);
    if (!opts.message) return null;

    const toast = document.createElement('section');
    toast.className = 'newnotif-toast is-' + opts.type;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', opts.type === 'error' ? 'assertive' : 'polite');

    const leading = opts.action
      ? '<button type="button" class="newnotif-toast__action">' + undoIcon() + '<span>' + escapeHtml(opts.action.label) + '</span></button>'
      : '<div class="newnotif-toast__icon">' + icon(opts.type) + '</div>';

    toast.innerHTML = [
      '<div class="newnotif-toast__inner">',
      '  ' + leading,
      '  <div class="newnotif-toast__content">',
      '    <div class="newnotif-toast__title">' + escapeHtml(opts.title) + '</div>',
      '    <p class="newnotif-toast__message">' + escapeHtml(opts.message) + '</p>',
      '  </div>',
      '  <button type="button" class="newnotif-toast__close" aria-label="Tutup notifikasi"><svg class="lucide lucide-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>',
      '</div>',
      '  <div class="newnotif-toast__progress"><span style="animation-duration:' + Math.max(0, opts.duration) + 'ms"></span></div>'
    ].join('');

    const closeBtn = toast.querySelector('.newnotif-toast__close');
    closeBtn.addEventListener('click', function(){
      removeToast(toast);
    });

    let timeoutId = null;
    let timerStartedAt = 0;
    let remaining = opts.duration;
    let pointerPaused = false;
    let focusPaused = false;
    let actionPending = false;

    function pauseTimer(){
      if (timeoutId !== null) {
        window.clearTimeout(timeoutId);
        timeoutId = null;
        remaining = Math.max(0, remaining - (Date.now() - timerStartedAt));
      }
      toast.classList.add('is-paused');
    }

    function startTimer(){
      if (opts.duration <= 0 || actionPending || pointerPaused || focusPaused || toast.dataset.leaving === '1') return;
      toast.classList.remove('is-paused');
      if (remaining <= 0) {
        removeToast(toast);
        return;
      }
      timerStartedAt = Date.now();
      timeoutId = window.setTimeout(function(){
        timeoutId = null;
        remaining = 0;
        removeToast(toast);
      }, remaining);
    }

    toast.addEventListener('mouseenter', function(){
      pointerPaused = true;
      pauseTimer();
    });
    toast.addEventListener('mouseleave', function(){
      pointerPaused = false;
      startTimer();
    });
    toast.addEventListener('focusin', function(){
      focusPaused = true;
      pauseTimer();
    });
    toast.addEventListener('focusout', function(){
      window.setTimeout(function(){
        focusPaused = toast.contains(document.activeElement);
        if (!focusPaused) startTimer();
      }, 0);
    });

    const actionBtn = toast.querySelector('.newnotif-toast__action');
    if (actionBtn && opts.action) {
      actionBtn.addEventListener('click', function(){
        if (actionPending) return;
        actionPending = true;
        actionBtn.disabled = true;
        actionBtn.setAttribute('aria-busy', 'true');
        toast.classList.add('is-action-pending');
        pauseTimer();

        let result;
        try {
          result = opts.action.onClick({
            toast: toast,
            dismiss: function(){ removeToast(toast); }
          });
        } catch (error) {
          actionFailed(error);
          return;
        }

        Promise.resolve(result).then(function(value){
          if (opts.action.dismiss && value !== false) removeToast(toast);
          if (!opts.action.dismiss || value === false) actionFailed();
        }, actionFailed);
      });
    }

    function actionFailed(error){
      actionPending = false;
      const terminal = Boolean(error && error.terminal);
      if (actionBtn && !terminal) {
        actionBtn.disabled = false;
        actionBtn.removeAttribute('aria-busy');
      }
      toast.classList.remove('is-action-pending');
      if (terminal) {
        removeToast(toast);
      } else {
        startTimer();
      }
      if (error !== undefined) {
        toast.dispatchEvent(new CustomEvent('newnotif:toast-action-error', { detail: error }));
        show({ type: 'error', message: String(error && error.message ? error.message : error) });
      }
    }

    stack.appendChild(toast);

    requestAnimationFrame(function(){
      toast.classList.add('is-visible');
    });

    if (opts.duration > 0) {
      startTimer();
    } else {
      const progress = toast.querySelector('.newnotif-toast__progress');
      if (progress) progress.style.display = 'none';
    }

    return toast;
  }

  function boot(items){
    if (!Array.isArray(items) || !items.length) return;
    items.forEach(function(item){ show(item); });
  }

  function flushBootstrap(){
    const store = window.__NEWNOTIF_BOOTSTRAP__;
    if (!store || !Array.isArray(store.toast) || !store.toast.length) return;

    const items = store.toast.splice(0, store.toast.length);
    boot(items);
  }

  document.addEventListener('DOMContentLoaded', flushBootstrap);
  document.addEventListener('newnotif:toast-boot', flushBootstrap);

  window.NewNotifToast = {
    show: show,
    boot: boot,
    success: function(input, duration){ return show(typeof input === 'string' ? { message: input, type: 'success', duration: duration } : Object.assign({}, input || {}, { type: 'success' })); },
    warning: function(input, duration){ return show(typeof input === 'string' ? { message: input, type: 'warning', duration: duration } : Object.assign({}, input || {}, { type: 'warning' })); },
    info: function(input, duration){ return show(typeof input === 'string' ? { message: input, type: 'info', duration: duration } : Object.assign({}, input || {}, { type: 'info' })); },
    error: function(input, duration){ return show(typeof input === 'string' ? { message: input, type: 'error', duration: duration } : Object.assign({}, input || {}, { type: 'error' })); }
  };
})();
