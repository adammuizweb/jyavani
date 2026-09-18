<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$forms = [
    'Article add' => (string)file_get_contents($root . '/dashboard/admin/posts/add.php'),
    'Article edit' => (string)file_get_contents($root . '/dashboard/admin/posts/edit.php'),
    'Page add' => (string)file_get_contents($root . '/dashboard/admin/pages/add.php'),
    'Page edit' => (string)file_get_contents($root . '/dashboard/admin/pages/edit.php'),
];
$api = (string)file_get_contents($root . '/public/static/js/editor/core-api.js');
$save = (string)file_get_contents($root . '/public/static/js/edit/ajax_save.js');
$mode = (string)file_get_contents($root . '/public/static/js/edit/editor_mode.js');
$quill = (string)file_get_contents($root . '/public/static/js/edit/quill.js');
$guard = (string)file_get_contents($root . '/public/static/dashboard/js/unsaved-guard.js');
$css = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$readme = (string)file_get_contents($root . '/README.md');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

foreach ($forms as $label => $source) {
    $check(str_contains($source, 'data-content-editor')
        && str_contains($source, 'name="editor_mode"')
        && str_contains($source, "'quill'")
        && str_contains($source, "'codemirror'")
        && str_contains($source, 'id="content-textarea" data-editor-canonical')
        && str_contains($source, 'id="quill-area"')
        && str_contains($source, 'id="codemirror-area"')
        && str_contains($source, 'class="jy-editor-mode-picker"')
        && str_contains($source, 'class="jy-editor-mode-options"')
        && str_contains($source, '/static/js/edit/codemirror.js')
        && str_contains($source, '/static/js/edit/quill.js')
        && str_contains($source, '/static/js/edit/editor_mode.js')
        && str_contains($source, '/static/js/edit/main-init.js'),
        $label . ' exposes the shared dual-editor shell and canonical field');
    $check(str_contains($source, 'data-jyavani-editor-actions')
        && str_contains($source, "do_action('content_editor_before'")
        && str_contains($source, "do_action('content_editor_actions'")
        && str_contains($source, "do_action('content_editor_after'"),
        $label . ' exposes editor UI extension surfaces');
    $check(str_contains($source, 'window.JyavaniEditorContext')
        && str_contains($source, '/static/js/editor/core-api.js')
        && str_contains($source, "filemtime(PUBLIC_PATH . '/static/js/editor/core-api.js')"),
        $label . ' publishes bounded context and cache-busts the public editor API');
}

$check(str_contains($api, 'window.JyavaniEditor = {')
    && str_contains($api, 'version: VERSION')
    && str_contains($api, 'ready: ready')
    && str_contains($api, 'current: function ()')
    && str_contains($api, 'registerButton: registerButton')
    && str_contains($api, 'on: on'),
    'Core publishes the versioned JyavaniEditor registry');
$check(str_contains($api, 'getMode: getMode')
    && str_contains($api, 'setMode: setMode')
    && str_contains($api, 'getContent: getContent')
    && str_contains($api, 'setContent: setContent')
    && str_contains($api, 'getSelection: getSelection')
    && str_contains($api, 'setSelection: setSelection')
    && str_contains($api, 'insert: insert')
    && str_contains($api, 'sync: sync')
    && str_contains($api, 'getRevision: function'),
    'editor handles abstract content, mode, selection, synchronization, and revision');
$check(str_contains($api, "error.code = 'EDITOR_REVISION_CONFLICT'")
    && str_contains($api, "error.code = 'EDITOR_LOSSY_MODE_CHANGE'")
    && str_contains($api, "modeError.code = 'EDITOR_SELECTION_MODE_MISMATCH'")
    && str_contains($api, 'options.ifRevision = selection.revision')
    && str_contains($api, 'function announceModeChange()')
    && str_contains($api, 'announcedMode = mode;')
    && str_contains($api, "revision++;\n    emit('modechange'")
    && substr_count($api, "emit('modechange'") === 1,
    'async replacements fail closed on stale revisions, lossy modes, and mismatched selections');
$check(str_contains($mode, 'quillApi.isHtmlComplex')
    && str_contains($quill, 'function isHtmlComplex(html)')
    && str_contains($quill, "'ul', 'ol', 'li', 'a', 'img', 'span', 'table'")
    && str_contains($quill, "quillStyleProperties = new Set(['color', 'background-color'])")
    && str_contains($quill, "(tag === 'th' || tag === 'td') && element.children.length > 0")
    && !str_contains($mode, 'setTimeout(syncQuillToCM')
    && !str_contains($mode, 'setTimeout(syncCMToQuill'),
    'mode changes synchronously preserve content and reject structures Quill would normalize');
$check(str_contains($mode, "initialMode === 'quill' || initialMode === 'codemirror'")
    && substr_count(implode('', $forms), 'array_key_exists($chosenMode, $editorModes)') === 4,
    'extension-owned editor modes survive rejected form submissions');
$clearHandlerStart = strpos($mode, "document.getElementById('__warn_clear').onclick");
$clearHandlerEnd = strpos($mode, "\n  function applyEditorMode", $clearHandlerStart ?: 0);
$clearHandler = substr($mode, $clearHandlerStart ?: 0, ($clearHandlerEnd ?: strlen($mode)) - ($clearHandlerStart ?: 0));
$check(str_contains($quill, "const destructiveTags = new Set(['script', 'style', 'iframe'")
    && str_contains($quill, 'if (!quillTags.has(tag))')
    && str_contains($quill, "return isHtmlComplex(stripped) ? escapeXml(template.content.textContent || '') : stripped;")
    && str_contains($quill, 'if (!quill) {')
    && !str_contains($clearHandler, 'applyEditorMode'),
    'confirmed complex-content conversion uses the Quill allowlist and one mode-transition owner');
$check(str_contains($quill, "const alt = m.alt && String(m.alt).trim() !== '' ? String(m.alt) : String(m.title || '')")
    && str_contains($quill, "'<figure>' + imageHtml + '<figcaption>'")
    && str_contains($quill, 'data-caption'),
    'the shared Quill media handler preserves title fallback and visible captions');
$check(str_contains($quill, "quill.update('api')")
    && str_contains($quill, "['alt', 'title', 'width', 'height', 'data-caption', 'data-media-id', 'data-media-removed']")
    && str_contains($quill, "div.querySelectorAll('img')")
    && !str_contains($quill, "div.querySelectorAll('img[data-caption]')"),
    'Core media mutations advance editor revisions and restore supported image metadata');
$check(str_contains($api, "document.dispatchEvent(new CustomEvent('jyavani:editor:' + name")
    && str_contains($api, "emit('ready'")
    && str_contains($api, "emit('change'")
    && str_contains($api, "emit('modechange'"),
    'the public editor API emits stable lifecycle events');
$check(str_contains($quill, 'if (window.ADIWIRA.quill) return;')
    && str_contains((string)file_get_contents($root . '/public/static/js/edit/codemirror.js'), 'if (window.ADIWIRA.codemirror) return;'),
    'editor engine adapters remain stable when plugin dependencies repeat page-owned scripts');
$check(str_contains($api, "form.addEventListener('submit', sync, true)")
    && str_contains($save, 'window.JyavaniEditor.current')
    && str_contains($save, 'canonical.value = editor.sync()'),
    'native and AJAX saves synchronize through the editor-neutral API');
$check(str_contains($guard, 'function markDirty(form)')
    && str_contains($guard, 'markDirty: markDirty'),
    'the unsaved-change guard provides a public dirty-state bridge');
$check(str_contains($css, '.jy-editor-actions{')
    && str_contains($css, '.jy-editor-action{')
    && str_contains($css, '.jy-editor-code-wrap{'),
    'shared editor actions and CodeMirror shell are dashboard themed');
$check(str_contains($css, '--adam-sticky-header-offset: 64px;')
    && str_contains($css, ".adam-quill .ql-toolbar.ql-snow{")
    && str_contains($css, 'top: var(--adam-sticky-header-offset);')
    && substr_count($css, 'overflow: clip;') >= 2,
    'Quill toolbars remain sticky below the dashboard header without clipped ancestors');
$check(str_contains($readme, '### Content Editor API')
    && str_contains($readme, 'window.JyavaniEditor'),
    'the public plugin contract is documented');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content editor extension contract check(s) failed.\n");
    exit(1);
}

echo "Content editor extension contract passed ({$checks} checks).\n";
