// /static/assets/js/anime.js
// - Scroll-trigger animasi: IntersectionObserver
// - Onload animasi: data-anime-trigger="load" atau class .onload
// - Delay: data-delay="300" atau class .delay-300 (CSS vars)
// - Durasi: data-duration="900" atau class .dur-800 (CSS vars)
// - Repeat: data-repeat="1" (jika ingin hilang saat keluar viewport)
// - Stagger: data-stagger="120" pada container

(() => {
  const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // selector kompatibel dengan versi awal + tambahan
const ANIM_SELECTOR = [
  '.anim',
  '.fade-up', '.fade-down', '.fade-left', '.fade-right',
  '.slide-up', '.slide-down', '.slide-left', '.slide-right',
  '.zoom-in', '.zoom-out',
  '.blur-in',
  '.rotate-in',
  '.flip-x', '.flip-y',
  '.pop',
  '.expand-center', '.typewrite',     // <-- tambah ini
  '[data-anime]'
].join(',');


  function toInt(v, defVal = null){
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : defVal;
  }

  function applyVars(el){
    // data-delay / data-duration override CSS vars
    const delay = toInt(el.getAttribute('data-delay'));
    const duration = toInt(el.getAttribute('data-duration'));
    const ease = el.getAttribute('data-ease');
    
        // Auto config untuk typewrite: steps & lebar (px)
    if (el.classList.contains('typewrite')) {
      const txt = (el.getAttribute('data-tw-text') || el.textContent || '').trim();
      const stepsAttr = toInt(el.getAttribute('data-tw-steps'));
      const steps = stepsAttr ?? Math.max(12, Math.min(120, txt.length || 36));
      el.style.setProperty('--tw-steps', String(steps));

      // scrollWidth tetap mengembalikan lebar konten meski width=0 (karena overflow)
      const w = Math.ceil(el.scrollWidth || 0);
      if (w > 0) el.style.setProperty('--tw-px', String(w));
    }

    if (delay !== null) el.style.setProperty('--anim-delay', `${delay}ms`);
    if (duration !== null) el.style.setProperty('--anim-duration', `${duration}ms`);
    if (ease) el.style.setProperty('--anim-ease', ease);
  }

  function ensureAnimClass(el){
    // Jika pakai data-anime="fade-up zoom-in" dll, otomatis tambahkan class
    const def = (el.getAttribute('data-anime') || '').trim();
    if (def) {
      def.split(/\s+/).forEach(c => el.classList.add(c));
      // optional: user bisa pakai data-anime tanpa .anim
      el.classList.add('anim');
    } else {
      // jika user hanya pakai .fade-up (versi lama), tetap oke
      // tidak wajib menambah .anim
    }
  }

  function show(el){
    el.classList.add('show');
  }

  function hide(el){
    el.classList.remove('show');
  }

  function triggerLoad(el){
    const d = toInt(el.getAttribute('data-delay'), 0) || 0;
    // biar transisi kebaca: tunggu 2 frame
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        if (d > 0) setTimeout(() => show(el), d);
        else show(el);
      });
    });
  }

  function setupStagger(container){
    const step = toInt(container.getAttribute('data-stagger'), 120) ?? 120;
    const childSel = container.getAttribute('data-stagger-selector') || ':scope > *';
    const children = Array.from(container.querySelectorAll(childSel));

    children.forEach((child, i) => {
      // Inherit anim dari container kalau anak tidak punya
      const inheritAnim = (container.getAttribute('data-anime-children') || '').trim();
      if (inheritAnim && !child.getAttribute('data-anime')) {
        child.setAttribute('data-anime', inheritAnim);
      }

      ensureAnimClass(child);
      applyVars(child);

      // Tambah delay bertingkat via CSS var (tidak merusak class delay-xxx)
      child.style.setProperty('--anim-delay', `${i * step}ms`);

      // Trigger ikut container (default scroll)
      const trigger = (container.getAttribute('data-anime-trigger') || container.getAttribute('data-trigger') || 'scroll').toLowerCase();
      child.setAttribute('data-anime-trigger', trigger);
    });

    return children;
  }

  function init(){
    const all = Array.from(document.querySelectorAll(ANIM_SELECTOR));

    // Reduced motion: tampilkan langsung (CSS sudah cover, tapi ini memastikan tidak “ke-hide”)
    if (prefersReduced) {
      all.forEach(el => {
        ensureAnimClass(el);
        show(el);
      });
      return;
    }

    // Stagger containers: kalau ada data-stagger, proses anaknya
    const staggerContainers = Array.from(document.querySelectorAll('[data-stagger]'));
    const staggerChildren = staggerContainers.flatMap(setupStagger);

    // Gabungkan: elemen biasa + anak stagger
    const elements = Array.from(new Set([...all, ...staggerChildren]));

    // fallback jika IntersectionObserver tidak ada
    const hasIO = 'IntersectionObserver' in window;

    if (!hasIO) {
      elements.forEach(el => {
        ensureAnimClass(el);
        applyVars(el);
        show(el);
      });
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        const el = entry.target;
        const once = (el.getAttribute('data-once') ?? '1') !== '0';
        const repeat = (el.getAttribute('data-repeat') ?? '0') === '1';

        if (entry.isIntersecting) {
          show(el);
          if (once && !repeat) observer.unobserve(el);
        } else if (repeat) {
          hide(el);
        }
      });
    }, {
      threshold: 0.2,
      root: null,
      rootMargin: '0px 0px -5% 0px'
    });

    elements.forEach(el => {
      ensureAnimClass(el);
      applyVars(el);

      // trigger mode
      const trigger = (el.getAttribute('data-anime-trigger') || el.getAttribute('data-trigger') || (el.classList.contains('onload') ? 'load' : 'scroll')).toLowerCase();

      if (trigger === 'load') {
        triggerLoad(el);
      } else {
        observer.observe(el);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
