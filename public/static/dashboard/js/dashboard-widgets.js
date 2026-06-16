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

function clearDragHighlights() {
  var sel = grid.querySelectorAll('.dw-col-target, .dw-insert-before, .dw-insert-after');
  for (var i = 0; i < sel.length; i++) sel[i].classList.remove('dw-col-target', 'dw-insert-before', 'dw-insert-after');
}

function handleDragOver(e) {
  if (!arrangeActive || !dragWidget) return;
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  clearDragHighlights();

  // Check if cursor is inside a column (even empty)
  var col = e.target.closest('.dw-col');
  if (col) {
    var row = col.closest('.dw-row');
    if (row) {
      var cols = row.querySelectorAll('.dw-col');
      if (cols.length > 1) {
        var c1c = (cols[0].getBoundingClientRect().left + cols[0].getBoundingClientRect().right) / 2;
        var c2c = (cols[1].getBoundingClientRect().left + cols[1].getBoundingClientRect().right) / 2;
        col = (Math.abs(e.clientX - c1c) <= Math.abs(e.clientX - c2c)) ? cols[0] : cols[1];
      }
    }
    col.classList.add('dw-col-target');
    var ref = getInsertRef(col, e.clientY);
    if (ref) ref.classList.add('dw-insert-before');
    if (ref) col.insertBefore(dragWidget, ref);
    else col.appendChild(dragWidget);
    return;
  }

  var target = getWidget(e.target);
  if (target === dragWidget) return;

  if (!target) {
    // Empty area — append as orphan, normalizeGrid will wrap it
    if (dragWidget.parentNode !== grid) grid.appendChild(dragWidget);
    return;
  }

  var targetCol  = target.closest('.dw-col');
  var rect       = target.getBoundingClientRect();
  var insertB4   = (e.clientY < rect.top + rect.height / 2);

  if (targetCol) {
    // Determine target column by mouse X (enables cross-column drops)
    var row = targetCol.closest('.dw-row');
    if (row) {
      var cols = row.querySelectorAll('.dw-col');
      if (cols.length > 1) {
        var c1c = (cols[0].getBoundingClientRect().left + cols[0].getBoundingClientRect().right) / 2;
        var c2c = (cols[1].getBoundingClientRect().left + cols[1].getBoundingClientRect().right) / 2;
        var newCol = (Math.abs(e.clientX - c1c) <= Math.abs(e.clientX - c2c)) ? cols[0] : cols[1];
        if (newCol !== targetCol) {
          // Cross-column drop: insert by Y position in new column
          newCol.classList.add('dw-col-target');
          var ref = getInsertRef(newCol, e.clientY);
          if (ref) ref.classList.add('dw-insert-before');
          if (ref) newCol.insertBefore(dragWidget, ref);
          else newCol.appendChild(dragWidget);
          return;
        }
      }
    }
    // Same column: existing logic
    targetCol.classList.add('dw-col-target');
    if (insertB4) {
      target.classList.add('dw-insert-before');
      targetCol.insertBefore(dragWidget, target);
    } else if (target.nextSibling) {
      target.nextSibling.classList.add('dw-insert-before');
      targetCol.insertBefore(dragWidget, target.nextSibling);
    } else {
      target.classList.add('dw-insert-after');
      targetCol.appendChild(dragWidget);
    }
  } else {
    if (dragWidget.parentNode !== grid) {
      grid.appendChild(dragWidget);
    }
    var targetRow = target.closest('.dw-row-full');
    if (targetRow) {
      targetRow.classList.add('dw-col-target');
      if (insertB4) {
        grid.insertBefore(dragWidget, targetRow);
      } else if (targetRow.nextSibling) {
        grid.insertBefore(dragWidget, targetRow.nextSibling);
      } else {
        grid.appendChild(dragWidget);
      }
    }
  }
}

function handleDrop(e) {
  e.preventDefault();
  clearDragHighlights();
  if (dragWidget) dragWidget.classList.remove('dw-dragging');
  dragWidget = null;
  normalizeGrid();
  updateWidgetPanel();
}

function handleDragEnd(e) {
  clearDragHighlights();
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

function getInsertRef(col, y) {
  for (var i = 0; i < col.children.length; i++) {
    var cr = col.children[i].getBoundingClientRect();
    if (y < cr.top + cr.height / 2) return col.children[i];
  }
  return null;
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
        if (row.nextSibling) grid.insertBefore(fr, row.nextSibling);
        else grid.appendChild(fr);
        fr.appendChild(w);
      });
    });
  });
}

function removeEmptyRows() {
  var rows = grid.querySelectorAll(':scope > .dw-row, :scope > .dw-row-full');
  Array.from(rows).forEach(function(r) {
    if (r.classList.contains('dw-row')) {
      var cols = r.querySelectorAll('.dw-col');
      var has = false;
      cols.forEach(function(c) { if (c.children.length) has = true; });
      if (!has) r.remove();
    } else if (!r.children.length) {
      r.remove();
    }
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
