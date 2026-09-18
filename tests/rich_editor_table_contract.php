<?php
declare(strict_types=1);

$root = dirname(__DIR__);
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

$check(str_contains($table, "JyavaniTableBlot.blotName = 'table'")
    && str_contains($table, "quill.clipboard.addMatcher('TABLE'")
    && str_contains($table, "toolbar.addHandler('table'")
    && str_contains($table, 'MAX_ROWS = 12')
    && str_contains($table, 'MAX_COLUMNS = 12'),
    'local Quill table blot supports bounded insertion and pasted tables');
$check(str_contains($table, "setAttribute('contenteditable', 'false')")
    && str_contains($table, 'aria-modal="true"')
    && str_contains($table, "button.setAttribute('aria-label', i18n.title)")
    && str_contains($table, "event.key === 'Escape'")
    && str_contains($table, 'data-table-remove'),
    'table editing uses a keyboard-accessible modal instead of unsafe inline HTML editing');
$check(str_contains($table, 'var activeDialogClose = null')
    && str_contains($table, "'jy-table-dialog-title-' + (++dialogSequence)")
    && str_contains($table, "if (typeof activeDialogClose === 'function') activeDialogClose()")
    && str_contains($table, 'if (activeDialogClose === close) activeDialogClose = null'),
    'table dialogs have unique labels and one active owner across mounted editors');
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
$check(str_contains($dashboardCss, '.jy-table-dialog{')
    && str_contains($dashboardCss, '.adam-quill .ql-editor .jy-editor-table')
    && str_contains($publicCss, '.jy-editor-table'),
    'tables are usable in the dashboard and responsive on public pages');
foreach (['Insert table', 'Edit table', 'Rows', 'Columns', 'Use first row as header', 'Row %d, column %d', 'Update table', 'Remove table', 'Media Library', 'File Library'] as $key) {
    $needle = "('default', '" . str_replace("'", "''", $key) . "'";
    $check(substr_count($translations, $needle) === 2, $key . ' has Indonesian and German seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " rich editor table contract check(s) failed.\n");
    exit(1);
}
echo "Rich editor table contract passed.\n";
