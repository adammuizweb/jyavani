(function () {
  'use strict';

  function setHelpOpen(help, open) {
    if (!help) return;
    help.dataset.open = open ? 'true' : 'false';
    const trigger = help.querySelector('.field-help__trigger');
    if (trigger) trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    const tooltip = help.querySelector('.field-help__tooltip');
    if (!open || !trigger || !tooltip) return;
    const triggerBounds = trigger.getBoundingClientRect();
    const tooltipWidth = tooltip.offsetWidth;
    const centeredLeft = triggerBounds.left + (triggerBounds.width / 2) - (tooltipWidth / 2);
    const centeredRight = centeredLeft + tooltipWidth;
    const edge = 12;
    const shift = centeredLeft < edge
      ? edge - centeredLeft
      : (centeredRight > window.innerWidth - edge ? window.innerWidth - edge - centeredRight : 0);
    tooltip.style.setProperty('--field-help-shift', shift + 'px');
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
    const help = event.target.closest('.field-help');
    if (!help || (event.relatedTarget && help.contains(event.relatedTarget))) return;
    closeOtherHelp(help);
    setHelpOpen(help, true);
  });

  document.addEventListener('mouseout', function (event) {
    const help = event.target.closest('.field-help');
    if (!help || (event.relatedTarget && help.contains(event.relatedTarget))) return;
    if (!help.contains(document.activeElement)) setHelpOpen(help, false);
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

  labelRequiredEditors();
  new MutationObserver(labelRequiredEditors).observe(document.body, { childList: true, subtree: true });
})();
