(function(){
'use strict';

var grid      = document.getElementById('dw-grid');
var toggle    = document.getElementById('dw-arrange-toggle');
var form      = document.getElementById('dw-layout-form');
var input     = document.getElementById('dw-layout-input');
var wpanel    = document.getElementById('dw-widget-panel');
var wlist     = document.getElementById('dw-widget-list');
var basePath  = window.ADMIN_PATH || '';

if (!grid || !toggle) return;

var arrangeActive = false;
var dragWidget    = null;

var allWidgetKeys = [];
try { allWidgetKeys = JSON.parse(wlist.dataset.keys || '[]'); } catch(e) {}

toggle.addEventListener('click', function(){
  arrangeActive = !arrangeActive;

  if (arrangeActive) {
    grid.classList.add('dw-arrange-active');
    toggle.textContent = 'Save Arrangement';
    toggle.dataset.active = '1';
  } else {
    saveLayout();
    return;
  }
});

function getWidget(el) {
  while (el && el !== document.body) {
    if (el.nodeType === 1 && el.classList.contains('dw-widget')) return el;
    el = el.parentNode;
  }
  return null;
}

function handleDragStart(e) {
  if (!arrangeActive) { e.preventDefault(); return; }
  // Ignore hide button clicks
  if (e.target.closest('.dw-hide-btn')) { e.preventDefault(); return; }
  var w = getWidget(this);
  if (!w) { e.preventDefault(); return; }
  dragWidget = w;
  w.classList.add('dw-dragging');
  e.dataTransfer.effectAllowed = 'move';
  try { e.dataTransfer.setData('text/plain', w.dataset.widget || ''); } catch(_) {}
}

function handleHideClick(e) {
  if (!arrangeActive) return;
  e.stopPropagation();
  var w = getWidget(this);
  if (!w) return;
  w.remove(); // remove from DOM
  updateWidgetPanel();
}

function handleDragOver(e) {
  if (!arrangeActive || !dragWidget) return;
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';

  var col = e.target.closest('.dw-col');

  if (col) {
    // Inside a normal row's column (even empty) — determine target by mouse X
    var row = col.closest('.dw-row');
    if (row && row.querySelectorAll('.dw-col').length > 1) col = getColByMouseX(row, e.clientX);
    insertByY(col, dragWidget, e.clientY);
    return;
  }

  // Not in a column — check if target is a widget in a full-width row
  var target = getWidget(e.target);
  if (target && target !== dragWidget) {
    var fullRow = target.closest('.dw-row-full');
    if (fullRow) {
      // Use the previous normal row's columns
      var prev = fullRow.previousElementSibling;
      while (prev && !prev.classList.contains('dw-row')) prev = prev.previousElementSibling;
      if (prev) {
        col = getColByMouseX(prev, e.clientX);
        if (col) { insertByY(col, dragWidget, e.clientY); return; }
      }
    }
  }

  // Fall back: append as direct grid child (normalizeGrid will wrap it)
  if (dragWidget.parentNode !== grid) grid.appendChild(dragWidget);
}

function getColByMouseX(row, x) {
  var cols = row.querySelectorAll('.dw-col');
  if (!cols.length) return null;
  var c1c = (cols[0].getBoundingClientRect().left + cols[0].getBoundingClientRect().right) / 2;
  if (cols.length < 2) return cols[0];
  var c2c = (cols[1].getBoundingClientRect().left + cols[1].getBoundingClientRect().right) / 2;
  return (Math.abs(x - c1c) <= Math.abs(x - c2c)) ? cols[0] : cols[1];
}

function insertByY(col, el, y) {
  var refChild = null;
  for (var i = 0; i < col.children.length; i++) {
    var cr = col.children[i].getBoundingClientRect();
    if (y < cr.top + cr.height / 2) { refChild = col.children[i]; break; }
  }
  if (refChild) col.insertBefore(el, refChild);
  else col.appendChild(el);
}

function handleDrop(e) {
  e.preventDefault();
  if (dragWidget) dragWidget.classList.remove('dw-dragging');
  dragWidget = null;
  normalizeGrid();
  updateWidgetPanel();
}

function handleDragEnd(e) {
  var w = getWidget(this);
  if (w) w.classList.remove('dw-dragging');
  if (dragWidget) dragWidget.classList.remove('dw-dragging');
  dragWidget = null;
  normalizeGrid();
}

function attachEvents() {
  var handles = grid.querySelectorAll('.dw-drag-handle');
  for (var i = 0; i < handles.length; i++) {
    handles[i].addEventListener('dragstart', handleDragStart);
    handles[i].addEventListener('dragend', handleDragEnd);
  }
  var hideBtns = grid.querySelectorAll('.dw-hide-btn');
  for (var i = 0; i < hideBtns.length; i++) {
    hideBtns[i].addEventListener('click', handleHideClick);
  }
}
attachEvents();
grid.addEventListener('dragover', handleDragOver);
grid.addEventListener('drop', handleDrop);

function getColHint(el) {
  var parentCol = el.closest('.dw-col');
  if (parentCol) {
    var row = parentCol.closest('.dw-row');
    if (row) {
      var cols = row.querySelectorAll('.dw-col');
      return (cols.length > 1 && parentCol === cols[1]) ? 'r' : 'l';
    }
  }
  return 'f';
}

function normalizeGrid() {
  fixOrphans();
  fixFullWidthMisplaced();
  removeEmptyRows();
  attachEvents();
}

function fixOrphans() {
  var items = Array.from(grid.querySelectorAll(':scope > .dw-widget'));
  items.forEach(function(w) {
    var r = document.createElement('div');
    if (w.dataset.fullWidth === '1') {
      r.className = 'dw-row-full';
      grid.replaceChild(r, w);
      r.appendChild(w);
    } else {
      r.className = 'dw-row';
      var c1 = document.createElement('div');
      c1.className = 'dw-col';
      var c2 = document.createElement('div');
      c2.className = 'dw-col';
      r.appendChild(c1);
      r.appendChild(c2);
      grid.replaceChild(r, w);
      c1.appendChild(w);
    }
  });
}

function fixFullWidthMisplaced() {
  var rows = Array.from(grid.querySelectorAll(':scope > .dw-row'));
  rows.forEach(function(row) {
    var cols = row.querySelectorAll('.dw-col');
    cols.forEach(function(col) {
      var fws = Array.from(col.querySelectorAll('.dw-widget[data-full-width="1"]'));
      fws.forEach(function(w) {
        col.removeChild(w);
        var fr = document.createElement('div');
        fr.className = 'dw-row-full';
        grid.insertBefore(fr, row.nextSibling);
        fr.appendChild(w);
      });
    });
  });
}

function removeEmptyRows() {
  var empty = Array.from(grid.querySelectorAll(':scope > .dw-row:empty, :scope > .dw-row-full:empty'));
  empty.forEach(function(r) { r.remove(); });
  // Also remove rows where all cols are empty
  var rows = grid.querySelectorAll(':scope > .dw-row');
  rows.forEach(function(row) {
    var cols = row.querySelectorAll('.dw-col');
    var hasContent = false;
    cols.forEach(function(c) { if (c.children.length) hasContent = true; });
    if (!hasContent) row.remove();
  });
}

function collectLayoutItems() {
  var items = [];
  var children = grid.children;
  for (var i = 0; i < children.length; i++) {
    var child = children[i];
    if (child.classList.contains('dw-row')) {
      var c1 = child.querySelector('.dw-col:first-child');
      var c2 = child.querySelector('.dw-col:last-child');
      var w1 = c1 ? Array.from(c1.querySelectorAll('.dw-widget')) : [];
      var w2 = c2 ? Array.from(c2.querySelectorAll('.dw-widget')) : [];
      w1.forEach(function(w) { items.push(w.dataset.widget + ':l'); });
      w2.forEach(function(w) { items.push(w.dataset.widget + ':r'); });
    } else if (child.classList.contains('dw-row-full')) {
      var fw = child.querySelectorAll('.dw-widget');
      for (var j = 0; j < fw.length; j++) items.push(fw[j].dataset.widget);
    } else if (child.classList.contains('dw-widget')) {
      items.push(child.dataset.widget);
    }
  }
  return items;
}

function getVisibleKeys() {
  var visible = [];
  var ws = grid.querySelectorAll('.dw-widget');
  for (var i = 0; i < ws.length; i++) visible.push(ws[i].dataset.widget);
  return visible;
}

function updateWidgetPanel() {
  if (!wpanel || !wlist) return;
  var visible = getVisibleKeys();
  var hidden  = allWidgetKeys.filter(function(k) { return visible.indexOf(k) < 0; });
  if (!hidden.length) { wpanel.style.display = 'none'; return; }
  wpanel.style.display = 'block';
  wlist.innerHTML = '';
  hidden.forEach(function(key) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'adam-button dw-add-btn';
    btn.textContent = '+ ' + key;
    btn.dataset.widget = key;
    btn.addEventListener('click', function() {
      var items = collectLayoutItems();
      items.push(key); // plain key, PHP defaults to left col
      if (input) input.value = JSON.stringify(items);
      if (form) {
        var fd = new FormData(form);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', basePath + '/admin/save_dashboard_layout.php', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function() { if (xhr.status === 200) location.reload(); };
        xhr.send(fd);
      }
    });
    wlist.appendChild(btn);
  });
}

function saveLayout() {
  var items = collectLayoutItems();
  if (input) input.value = JSON.stringify(items);
  if (!form) return;

  var fd = new FormData(form);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', basePath + '/admin/save_dashboard_layout.php', true);
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.setRequestHeader('Accept', 'application/json');
  xhr.onload = function(){
    if (xhr.status === 200) {
      try {
        var d = JSON.parse(xhr.responseText);
        if (d && d.ok) location.reload();
      } catch(_) {}
    }
  };
  xhr.send(fd);
}

})();
