(function () {
  'use strict';

  function rootsFor(scope, key) {
    return Array.from(scope.querySelectorAll('[data-pcat-pagination-root]')).filter(function (root) {
      return root.getAttribute('data-pcat-pagination-root') === key;
    });
  }

  function activateScripts(root) {
    root.querySelectorAll('script').forEach(function (oldScript) {
      var script = document.createElement('script');
      Array.from(oldScript.attributes).forEach(function (attribute) {
        script.setAttribute(attribute.name, attribute.value);
      });
      script.textContent = oldScript.textContent;
      oldScript.replaceWith(script);
    });
  }

  function paginationRootFor(slider) {
    return slider.closest('[data-pcat-pagination-root]');
  }

  function paginationLink(slider, relation) {
    var root = paginationRootFor(slider);
    return root ? root.querySelector('.pcat-pagination a[rel="' + relation + '"]') : null;
  }

  function initCoreSlider(slider) {
    if (slider.dataset.pcatSliderReady === 'true') return;
    var track = slider.querySelector('[data-pcat-slider-track]');
    var viewport = slider.querySelector('[data-pcat-slider-viewport]');
    var previous = slider.querySelector('[data-pcat-slider-prev]');
    var next = slider.querySelector('[data-pcat-slider-next]');
    if (!track || !viewport || !previous || !next) return;

    slider.dataset.pcatSliderReady = 'true';
    slider.classList.add('is-pcat-slider-ready');
    var paginationRoot = paginationRootFor(slider);
    if (paginationRoot && paginationRoot.dataset.pcatPaginationMode === 'slider') {
      paginationRoot.classList.add('is-pcat-slider-pagination-ready');
    }

    var index = 0;

    function columnsPerView() {
      var value = parseInt(getComputedStyle(slider).getPropertyValue('--pcat-slider-cols'), 10);
      return Number.isFinite(value) && value > 0 ? value : 1;
    }

    function maximumIndex() {
      return Math.max(0, track.children.length - columnsPerView());
    }

    function stepWidth() {
      var first = track.children[0];
      if (!first) return 0;
      var gap = parseFloat(getComputedStyle(track).gap || '0') || 0;
      return first.getBoundingClientRect().width + gap;
    }

    function render() {
      var maximum = maximumIndex();
      index = Math.max(0, Math.min(index, maximum));
      var step = stepWidth();
      track.style.transform = step ? 'translateX(' + (-index * step) + 'px)' : 'translateX(0px)';
      var previousPage = paginationLink(slider, 'prev');
      var nextPage = paginationLink(slider, 'next');
      previous.disabled = index === 0 && !previousPage;
      next.disabled = index === maximum && !nextPage;
      slider.classList.toggle('is-pcat-slider-static', maximum === 0 && !previousPage && !nextPage);
    }

    function move(direction) {
      var maximum = maximumIndex();
      if (direction < 0 && index === 0) {
        var previousPage = paginationLink(slider, 'prev');
        if (previousPage) previousPage.click();
        return;
      }
      if (direction > 0 && index === maximum) {
        var nextPage = paginationLink(slider, 'next');
        if (nextPage) nextPage.click();
        return;
      }
      index += direction;
      render();
    }

    previous.addEventListener('click', function () { move(-1); });
    next.addEventListener('click', function () { move(1); });

    var pointerStart = 0;
    var pointerDelta = 0;
    var pointerActive = false;
    viewport.addEventListener('pointerdown', function (event) {
      pointerActive = true;
      pointerStart = event.clientX;
      pointerDelta = 0;
      try { viewport.setPointerCapture(event.pointerId); } catch (error) {}
    });
    viewport.addEventListener('pointermove', function (event) {
      if (pointerActive) pointerDelta = event.clientX - pointerStart;
    });
    viewport.addEventListener('pointerup', function () {
      if (!pointerActive) return;
      pointerActive = false;
      if (Math.abs(pointerDelta) > 40) move(pointerDelta < 0 ? 1 : -1);
    });
    viewport.addEventListener('pointercancel', function () { pointerActive = false; });

    if (paginationRoot && paginationRoot.dataset.pcatPaginationArrival === 'previous') {
      index = maximumIndex();
    }
    if (paginationRoot) delete paginationRoot.dataset.pcatPaginationArrival;
    if ('ResizeObserver' in window) new ResizeObserver(render).observe(viewport);
    render();
  }

  function initializeSliders(scope) {
    var sliders = [];
    if (scope instanceof Element && scope.matches('[data-pcat-pagination-slider="core"]')) sliders.push(scope);
    if (scope && typeof scope.querySelectorAll === 'function') {
      sliders = sliders.concat(Array.from(scope.querySelectorAll('[data-pcat-pagination-slider="core"]')));
    }
    sliders.forEach(initCoreSlider);
  }

  function currentPaginationUrl(link, key) {
    var target = new URL(link.href, window.location.href);
    var current = new URL(window.location.href);
    current.pathname = target.pathname;
    if (target.searchParams.has(key)) current.searchParams.set(key, target.searchParams.get(key));
    else current.searchParams.delete(key);
    target.searchParams.forEach(function (value, name) {
      if (name.indexOf('pcat_seed_') === 0) current.searchParams.set(name, value);
    });
    document.querySelectorAll('[data-pcat-pagination-root] .pcat-pagination a[href]').forEach(function (candidate) {
      var candidateUrl = new URL(candidate.href, window.location.href);
      candidateUrl.searchParams.forEach(function (value, name) {
        if (name.indexOf('pcat_seed_') === 0) current.searchParams.set(name, value);
      });
    });
    current.hash = target.hash;
    return current;
  }

  function seedStableCurrentUrl(targetUrl) {
    var current = new URL(window.location.href);
    targetUrl.searchParams.forEach(function (value, name) {
      if (name.indexOf('pcat_seed_') === 0) current.searchParams.set(name, value);
    });
    return current;
  }

  async function loadPage(link, root) {
    var key = root.getAttribute('data-pcat-pagination-root') || '';
    if (!key) return;
    var activeRoots = rootsFor(document, key);
    if (activeRoots.some(function (candidate) { return candidate.dataset.paginationLoading === 'true'; })) return;
    activeRoots.forEach(function (candidate) {
      candidate.dataset.paginationLoading = 'true';
      candidate.setAttribute('aria-busy', 'true');
    });
    var targetUrl = currentPaginationUrl(link, key);

    try {
      var response = await fetch(targetUrl.href, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!response.ok) throw new Error('Pagination request failed');
      var documentText = await response.text();
      var nextDocument = new DOMParser().parseFromString(documentText, 'text/html');
      var nextRoots = rootsFor(nextDocument, key);
      if (!nextRoots[0]) throw new Error('Pagination target is missing');
      var focusedReplacement = null;
      var replacements = [];
      activeRoots.forEach(function (currentRoot, index) {
        var incoming = nextRoots[index] || nextRoots[0];
        var replacement = document.importNode(incoming, true);
        if (replacement.dataset.pcatPaginationMode === 'slider') {
          replacement.dataset.pcatPaginationArrival = link.getAttribute('rel') === 'prev' ? 'previous' : 'next';
        }
        currentRoot.replaceWith(replacement);
        activateScripts(replacement);
        initializeSliders(replacement);
        replacements.push(replacement);
        if (currentRoot === root) focusedReplacement = replacement;
      });
      if (typeof window.JyavaniSliderPageInit === 'function') {
        replacements.forEach(function (replacement) { window.JyavaniSliderPageInit(replacement); });
      }
      history.replaceState(Object.assign({}, history.state, { pcatPagination: true }), '', seedStableCurrentUrl(targetUrl).href);
      history.pushState({ pcatPagination: true }, '', targetUrl.href);
      if (focusedReplacement) focusedReplacement.focus({ preventScroll: true });
      document.dispatchEvent(new CustomEvent('jyavani:preset-pagination-updated', {
        detail: { key: key, root: focusedReplacement, roots: replacements, url: targetUrl.href }
      }));
    } catch (error) {
      window.location.assign(targetUrl.href);
    } finally {
      activeRoots.forEach(function (candidate) {
        if (!candidate.isConnected) return;
        delete candidate.dataset.paginationLoading;
        candidate.removeAttribute('aria-busy');
      });
    }
  }

  document.addEventListener('click', function (event) {
    var link = event.target.closest('[data-pcat-pagination-root] .pcat-pagination a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return;
    var root = link.closest('[data-pcat-pagination-root]');
    if (!root) return;
    event.preventDefault();
    loadPage(link, root);
  });

  window.addEventListener('popstate', function () {
    if (document.querySelector('[data-pcat-pagination-root]')) window.location.reload();
  });

  window.JyavaniPresetPagination = { init: initializeSliders };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { initializeSliders(document); });
  } else {
    initializeSliders(document);
  }
})();
