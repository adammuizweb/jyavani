/* /adiwira/static/js/muiz-notify.js */
(function(){
  'use strict';

  // ---------------------------
  // MUÍZ TOAST
  // ---------------------------
  const MU = window.muizNotify = window.muizNotify || {};

  let stackEl = null;
  let seq = 0;

  function ensureStack(){
    if (stackEl && document.body.contains(stackEl)) return stackEl;

    stackEl = document.createElement('div');
    stackEl.className = 'muiz-toast-stack';
    stackEl.setAttribute('aria-live', 'polite');
    stackEl.setAttribute('aria-relevant', 'additions removals');
    document.body.appendChild(stackEl);
    return stackEl;
  }

  function pickMeta(type){
    const t = String(type || 'info').toLowerCase();
    if (t === 'success') return { cls:'is-success', title:'Sukses', ico:'✓', role:'status' };
    if (t === 'error')   return { cls:'is-error',   title:'Gagal',  ico:'!', role:'alert'  };
    if (t === 'warning') return { cls:'is-warning', title:'Info',   ico:'!', role:'status' };
    return { cls:'is-info', title:'Info', ico:'i', role:'status' };
  }

  function makeBtn(label, onClick){
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'muiz-toast__btn';
    b.textContent = String(label || 'Action');
    b.addEventListener('click', function(ev){
      try { onClick && onClick(ev); } catch(e){}
    });
    return b;
  }

  function dismissToast(toastEl){
    if (!toastEl || !toastEl.parentNode) return;
    toastEl.classList.remove('is-show');
    // remove after transition
    setTimeout(() => {
      try { toastEl.remove(); } catch(e){
        try { toastEl.parentNode && toastEl.parentNode.removeChild(toastEl); } catch(_e){}
      }
    }, 220);
  }

  /**
   * muizNotify.toast(message, type, durationMs, opts)
   * opts:
   *  - title: string
   *  - label: string (action button)
   *  - onClick: fn
   *  - persistent: boolean (no auto-dismiss)
   */
  MU.toast = function(message, type, durationMs, opts){
    opts = opts || {};
    const meta = pickMeta(type);
    const wrap = ensureStack();

    const id = 'muiz_toast_' + (++seq);

    const toast = document.createElement('div');
    toast.className = 'muiz-toast ' + meta.cls;
    toast.id = id;
    toast.setAttribute('role', meta.role);

    // rail
    const rail = document.createElement('div');
    rail.className = 'muiz-toast__rail';

    // icon
    const ico = document.createElement('div');
    ico.className = 'muiz-toast__ico';
    ico.textContent = meta.ico;

    // body
    const body = document.createElement('div');
    body.className = 'muiz-toast__body';

    const title = document.createElement('p');
    title.className = 'muiz-toast__title';
    title.textContent = String(opts.title || meta.title);

    const msg = document.createElement('p');
    msg.className = 'muiz-toast__msg';
    msg.textContent = String(message || '');

    body.appendChild(title);
    body.appendChild(msg);

    // actions
    const actions = document.createElement('div');
    actions.className = 'muiz-toast__actions';

    if (opts.label && typeof opts.onClick === 'function') {
      const act = makeBtn(opts.label, function(ev){
        // optional: auto close after action
        try { opts.onClick(ev); } finally { dismissToast(toast); }
      });
      actions.appendChild(act);
    }

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'muiz-toast__close';
    closeBtn.setAttribute('aria-label', 'Tutup notifikasi');
    closeBtn.textContent = '×';
    closeBtn.addEventListener('click', () => dismissToast(toast));
    actions.appendChild(closeBtn);

    toast.appendChild(rail);
    toast.appendChild(ico);
    toast.appendChild(body);
    toast.appendChild(actions);

    // newest on bottom: append
    wrap.appendChild(toast);

    // animate in
    requestAnimationFrame(() => toast.classList.add('is-show'));

    // auto dismiss
    const ms = Math.max(0, parseInt(durationMs || 3500, 10));
    const persistent = !!opts.persistent || ms === 0;

    let timer = null;
    let paused = false;

    function arm(){
      if (persistent) return;
      clearTimeout(timer);
      timer = setTimeout(() => dismissToast(toast), ms);
    }

    toast.addEventListener('mouseenter', () => { paused = true; clearTimeout(timer); });
    toast.addEventListener('mouseleave', () => { paused = false; arm(); });

    arm();

    return id;
  };

  // Backward compatibility: existing code already calls showToast(...)
  window.showToast = window.showToast || function(message, type, durationMs, opts){
    return MU.toast(message, type, durationMs, opts);
  };

  // ---------------------------
  // MUÍZ ALERT AUTODISMISS
  // (NO prototype override!)
  // ---------------------------
  function setupAdamAlerts(){
    const els = document.querySelectorAll('.adam-alert.auto-dismiss');
    if (!els.length) return;

    els.forEach((el) => {
      // default 3000ms; can override with data-dismiss-ms
      const ms = el.dataset && el.dataset.dismissMs ? parseInt(el.dataset.dismissMs, 10) : 3000;
      if (!Number.isFinite(ms) || ms <= 0) return;

      setTimeout(() => {
        // If user already removed it manually, skip
        if (!el || !el.parentNode) return;

        el.style.transition = 'opacity .28s ease, transform .28s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-4px)';

        setTimeout(() => {
          try { el.remove(); } catch(e){
            try { el.parentNode && el.parentNode.removeChild(el); } catch(_e){}
          }
        }, 320);
      }, ms);
    });

    // delegated close support (.alert-close / .close)
    document.addEventListener('click', function(ev){
      const btn = ev.target && ev.target.closest
        ? ev.target.closest('.adam-alert .alert-close, .adam-alert .close, .alert-close, .close')
        : null;
      if (!btn) return;
      const box = btn.closest('.adam-alert');
      if (!box) return;

      box.style.transition = 'opacity .2s ease, transform .2s ease';
      box.style.opacity = '0';
      box.style.transform = 'translateY(-4px)';
      setTimeout(() => {
        try { box.remove(); } catch(e){
          try { box.parentNode && box.parentNode.removeChild(box); } catch(_e){}
        }
      }, 240);
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupAdamAlerts);
  } else {
    setupAdamAlerts();
  }
})();