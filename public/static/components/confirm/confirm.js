(function(){
  'use strict';

  const state = {
    root: null,
    overlay: null,
    badge: null,
    title: null,
    message: null,
    cancelBtn: null,
    confirmBtn: null,
    resolver: null,
    lastFocus: null,
    prevHtmlOverflow: '',
    prevBodyOverflow: ''
  };

  function ensureRoot(){
    if (state.overlay) return;

    state.root = document.getElementById('newnotif-confirm-root');
    if (!state.root) {
      state.root = document.createElement('div');
      state.root.id = 'newnotif-confirm-root';
      document.body.appendChild(state.root);
    }

    state.root.innerHTML = '';

    const overlay = document.createElement('div');
    overlay.className = 'newnotif-confirm';
    overlay.setAttribute('data-variant', 'warning');
    overlay.setAttribute('hidden', 'hidden');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'newnotif-confirm-title');
    overlay.setAttribute('aria-describedby', 'newnotif-confirm-message');

    overlay.innerHTML = [
      '<div class="newnotif-confirm__panel" role="document">',
      '  <div class="newnotif-confirm__body">',
      '    <div class="newnotif-confirm__badge" id="newnotif-confirm-badge">Konfirmasi</div>',
      '    <h3 class="newnotif-confirm__title" id="newnotif-confirm-title">Konfirmasi</h3>',
      '    <p class="newnotif-confirm__message" id="newnotif-confirm-message">Lanjutkan aksi ini?</p>',
      '    <div class="newnotif-confirm__actions">',
      '      <button type="button" class="newnotif-btn newnotif-btn--ghost" data-role="cancel">Batal</button>',
      '      <button type="button" class="newnotif-btn newnotif-btn--confirm" data-role="confirm">Lanjutkan</button>',
      '    </div>',
      '  </div>',
      '</div>'
    ].join('');

    state.root.appendChild(overlay);

    state.overlay = overlay;
    state.badge = overlay.querySelector('#newnotif-confirm-badge');
    state.title = overlay.querySelector('#newnotif-confirm-title');
    state.message = overlay.querySelector('#newnotif-confirm-message');
    state.cancelBtn = overlay.querySelector('[data-role="cancel"]');
    state.confirmBtn = overlay.querySelector('[data-role="confirm"]');

    overlay.addEventListener('click', function(ev){
      if (ev.target === overlay) {
        close(false);
      }
    });

    state.cancelBtn.addEventListener('click', function(){
      close(false);
    });

    state.confirmBtn.addEventListener('click', function(){
      close(true);
    });
  }

  function getFocusable(){
    if (!state.overlay) return [];
    return Array.from(state.overlay.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    )).filter(function(el){
      return !el.hasAttribute('disabled') && !el.getAttribute('aria-hidden');
    });
  }

  function handleKeydown(ev){
    if (!state.overlay || !state.overlay.classList.contains('is-open')) return;

    if (ev.key === 'Escape') {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      close(false);
      return;
    }

    if (ev.key !== 'Tab') return;

    const focusable = getFocusable();
    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    if (ev.shiftKey && active === first) {
      ev.preventDefault();
      last.focus();
      return;
    }

    if (!ev.shiftKey && active === last) {
      ev.preventDefault();
      first.focus();
    }
  }

  function open(opts){
    ensureRoot();

    opts = opts || {};
    const variant = (opts.variant === 'danger') ? 'danger' : 'warning';

    state.lastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    state.prevHtmlOverflow = document.documentElement.style.overflow || '';
    state.prevBodyOverflow = document.body.style.overflow || '';

    state.overlay.setAttribute('data-variant', variant);
    state.overlay.removeAttribute('hidden');
    state.overlay.classList.add('is-open');

    state.badge.textContent = String(opts.badgeText || (variant === 'danger' ? 'Aksi berbahaya' : 'Perlu konfirmasi'));
    state.title.textContent = String(opts.title || (variant === 'danger' ? 'Konfirmasi Hapus' : 'Konfirmasi Perubahan'));

    if (opts.html) {
      state.message.innerHTML = String(opts.html);
    } else {
      state.message.textContent = String(opts.message || 'Lanjutkan aksi ini?');
    }

    state.cancelBtn.textContent = String(opts.cancelText || 'Batal');
    state.confirmBtn.textContent = String(opts.confirmText || (variant === 'danger' ? 'Hapus' : 'Lanjutkan'));

    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';

    document.addEventListener('keydown', handleKeydown, true);

    setTimeout(function(){
      const target = (opts.focus === 'cancel') ? state.cancelBtn : state.confirmBtn;
      if (target && typeof target.focus === 'function') {
        target.focus();
      }
    }, 0);

    return new Promise(function(resolve){
      state.resolver = resolve;
    });
  }

  function close(result){
    if (!state.overlay) return;

    state.overlay.classList.remove('is-open');
    state.overlay.setAttribute('hidden', 'hidden');
    document.documentElement.style.overflow = state.prevHtmlOverflow;
    document.body.style.overflow = state.prevBodyOverflow;
    document.removeEventListener('keydown', handleKeydown, true);

    const resolve = state.resolver;
    state.resolver = null;

    if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
      try { state.lastFocus.focus(); } catch (e) {}
    }

    if (typeof resolve === 'function') {
      resolve(Boolean(result));
    }
  }

  window.NewNotifConfirm = {
    open: open,
    warning: function(opts){
      return open(Object.assign({}, opts || {}, { variant: 'warning' }));
    },
    danger: function(opts){
      return open(Object.assign({}, opts || {}, { variant: 'danger' }));
    }
  };
})();
