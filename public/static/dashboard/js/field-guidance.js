(function () {
  'use strict';

  function setHelpOpen(help, open) {
    if (!help) return;
    help.dataset.open = open ? 'true' : 'false';
    const trigger = help.querySelector('.field-help__trigger');
    if (trigger) trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    const tooltip = help.__fieldHelpTooltip || help.querySelector('.field-help__tooltip');
    if (!tooltip) return;
    help.__fieldHelpTooltip = tooltip;
    tooltip.__fieldHelpOwner = help;
    if (!open || !trigger) {
      tooltip.classList.remove('field-help__tooltip--portal');
      tooltip.style.left = '';
      tooltip.style.top = '';
      if (tooltip.parentElement !== help) help.appendChild(tooltip);
      return;
    }

    tooltip.classList.add('field-help__tooltip--portal');
    document.body.appendChild(tooltip);
    tooltip.style.left = '0px';
    tooltip.style.top = '0px';
    const triggerBounds = trigger.getBoundingClientRect();
    const tooltipWidth = tooltip.offsetWidth;
    const tooltipHeight = tooltip.offsetHeight;
    const edge = 12;
    const centeredLeft = triggerBounds.left + (triggerBounds.width / 2) - (tooltipWidth / 2);
    const left = Math.max(edge, Math.min(centeredLeft, window.innerWidth - tooltipWidth - edge));
    const above = triggerBounds.top - tooltipHeight - 8;
    const top = above >= edge ? above : Math.min(window.innerHeight - tooltipHeight - edge, triggerBounds.bottom + 8);
    tooltip.style.left = left + 'px';
    tooltip.style.top = Math.max(edge, top) + 'px';
  }

  function cancelHelpClose(help) {
    if (!help || !help.__fieldHelpCloseTimer) return;
    clearTimeout(help.__fieldHelpCloseTimer);
    delete help.__fieldHelpCloseTimer;
  }

  function scheduleHelpClose(help) {
    if (!help) return;
    cancelHelpClose(help);
    help.__fieldHelpCloseTimer = setTimeout(function () {
      delete help.__fieldHelpCloseTimer;
      const tooltip = help.__fieldHelpTooltip;
      if (!help.contains(document.activeElement) && !help.matches(':hover') && !(tooltip && tooltip.matches(':hover'))) {
        setHelpOpen(help, false);
      }
    }, 80);
  }

  function closeOtherHelp(current) {
    document.querySelectorAll('.field-help[data-open="true"]').forEach(function (help) {
      if (help !== current) setHelpOpen(help, false);
    });
  }

  function labelRequiredEditors() {
    document.querySelectorAll('[data-required-editor-label][id]').forEach(function (label) {
      const form = label.closest('form');
      if (!form) return;
      form.querySelectorAll('#quill-editor .ql-editor, .CodeMirror textarea, #cm-textarea').forEach(function (editor) {
        editor.setAttribute('aria-labelledby', label.id);
        editor.setAttribute('aria-required', 'true');
      });
    });
  }

  document.addEventListener('pointerdown', function (event) {
    const trigger = event.target.closest('.field-help__trigger');
    if (!trigger) return;
    const help = trigger.closest('.field-help');
    trigger.dataset.helpWasOpen = help && help.dataset.open === 'true' ? 'true' : 'false';
  });

  document.addEventListener('mouseover', function (event) {
    const portal = event.target.closest('.field-help__tooltip--portal');
    if (portal && portal.__fieldHelpOwner) {
      cancelHelpClose(portal.__fieldHelpOwner);
      return;
    }
    const help = event.target.closest('.field-help');
    if (!help || (event.relatedTarget && help.contains(event.relatedTarget))) return;
    cancelHelpClose(help);
    closeOtherHelp(help);
    setHelpOpen(help, true);
  });

  document.addEventListener('mouseout', function (event) {
    const portal = event.target.closest('.field-help__tooltip--portal');
    if (portal && portal.__fieldHelpOwner) {
      scheduleHelpClose(portal.__fieldHelpOwner);
      return;
    }
    const help = event.target.closest('.field-help');
    if (!help || (event.relatedTarget && help.contains(event.relatedTarget))) return;
    scheduleHelpClose(help);
  });

  document.addEventListener('click', function (event) {
    const trigger = event.target.closest('.field-help__trigger');
    if (!trigger) {
      closeOtherHelp(null);
      return;
    }
    const help = trigger.closest('.field-help');
    const open = trigger.dataset.helpWasOpen
      ? trigger.dataset.helpWasOpen !== 'true'
      : help && help.dataset.open !== 'true';
    delete trigger.dataset.helpWasOpen;
    closeOtherHelp(help);
    setHelpOpen(help, open);
  });

  document.addEventListener('focusin', function (event) {
    const help = event.target.closest('.field-help');
    if (!help) return;
    closeOtherHelp(help);
    setHelpOpen(help, true);
  });

  document.addEventListener('focusout', function (event) {
    const help = event.target.closest('.field-help');
    if (!help) return;
    setTimeout(function () {
      if (!help.contains(document.activeElement) && !help.matches(':hover')) setHelpOpen(help, false);
    }, 0);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    const help = event.target.closest('.field-help') || document.querySelector('.field-help[data-open="true"]');
    if (!help) return;
    setHelpOpen(help, false);
    event.stopPropagation();
  });

  window.addEventListener('resize', function () {
    document.querySelectorAll('.field-help[data-open="true"]').forEach(function (help) {
      setHelpOpen(help, true);
    });
  });
  window.addEventListener('scroll', function () {
    document.querySelectorAll('.field-help[data-open="true"]').forEach(function (help) {
      setHelpOpen(help, true);
    });
  }, true);

  labelRequiredEditors();
  new MutationObserver(labelRequiredEditors).observe(document.body, { childList: true, subtree: true });
})();
