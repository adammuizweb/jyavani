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

const rawDuration = opts.duration;

let finalDuration = defaults[finalType].duration;
if (
  rawDuration !== null &&
  rawDuration !== undefined &&
  rawDuration !== '' &&
  Number.isFinite(Number(rawDuration)) &&
  Number(rawDuration) > 0
) {
  finalDuration = Number(rawDuration);
}

return {
  type: finalType,
  title: String(opts.title || defaults[finalType].title),
  message: String(opts.message || opts.text || ''),
  duration: finalDuration
};
  }

  function icon(type){
    if (type === 'success') {
      return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if (type === 'warning') {
      return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 9v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 17h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10.29 3.86L1.82 18A2 2 0 0 0 3.53 21h16.94a2 2 0 0 0 1.71-3l-8.47-14.14a2 2 0 0 0-3.42 0z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    }
    if (type === 'error') {
      return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"/><path d="M12 10v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 7h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
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

    toast.innerHTML = [
      '<div class="newnotif-toast__inner">',
      '  <div class="newnotif-toast__icon">' + icon(opts.type) + '</div>',
      '  <div class="newnotif-toast__content">',
      '    <div class="newnotif-toast__title">' + escapeHtml(opts.title) + '</div>',
      '    <p class="newnotif-toast__message">' + escapeHtml(opts.message) + '</p>',
      '  </div>',
      '  <button type="button" class="newnotif-toast__close" aria-label="Tutup notifikasi">✕</button>',
      '</div>',
      '  <div class="newnotif-toast__progress"><span style="animation-duration:' + Math.max(0, opts.duration) + 'ms"></span></div>'
    ].join('');

    const closeBtn = toast.querySelector('.newnotif-toast__close');
    closeBtn.addEventListener('click', function(){
      removeToast(toast);
    });

    stack.appendChild(toast);

    requestAnimationFrame(function(){
      toast.classList.add('is-visible');
    });

    if (opts.duration > 0) {
      window.setTimeout(function(){
        removeToast(toast);
      }, opts.duration);
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