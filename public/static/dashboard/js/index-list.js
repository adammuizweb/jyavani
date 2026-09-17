document.addEventListener('DOMContentLoaded', function(){
  // keyboard: tekan Delete pada baris terpilih untuk buka modal (UX kecil)
  document.querySelectorAll('.bulkCheckbox').forEach(cb => {
    cb.addEventListener('keydown', function(e){
      if (e.key === 'Delete') {
        const tr = this.closest('tr');
        const btn = tr && tr.querySelector('.adam-hapus');
        if (btn) btn.click();
      }
    });
  });

  function menuItems(menu){
    return Array.from(menu.querySelectorAll('[role="menuitem"]:not(:disabled)'));
  }

  function menuTrigger(menu){
    return menu.parentElement ? menu.parentElement.querySelector('.bin-actions-toggle') : null;
  }

  function closeBinMenus(except, restoreFocus){
    document.querySelectorAll('.bin-actions-menu').forEach(function(menu){
      if (menu === except || menu.hidden) return;
      menu.hidden = true;
      menu.style.visibility = '';
      const trigger = menuTrigger(menu);
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
      if (restoreFocus && trigger) trigger.focus();
    });
  }

  function positionBinMenu(trigger, menu){
    menu.style.visibility = 'hidden';
    const triggerRect = trigger.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const left = Math.max(12, Math.min(window.innerWidth - menuRect.width - 12, triggerRect.right - menuRect.width));
    const top = triggerRect.bottom + menuRect.height + 12 <= window.innerHeight
      ? triggerRect.bottom + 6
      : Math.max(12, triggerRect.top - menuRect.height - 6);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.visibility = 'visible';
  }

  function openBinMenu(trigger, focusItem){
    const menuId = trigger.getAttribute('aria-controls') || '';
    const menu = menuId ? document.getElementById(menuId) : null;
    if (!menu) return;
    closeBinMenus(menu, false);
    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    positionBinMenu(trigger, menu);
    if (focusItem) menuItems(menu)[0]?.focus();
  }

  document.addEventListener('click', function(event){
    const trigger = event.target.closest('.bin-actions-toggle');
    if (trigger) {
      event.stopPropagation();
      const menu = document.getElementById(trigger.getAttribute('aria-controls') || '');
      if (!menu) return;
      if (menu.hidden) openBinMenu(trigger, true);
      else closeBinMenus(null, false);
      return;
    }
    if (event.target.closest('.bin-actions-menu')) {
      // Keep the invoking item visible so cancelled confirmations can restore focus.
      return;
    }
    closeBinMenus(null, false);
  });

  document.addEventListener('keydown', function(event){
    const trigger = event.target.closest('.bin-actions-toggle');
    if (trigger && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
      event.preventDefault();
      openBinMenu(trigger, true);
      const menu = document.getElementById(trigger.getAttribute('aria-controls') || '');
      const items = menu ? menuItems(menu) : [];
      if (event.key === 'ArrowUp') items[items.length - 1]?.focus();
      return;
    }

    const menu = event.target.closest('.bin-actions-menu');
    if (!menu) return;
    const items = menuItems(menu);
    const current = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      closeBinMenus(null, true);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      items[(current + step + items.length) % items.length]?.focus();
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
    }
  });

  document.addEventListener('focusin', function(event){
    if (event.target.closest('.newnotif-confirm.is-open, [role="dialog"][aria-hidden="false"]')) return;
    if (!event.target.closest('.bin-row-overflow')) closeBinMenus(null, false);
  });
  window.addEventListener('resize', function(){ closeBinMenus(null, false); });
  window.addEventListener('scroll', function(){ closeBinMenus(null, false); }, true);
});
