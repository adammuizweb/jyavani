(function(){
'use strict';

var grid     = document.getElementById('dw-grid');
var toggle   = document.getElementById('dw-arrange-toggle');
var form     = document.getElementById('dw-layout-form');
var input    = document.getElementById('dw-layout-input');
var basePath = window.ADMIN_PATH || '';

if (!grid || !toggle) return;

var arrangeActive = false;
var dragWidget    = null;

// ─── Toggle arrange mode ───
toggle.addEventListener('click', function(){
  arrangeActive = !arrangeActive;
  toggle.textContent = arrangeActive
    ? (window._e ? _e('Save Arrangement') : 'Save Arrangement')
    : (window._e ? _e('Arrange Widgets') : 'Arrange Widgets');
  toggle.dataset.active = arrangeActive ? '1' : '0';
  grid.classList.toggle('dw-arrange-active', arrangeActive);

  if (!arrangeActive) {
    saveLayout();
  }
});

// ─── Get parent .dw-widget ───
function getWidget(el) {
  while (el && el !== document.body && el !== grid) {
    if (el.nodeType === 1 && el.classList && el.classList.contains('dw-widget')) return el;
    el = el.parentNode;
  }
  return null;
}

// ─── Get parent .dw-col ───
function getCol(el) {
  while (el && el !== document.body && el !== grid) {
    if (el.nodeType === 1 && el.classList && el.classList.contains('dw-col')) return el;
    el = el.parentNode;
  }
  return null;
}

// ─── Drag start ───
function handleDragStart(e) {
  if (!arrangeActive) { e.preventDefault(); return; }
  var w = getWidget(this);
  if (!w) { e.preventDefault(); return; }
  dragWidget = w;
  w.classList.add('dw-dragging');
  e.dataTransfer.effectAllowed = 'move';
  try { e.dataTransfer.setData('text/plain', w.dataset.widget || ''); } catch(_) {}
}

// ─── Drag over — real-time swap ───
function handleDragOver(e) {
  if (!arrangeActive || !dragWidget) return;
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';

  var target = getWidget(e.target);
  if (!target || target === dragWidget) return;

  // Determine insert position based on cursor Y
  var rect = target.getBoundingClientRect();
  var y = e.clientY - rect.top;
  var mid = rect.height / 2;

  var col = target.parentNode;
  if (col === dragWidget.parentNode) {
    // Same column
    if (y < mid) {
      col.insertBefore(dragWidget, target);
    } else if (target.nextSibling) {
      col.insertBefore(dragWidget, target.nextSibling);
    } else {
      col.appendChild(dragWidget);
    }
  } else {
    // Different column
    dragWidget.dataset.col = col.id === 'dw-col-2' ? '2' : '1';
    if (y < mid) {
      col.insertBefore(dragWidget, target);
    } else if (target.nextSibling) {
      col.insertBefore(dragWidget, target.nextSibling);
    } else {
      col.appendChild(dragWidget);
    }
  }
}

// ─── Drop ───
function handleDrop(e) {
  e.preventDefault();
  if (dragWidget) dragWidget.classList.remove('dw-dragging');
  dragWidget = null;
}

// ─── Drag end ───
function handleDragEnd(e) {
  var w = getWidget(this);
  if (w) w.classList.remove('dw-dragging');
  if (dragWidget) dragWidget.classList.remove('dw-dragging');
  dragWidget = null;
}

// ─── Attach events ───
function attachEvents() {
  var handles = grid.querySelectorAll('.dw-drag-handle');
  for (var i = 0; i < handles.length; i++) {
    handles[i].addEventListener('dragstart', handleDragStart);
    handles[i].addEventListener('dragend', handleDragEnd);
  }
  grid.addEventListener('dragover', handleDragOver);
  grid.addEventListener('drop', handleDrop);
}
attachEvents();

// ─── Save layout ───
function saveLayout() {
  var col1 = document.getElementById('dw-col-1');
  var col2 = document.getElementById('dw-col-2');
  if (!col1 || !col2) return;

  var items = [];
  var widgets1 = col1.querySelectorAll('.dw-widget');
  var widgets2 = col2.querySelectorAll('.dw-widget');

  for (var i = 0; i < widgets1.length; i++) {
    items.push({ w: widgets1[i].dataset.widget, col: 1 });
  }
  for (var i = 0; i < widgets2.length; i++) {
    items.push({ w: widgets2[i].dataset.widget, col: 2 });
  }

  if (input) input.value = JSON.stringify(items);
  if (form) {
    var fd = new FormData(form);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', basePath + '/admin/save_dashboard_layout.php', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onload = function(){
      if (xhr.status === 200) {
        try {
          var d = JSON.parse(xhr.responseText);
          if (d && d.ok && window.NewNotifToast) {
            window.NewNotifToast.success(
              window._e ? _e('Widget arrangement saved.') : 'Widget arrangement saved.',
              ''
            );
          }
        } catch(_) {}
      }
    };
    xhr.send(fd);
  }
}

})();
