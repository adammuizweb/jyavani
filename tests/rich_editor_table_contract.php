<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/cms_content.php';
$table = (string)file_get_contents($root . '/public/static/vendor/quill/quill-table.js');
$edit = (string)file_get_contents($root . '/public/static/js/edit/quill.js');
$mode = (string)file_get_contents($root . '/public/static/js/edit/editor_mode.js');
$layout = (string)file_get_contents($root . '/dashboard/theme/adiwira/layout.php');
$postAdd = (string)file_get_contents($root . '/dashboard/admin/posts/add.php');
$pageAdd = (string)file_get_contents($root . '/dashboard/admin/pages/add.php');
$postEdit = (string)file_get_contents($root . '/dashboard/admin/posts/edit.php');
$pageEdit = (string)file_get_contents($root . '/dashboard/admin/pages/edit.php');
$sanitizer = (string)file_get_contents($root . '/cfg/helpers/cms_content.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$publicCss = (string)file_get_contents($root . '/public/static/vendor/quill/quill.snow.pub.css');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($table, "JyavaniTableContainerBlot.blotName = 'table'")
    && str_contains($table, "JyavaniTableCellBlot.blotName = 'table-cell'")
    && str_contains($table, "class JyavaniTableCellBlot extends Block")
    && substr_count($table, 'extends Container') === 3
    && str_contains($table, "quill.clipboard.addMatcher('TH'")
    && str_contains($table, "quill.clipboard.addMatcher('TD'")
    && str_contains($table, "quill.clipboard.addMatcher('TABLE'")
    && str_contains($table, "toolbar.addHandler('table'")
    && str_contains($table, 'MAX_ROWS = 12')
    && str_contains($table, 'MAX_COLUMNS = 12'),
    'local Quill table hierarchy supports bounded editable insertion and formatted pasted tables');
$check(!str_contains($table, "setAttribute('contenteditable', 'false')")
    && str_contains($table, 'aria-modal="true"')
    && str_contains($table, "['row-above', i18n.rowAbove]")
    && str_contains($table, "['column-before', i18n.columnBefore]")
    && str_contains($table, "prependBinding(quill, { key: 9")
    && str_contains($table, "{ key: 13, shiftKey: null, altKey: null, ctrlKey: null, metaKey: null }")
    && str_contains($table, "quill.on('text-change', state.textHandler)"),
    'table cells edit directly in Quill with contextual structure controls and guarded keyboard boundaries');
$check(str_contains($table, "new Parchment.Attributor.Class('table-break'")
    && str_contains($table, "new Parchment.Attributor.Class('table-heading'")
    && str_contains($table, "new Parchment.Attributor.Class('table-list'")
    && str_contains($table, "insertLineMarker(quill, range.index, 'table-break', '1')")
    && str_contains($table, "formatCellSegments(state, 'table-heading', value)")
    && str_contains($table, "formatCellSegments(state, 'table-list'")
    && str_contains($table, 'function multilineCellDelta(delta, maximumLength)')
    && str_contains($table, 'MAX_CELL_LENGTH - existingLength + range.length')
    && str_contains($table, "addEventListener('beforeinput', state.beforeInputHandler)")
    && str_contains($table, "addEventListener('drop', state.dropHandler)")
    && str_contains($table, 'function listMarker(quill, segment)'),
    'Enter, heading, and ordered or bullet list formatting stay within the active table cell');
$check(str_contains($table, 'return multilineCellDelta(delta, MAX_CELL_LENGTH)')
    && str_contains($table, ".insert('\\n', { 'table-cell': cloneMeta(meta) })")
    && str_contains($edit, 'function isTableCellContentSupported(cell)')
    && str_contains($edit, "tag === 'ol' || tag === 'ul'")
    && str_contains($edit, "item.tagName.toLowerCase() === 'li'")
    && substr_count($edit, '!isTableCellContentSupported(element)') === 2,
    'semantic cell lists use the multiline table converter and remain eligible for Rich Editor mode');
$check(str_contains($table, 'var editorStates = typeof WeakMap')
    && str_contains($table, 'quill.__jyavaniTableConfigured = true')
    && str_contains($table, 'quill.off(\'selection-change\', state.selectionObserver)')
    && str_contains($table, "quill.root.removeEventListener('click', state.clickHandler)")
    && str_contains($table, "quill.root.removeEventListener('paste', state.pasteHandler, true)")
    && str_contains($table, 'if (state.tools) state.tools.remove()'),
    'table controls and listeners are isolated and removed for each mounted editor');
$check(str_contains($edit, 'function tableCellNormalizedLength(cell)')
    && str_contains($edit, 'String(cell.textContent || \'\').length + items + Math.max(0, items - 1)')
    && str_contains($edit, 'function tableCellPlainText(cell)')
    && str_contains($edit, "join('\\n')"),
    'table import bounds normalized list markers and keeps readable separators in lossy fallbacks');
$check(str_contains($table, 'function selectionCrossesTableBoundary')
    && str_contains($table, 'var endContext = contextAt(quill, range.index + range.length)')
    && str_contains($table, 'startContext.cell !== endContext.cell')
    && str_contains($table, "{ key: 8, shiftKey: null, altKey: null, ctrlKey: null, metaKey: null }")
    && str_contains($table, "{ key: 46, shiftKey: null, altKey: null, ctrlKey: null, metaKey: null }")
    && str_contains($table, 'event.stopImmediatePropagation()')
    && str_contains($table, "addEventListener('paste', state.pasteHandler, true)"),
    'modified deletion, cross-cell cut, and nested table paste cannot bypass table boundaries');
$check(str_contains($table, "quill.updateContents(delta, 'user')")
    && str_contains($table, 'quill.history.cutoff()')
    && str_contains($table, "state.insertedTables[tableId] = true")
    && str_contains($table, "quill.setContents(new Delta().insert('\\n'), 'silent')"),
    'table insertion and structure changes participate in Quill history and restore an ordinary empty document');
$check(str_contains($edit, "['link','image','video','table']")
    && str_contains($edit, 'JyavaniQuillTable.configure')
    && str_contains($postAdd, '/static/js/edit/quill.js')
    && str_contains($pageAdd, '/static/js/edit/quill.js')
    && str_contains($postEdit, '/static/js/edit/quill.js')
    && str_contains($pageEdit, '/static/js/edit/quill.js'),
    'all Article and Page editors use the shared Quill table integration');
$check(!is_file($root . '/public/static/js/add/quill-init.js')
    && substr_count($postAdd . $pageAdd, '<script src="/static/js/edit/codemirror.js"') === 2
    && substr_count($postAdd . $pageAdd, '<script src="/static/js/edit/editor_mode.js') === 2
    && substr_count($postAdd . $pageAdd, '<script src="/static/js/editor/core-api.js') === 2
    && substr_count($postAdd . $pageAdd, '<script src="/static/js/edit/main-init.js"') === 2,
    'Article and Page Add use the shared dual-editor runtime without a Quill-only fallback');
$check(!preg_match('/complexPattern[\s\S]{0,180}table/', $edit)
    && !preg_match('/complexPattern[\s\S]{0,180}table/', $mode),
    'saved tables remain editable in Rich Editor mode');
$check(str_contains($edit, "!(op.attributes && op.attributes['table-cell'])"),
    'blank-line cleanup preserves one structural newline for every table cell');
$check(str_contains($layout, '/static/vendor/quill/quill-table.js')
    && str_contains($layout, "filemtime(PUBLIC_PATH . '/static/vendor/quill/quill-table.js')")
    && str_contains($layout, 'window.jyavaniTableEditorI18n'),
    'dashboard cache-busts the local table module and provides translated labels');
$check(str_contains($edit, "modal_file/index.php?embedded=1")
    && str_contains($edit, "modal_img/index.php?embedded=1")
    && str_contains($table, "querySelector('.ql-video')")
    && str_contains($table, 'fileButton.innerHTML')
    && str_contains($table, 'i18n.fileLibrary'),
    'former video control uses a file icon and opens File Library while image opens Media Library');
$check(str_contains($postAdd, "filemtime(PUBLIC_PATH . '/static/js/edit/quill.js')")
    && str_contains($pageAdd, "filemtime(PUBLIC_PATH . '/static/js/edit/quill.js')")
    && str_contains($postEdit, "filemtime(PUBLIC_PATH . '/static/js/edit/quill.js')")
    && str_contains($pageEdit, "filemtime(PUBLIC_PATH . '/static/js/edit/quill.js')")
    && substr_count($postAdd . $pageAdd . $postEdit . $pageEdit, "filemtime(PUBLIC_PATH . '/static/js/editor/core-api.js')") === 4,
    'all post and page editors cache-bust their shared editor assets');
$check(str_contains($sanitizer, "'table','thead','tbody','tfoot','tr','th','td'")
    && str_contains($sanitizer, "'th' => ['colspan','rowspan']")
    && str_contains($sanitizer, "'td' => ['colspan','rowspan']"),
    'restricted HTML sanitizer preserves safe table structure and spans');
$sanitizedTable = cms_sanitize_restricted_html('<table class="jy-editor-table" onclick="bad()"><tbody><tr><th><strong>Head</strong></th><td><a href="https://example.com" target="_blank"><em>Value</em></a><script>bad()</script></td></tr></tbody></table>');
$check(str_contains($sanitizedTable, '<table class="jy-editor-table">')
    && str_contains($sanitizedTable, '<strong>Head</strong>')
    && str_contains($sanitizedTable, '<em>Value</em>')
    && str_contains($sanitizedTable, 'rel="noopener noreferrer"')
    && !str_contains($sanitizedTable, 'onclick')
    && !str_contains($sanitizedTable, '<script'),
    'restricted saves retain formatted table cells while removing executable markup');
$sanitizedCellList = cms_sanitize_restricted_html('<table><tbody><tr><th>Programs</th><td><ol><li>One <strong>A</strong></li><li>Two</li></ol></td></tr></tbody></table>');
$check(str_contains($sanitizedCellList, '<ol>')
    && substr_count($sanitizedCellList, '<li>') === 2
    && str_contains($sanitizedCellList, '<strong>A</strong>'),
    'restricted saves retain semantic ordered lists and inline formatting inside table cells');
$check(str_contains($dashboardCss, '.jy-table-dialog{')
    && str_contains($dashboardCss, '.jy-table-tools{')
    && str_contains($dashboardCss, '.jy-table-list-ordered::before')
    && str_contains($publicCss, '.jy-editor-table .jy-table-heading-1')
    && str_contains($dashboardCss, '.adam-quill .ql-editor .jy-editor-table')
    && str_contains($publicCss, '.jy-editor-table'),
    'tables are usable in the dashboard and responsive on public pages');
foreach (['Insert table', 'Rows', 'Columns', 'Use first row as header', 'Update table', 'Remove table', 'Table actions', 'Table settings', 'Add row above', 'Add row below', 'Delete row', 'Add column before', 'Add column after', 'Delete column', 'Reducing rows or columns will remove cell content. Continue?', 'Media Library', 'File Library'] as $key) {
    $needle = "('default', '" . str_replace("'", "''", $key) . "'";
    $check(substr_count($translations, $needle) >= 2, $key . ' has Indonesian and German seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " rich editor table contract check(s) failed.\n");
    exit(1);
}
echo "Rich editor table contract passed.\n";
