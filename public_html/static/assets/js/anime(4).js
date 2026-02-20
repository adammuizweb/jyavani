/* /static/assets/js/anime.js
   ==========================================================
   AnimeFX — utility animation JS (ROBUST)
   Fix wave-span: works on existing children letters too
   ========================================================== */

(() => {
  const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const SEL = [
    '.anim',
    '.fade-up', '.fade-down', '.fade-left', '.fade-right',
    '.slide-up', '.slide-down', '.slide-left', '.slide-right',
    '.zoom-in', '.zoom-out',
    '.blur-in',
    '.rotate-in',
    '.flip-x', '.flip-y',
    '.pop',
    '.expand-center-safe',
    '.typewrite',
    '.wave-span',
    '.moving-line',
    '.flip-logo',

    '[data-anim]',
    '[data-anim-trigger]',
    '[data-stagger]',
    '[data-anim-stagger]',
    '.onload'
  ].join(',');

  const FX = {
    debug: false,
    defaultThreshold: 0.2,
    defaultRootMargin: '0px 0px -5% 0px',
  };

  function log(...a){ if (FX.debug) console.log('[AnimeFX]', ...a); }

  function toInt(v, defVal = null){
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : defVal;
  }

  function attr(el, nameNew, nameOld){
    const vNew = el.getAttribute(nameNew);
    if (vNew != null && String(vNew).trim() !== '') return vNew;
    const vOld = nameOld ? el.getAttribute(nameOld) : null;
    if (vOld != null && String(vOld).trim() !== '') return vOld;
    return null;
  }

  function getTrigger(el){
    const t =
      (attr(el, 'data-anim-trigger', 'data-anime-trigger') ||
       attr(el, 'data-trigger', null) ||
       (el.classList.contains('onload') ? 'load' : null) ||
       'scroll');
    return String(t).toLowerCase();
  }

  function getOnce(el){
    const v = attr(el, 'data-anim-once', 'data-once');
    return (v ?? '1') !== '0';
  }

  function getRepeat(el){
    const v = attr(el, 'data-anim-repeat', 'data-repeat');
    return (v ?? '0') === '1';
  }

  function ensureDataAnimClasses(el){
    const def = (el.getAttribute('data-anim') || el.getAttribute('data-anime') || '').trim();
    if (!def) return;
    def.split(/\s+/).forEach(c => el.classList.add(c));
    el.classList.add('anim');
  }

  function applyTimingVars(el){
    const delay = toInt(attr(el, 'data-anim-delay', 'data-delay'));
    const duration = toInt(attr(el, 'data-anim-duration', 'data-duration'));
    const ease = attr(el, 'data-anim-ease', 'data-ease');

    if (delay !== null) el.style.setProperty('--anim-delay', `${delay}ms`);
    if (duration !== null) el.style.setProperty('--anim-duration', `${duration}ms`);
    if (ease) el.style.setProperty('--anim-ease', ease);
  }

  function applyMovingLineVars(el){
    if (!el.classList.contains('moving-line')) return;
    const mlDur = toInt(el.getAttribute('data-ml-duration'));
    const mlDelay = toInt(el.getAttribute('data-ml-delay'));
    const mlEase = el.getAttribute('data-ml-ease');
    if (mlDur !== null) el.style.setProperty('--ml-duration', `${mlDur}ms`);
    if (mlDelay !== null) el.style.setProperty('--ml-delay', `${mlDelay}ms`);
    if (mlEase) el.style.setProperty('--ml-ease', mlEase);
  }

  function prepareTypewrite(el){
    if (!el.classList.contains('typewrite')) return;

    const txt = (el.getAttribute('data-tw-text') || el.textContent || '').trim();
    const stepsAttr = toInt(el.getAttribute('data-tw-steps'));
    const steps = stepsAttr ?? Math.max(12, Math.min(140, txt.length || 36));
    el.style.setProperty('--tw-steps', String(steps));

    const dur = toInt(attr(el, 'data-anim-duration', 'data-duration'));
    if (dur !== null) el.style.setProperty('--tw-duration', `${dur}ms`);

    requestAnimationFrame(() => {
      const w = Math.ceil(el.scrollWidth || 0);
      if (w > 0) el.style.setProperty('--tw-px', String(w));
    });
  }
  
  function applyFlipLogoVars(el){
  if (!el.classList.contains('flip-logo')) return;

  const dur = toInt(el.getAttribute('data-fl-duration')) ?? null;
  const delay = toInt(el.getAttribute('data-fl-delay')) ?? null;
  const ease = (el.getAttribute('data-fl-ease') || '').trim();

  if (dur !== null) el.style.setProperty('--fl-duration', `${dur}ms`);
  if (delay !== null) el.style.setProperty('--fl-delay', `${delay}ms`);
  if (ease) el.style.setProperty('--fl-ease', ease);
}

function prepareFlipLogo(el){
  if (!el.classList.contains('flip-logo')) return;
  if (el.__flipPrepared) return;

  // setelah animasi selesai, matikan animasi agar hover bisa menang
  el.addEventListener('animationend', (ev) => {
    if (ev.animationName === 'fx_flip_logo') {
      el.classList.add('fx-done');
    }
  });

  el.__flipPrepared = true;
}

  /* -------- wave-span (FIXED) -------- */
  function getWaveTarget(el){
    const sel = (el.getAttribute('data-wave-target') || '').trim();
    if (sel) return el.querySelector(sel);

    if (el.children.length === 0) return el; // text-only OK
    return null;
  }

  function splitTextToSpans(target, {spaceClass, letterClass}){
    // only safe if no element children
    if (target.children.length > 0) return false;

const raw = (target.getAttribute('data-wave-text') || target.textContent || '');
const text = String(raw).replace(/^\s+|\s+$/g, '');

    if (!target.getAttribute('aria-label')) target.setAttribute('aria-label', text.trim());

    target.textContent = '';
    const frag = document.createDocumentFragment();

    let idx = 0;
    for (const ch of text) {
      if (/\s/.test(ch)) {
        const sp = document.createElement('span');
        sp.className = spaceClass;
        sp.textContent = '\u00A0';
        frag.appendChild(sp);
        continue;
      }
      const span = document.createElement('span');
      span.className = letterClass;
      span.textContent = ch;
      span.dataset.i = String(idx++);
      frag.appendChild(span);
    }

    target.appendChild(frag);
    return true;
  }

  function markExistingChildrenAsLetters(target){
    // ✅ NEW: if target already contains elements for each letter
    // Example: <span class="letter">J</span> ...
    const kids = Array.from(target.children).filter(n => n && n.nodeType === 1);
    if (!kids.length) return [];

    kids.forEach(node => node.classList.add('ws-letter'));
    return kids;
  }

  function prepareWaveSpan(el){
    if (!el.classList.contains('wave-span')) return;
    if (el.__wavePrepared) return;

    const target = getWaveTarget(el);
    if (!target) {
      log('wave-span skipped (needs text-only or data-wave-target):', el);
      el.__wavePrepared = true;
      return;
    }

    // set vars
    const dur =
      toInt(el.getAttribute('data-wave-duration')) ??
      toInt(attr(el, 'data-anim-duration', 'data-duration')) ??
      null;

    const step = toInt(el.getAttribute('data-wave-step')) ?? 32;
    const ease = (el.getAttribute('data-wave-ease') || '').trim();

    if (dur !== null) el.style.setProperty('--ws-duration', `${dur}ms`);
    el.style.setProperty('--ws-step', `${step}ms`);
    if (ease) el.style.setProperty('--ws-ease', ease);

    const baseDelay = toInt(attr(el, 'data-anim-delay', 'data-delay')) ?? 0;

    // ✅ Case A: target already has children letters => just mark & stagger
    if (target.children.length > 0) {
      const nodes = markExistingChildrenAsLetters(target);
      nodes.forEach((node, i) => {
        node.style.animationDelay = `${baseDelay + (i * step)}ms`;
      });

      el.__wavePrepared = true;
      return;
    }

    // ✅ Case B: text-only => split safely
    const ok = splitTextToSpans(target, { spaceClass: 'ws-space', letterClass: 'ws-letter' });
    if (!ok) {
      el.__wavePrepared = true;
      return;
    }

    const letters = target.querySelectorAll('.ws-letter');
    letters.forEach((sp, i) => {
      sp.style.animationDelay = `${baseDelay + (i * step)}ms`;
    });

    el.__wavePrepared = true;
  }

  function show(el){ el.classList.add('show'); }
  function hide(el){ el.classList.remove('show'); }

  function triggerLoad(el){
    requestAnimationFrame(() => requestAnimationFrame(() => show(el)));
  }

  function setupStagger(container){
    const step = toInt(attr(container, 'data-anim-stagger', 'data-stagger'), 120) ?? 120;
    const childSel = attr(container, 'data-anim-stagger-selector', 'data-stagger-selector') || ':scope > *';
    const inheritAnim = (attr(container, 'data-anim-stagger-children', 'data-anime-children') || '').trim();
    const trigger = getTrigger(container);

    const children = Array.from(container.querySelectorAll(childSel));
    children.forEach((child, i) => {
      if (inheritAnim && !child.getAttribute('data-anim') && !child.getAttribute('data-anime')) {
        child.setAttribute('data-anim', inheritAnim);
      }
      ensureDataAnimClasses(child);
      applyTimingVars(child);
      applyMovingLineVars(child);
      prepareWaveSpan(child);
      prepareTypewrite(child);

      child.style.setProperty('--anim-delay', `${i * step}ms`);
      child.setAttribute('data-anim-trigger', trigger);
    });
    return children;
  }

  let observer = null;

  function ensureObserver(){
    if (observer) return observer;
    if (!('IntersectionObserver' in window)) return null;

    observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        const el = entry.target;
        const once = getOnce(el);
        const repeat = getRepeat(el);

        if (entry.isIntersecting) {
          show(el);
          if (once && !repeat) observer.unobserve(el);
        } else if (repeat) {
          hide(el);
        }
      });
    }, {
      threshold: FX.defaultThreshold,
      root: null,
      rootMargin: FX.defaultRootMargin
    });

    return observer;
  }

  function initElement(el){
    if (el.__fxInited) return;

    ensureDataAnimClasses(el);
    applyTimingVars(el);
    applyMovingLineVars(el);
    applyFlipLogoVars(el);
    prepareFlipLogo(el);
    prepareWaveSpan(el);
    prepareTypewrite(el);

    if (prefersReduced) {
      show(el);
      el.__fxInited = true;
      return;
    }

    const trigger = getTrigger(el);

    if (trigger === 'load') {
      triggerLoad(el);
    } else if (trigger === 'manual') {
      // do nothing
    } else {
      const io = ensureObserver();
      if (!io) show(el);
      else io.observe(el);
    }

    el.__fxInited = true;
  }

  function scan(root = document){
    const all = Array.from(root.querySelectorAll(SEL));

    const stContainers = Array.from(root.querySelectorAll('[data-stagger],[data-anim-stagger]'));
    const stKids = stContainers.flatMap(setupStagger);

    const elements = Array.from(new Set([...all, ...stKids]));
    elements.forEach(initElement);
  }

  window.AnimeFX = {
    config: FX,
    refresh: () => scan(document),
    show: (selectorOrEl) => {
      const el = (typeof selectorOrEl === 'string') ? document.querySelector(selectorOrEl) : selectorOrEl;
      if (el) show(el);
    },
    hide: (selectorOrEl) => {
      const el = (typeof selectorOrEl === 'string') ? document.querySelector(selectorOrEl) : selectorOrEl;
      if (el) hide(el);
    }
  };

  let moTimer = null;
  const mo = new MutationObserver((mutations) => {
    clearTimeout(moTimer);
    moTimer = setTimeout(() => {
      for (const m of mutations) {
        for (const node of m.addedNodes) {
          if (node && node.nodeType === 1) scan(node);
        }
      }
    }, 60);
  });

  function boot(){
    scan(document);
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
