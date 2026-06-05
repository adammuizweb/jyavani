// Script Navigasi 
  (function(){
    const burger = document.getElementById('adamz-burger');
    const drawer = document.getElementById('adamz-mobile-drawer');
    const backdrop = document.getElementById('adamz-backdrop');
    const closeBtn = document.getElementById('adamz-close');

    function openDrawer(){
      drawer.classList.add('open');
      document.body.style.overflow = 'hidden'; 
    }
    function closeDrawer(){
      drawer.classList.remove('open');
      document.body.style.overflow = '';
    }

    if(burger) burger.addEventListener('click', openDrawer);
    if(closeBtn) closeBtn.addEventListener('click', closeDrawer);
    if(backdrop) backdrop.addEventListener('click', closeDrawer);

    document.querySelectorAll('.adamz-panel .toggle').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const parentLi = btn.parentElement;
        parentLi.classList.toggle('open');
      });
    });

    window.addEventListener('resize', () => {
      if(window.innerWidth > 1024 && drawer.classList.contains('open')){
        closeDrawer();
      }
    });
  })();
  
// END 