(function(){
  const acc = document.getElementById('theme-meta-accordion');
  if (!acc) return;

  const btn  = acc.querySelector('.adam-accordion-toggle');
  const body = acc.querySelector('.adam-accordion-body');

  // key unik per browser (bisa ditambah user_id kalau mau)
  const STORAGE_KEY = 'adiwira.theme.meta.open';

  function setState(isOpen, save = true) {
    acc.dataset.open = isOpen ? '1' : '0';
    body.hidden = !isOpen;
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    if (save) {
      try {
        localStorage.setItem(STORAGE_KEY, isOpen ? '1' : '0');
      } catch(e){}
    }
  }

  // === INIT ===
  let saved = null;
  try {
    saved = localStorage.getItem(STORAGE_KEY);
  } catch(e){}

  if (saved === '0') {
    setState(false, false);
  } else {
    // default OPEN
    setState(true, false);
  }

  // === TOGGLE ===
  btn.addEventListener('click', () => {
    const isOpen = acc.dataset.open === '1';
    setState(!isOpen);
  });

})();