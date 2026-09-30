(function () {
  'use strict';

  function menuItems(menu) {
    return Array.from(menu.querySelectorAll('[role="menuitem"]:not(:disabled)'));
  }

  function menuFor(trigger) {
    var id = trigger.getAttribute('aria-controls') || '';
    var menu = id ? document.getElementById(id) : null;
    return menu && menu.classList.contains('adam-actions__menu') ? menu : null;
  }

  function triggerFor(menu) {
    var actions = menu.closest('.adam-actions');
    return actions ? actions.querySelector('.adam-actions__trigger') : null;
  }

  function closeMenu(menu, restoreFocus) {
    if (menu.hidden) return;
    menu.hidden = true;
    menu.style.visibility = '';
    menu.style.left = '';
    menu.style.top = '';
    menu.style.maxHeight = '';
    menu.style.overflowY = '';
    var trigger = triggerFor(menu);
    if (trigger) {
      trigger.setAttribute('aria-expanded', 'false');
      if (restoreFocus) trigger.focus({ preventScroll: true });
    }
  }

  function closeMenus(except, restoreFocus) {
    document.querySelectorAll('.adam-actions__menu').forEach(function (menu) {
      if (menu !== except) closeMenu(menu, restoreFocus && menu.contains(document.activeElement));
    });
  }

  function positionMenu(trigger, menu) {
    menu.style.visibility = 'hidden';
    var triggerRect = trigger.getBoundingClientRect();
    var menuRect = menu.getBoundingClientRect();
    var edge = 12;
    var gap = 6;
    var left = Math.max(edge, Math.min(window.innerWidth - menuRect.width - edge, triggerRect.right - menuRect.width));
    var top = triggerRect.bottom + menuRect.height + edge <= window.innerHeight
      ? triggerRect.bottom + gap
      : Math.max(edge, triggerRect.top - menuRect.height - gap);
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.maxHeight = Math.max(120, window.innerHeight - (edge * 2)) + 'px';
    menu.style.overflowY = 'auto';
    menu.style.visibility = 'visible';
  }

  function openMenu(trigger, focusAt) {
    var menu = menuFor(trigger);
    if (!menu) return;
    closeMenus(menu, false);
    menu.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    positionMenu(trigger, menu);
    var items = menuItems(menu);
    if (focusAt === 'first') items[0]?.focus({ preventScroll: true });
    if (focusAt === 'last') items[items.length - 1]?.focus({ preventScroll: true });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('.adam-actions__trigger');
    if (trigger) {
      event.stopPropagation();
      var menu = menuFor(trigger);
      if (!menu) return;
      if (menu.hidden) openMenu(trigger, 'first');
      else closeMenu(menu, false);
      return;
    }

    var menuItem = event.target.closest('.adam-actions__menu [role="menuitem"]');
    if (menuItem) {
      var itemMenu = menuItem.closest('.adam-actions__menu');
      if (itemMenu) closeMenu(itemMenu, false);
      return;
    }

    if (!event.target.closest('.adam-actions__menu')) closeMenus(null, false);
  });

  document.addEventListener('keydown', function (event) {
    var trigger = event.target.closest('.adam-actions__trigger');
    if (trigger && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
      event.preventDefault();
      openMenu(trigger, event.key === 'ArrowUp' ? 'last' : 'first');
      return;
    }

    var menu = event.target.closest('.adam-actions__menu');
    if (!menu) return;
    var items = menuItems(menu);
    var current = items.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      closeMenu(menu, true);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      var step = event.key === 'ArrowDown' ? 1 : -1;
      items[(current + step + items.length) % items.length]?.focus();
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
    }
  });

  document.addEventListener('focusin', function (event) {
    if (event.target.closest('.newnotif-confirm.is-open, [role="dialog"][aria-hidden="false"]')) return;
    if (!event.target.closest('.adam-actions')) closeMenus(null, false);
  });
  window.addEventListener('resize', function () { closeMenus(null, false); });
  window.addEventListener('scroll', function () { closeMenus(null, false); }, true);
})();
