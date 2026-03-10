// lokasi file /adiwira/theme/adam/js/alerts.js
(function () {
  if (window.__adiwiraAlertsReady) return;
  window.__adiwiraAlertsReady = true;

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function ensureBaseStyles() {
    if (document.getElementById('adiwira-alerts-style')) return;

    const style = document.createElement('style');
    style.id = 'adiwira-alerts-style';
    style.textContent = `
      :root{
        --adiwira-toast-success-bg:#ecfdf3;
        --adiwira-toast-success-bd:#a7f3d0;
        --adiwira-toast-success-tx:#065f46;

        --adiwira-toast-error-bg:#fef2f2;
        --adiwira-toast-error-bd:#fecaca;
        --adiwira-toast-error-tx:#991b1b;

        --adiwira-toast-info-bg:#eff6ff;
        --adiwira-toast-info-bd:#bfdbfe;
        --adiwira-toast-info-tx:#1d4ed8;

        --adiwira-toast-warning-bg:#fff7ed;
        --adiwira-toast-warning-bd:#fdba74;
        --adiwira-toast-warning-tx:#9a3412;

        --adiwira-modal-overlay:rgba(0,0,0,.45);
        --adiwira-modal-surface:#ffffff;
        --adiwira-modal-border:#dbe2ea;
        --adiwira-modal-text:#0f172a;
        --adiwira-modal-muted:#475569;
        --adiwira-modal-shadow:0 18px 48px rgba(0,0,0,.18);

        --adiwira-alert-success-bg:#e8f7ec;
        --adiwira-alert-success-bd:#b6e2c2;
        --adiwira-alert-success-tx:#14532d;

        --adiwira-alert-error-bg:#fef2f2;
        --adiwira-alert-error-bd:#fecaca;
        --adiwira-alert-error-tx:#991b1b;
      }

      html[data-theme="dark"], body.dark, body.theme-dark{
        --adiwira-toast-success-bg:#052e1b;
        --adiwira-toast-success-bd:#166534;
        --adiwira-toast-success-tx:#bbf7d0;

        --adiwira-toast-error-bg:#3b0a0a;
        --adiwira-toast-error-bd:#7f1d1d;
        --adiwira-toast-error-tx:#fecaca;

        --adiwira-toast-info-bg:#0b2545;
        --adiwira-toast-info-bd:#1d4ed8;
        --adiwira-toast-info-tx:#bfdbfe;

        --adiwira-toast-warning-bg:#3b1f0a;
        --adiwira-toast-warning-bd:#9a3412;
        --adiwira-toast-warning-tx:#fed7aa;

        --adiwira-modal-overlay:rgba(0,0,0,.62);
        --adiwira-modal-surface:#0f172a;
        --adiwira-modal-border:#243041;
        --adiwira-modal-text:#e5eefb;
        --adiwira-modal-muted:#93a4bd;
        --adiwira-modal-shadow:0 18px 48px rgba(0,0,0,.38);

        --adiwira-alert-success-bg:#052e1b;
        --adiwira-alert-success-bd:#166534;
        --adiwira-alert-success-tx:#bbf7d0;

        --adiwira-alert-error-bg:#3b0a0a;
        --adiwira-alert-error-bd:#7f1d1d;
        --adiwira-alert-error-tx:#fecaca;
      }

      #adiwira-toast-container{
        position:fixed;
        top:16px;
        right:16px;
        z-index:99999;
        display:flex;
        flex-direction:column;
        gap:10px;
        max-width:360px;
      }

      .adiwira-toast{
        border-radius:12px;
        padding:12px 14px;
        box-shadow:0 10px 24px rgba(0,0,0,.10);
        font-size:14px;
        line-height:1.4;
        opacity:0;
        transform:translateY(-6px);
        transition:opacity .2s ease, transform .2s ease;
        backdrop-filter:blur(8px);
      }
      .adiwira-toast.is-success{
        background:var(--adiwira-toast-success-bg);
        border:1px solid var(--adiwira-toast-success-bd);
        color:var(--adiwira-toast-success-tx);
      }
      .adiwira-toast.is-error{
        background:var(--adiwira-toast-error-bg);
        border:1px solid var(--adiwira-toast-error-bd);
        color:var(--adiwira-toast-error-tx);
      }
      .adiwira-toast.is-info{
        background:var(--adiwira-toast-info-bg);
        border:1px solid var(--adiwira-toast-info-bd);
        color:var(--adiwira-toast-info-tx);
      }
      .adiwira-toast.is-warning{
        background:var(--adiwira-toast-warning-bg);
        border:1px solid var(--adiwira-toast-warning-bd);
        color:var(--adiwira-toast-warning-tx);
      }

      .adam-alert{
        border-radius:10px;
        padding:.85rem 1rem;
        border:1px solid;
      }
      .adam-alert.success{
        background:var(--adiwira-alert-success-bg);
        border-color:var(--adiwira-alert-success-bd);
        color:var(--adiwira-alert-success-tx);
      }
      .adam-alert.error{
        background:var(--adiwira-alert-error-bg);
        border-color:var(--adiwira-alert-error-bd);
        color:var(--adiwira-alert-error-tx);
      }

      #deleteModal{
        position:fixed;
        inset:0;
        display:none;
        align-items:center;
        justify-content:center;
        padding:16px;
        background:var(--adiwira-modal-overlay);
        z-index:3000;
      }

      #deleteModal .adam-modal__panel{
        background:var(--adiwira-modal-surface);
        color:var(--adiwira-modal-text);
        border:1px solid var(--adiwira-modal-border);
        border-radius:14px;
        width:100%;
        max-width:420px;
        box-shadow:var(--adiwira-modal-shadow);
        padding:1.25rem;
      }

      #deleteModal .adam-modal__title{
        margin:0 0 .5rem 0;
        color:var(--adiwira-modal-text);
      }

      #deleteModal .adam-modal__text{
        margin:0;
        color:var(--adiwira-modal-muted);
      }
    `;
    document.head.appendChild(style);
  }

  function ensureToastContainer() {
    let el = document.getElementById('adiwira-toast-container');
    if (el) return el;

    el = document.createElement('div');
    el.id = 'adiwira-toast-container';
    document.body.appendChild(el);
    return el;
  }

  function createToast(message, type, duration, options) {
    const container = ensureToastContainer();
    const toast = document.createElement('div');
    toast.className = 'adiwira-toast is-' + String(type || 'info');

    const row = document.createElement('div');
    row.style.display = 'flex';
    row.style.alignItems = 'flex-start';
    row.style.gap = '10px';

    const text = document.createElement('div');
    text.style.flex = '1';
    text.textContent = String(message || '');
    row.appendChild(text);

    if (options && options.label && typeof options.onClick === 'function') {
      const actionBtn = document.createElement('button');
      actionBtn.type = 'button';
      actionBtn.textContent = String(options.label);
      actionBtn.style.border = '0';
      actionBtn.style.background = 'transparent';
      actionBtn.style.cursor = 'pointer';
      actionBtn.style.fontWeight = '700';
      actionBtn.style.color = 'inherit';
      actionBtn.addEventListener('click', function () {
        try { options.onClick(); } catch (e) {}
        close();
      });
      row.appendChild(actionBtn);
    }

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.textContent = '×';
    closeBtn.setAttribute('aria-label', 'Close');
    closeBtn.style.border = '0';
    closeBtn.style.background = 'transparent';
    closeBtn.style.cursor = 'pointer';
    closeBtn.style.fontSize = '18px';
    closeBtn.style.lineHeight = '1';
    closeBtn.style.color = 'inherit';
    row.appendChild(closeBtn);

    toast.appendChild(row);
    container.appendChild(toast);

    function close() {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-6px)';
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 220);
    }

    closeBtn.addEventListener('click', close);

    requestAnimationFrame(function () {
      toast.style.opacity = '1';
      toast.style.transform = 'translateY(0)';
    });

    let timer = null;
    const ms = Number.isFinite(duration) && duration > 0 ? duration : 4000;
    timer = setTimeout(close, ms);

    toast.addEventListener('mouseenter', function () {
      if (timer) clearTimeout(timer);
    });

    toast.addEventListener('mouseleave', function () {
      timer = setTimeout(close, 1500);
    });

    return { close: close };
  }

  if (typeof window.showToast !== 'function') {
    window.showToast = function (message, type, duration, options) {
      return createToast(message, type || 'info', duration || 4000, options || null);
    };
  }

  window.safeToast = function (message, type, duration, options) {
    return window.showToast(message, type || 'info', duration || 4000, options || null);
  };

  ensureBaseStyles();

  onReady(function () {
    const alerts = document.querySelectorAll('.adam-alert.auto-dismiss');
    alerts.forEach(function (alertEl) {
      if (alertEl.classList.contains('error')) return;

      const dismissMs = parseInt(alertEl.getAttribute('data-dismiss-ms') || '3000', 10);
      const delay = Number.isFinite(dismissMs) && dismissMs > 0 ? dismissMs : 3000;

      setTimeout(function () {
        alertEl.style.transition = 'opacity .3s ease, transform .3s ease';
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-4px)';

        setTimeout(function () {
          if (alertEl.parentNode) alertEl.parentNode.removeChild(alertEl);
        }, 300);
      }, delay);
    });

    const modal = document.getElementById('deleteModal');
    if (!modal) return;

    const panel = modal.querySelector('.adam-modal__panel');
    if (panel && !panel.__adiwiraBound) {
      panel.__adiwiraBound = true;
      panel.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }

    window.closeDeleteModal = function () {
      modal.classList.remove('show');
      modal.style.display = 'none';
      document.documentElement.style.overflow = '';
      document.body.style.overflow = '';
    };

    window.openDeleteModal = function (arg1, arg2) {
      let id = '';
      let label = '';

      if (arg1 && typeof arg1 === 'object' && arg1.nodeType === 1) {
        id = arg1.dataset && arg1.dataset.id ? arg1.dataset.id : '';
        label = (arg1.dataset && (arg1.dataset.title || arg1.dataset.name || arg1.dataset.label)) || '';
      } else {
        id = arg1 != null ? String(arg1) : '';
        label = arg2 != null ? String(arg2) : '';
      }

      if (!id) return;

      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }

      const idInput = document.getElementById('deleteId');
      if (idInput) idInput.value = id;

      const txt = document.getElementById('deleteText');
      if (txt) {
        txt.textContent = label
          ? 'Yakin ingin menghapus user "' + label + '"?'
          : 'Apakah kamu yakin ingin menghapus data ini?';
      }

      modal.classList.add('show');
      modal.style.display = 'flex';
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';

      const confirmBtn = modal.querySelector('button[type="submit"], .adam-btn--danger');
      if (confirmBtn) confirmBtn.focus();
    };

    if (!modal.__adiwiraOverlayBound) {
      modal.__adiwiraOverlayBound = true;
      modal.addEventListener('click', function (e) {
        if (e.target === modal) window.closeDeleteModal();
      });
    }

    if (!document.__adiwiraEscBound) {
      document.__adiwiraEscBound = true;
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('show')) {
          window.closeDeleteModal();
        }
      });
    }
  });
})();