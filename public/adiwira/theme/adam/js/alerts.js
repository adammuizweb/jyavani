(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function ensureToastContainer() {
    let el = document.getElementById('adiwira-toast-container');
    if (el) return el;

    el = document.createElement('div');
    el.id = 'adiwira-toast-container';
    el.style.position = 'fixed';
    el.style.top = '16px';
    el.style.right = '16px';
    el.style.zIndex = '99999';
    el.style.display = 'flex';
    el.style.flexDirection = 'column';
    el.style.gap = '10px';
    el.style.maxWidth = '360px';
    document.body.appendChild(el);
    return el;
  }

  function createToast(message, type, duration, options) {
    const container = ensureToastContainer();
    const toast = document.createElement('div');

    const colors = {
      success: { bg: '#ecfdf3', bd: '#a7f3d0', tx: '#065f46' },
      error:   { bg: '#fef2f2', bd: '#fecaca', tx: '#991b1b' },
      info:    { bg: '#eff6ff', bd: '#bfdbfe', tx: '#1d4ed8' },
      warning: { bg: '#fff7ed', bd: '#fdba74', tx: '#9a3412' }
    };

    const palette = colors[type] || colors.info;

    toast.style.background = palette.bg;
    toast.style.border = '1px solid ' + palette.bd;
    toast.style.color = palette.tx;
    toast.style.borderRadius = '12px';
    toast.style.padding = '12px 14px';
    toast.style.boxShadow = '0 10px 24px rgba(0,0,0,.10)';
    toast.style.fontSize = '14px';
    toast.style.lineHeight = '1.4';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-6px)';
    toast.style.transition = 'opacity .2s ease, transform .2s ease';

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

    const ms = Number.isFinite(duration) && duration > 0 ? duration : 4000;
    const timer = setTimeout(close, ms);

    toast.addEventListener('mouseenter', function () {
      clearTimeout(timer);
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

  onReady(function () {
    const alerts = document.querySelectorAll('.adam-alert');
    alerts.forEach(function (alertEl) {
      if (alertEl.classList.contains('error')) return;

      const dismissMs = parseInt(alertEl.getAttribute('data-dismiss-ms') || '3000', 10);
      const delay = Number.isFinite(dismissMs) && dismissMs > 0 ? dismissMs : 3000;

      setTimeout(function () {
        alertEl.style.transition = 'opacity .3s ease, transform .3s ease';
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-4px)';

        setTimeout(function () {
          if (alertEl.parentNode) {
            alertEl.parentNode.removeChild(alertEl);
          }
        }, 300);
      }, delay);
    });

    const modal = document.getElementById('deleteModal');
    if (!modal) return;

    const panel = modal.querySelector('.adam-modal__panel');
    if (panel) {
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

    window.openDeleteModal = function (btn) {
      if (!btn) return;

      if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
      }

      const idInput = document.getElementById('deleteId');
      if (idInput) idInput.value = btn.dataset.id || '';

      const txt = document.getElementById('deleteText');
      if (txt) {
        const label = btn.dataset.title || btn.dataset.name || btn.dataset.label || '';
        txt.innerText = label ? `Hapus "${label}"?` : 'Apakah kamu yakin ingin menghapus data ini?';
      }

      modal.classList.add('show');
      modal.style.display = 'flex';
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';

      const confirmBtn = modal.querySelector('button[type="submit"], .adam-btn--danger');
      if (confirmBtn) confirmBtn.focus();
    };

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        window.closeDeleteModal();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('show')) {
        window.closeDeleteModal();
      }
    });
  });
})();