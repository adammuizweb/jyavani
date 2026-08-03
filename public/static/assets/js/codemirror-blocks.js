(function () {
  'use strict';

  // Core CodeMirror static-highlight loader for <pre><code> blocks in single
  // post/page content. Loads CodeMirror assets on demand, only when at least
  // one block has a detectable/available language mode.

  if (window.__cmBlocksInit) return; // idempotent: whichever loader runs first wins
  window.__cmBlocksInit = true;

  var CM_BASE = '/static/vendor/codemirror/';

  var MODE_FILE = {
    xml: 'mode/xml/xml.min.js',
    clike: 'mode/clike/clike.min.js',
    javascript: 'mode/javascript/javascript.min.js',
    css: 'mode/css/css.min.js',
    htmlmixed: 'mode/htmlmixed/htmlmixed.min.js',
    php: 'mode/php/php.min.js'
  };
  var MODE_DEPS = {
    php: ['xml', 'clike', 'javascript', 'css', 'htmlmixed'],
    htmlmixed: ['xml', 'javascript', 'css'],
    javascript: [],
    css: [],
    xml: [],
    clike: []
  };
  var MODE_LABEL = {
    php: 'PHP',
    javascript: 'JavaScript',
    htmlmixed: 'HTML',
    css: 'CSS',
    xml: 'XML',
    clike: 'C/C++/Java'
  };
  var ALIAS = {
    js: 'javascript', mjs: 'javascript', es6: 'javascript', es: 'javascript',
    ecmascript: 'javascript', jsx: 'javascript',
    html: 'htmlmixed', htm: 'htmlmixed', htmlmixed: 'htmlmixed', markup: 'htmlmixed',
    xml: 'xml', svg: 'xml',
    c: 'clike', cpp: 'clike', cxx: 'clike', 'c++': 'clike', h: 'clike', hpp: 'clike',
    java: 'clike', csharp: 'clike', cs: 'clike', kotlin: 'clike'
  };

  // ---- find content containers ----------------------------------------------
  // Prefer an explicit opt-in marker (data-cm-blocks on the content wrapper),
  // then <article> elements (default/portfolio themes), then <main> as last resort.
  function contentContainers() {
    var marked = document.querySelectorAll('[data-cm-blocks]');
    if (marked.length) return marked;
    var arts = document.querySelectorAll('article');
    if (arts.length) return arts;
    return document.querySelectorAll('main');
  }

  var blocks = [];
  var containers = contentContainers();
  for (var c = 0; c < containers.length; c++) {
    var pres = containers[c].querySelectorAll('pre');
    for (var i = 0; i < pres.length; i++) {
      var pre = pres[i];
      var target = pre.querySelector('code') || pre; // highlight code when present, else the <pre> itself
      blocks.push({ pre: pre, target: target });
    }
  }
  if (!blocks.length) return;

  // ---- helpers --------------------------------------------------------------
  function classNameOf(el) {
    return typeof el.className === 'string' ? el.className : (el.className.baseVal || '');
  }

  function codeText(code) {
    var text = code.textContent || '';
    if (text.charCodeAt(0) === 10) text = text.slice(1); // leading newline from pretty-printed HTML
    var lines = text.split('\n');
    var min = Infinity;
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].trim() === '') continue;
      var indent = lines[i].match(/^[ \t]*/)[0].length;
      if (indent < min) min = indent;
    }
    if (min > 0 && isFinite(min)) {
      var re = new RegExp('^[ \\t]{' + min + '}');
      for (var j = 0; j < lines.length; j++) {
        lines[j] = lines[j].replace(re, '');
      }
    }
    return lines.join('\n');
  }

  function sniffMode(text) {
    if (/<\?php|<%/.test(text)) return 'php';
    if (/<!doctype\s+html|<html[ >]/i.test(text)) return 'htmlmixed';
    if (/<\?xml|xmlns=/.test(text)) return 'xml';
    if (/@(media|keyframes|supports|font-face)/.test(text) && /[{;}]/.test(text)) return 'css';
    if (/\b(function|const|let|var|return|console\.|=>)\b/.test(text) && /[{;}]/.test(text)) return 'javascript';
    return null;
  }

  function detect(b) {
    var cls = (classNameOf(b.target) + ' ' + classNameOf(b.pre)).toLowerCase();
    var m = cls.match(/language-([a-z0-9+#_-]+)/) || cls.match(/\blang-([a-z0-9+#_-]+)/);
    var raw = m ? m[1] : '';
    var mode = ALIAS[raw] || raw;
    if (MODE_LABEL[mode]) {
      return { mode: mode, label: MODE_LABEL[mode], highlight: true };
    }
    if (raw) {
      return { mode: null, label: raw, highlight: false }; // no mode available (python, sql, bash, ...)
    }
    var sniffed = sniffMode(codeText(b.target));
    if (sniffed) return { mode: sniffed, label: MODE_LABEL[sniffed], highlight: true };
    return { mode: null, label: 'Code', highlight: false };
  }

  // ---- chrome (header + copy button) ----------------------------------------
  function addChrome(b, info) {
    var wrap = document.createElement('div');
    wrap.className = 'cm-block';
    b.pre.parentNode.insertBefore(wrap, b.pre);
    wrap.appendChild(b.pre);

    var head = document.createElement('div');
    head.className = 'cm-block-head';

    var label = document.createElement('span');
    label.className = 'cm-block-lang';
    label.textContent = info.label;

    var copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'cm-block-copy';
    copy.textContent = 'Copy';
    copy.setAttribute('aria-label', 'Copy code');
    copy.addEventListener('click', function () { copyText(b.originalText, copy); });

    head.appendChild(label);
    head.appendChild(copy);
    wrap.insertBefore(head, b.pre);
    b.pre.classList.add('cm-block-pre');
  }

  function copyText(text, btn) {
    var done = function () {
      var old = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = old; }, 1500);
    };
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      document.body.removeChild(ta);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else {
      fallback();
    }
  }

  // ---- asset loading ----------------------------------------------------------
  function loadCss(urls) {
    for (var i = 0; i < urls.length; i++) {
      if (document.querySelector('link[href="' + urls[i] + '"]')) continue;
      var l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = urls[i];
      document.head.appendChild(l);
    }
  }

  function loadScripts(urls, onDone) {
    var i = 0;
    function next() {
      if (i >= urls.length) { if (onDone) onDone(); return; }
      var s = document.createElement('script');
      s.src = urls[i++];
      s.async = false;
      s.onload = next;
      s.onerror = next; // skip a missing mode rather than hanging
      document.body.appendChild(s);
    }
    next();
  }

  // ---- main flow ---------------------------------------------------------------
  var info = [];
  var needModes = {};
  var anyHighlight = false;

  for (var b = 0; b < blocks.length; b++) {
    blocks[b].originalText = codeText(blocks[b].target);
    var inf = detect(blocks[b]);
    info.push(inf);
    addChrome(blocks[b], inf);
    if (inf.highlight) {
      anyHighlight = true;
      needModes[inf.mode] = 1;
    }
  }

  function paint(b, inf) {
    if (inf.highlight) {
      b.pre.classList.add('cm-s-dracula');
      try {
        CodeMirror.runMode(b.originalText, inf.mode, b.target, { tabSize: 4 });
        return;
      } catch (e) { /* fall through to plain text */ }
    }
    b.target.textContent = b.originalText;
  }

  if (!anyHighlight) {
    for (var n = 0; n < blocks.length; n++) paint(blocks[n], info[n]);
    return;
  }

  var cssUrls = [CM_BASE + 'codemirror.min.css', CM_BASE + 'theme/dracula.min.css'];
  var jsUrls = [CM_BASE + 'codemirror.min.js', CM_BASE + 'addon/runmode/runmode.js'];

  var loaded = {};
  var ordered = [];
  function collect(mode) {
    var deps = MODE_DEPS[mode] || [];
    for (var d = 0; d < deps.length; d++) collect(deps[d]);
    if (!loaded[mode]) {
      loaded[mode] = 1;
      ordered.push(mode);
    }
  }
  for (var m in needModes) collect(m);
  for (var k = 0; k < ordered.length; k++) {
    jsUrls.push(CM_BASE + MODE_FILE[ordered[k]]);
  }

  loadCss(cssUrls);
  loadScripts(jsUrls, function () {
    for (var p = 0; p < blocks.length; p++) paint(blocks[p], info[p]);
  });
})();
