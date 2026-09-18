/* Jyavani table support for the bundled Quill 1.x editor. */
(function () {
  'use strict';

  if (typeof window.Quill !== 'function' || window.JyavaniQuillTable) return;

  var Quill = window.Quill;
  var BlockEmbed = Quill.import('blots/block/embed');
  var Delta = Quill.import('delta');
  var MAX_ROWS = 12;
  var MAX_COLUMNS = 12;

  function bounded(value, fallback, maximum) {
    var parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? Math.max(1, Math.min(maximum, parsed)) : fallback;
  }

  function normalized(value) {
    value = value && typeof value === 'object' ? value : {};
    var rows = bounded(value.rows, 3, MAX_ROWS);
    var columns = bounded(value.columns, 3, MAX_COLUMNS);
    var cells = Array.isArray(value.cells) ? value.cells : [];
    return {
      rows: rows,
      columns: columns,
      header: value.header !== false,
      cells: Array.from({ length: rows }, function (_, row) {
        return Array.from({ length: columns }, function (_, column) {
          var sourceRow = Array.isArray(cells[row]) ? cells[row] : [];
          return String(sourceRow[column] == null ? '' : sourceRow[column]).slice(0, 2000);
        });
      })
    };
  }

  function valueFromNode(table) {
    var rows = Array.from(table.querySelectorAll('tr'));
    var columns = rows.reduce(function (largest, row) {
      return Math.max(largest, row.querySelectorAll(':scope > th, :scope > td').length);
    }, 0);
    var value = {
      rows: rows.length || 1,
      columns: columns || 1,
      header: !!table.querySelector('thead') || !!(rows[0] && rows[0].querySelector(':scope > th')),
      cells: rows.map(function (row) {
        return Array.from(row.querySelectorAll(':scope > th, :scope > td')).map(function (cell) {
          return String(cell.textContent || '').trim();
        });
      })
    };
    return normalized(value);
  }

  function buildTable(node, value) {
    value = normalized(value);
    node.className = 'jy-editor-table';
    node.setAttribute('data-jyavani-table', '1');
    node.setAttribute('contenteditable', 'false');
    node.innerHTML = '';

    var body = document.createElement('tbody');
    value.cells.forEach(function (row, rowIndex) {
      var tr = document.createElement('tr');
      row.forEach(function (text) {
        var cell = document.createElement(value.header && rowIndex === 0 ? 'th' : 'td');
        cell.textContent = text;
        tr.appendChild(cell);
      });
      body.appendChild(tr);
    });
    node.appendChild(body);
    return node;
  }

  class JyavaniTableBlot extends BlockEmbed {
    static create(value) {
      return buildTable(super.create(), value);
    }

    static value(node) {
      return valueFromNode(node);
    }
  }

  JyavaniTableBlot.blotName = 'table';
  JyavaniTableBlot.tagName = 'TABLE';
  JyavaniTableBlot.className = 'jy-editor-table';
  Quill.register(JyavaniTableBlot, true);

  var activeDialogClose = null;
  var dialogSequence = 0;

  function labels() {
    var supplied = window.jyavaniTableEditorI18n || {};
    return Object.assign({
      title: 'Insert table',
      editTitle: 'Edit table',
      rows: 'Rows',
      columns: 'Columns',
      header: 'Use first row as header',
      cell: 'Row %d, column %d',
      cancel: 'Cancel',
      insert: 'Insert table',
      update: 'Update table',
      remove: 'Remove table',
      mediaLibrary: 'Media Library',
      fileLibrary: 'File Library'
    }, supplied);
  }

  function format(template, row, column) {
    return String(template).replace('%d', String(row)).replace('%d', String(column));
  }

  function openEditor(initial, onSave, onRemove) {
    if (typeof activeDialogClose === 'function') activeDialogClose();
    var i18n = labels();
    var value = normalized(initial || {});
    var overlay = document.createElement('div');
    var titleId = 'jy-table-dialog-title-' + (++dialogSequence);
    overlay.className = 'jy-table-dialog';
    overlay.innerHTML =
      '<div class="jy-table-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="' + titleId + '">' +
        '<h3 id="' + titleId + '"></h3>' +
        '<div class="jy-table-dialog__options">' +
          '<label><span></span><input type="number" min="1" max="' + MAX_ROWS + '" data-table-rows></label>' +
          '<label><span></span><input type="number" min="1" max="' + MAX_COLUMNS + '" data-table-columns></label>' +
          '<label class="jy-table-dialog__check"><input type="checkbox" data-table-header><span></span></label>' +
        '</div>' +
        '<div class="jy-table-dialog__grid" data-table-grid></div>' +
        '<div class="jy-table-dialog__actions">' +
          (onRemove ? '<button type="button" class="btn btn-danger" data-table-remove></button>' : '') +
          '<span></span><button type="button" class="btn btn-outline" data-table-cancel></button>' +
          '<button type="button" class="btn btn-primary" data-table-save></button>' +
        '</div>' +
      '</div>';

    var title = overlay.querySelector('h3');
    var rowInput = overlay.querySelector('[data-table-rows]');
    var columnInput = overlay.querySelector('[data-table-columns]');
    var headerInput = overlay.querySelector('[data-table-header]');
    var grid = overlay.querySelector('[data-table-grid]');
    var cancel = overlay.querySelector('[data-table-cancel]');
    var save = overlay.querySelector('[data-table-save]');
    var remove = overlay.querySelector('[data-table-remove]');

    title.textContent = onRemove ? i18n.editTitle : i18n.title;
    rowInput.previousElementSibling.textContent = i18n.rows;
    columnInput.previousElementSibling.textContent = i18n.columns;
    headerInput.nextElementSibling.textContent = i18n.header;
    cancel.textContent = i18n.cancel;
    save.textContent = onRemove ? i18n.update : i18n.insert;
    if (remove) remove.textContent = i18n.remove;
    rowInput.value = value.rows;
    columnInput.value = value.columns;
    headerInput.checked = value.header;

    function readGrid() {
      var cells = [];
      grid.querySelectorAll('[data-table-cell]').forEach(function (input) {
        var row = parseInt(input.getAttribute('data-row'), 10);
        var column = parseInt(input.getAttribute('data-column'), 10);
        if (!cells[row]) cells[row] = [];
        cells[row][column] = input.value;
      });
      return cells;
    }

    function renderGrid() {
      var previous = readGrid();
      var rows = bounded(rowInput.value, value.rows, MAX_ROWS);
      var columns = bounded(columnInput.value, value.columns, MAX_COLUMNS);
      rowInput.value = rows;
      columnInput.value = columns;
      grid.style.setProperty('--table-columns', columns);
      grid.innerHTML = '';
      for (var row = 0; row < rows; row++) {
        for (var column = 0; column < columns; column++) {
          var input = document.createElement('input');
          input.type = 'text';
          input.setAttribute('data-table-cell', '1');
          input.setAttribute('data-row', row);
          input.setAttribute('data-column', column);
          input.setAttribute('aria-label', format(i18n.cell, row + 1, column + 1));
          input.value = previous[row] && previous[row][column] != null
            ? previous[row][column]
            : (value.cells[row] && value.cells[row][column] != null ? value.cells[row][column] : '');
          grid.appendChild(input);
        }
      }
    }

    var closed = false;
    function close() {
      if (closed) return;
      closed = true;
      if (activeDialogClose === close) activeDialogClose = null;
      document.removeEventListener('keydown', onKeydown);
      overlay.remove();
    }

    function onKeydown(event) {
      if (event.key === 'Escape') close();
    }

    rowInput.addEventListener('change', renderGrid);
    columnInput.addEventListener('change', renderGrid);
    cancel.addEventListener('click', close);
    save.addEventListener('click', function () {
      onSave(normalized({
        rows: rowInput.value,
        columns: columnInput.value,
        header: headerInput.checked,
        cells: readGrid()
      }));
      close();
    });
    if (remove) remove.addEventListener('click', function () { onRemove(); close(); });
    overlay.addEventListener('mousedown', function (event) { if (event.target === overlay) close(); });
    document.addEventListener('keydown', onKeydown);
    document.body.appendChild(overlay);
    activeDialogClose = close;
    renderGrid();
    var firstCell = grid.querySelector('input');
    (firstCell || rowInput).focus();
    return close;
  }

  function configure(quill) {
    if (!quill || quill.__jyavaniTableConfigured) return;
    quill.__jyavaniTableConfigured = true;
    quill.clipboard.addMatcher('TABLE', function (node) {
      return new Delta().insert({ table: valueFromNode(node) });
    });

    function insertTable() {
      if (typeof quill.__jyavaniTableCloseDialog === 'function') quill.__jyavaniTableCloseDialog();
      quill.__jyavaniTableCloseDialog = openEditor(null, function (value) {
        var range = quill.getSelection(true) || { index: Math.max(0, quill.getLength() - 1) };
        quill.insertEmbed(range.index, 'table', value, 'user');
        quill.setSelection(range.index + 1, 0, 'silent');
      });
    }

    var toolbar = quill.getModule('toolbar');
    if (toolbar) {
      toolbar.addHandler('table', insertTable);
      var labelButton = function () {
        var button = toolbar.container && toolbar.container.querySelector('.ql-table');
        var imageButton = toolbar.container && toolbar.container.querySelector('.ql-image');
        var fileButton = toolbar.container && toolbar.container.querySelector('.ql-video');
        var i18n = labels();
        if (button) {
          button.setAttribute('title', i18n.title);
          button.setAttribute('aria-label', i18n.title);
        }
        if (imageButton) {
          imageButton.setAttribute('title', i18n.mediaLibrary);
          imageButton.setAttribute('aria-label', i18n.mediaLibrary);
        }
        if (fileButton) {
          fileButton.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path class="ql-stroke" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path class="ql-stroke" d="M14 2v6h6"></path></svg>';
          fileButton.setAttribute('title', i18n.fileLibrary);
          fileButton.setAttribute('aria-label', i18n.fileLibrary);
        }
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', labelButton, { once: true });
      else labelButton();
    }

    quill.root.addEventListener('click', function (event) {
      var table = event.target.closest && event.target.closest('table.jy-editor-table');
      if (!table || !quill.root.contains(table)) return;
      var blot = Quill.find(table);
      if (!blot) return;
      var index = quill.getIndex(blot);
      if (typeof quill.__jyavaniTableCloseDialog === 'function') quill.__jyavaniTableCloseDialog();
      quill.__jyavaniTableCloseDialog = openEditor(valueFromNode(table), function (value) {
        quill.deleteText(index, 1, 'silent');
        quill.insertEmbed(index, 'table', value, 'user');
        quill.setSelection(index + 1, 0, 'silent');
      }, function () {
        quill.deleteText(index, 1, 'user');
      });
    });
  }

  function destroy(quill) {
    if (!quill || typeof quill.__jyavaniTableCloseDialog !== 'function') return;
    quill.__jyavaniTableCloseDialog();
    delete quill.__jyavaniTableCloseDialog;
  }

  window.JyavaniQuillTable = { configure: configure, destroy: destroy };
})();
