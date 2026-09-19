/* Editable table support for the bundled Quill 1.x editor. */
(function () {
  'use strict';

  if (typeof window.Quill !== 'function' || window.JyavaniQuillTable) return;

  var Quill = window.Quill;
  var Block = Quill.import('blots/block');
  var Container = Quill.import('blots/container');
  var Parchment = Quill.import('parchment');
  var Delta = Quill.import('delta');
  var MAX_ROWS = 12;
  var MAX_COLUMNS = 12;
  var MAX_CELL_LENGTH = 2000;
  var identitySequence = 0;
  var cellMetadata = typeof WeakMap === 'function' ? new WeakMap() : null;
  var editorStates = typeof WeakMap === 'function' ? new WeakMap() : null;

  var TableBreakFormat = new Parchment.Attributor.Class('table-break', 'jy-table-break', {
    scope: Parchment.Scope.INLINE,
    whitelist: ['1']
  });
  var TableHeadingFormat = new Parchment.Attributor.Class('table-heading', 'jy-table-heading', {
    scope: Parchment.Scope.INLINE,
    whitelist: ['1', '2', '3', '4', '5', '6']
  });
  var TableListFormat = new Parchment.Attributor.Class('table-list', 'jy-table-list', {
    scope: Parchment.Scope.INLINE,
    whitelist: ['ordered', 'bullet']
  });

  function identity(prefix) {
    identitySequence++;
    return prefix + '-' + Date.now().toString(36) + '-' + identitySequence.toString(36);
  }

  function bounded(value, fallback, maximum) {
    var parsed = parseInt(value, 10);
    return Number.isFinite(parsed) ? Math.max(1, Math.min(maximum, parsed)) : fallback;
  }

  function normalizeMeta(value) {
    value = value && typeof value === 'object' ? value : {};
    return {
      table: typeof value.table === 'string' && value.table ? value.table : identity('table'),
      row: typeof value.row === 'string' && value.row ? value.row : identity('row'),
      header: value.header === true
    };
  }

  function cloneMeta(value) {
    value = normalizeMeta(value);
    return { table: value.table, row: value.row, header: value.header };
  }

  function setMeta(node, value) {
    var normalized = cloneMeta(value);
    if (cellMetadata) cellMetadata.set(node, normalized);
    node.__jyavaniTableCell = normalized;
    return normalized;
  }

  function metaFromDom(node) {
    if (!node) return normalizeMeta({});
    var known = cellMetadata && cellMetadata.get(node);
    if (!known) known = node.__jyavaniTableCell;
    if (known) return cloneMeta(known);

    var table = node.closest && node.closest('table');
    var row = node.closest && node.closest('tr');
    if (table && !table.__jyavaniTableId) table.__jyavaniTableId = identity('table');
    if (row && !row.__jyavaniRowId) row.__jyavaniRowId = identity('row');
    return setMeta(node, {
      table: table && table.__jyavaniTableId,
      row: row && row.__jyavaniRowId,
      header: node.tagName === 'TH'
    });
  }

  function firstCell(container) {
    var current = container && container.children && container.children.head;
    while (current) {
      if (current instanceof JyavaniTableCellBlot) return current;
      var nested = firstCell(current);
      if (nested) return nested;
      current = current.next;
    }
    return null;
  }

  class JyavaniTableCellBlot extends Block {
    static create(value) {
      value = normalizeMeta(value);
      var node = super.create(value.header ? 'TH' : 'TD');
      setMeta(node, value);
      return node;
    }

    static formats(node) {
      return metaFromDom(node);
    }

    clone() {
      var node = this.domNode.cloneNode(false);
      setMeta(node, metaFromDom(this.domNode));
      return Parchment.create(node);
    }

    format(name, value) {
      if (name === this.statics.blotName) {
        if (!value) {
          return;
        }
        value = normalizeMeta(value);
        var needsHeader = value.header === true;
        var hasHeader = this.domNode.tagName === 'TH';
        if (needsHeader !== hasHeader) {
          var replacement = Parchment.create(this.statics.blotName, value);
          this.moveChildren(replacement);
          replacement.replace(this);
        } else {
          setMeta(this.domNode, value);
        }
        return;
      }
      if (name === 'header' || name === 'list' || name === 'blockquote' || name === 'code-block') return;
      super.format(name, value);
    }

    optimize(context) {
      super.optimize(context);
      if (this.parent instanceof JyavaniTableRowBlot) {
        var previous = this.prev;
        if (previous instanceof JyavaniTableCellBlot) {
          var mine = metaFromDom(this.domNode);
          var theirs = metaFromDom(previous.domNode);
          if (mine.table !== theirs.table || mine.row !== theirs.row) {
            var oldRow = this.parent;
            var newRow = Parchment.create(JyavaniTableRowBlot.blotName);
            oldRow.parent.insertBefore(newRow, oldRow.next);
            var current = this;
            while (current) {
              var next = current.next;
              newRow.appendChild(current);
              current = next;
            }
          }
        }
        return;
      }
      var row = this.wrap(JyavaniTableRowBlot.blotName);
      var body = row.wrap(JyavaniTableBodyBlot.blotName);
      body.wrap(JyavaniTableContainerBlot.blotName);
    }
  }

  JyavaniTableCellBlot.blotName = 'table-cell';
  JyavaniTableCellBlot.tagName = ['TD', 'TH'];

  class JyavaniTableRowBlot extends Container {
    optimize(context) {
      super.optimize(context);
      var mine = firstCell(this);
      if (mine) {
        var baseline = metaFromDom(mine.domNode);
        var candidate = mine.next;
        while (candidate) {
          if (candidate instanceof JyavaniTableCellBlot) {
            var candidateMeta = metaFromDom(candidate.domNode);
            if (candidateMeta.table !== baseline.table || candidateMeta.row !== baseline.row) {
              var splitRow = Parchment.create(JyavaniTableRowBlot.blotName);
              this.parent.insertBefore(splitRow, this.next);
              var moving = candidate;
              while (moving) {
                var movingNext = moving.next;
                splitRow.appendChild(moving);
                moving = movingNext;
              }
              return;
            }
          }
          candidate = candidate.next;
        }
      }
      var next = this.next;
      var theirs = next instanceof JyavaniTableRowBlot ? firstCell(next) : null;
      if (!mine || !theirs) return;
      var left = metaFromDom(mine.domNode);
      var right = metaFromDom(theirs.domNode);
      if (left.table === right.table && left.row === right.row) {
        next.moveChildren(this);
        next.remove();
      }
    }
  }

  JyavaniTableRowBlot.blotName = 'table-row';
  JyavaniTableRowBlot.tagName = 'TR';
  JyavaniTableRowBlot.scope = Parchment.Scope.BLOCK_BLOT;
  JyavaniTableRowBlot.allowedChildren = [JyavaniTableCellBlot];

  class JyavaniTableBodyBlot extends Container {
    optimize(context) {
      super.optimize(context);
      var mine = firstCell(this);
      if (mine) {
        var baseline = metaFromDom(mine.domNode);
        var candidate = mine.parent && mine.parent.next;
        while (candidate) {
          var candidateCell = firstCell(candidate);
          if (candidateCell && metaFromDom(candidateCell.domNode).table !== baseline.table) {
            var splitBody = Parchment.create(JyavaniTableBodyBlot.blotName);
            this.parent.insertBefore(splitBody, this.next);
            var moving = candidate;
            while (moving) {
              var movingNext = moving.next;
              splitBody.appendChild(moving);
              moving = movingNext;
            }
            return;
          }
          candidate = candidate.next;
        }
      }
      var next = this.next;
      var theirs = next instanceof JyavaniTableBodyBlot ? firstCell(next) : null;
      if (mine && theirs && metaFromDom(mine.domNode).table === metaFromDom(theirs.domNode).table) {
        next.moveChildren(this);
        next.remove();
      }
    }
  }

  JyavaniTableBodyBlot.blotName = 'table-body';
  JyavaniTableBodyBlot.tagName = 'TBODY';
  JyavaniTableBodyBlot.scope = Parchment.Scope.BLOCK_BLOT;
  JyavaniTableBodyBlot.allowedChildren = [JyavaniTableRowBlot];

  class JyavaniTableContainerBlot extends Container {
    static create() {
      var node = super.create();
      node.classList.add('jy-editor-table');
      node.setAttribute('data-jyavani-table', '1');
      return node;
    }

    optimize(context) {
      super.optimize(context);
      var mine = firstCell(this);
      if (mine) {
        var baseline = metaFromDom(mine.domNode);
        var candidate = mine.parent && mine.parent.parent && mine.parent.parent.next;
        while (candidate) {
          var candidateCell = firstCell(candidate);
          if (candidateCell && metaFromDom(candidateCell.domNode).table !== baseline.table) {
            var splitTable = Parchment.create(JyavaniTableContainerBlot.blotName);
            this.parent.insertBefore(splitTable, this.next);
            var moving = candidate;
            while (moving) {
              var movingNext = moving.next;
              splitTable.appendChild(moving);
              moving = movingNext;
            }
            return;
          }
          candidate = candidate.next;
        }
      }
      var next = this.next;
      var theirs = next instanceof JyavaniTableContainerBlot ? firstCell(next) : null;
      if (mine && theirs && metaFromDom(mine.domNode).table === metaFromDom(theirs.domNode).table) {
        next.moveChildren(this);
        next.remove();
      }
    }
  }

  JyavaniTableContainerBlot.blotName = 'table';
  JyavaniTableContainerBlot.tagName = 'TABLE';
  JyavaniTableContainerBlot.className = 'jy-editor-table';
  JyavaniTableContainerBlot.scope = Parchment.Scope.BLOCK_BLOT;
  JyavaniTableContainerBlot.allowedChildren = [JyavaniTableBodyBlot];

  Quill.register(JyavaniTableCellBlot, true);
  Quill.register(JyavaniTableRowBlot, true);
  Quill.register(JyavaniTableBodyBlot, true);
  Quill.register(JyavaniTableContainerBlot, true);
  Quill.register(TableBreakFormat, true);
  Quill.register(TableHeadingFormat, true);
  Quill.register(TableListFormat, true);

  function labels() {
    var supplied = window.jyavaniTableEditorI18n || {};
    return Object.assign({
      title: 'Insert table',
      editTitle: 'Table settings',
      rows: 'Rows',
      columns: 'Columns',
      header: 'Use first row as header',
      cancel: 'Cancel',
      insert: 'Insert table',
      update: 'Update table',
      remove: 'Remove table',
      actions: 'Table actions',
      settings: 'Table settings',
      rowAbove: 'Add row above',
      rowBelow: 'Add row below',
      deleteRow: 'Delete row',
      columnBefore: 'Add column before',
      columnAfter: 'Add column after',
      deleteColumn: 'Delete column',
      confirmShrink: 'Reducing rows or columns will remove cell content. Continue?',
      mediaLibrary: 'Media Library',
      fileLibrary: 'File Library'
    }, supplied);
  }

  function directRows(table) {
    var rows = [];
    Array.from(table.children || []).forEach(function (child) {
      if (child.tagName === 'TR') rows.push(child);
      else if (child.tagName === 'THEAD' || child.tagName === 'TBODY' || child.tagName === 'TFOOT') {
        Array.from(child.children || []).forEach(function (row) { if (row.tagName === 'TR') rows.push(row); });
      }
    });
    return rows;
  }

  function directCells(row) {
    return Array.from(row && row.children || []).filter(function (cell) {
      return cell.tagName === 'TD' || cell.tagName === 'TH';
    });
  }

  function cellDelta(delta, meta) {
    var result = new Delta();
    var remaining = MAX_CELL_LENGTH;
    var operations = (delta && delta.ops || []).map(function (op) {
      return { insert: op.insert, attributes: Object.assign({}, op.attributes || {}) };
    });
    for (var index = operations.length - 1; index >= 0; index--) {
      if (typeof operations[index].insert !== 'string') continue;
      if (operations[index].insert.endsWith('\n')) {
        operations[index].insert = operations[index].insert.slice(0, -1);
      }
      break;
    }
    operations.forEach(function (op) {
      if (remaining < 1) return;
      if (typeof op.insert !== 'string') return;
      var text = op.insert.replace(/\n/g, ' ');
      if (!text) return;
      text = text.slice(0, remaining);
      remaining -= text.length;
      var attributes = Object.assign({}, op.attributes || {});
      delete attributes.header;
      delete attributes.list;
      delete attributes.blockquote;
      delete attributes['code-block'];
      delete attributes['table-cell'];
      if (Object.keys(attributes).length) result.insert(text, attributes);
      else result.insert(text);
    });
    return result.insert('\n', { 'table-cell': cloneMeta(meta) });
  }

  function installClipboardMatchers(quill) {
    var importTables = typeof WeakMap === 'function' ? new WeakMap() : null;
    var importRows = typeof WeakMap === 'function' ? new WeakMap() : null;

    function importedMeta(node) {
      var table = node.closest('table');
      var row = node.closest('tr');
      var tableId = importTables && importTables.get(table);
      var rowId = importRows && importRows.get(row);
      if (!tableId) {
        tableId = identity('table');
        if (importTables) importTables.set(table, tableId);
      }
      if (!rowId) {
        rowId = identity('row');
        if (importRows) importRows.set(row, rowId);
      }
      return { table: tableId, row: rowId, header: node.tagName === 'TH' };
    }

    function matchCell(node, delta) {
      var result = cellDelta(delta, importedMeta(node));
      node.__jyavaniImportedCellDelta = result;
      return result;
    }

    quill.clipboard.addMatcher('TH', matchCell);
    quill.clipboard.addMatcher('TD', matchCell);
    quill.clipboard.addMatcher('BR', function (node, delta) {
      var cell = node.closest && node.closest('th,td');
      if (!cell) return delta;
      if (node.parentNode === cell && cell.children.length === 1 && String(cell.textContent || '') === '') {
        return new Delta();
      }
      return new Delta().insert('\u200b', { 'table-break': '1' });
    });
    quill.clipboard.addMatcher('TABLE', function (node) {
      var rows = directRows(node).slice(0, MAX_ROWS);
      var columns = rows.reduce(function (largest, row) {
        return Math.max(largest, directCells(row).length);
      }, 1);
      columns = Math.min(MAX_COLUMNS, columns);
      var result = new Delta();
      rows.forEach(function (row) {
        var cells = directCells(row).slice(0, columns);
        var firstMeta = cells[0] ? importedMeta(cells[0]) : { table: identity('table'), row: identity('row') };
        var rowId = firstMeta.row;
        var tableId = firstMeta.table;
        for (var column = 0; column < columns; column++) {
          var cell = cells[column];
          if (cell && cell.__jyavaniImportedCellDelta) result = result.concat(cell.__jyavaniImportedCellDelta);
          else result = result.insert('\n', { 'table-cell': {
            table: tableId,
            row: rowId,
            header: !!(cell && cell.tagName === 'TH')
          } });
        }
      });
      return result;
    });
  }

  function tableDelta(rows, columns, header, tableId) {
    tableId = tableId || identity('table');
    var result = new Delta();
    for (var row = 0; row < rows; row++) {
      var rowId = identity('row');
      for (var column = 0; column < columns; column++) {
        result.insert('\n', { 'table-cell': {
          table: tableId,
          row: rowId,
          header: header && row === 0
        } });
      }
    }
    return result;
  }

  function contextAt(quill, index) {
    var line = quill.getLine(Math.max(0, index));
    var cell = line && line[0];
    if (!(cell instanceof JyavaniTableCellBlot) && !cell && index > 0) {
      line = quill.getLine(index - 1);
      cell = line && line[0];
    }
    if (!(cell instanceof JyavaniTableCellBlot)) return null;
    var row = cell.parent;
    var body = row && row.parent;
    var table = body && body.parent;
    if (!(row instanceof JyavaniTableRowBlot) || !(table instanceof JyavaniTableContainerBlot)) return null;
    return { cell: cell, row: row, body: body, table: table };
  }

  function selectionCrossesTableBoundary(quill, range) {
    if (!range || range.length < 1) return false;
    var startContext = contextAt(quill, range.index);
    var endContext = contextAt(quill, range.index + range.length);
    if (!!startContext !== !!endContext) return true;
    if (startContext && endContext && startContext.cell !== endContext.cell) return true;
    var lines = quill.getLines(range.index, range.length);
    var cells = [];
    var hasOrdinaryLine = false;
    lines.forEach(function (line) {
      if (line instanceof JyavaniTableCellBlot) {
        if (cells.indexOf(line) === -1) cells.push(line);
      } else {
        hasOrdinaryLine = true;
      }
    });
    return cells.length > 1 || (cells.length > 0 && hasOrdinaryLine);
  }

  function deltaWithExitParagraph(delta) {
    var operations = delta && delta.ops || [];
    var last = operations.length ? operations[operations.length - 1] : null;
    if (last && typeof last.insert === 'string' && last.insert.endsWith('\n')
        && last.attributes && last.attributes['table-cell']) {
      return delta.concat(new Delta().insert('\n'));
    }
    return delta;
  }

  function multilineCellDelta(delta, maximumLength) {
    var lines = [{ operations: [], block: {} }];
    (delta && delta.ops || []).forEach(function (op) {
      if (typeof op.insert !== 'string') return;
      var attributes = Object.assign({}, op.attributes || {});
      var header = attributes.header;
      var list = attributes.list;
      delete attributes.header;
      delete attributes.list;
      delete attributes.blockquote;
      delete attributes['code-block'];
      delete attributes['table-cell'];
      var parts = op.insert.split('\n');
      parts.forEach(function (part, index) {
        if (part) lines[lines.length - 1].operations.push({ insert: part, attributes: attributes });
        if (index < parts.length - 1) {
          lines[lines.length - 1].block = { header: header, list: list };
          lines.push({ operations: [], block: {} });
        }
      });
    });
    if (lines.length > 1 && lines[lines.length - 1].operations.length === 0) lines.pop();
    var result = new Delta();
    var requestedMaximum = parseInt(maximumLength, 10);
    var remaining = Number.isFinite(requestedMaximum)
      ? Math.max(0, Math.min(MAX_CELL_LENGTH, requestedMaximum))
      : MAX_CELL_LENGTH;
    lines.some(function (line, lineIndex) {
      if (remaining < 1) return true;
      if (line.block.list === 'ordered' || line.block.list === 'bullet') {
        result.insert('\u200b', { 'table-list': line.block.list });
        remaining--;
      }
      line.operations.forEach(function (op) {
        if (remaining < 1) return;
        var text = op.insert.slice(0, remaining);
        remaining -= text.length;
        var attributes = Object.assign({}, op.attributes || {});
        if (line.block.header && /^[1-6]$/.test(String(line.block.header))) {
          attributes['table-heading'] = String(line.block.header);
        }
        result.insert(text, Object.keys(attributes).length ? attributes : undefined);
      });
      if (lineIndex < lines.length - 1 && remaining > 0) {
        result.insert('\u200b', { 'table-break': '1' });
        remaining--;
      }
      return remaining < 1;
    });
    return result;
  }

  function tableInfo(quill, context) {
    if (!context || !context.table || !context.table.domNode.isConnected) return null;
    var rows = directRows(context.table.domNode).map(function (rowNode) {
      return directCells(rowNode).map(function (node) { return Quill.find(node); }).filter(Boolean);
    }).filter(function (row) { return row.length > 0; });
    var rowIndex = rows.findIndex(function (row) { return row.indexOf(context.cell) !== -1; });
    var columnIndex = rowIndex < 0 ? -1 : rows[rowIndex].indexOf(context.cell);
    if (rowIndex < 0 || columnIndex < 0) return null;
    return {
      context: context,
      rows: rows,
      rowIndex: rowIndex,
      columnIndex: columnIndex,
      tableId: metaFromDom(context.cell.domNode).table,
      header: rows[0].some(function (cell) { return cell.domNode.tagName === 'TH'; })
    };
  }

  function findTable(quill, tableId) {
    var tables = Array.from(quill.root.querySelectorAll('table.jy-editor-table'));
    for (var index = 0; index < tables.length; index++) {
      var cellNode = tables[index].querySelector('th,td');
      var cell = cellNode && Quill.find(cellNode);
      if (cell instanceof JyavaniTableCellBlot && metaFromDom(cell.domNode).table === tableId) {
        return contextAt(quill, cell.offset(quill.scroll));
      }
    }
    return null;
  }

  function setHeader(quill, tableId, enabled) {
    var context = findTable(quill, tableId);
    var info = tableInfo(quill, context);
    if (!info) return;
    var changes = [];
    info.rows.forEach(function (row, rowIndex) {
      row.forEach(function (cell) {
        var meta = metaFromDom(cell.domNode);
        meta.header = enabled && rowIndex === 0;
        changes.push({ index: cell.offset(quill.scroll), meta: meta });
      });
    });
    changes.sort(function (left, right) { return right.index - left.index; });
    changes.forEach(function (change) {
      quill.formatLine(change.index, 1, 'table-cell', change.meta, 'user');
    });
  }

  function insertRow(quill, context, after) {
    var info = tableInfo(quill, context);
    if (!info || info.rows.length >= MAX_ROWS) return false;
    var reference = info.rows[info.rowIndex];
    var index = after
      ? reference[reference.length - 1].offset(quill.scroll) + reference[reference.length - 1].length()
      : reference[0].offset(quill.scroll);
    var meta = metaFromDom(reference[0].domNode);
    var rowId = identity('row');
    var delta = new Delta().retain(index);
    for (var column = 0; column < reference.length; column++) {
      delta.insert('\n', { 'table-cell': { table: meta.table, row: rowId, header: false } });
    }
    quill.updateContents(delta, 'user');
    if (!after && info.rowIndex === 0 && info.header) setHeader(quill, info.tableId, true);
    return true;
  }

  function deleteRow(quill, context) {
    var info = tableInfo(quill, context);
    if (!info) return false;
    if (info.rows.length === 1) return deleteTable(quill, context);
    var row = info.rows[info.rowIndex];
    var start = row[0].offset(quill.scroll);
    var length = row.reduce(function (total, cell) { return total + cell.length(); }, 0);
    quill.deleteText(start, length, 'user');
    if (info.rowIndex === 0 && info.header) setHeader(quill, info.tableId, true);
    return true;
  }

  function insertColumn(quill, context, after) {
    var info = tableInfo(quill, context);
    if (!info || info.rows[0].length >= MAX_COLUMNS) return false;
    var positions = info.rows.map(function (row) {
      var reference = row[Math.min(info.columnIndex, row.length - 1)];
      return {
        index: reference.offset(quill.scroll) + (after ? reference.length() : 0),
        meta: metaFromDom(reference.domNode)
      };
    }).sort(function (left, right) { return left.index - right.index; });
    var delta = new Delta();
    var consumed = 0;
    positions.forEach(function (position) {
      delta.retain(position.index - consumed);
      delta.insert('\n', { 'table-cell': cloneMeta(position.meta) });
      consumed = position.index;
    });
    quill.updateContents(delta, 'user');
    return true;
  }

  function deleteColumn(quill, context) {
    var info = tableInfo(quill, context);
    if (!info) return false;
    if (info.rows[0].length === 1) return deleteTable(quill, context);
    var targets = info.rows.map(function (row) {
      var cell = row[Math.min(info.columnIndex, row.length - 1)];
      return { index: cell.offset(quill.scroll), length: cell.length() };
    }).sort(function (left, right) { return left.index - right.index; });
    var delta = new Delta();
    var consumed = 0;
    targets.forEach(function (target) {
      delta.retain(target.index - consumed);
      delta.delete(target.length);
      consumed = target.index + target.length;
    });
    quill.updateContents(delta, 'user');
    return true;
  }

  function deleteTable(quill, context) {
    var info = tableInfo(quill, context);
    if (!info) return false;
    var cells = [].concat.apply([], info.rows);
    var start = cells[0].offset(quill.scroll);
    var length = cells.reduce(function (total, cell) { return total + cell.length(); }, 0);
    if (start === 0 && length >= quill.getLength()) quill.setContents(new Delta().insert('\n'), 'user');
    else quill.deleteText(start, length, 'user');
    quill.setSelection(Math.max(0, start - 1), 0, 'silent');
    return true;
  }

  function resizeTable(quill, context, rows, columns, header) {
    var info = tableInfo(quill, context);
    if (!info) return;
    var tableId = info.tableId;
    quill.history.cutoff();
    while (info.rows.length < rows) {
      var last = info.rows[info.rows.length - 1][0];
      insertRow(quill, contextAt(quill, last.offset(quill.scroll)), true);
      info = tableInfo(quill, findTable(quill, tableId));
    }
    while (info && info.rows.length > rows) {
      var lastRow = info.rows[info.rows.length - 1][0];
      deleteRow(quill, contextAt(quill, lastRow.offset(quill.scroll)));
      info = tableInfo(quill, findTable(quill, tableId));
    }
    while (info && info.rows[0].length < columns) {
      var lastColumn = info.rows[0][info.rows[0].length - 1];
      insertColumn(quill, contextAt(quill, lastColumn.offset(quill.scroll)), true);
      info = tableInfo(quill, findTable(quill, tableId));
    }
    while (info && info.rows[0].length > columns) {
      var removeColumn = info.rows[0][info.rows[0].length - 1];
      deleteColumn(quill, contextAt(quill, removeColumn.offset(quill.scroll)));
      info = tableInfo(quill, findTable(quill, tableId));
    }
    setHeader(quill, tableId, header);
    quill.history.cutoff();
  }

  function openSettings(state, initial, onSave, onRemove) {
    if (typeof state.closeDialog === 'function') state.closeDialog();
    var i18n = labels();
    var overlay = document.createElement('div');
    var titleId = 'jy-table-dialog-title-' + identitySequence + '-' + Date.now().toString(36);
    overlay.className = 'jy-table-dialog';
    overlay.innerHTML =
      '<div class="jy-table-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="' + titleId + '">' +
        '<h3 id="' + titleId + '"></h3>' +
        '<div class="jy-table-dialog__options">' +
          '<label><span></span><input type="number" min="1" max="' + MAX_ROWS + '" data-table-rows></label>' +
          '<label><span></span><input type="number" min="1" max="' + MAX_COLUMNS + '" data-table-columns></label>' +
          '<label class="jy-table-dialog__check"><input type="checkbox" data-table-header><span></span></label>' +
        '</div>' +
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
    rowInput.value = bounded(initial.rows, 3, MAX_ROWS);
    columnInput.value = bounded(initial.columns, 3, MAX_COLUMNS);
    headerInput.checked = initial.header !== false;
    var closed = false;
    function close() {
      if (closed) return;
      closed = true;
      if (state.closeDialog === close) state.closeDialog = null;
      document.removeEventListener('keydown', onKeydown);
      overlay.remove();
    }
    function onKeydown(event) { if (event.key === 'Escape') close(); }
    cancel.addEventListener('click', close);
    save.addEventListener('click', function () {
      if (onSave({
        rows: bounded(rowInput.value, 3, MAX_ROWS),
        columns: bounded(columnInput.value, 3, MAX_COLUMNS),
        header: headerInput.checked
      }) === false) return;
      close();
    });
    if (remove) remove.addEventListener('click', function () { onRemove(); close(); });
    overlay.addEventListener('mousedown', function (event) { if (event.target === overlay) close(); });
    document.addEventListener('keydown', onKeydown);
    document.body.appendChild(overlay);
    state.closeDialog = close;
    rowInput.focus();
  }

  function insertTable(state) {
    var quill = state.quill;
    var range = quill.getSelection(true) || { index: Math.max(0, quill.getLength() - 1), length: 0 };
    var existing = contextAt(quill, range.index);
    if (existing) return editSettings(state, existing);
    openSettings(state, { rows: 3, columns: 3, header: true }, function (value) {
      var current = quill.getSelection(true) || range;
      var lineInfo = quill.getLine(current.index);
      var line = lineInfo && lineInfo[0];
      var index = current.index;
      var appendExit = true;
      if (line && line.length() === 1) {
        index = line.offset(quill.scroll);
        appendExit = false;
      } else if (line) {
        index = line.offset(quill.scroll) + line.length();
      }
      var delta = new Delta().retain(index);
      var tableId = identity('table');
      delta = delta.concat(tableDelta(value.rows, value.columns, value.header, tableId));
      if (appendExit) delta.insert('\n');
      state.insertedTables[tableId] = true;
      quill.history.cutoff();
      quill.updateContents(delta, 'user');
      quill.history.cutoff();
      quill.setSelection(index, 0, 'user');
      quill.focus();
      updateTools(state, quill.getSelection());
    });
  }

  function editSettings(state, context) {
    var info = tableInfo(state.quill, context);
    if (!info) return;
    openSettings(state, {
      rows: info.rows.length,
      columns: info.rows[0].length,
      header: info.header
    }, function (value) {
      var current = findTable(state.quill, info.tableId);
      var currentInfo = tableInfo(state.quill, current);
      if (!currentInfo) return;
      var removesContent = currentInfo.rows.some(function (row, rowIndex) {
        return row.some(function (cell, columnIndex) {
          return (rowIndex >= value.rows || columnIndex >= value.columns)
            && String(cell.domNode.textContent || '').trim() !== '';
        });
      });
      if (removesContent && !window.confirm(labels().confirmShrink)) return false;
      resizeTable(state.quill, current, value.rows, value.columns, value.header);
    }, function () {
      var current = findTable(state.quill, info.tableId);
      if (current) deleteTable(state.quill, current);
    });
  }

  function createTools(state) {
    var i18n = labels();
    var tools = document.createElement('div');
    tools.className = 'jy-table-tools';
    tools.setAttribute('role', 'toolbar');
    tools.setAttribute('aria-label', i18n.actions);
    [
      ['row-above', i18n.rowAbove], ['row-below', i18n.rowBelow], ['delete-row', i18n.deleteRow],
      ['column-before', i18n.columnBefore], ['column-after', i18n.columnAfter], ['delete-column', i18n.deleteColumn],
      ['settings', i18n.settings], ['remove', i18n.remove]
    ].forEach(function (definition) {
      var button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-table-action', definition[0]);
      button.textContent = definition[1];
      tools.appendChild(button);
    });
    tools.hidden = true;
    tools.addEventListener('mousedown', function (event) { event.preventDefault(); });
    tools.addEventListener('click', function (event) {
      var button = event.target.closest('[data-table-action]');
      if (!button || !state.activeContext) return;
      var action = button.getAttribute('data-table-action');
      var context = state.activeContext;
      state.quill.history.cutoff();
      if (action === 'row-above') insertRow(state.quill, context, false);
      else if (action === 'row-below') insertRow(state.quill, context, true);
      else if (action === 'delete-row') deleteRow(state.quill, context);
      else if (action === 'column-before') insertColumn(state.quill, context, false);
      else if (action === 'column-after') insertColumn(state.quill, context, true);
      else if (action === 'delete-column') deleteColumn(state.quill, context);
      else if (action === 'settings') editSettings(state, context);
      else if (action === 'remove') deleteTable(state.quill, context);
      state.quill.history.cutoff();
      updateTools(state, state.quill.getSelection());
    });
    document.body.appendChild(tools);
    return tools;
  }

  function updateTools(state, range) {
    var context = range ? contextAt(state.quill, range.index) : null;
    state.activeContext = context;
    if (!context || !context.cell.domNode.isConnected) {
      state.tools.hidden = true;
      toggleBlockControls(state, false);
      return;
    }
    var rect = context.cell.domNode.getBoundingClientRect();
    state.tools.hidden = false;
    state.tools.style.left = Math.max(8, Math.min(window.innerWidth - state.tools.offsetWidth - 8, rect.left)) + 'px';
    state.tools.style.top = Math.max(8, rect.top - state.tools.offsetHeight - 8) + 'px';
    toggleBlockControls(state, true);
  }

  function toggleBlockControls(state, disabled) {
    if (!state.toolbar || !state.toolbar.container) return;
    ['.ql-blockquote', '.ql-code-block'].forEach(function (selector) {
      state.toolbar.container.querySelectorAll(selector).forEach(function (control) { control.disabled = disabled; });
    });
    var tableButton = state.toolbar.container.querySelector('.ql-table');
    if (tableButton) tableButton.classList.toggle('ql-active', disabled);
  }

  function prependBinding(quill, binding, handler) {
    quill.keyboard.addBinding(binding, handler);
    var list = quill.keyboard.bindings[binding.key];
    if (list && list.length > 1) list.unshift(list.pop());
  }

  function cellSegments(quill, context) {
    var start = context.cell.offset(quill.scroll);
    var end = start + context.cell.length() - 1;
    var segments = [];
    var segmentStart = start;
    var position = start;
    (quill.getContents(start, Math.max(0, end - start)).ops || []).forEach(function (op) {
      var length = typeof op.insert === 'string' ? op.insert.length : 1;
      if (op.attributes && op.attributes['table-break']) {
        segments.push({ start: segmentStart, end: position });
        segmentStart = position + length;
      }
      position += length;
    });
    segments.push({ start: segmentStart, end: end });
    return segments;
  }

  function listMarker(quill, segment) {
    if (segment.end <= segment.start) return null;
    var op = (quill.getContents(segment.start, 1).ops || [])[0];
    if (!op || op.insert !== '\u200b' || !op.attributes || !op.attributes['table-list']) return null;
    return { index: segment.start, value: op.attributes['table-list'] };
  }

  function insertLineMarker(quill, index, format, value) {
    quill.insertText(index, '\u200b', 'user');
    quill.removeFormat(index, 1, 'silent');
    quill.formatText(index, 1, format, value, 'silent');
  }

  function segmentAt(quill, context, index) {
    var segments = cellSegments(quill, context);
    return segments.find(function (segment) { return index >= segment.start && index <= segment.end; }) || segments[segments.length - 1];
  }

  function formatCellSegments(state, format, value) {
    var quill = state.quill;
    var range = quill.getSelection(true);
    var context = range && contextAt(quill, range.index);
    if (!context || selectionCrossesTableBoundary(quill, range)) return false;
    var endContext = range.length > 0 ? contextAt(quill, range.index + range.length) : context;
    if (!endContext || endContext.cell !== context.cell) return false;
    var selectionEnd = range.index + range.length;
    var segments = cellSegments(quill, context).filter(function (segment) {
      return range.length < 1
        ? range.index >= segment.start && range.index <= segment.end
        : segment.end > range.index && segment.start < selectionEnd;
    }).sort(function (left, right) { return right.start - left.start; });
    if (format === 'table-list' && value) {
      var markersNeeded = segments.filter(function (segment) { return !listMarker(quill, segment); }).length;
      if (String(context.cell.domNode.textContent || '').length + markersNeeded > MAX_CELL_LENGTH) return false;
    }
    var selectionStartShift = 0;
    var selectionEndShift = 0;
    function recordMarkerChange(index, amount) {
      if (index <= range.index) selectionStartShift += amount;
      if (range.length < 1) selectionEndShift = selectionStartShift;
      else if (index < selectionEnd) selectionEndShift += amount;
    }
    segments.forEach(function (segment) {
      var marker = listMarker(quill, segment);
      var contentStart = marker ? marker.index + 1 : segment.start;
      var contentLength = Math.max(0, segment.end - contentStart);
      if (format === 'table-heading') {
        if (marker && value) {
          quill.deleteText(marker.index, 1, 'user');
          contentStart--;
          recordMarkerChange(marker.index, -1);
        }
        if (contentLength > 0) quill.formatText(contentStart, contentLength, 'table-heading', value || false, 'user');
      } else if (format === 'table-list') {
        if (contentLength > 0) quill.formatText(contentStart, contentLength, 'table-heading', false, 'user');
        if (!value && marker) {
          quill.deleteText(marker.index, 1, 'user');
          recordMarkerChange(marker.index, -1);
        }
        else if (value && marker) quill.formatText(marker.index, 1, 'table-list', value, 'user');
        else if (value) {
          insertLineMarker(quill, segment.start, 'table-list', value);
          recordMarkerChange(segment.start, 1);
        }
      }
    });
    var newStart = Math.max(0, range.index + selectionStartShift);
    var newEnd = Math.max(newStart, selectionEnd + selectionEndShift);
    quill.setSelection(newStart, newEnd - newStart, 'silent');
    return true;
  }

  function installKeyboard(state) {
    var quill = state.quill;
    function cellsFor(context) {
      var info = tableInfo(quill, context);
      return info ? [].concat.apply([], info.rows) : [];
    }
    function move(range, backwards) {
      var context = contextAt(quill, range.index);
      if (!context) return true;
      var cells = cellsFor(context);
      var index = cells.indexOf(context.cell) + (backwards ? -1 : 1);
      if (index >= 0 && index < cells.length) quill.setSelection(cells[index].offset(quill.scroll), 0, 'user');
      else {
        var tableIndex = context.table.offset(quill.scroll);
        if (!backwards && !context.table.next) {
          var exitBlock = Parchment.create('block');
          context.table.parent.insertBefore(exitBlock, context.table.next);
          exitBlock.optimize();
          quill.update('user');
          quill.setSelection(exitBlock.offset(quill.scroll), 0, 'user');
        } else {
          quill.setSelection(backwards ? Math.max(0, tableIndex - 1) : tableIndex + context.table.length(), 0, 'user');
        }
      }
      return false;
    }
    prependBinding(quill, { key: 9, shiftKey: false }, function (range) { return move(range, false); });
    prependBinding(quill, { key: 9, shiftKey: true }, function (range) { return move(range, true); });
    prependBinding(quill, { key: 13, shiftKey: null, altKey: null, ctrlKey: null, metaKey: null }, function (range) {
      var context = contextAt(quill, range.index);
      if (!context || selectionCrossesTableBoundary(quill, range)) return context ? false : true;
      var activeSegment = segmentAt(quill, context, range.index);
      var activeList = listMarker(quill, activeSegment);
      var markerCount = activeList ? 2 : 1;
      if (String(context.cell.domNode.textContent || '').length - range.length + markerCount > MAX_CELL_LENGTH) return false;
      if (range.length > 0) quill.deleteText(range.index, range.length, 'user');
      insertLineMarker(quill, range.index, 'table-break', '1');
      var nextIndex = range.index + 1;
      if (activeList) {
        insertLineMarker(quill, nextIndex, 'table-list', activeList.value);
        nextIndex++;
      }
      quill.setSelection(nextIndex, 0, 'user');
      quill.format('table-break', false, 'silent');
      quill.format('table-list', false, 'silent');
      return false;
    });
    prependBinding(quill, { key: 8, shiftKey: null, altKey: null, ctrlKey: null, metaKey: null }, function (range) {
      if (selectionCrossesTableBoundary(quill, range)) return false;
      var context = contextAt(quill, range.index);
      if (!context) return contextAt(quill, Math.max(0, range.index - 1)) ? false : true;
      if (range.length > 0) {
        var end = contextAt(quill, range.index + range.length);
        return !!end && end.cell === context.cell;
      }
      return range.index > context.cell.offset(quill.scroll);
    });
    prependBinding(quill, { key: 46, shiftKey: null, altKey: null, ctrlKey: null, metaKey: null }, function (range) {
      if (selectionCrossesTableBoundary(quill, range)) return false;
      var context = contextAt(quill, range.index);
      if (!context) return contextAt(quill, range.index + 1) ? false : true;
      if (range.length > 0) {
        var end = contextAt(quill, range.index + range.length);
        return !!end && end.cell === context.cell;
      }
      return range.index < context.cell.offset(quill.scroll) + context.cell.length() - 1;
    });
  }

  function installToolbar(state) {
    var toolbar = state.toolbar;
    if (!toolbar) return;
    toolbar.addHandler('table', function () { insertTable(state); });
    ['header', 'list', 'blockquote', 'code-block'].forEach(function (format) {
      toolbar.addHandler(format, function (value) {
        var range = state.quill.getSelection(true);
        if (range && contextAt(state.quill, range.index)) {
          if (format === 'header') formatCellSegments(state, 'table-heading', value);
          else if (format === 'list') {
            var context = contextAt(state.quill, range.index);
            var end = range.index + range.length;
            var selectedSegments = context ? cellSegments(state.quill, context).filter(function (segment) {
              return range.length < 1
                ? range.index >= segment.start && range.index <= segment.end
                : segment.end > range.index && segment.start < end;
            }) : [];
            var allMatch = selectedSegments.length > 0 && selectedSegments.every(function (segment) {
              var marker = listMarker(state.quill, segment);
              return marker && marker.value === value;
            });
            formatCellSegments(state, 'table-list', allMatch ? false : value);
          }
          return;
        }
        state.quill.format(format, value, 'user');
      });
    });
    toolbar.addHandler('clean', function () {
      var range = state.quill.getSelection(true);
      if (!range || !contextAt(state.quill, range.index)) {
        if (range) state.quill.removeFormat(range.index, range.length, 'user');
        return;
      }
      formatCellSegments(state, 'table-heading', false);
      formatCellSegments(state, 'table-list', false);
      range = state.quill.getSelection(true) || range;
      ['bold', 'italic', 'underline', 'strike', 'color', 'background', 'script', 'link', 'size'].forEach(function (format) {
        state.quill.formatText(range.index, range.length, format, false, 'user');
      });
    });
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
  }

  function configure(quill) {
    if (!quill || quill.__jyavaniTableConfigured) return;
    quill.__jyavaniTableConfigured = true;
    var state = {
      quill: quill,
      toolbar: quill.getModule('toolbar'),
      tools: null,
      activeContext: null,
      closeDialog: null,
      insertedTables: Object.create(null)
    };
    state.tools = createTools(state);
    state.selectionHandler = function (range) { updateTools(state, range); };
    state.textHandler = function () {
      var contents = quill.getContents();
      if (contents && contents.ops && contents.ops.length === 1
          && contents.ops[0].insert === '\n'
          && contents.ops[0].attributes && contents.ops[0].attributes['table-cell']) {
        var onlyMeta = contents.ops[0].attributes['table-cell'];
        if (onlyMeta && state.insertedTables[onlyMeta.table]) {
          delete state.insertedTables[onlyMeta.table];
          quill.setContents(new Delta().insert('\n'), 'silent');
        }
      }
      updateTools(state, quill.getSelection());
    };
    state.clickHandler = function () { setTimeout(function () { updateTools(state, quill.getSelection()); }, 0); };
    state.cutHandler = function (event) {
      var range = quill.getSelection() || state.lastRange;
      if (!range || range.length < 1) return;
      if (selectionCrossesTableBoundary(quill, range)) event.preventDefault();
    };
    state.beforeInputHandler = function (event) {
      if (!event.inputType || event.inputType.indexOf('insert') !== 0 || event.inputType === 'insertParagraph') return;
      var range = quill.getSelection() || state.lastRange;
      var context = range && contextAt(quill, range.index);
      if (!context || selectionCrossesTableBoundary(quill, range)) return;
      var transferredText = event.dataTransfer && event.dataTransfer.getData('text/plain');
      var insertedLength = typeof event.data === 'string' ? event.data.length : String(transferredText || '').length;
      if (insertedLength < 1) {
        if (event.inputType === 'insertFromDrop') event.preventDefault();
        return;
      }
      var existingLength = String(context.cell.domNode.textContent || '').length;
      if (existingLength - range.length + insertedLength > MAX_CELL_LENGTH) event.preventDefault();
    };
    state.dropHandler = function (event) {
      var cell = event.target && event.target.closest && event.target.closest('th,td');
      if (!cell) return;
      var range = quill.getSelection() || state.lastRange;
      if (range && selectionCrossesTableBoundary(quill, range)) {
        event.preventDefault();
        return;
      }
      var context = range && contextAt(quill, range.index);
      var replacementLength = context && context.cell.domNode === cell ? range.length : 0;
      var insertedLength = String(event.dataTransfer && event.dataTransfer.getData('text/plain') || '').length;
      if (insertedLength < 1 || String(cell.textContent || '').length - replacementLength + insertedLength > MAX_CELL_LENGTH) {
        event.preventDefault();
      }
    };
    state.pasteHandler = function (event) {
      var range = quill.getSelection() || state.lastRange;
      var context = range && contextAt(quill, range.index);
      var html = event.clipboardData && event.clipboardData.getData('text/html');
      var text = event.clipboardData && event.clipboardData.getData('text/plain');
      if (selectionCrossesTableBoundary(quill, range)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
      }
      if (!context) return;
      if (html && /<table[\s>]/i.test(html)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        var tableIndex = context.table.offset(quill.scroll) + context.table.length();
        var tablePaste = deltaWithExitParagraph(quill.clipboard.convert(html));
        quill.history.cutoff();
        quill.updateContents(new Delta().retain(tableIndex).concat(tablePaste), 'user');
        quill.history.cutoff();
        quill.setSelection(tableIndex, 0, 'user');
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
      var existingLength = String(context.cell.domNode.textContent || '').length;
      var availableLength = Math.max(0, MAX_CELL_LENGTH - existingLength + range.length);
      var pasted = multilineCellDelta(
        html ? quill.clipboard.convert(html) : new Delta().insert(String(text || '')),
        availableLength
      );
      quill.history.cutoff();
      var change = new Delta().retain(range.index);
      if (range.length > 0) change.delete(range.length);
      change = change.concat(pasted);
      quill.updateContents(change, 'user');
      quill.history.cutoff();
      quill.setSelection(range.index + pasted.length(), 0, 'user');
    };
    state.scrollHandler = function () { if (!state.tools.hidden) updateTools(state, quill.getSelection()); };
    state.selectionObserver = function (range) {
      if (range) state.lastRange = { index: range.index, length: range.length };
      state.selectionHandler(range);
    };
    quill.on('selection-change', state.selectionObserver);
    quill.on('text-change', state.textHandler);
    quill.root.addEventListener('click', state.clickHandler);
    quill.root.addEventListener('cut', state.cutHandler);
    quill.root.addEventListener('beforeinput', state.beforeInputHandler);
    quill.root.addEventListener('drop', state.dropHandler);
    quill.root.addEventListener('paste', state.pasteHandler, true);
    window.addEventListener('resize', state.scrollHandler);
    window.addEventListener('scroll', state.scrollHandler, true);
    installClipboardMatchers(quill);
    installKeyboard(state);
    installToolbar(state);
    if (editorStates) editorStates.set(quill, state);
    quill.__jyavaniTableState = state;
  }

  function destroy(quill) {
    if (!quill) return;
    var state = editorStates && editorStates.get(quill) || quill.__jyavaniTableState;
    if (!state) return;
    if (typeof state.closeDialog === 'function') state.closeDialog();
    quill.off('selection-change', state.selectionObserver);
    quill.off('text-change', state.textHandler);
    quill.root.removeEventListener('click', state.clickHandler);
    quill.root.removeEventListener('cut', state.cutHandler);
    quill.root.removeEventListener('beforeinput', state.beforeInputHandler);
    quill.root.removeEventListener('drop', state.dropHandler);
    quill.root.removeEventListener('paste', state.pasteHandler, true);
    window.removeEventListener('resize', state.scrollHandler);
    window.removeEventListener('scroll', state.scrollHandler, true);
    if (state.tools) state.tools.remove();
    toggleBlockControls(state, false);
    if (editorStates) editorStates.delete(quill);
    delete quill.__jyavaniTableState;
  }

  window.JyavaniQuillTable = {
    configure: configure,
    destroy: destroy,
    limits: { rows: MAX_ROWS, columns: MAX_COLUMNS, cellLength: MAX_CELL_LENGTH }
  };
})();
