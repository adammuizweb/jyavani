<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/editor_helpers.php';
$api = (string)file_get_contents($root . '/public/static/js/editor/core-api.js');
$quill = (string)file_get_contents($root . '/public/static/js/edit/quill.js');
$quillTable = (string)file_get_contents($root . '/public/static/vendor/quill/quill-table.js');
$registry = (string)file_get_contents($root . '/plugins/index.php');
$readme = (string)file_get_contents($root . '/README.md');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$html = content_editor_render_mount([
    'id' => 'translation-editor',
    'name' => 'translated_content',
    'mode_name' => 'translated_editor_mode',
    'value' => '<p>Safe &amp; bounded</p>',
    'initial_mode' => 'codemirror',
    'direction' => 'rtl',
    'label' => 'Translated content',
]);
$check(str_contains($html, 'id="translation-editor"')
    && str_contains($html, 'data-jyavani-editor-mount')
    && str_contains($html, 'data-editor-canonical')
    && str_contains($html, 'data-editor-quill')
    && str_contains($html, 'data-editor-codemirror'),
    'Core renders a complete scoped editor mount shell');
$check(str_contains($html, 'name="translated_content"')
    && str_contains($html, 'name="translated_editor_mode"')
    && str_contains($html, 'value="codemirror" data-editor-mode="codemirror" checked')
    && str_contains($html, 'dir="rtl"'),
    'mount shell preserves validated field, mode, and direction options');
$defaultNames = content_editor_render_mount(['id' => 'first-editor'])
    . content_editor_render_mount(['id' => 'second-editor']);
$check(str_contains($defaultNames, 'name="first-editor_editor_mode"')
    && str_contains($defaultNames, 'name="second-editor_editor_mode"'),
    'mount shells derive isolated default mode field names');
$check(str_contains($html, '&lt;p&gt;Safe &amp;amp; bounded&lt;/p&gt;')
    && !str_contains($html, '<p>Safe &amp; bounded</p>'),
    'mount shell escapes canonical content instead of trusting plugin HTML');

foreach ([
    ['id' => '../bad', 'name' => 'content'],
    ['id' => 'valid', 'name' => '../content'],
    ['id' => 'valid', 'name' => 'content', 'mode_name' => 'bad[]name'],
    ['id' => 'valid', 'name' => 'content', 'initial_mode' => 'builder'],
    ['id' => 'valid', 'name' => 'content', 'direction' => 'auto'],
] as $invalid) {
    try {
        content_editor_render_mount($invalid);
        $check(false, 'invalid mount options fail closed');
    } catch (InvalidArgumentException) {
        $check(true, 'invalid mount options fail closed');
    }
}

$check(str_contains($api, 'var VERSION = 2;')
    && str_contains($api, 'mount: mountEditor')
    && str_contains($api, 'get: mountedEditor')
    && str_contains($api, 'capabilities: { mount: true'),
    'JyavaniEditor publishes the additive schema-2 mount capability');
$check(str_contains($api, 'snapshot: function ()')
    && str_contains($api, 'markSaved: function (snapshot)')
    && str_contains($api, 'registerButton: function (action)')
    && str_contains($api, 'destroy: function ()')
    && str_contains($api, "error.code = 'EDITOR_DESTROYED'")
    && str_contains($api, "input.removeEventListener('change', handleMountedModeChange)")
    && str_contains($api, "form.removeEventListener('submit', mountedContent, true)")
    && str_contains($api, "form.removeEventListener('reset', handleMountedFormReset)"),
    'mounted handles expose scoped lifecycle and save-snapshot methods');
$check(str_contains($api, "error.code = 'EDITOR_REVISION_CONFLICT'")
    && str_contains($api, "error.code = 'EDITOR_LOSSY_MODE_CHANGE'")
    && str_contains($api, "mismatch.code = 'EDITOR_SELECTION_MODE_MISMATCH'")
    && str_contains($api, 'mountAssertRevision(selection.revision)'),
    'mounted async mutations retain revision and mode safety');
$check(str_contains($api, 'options.confirmLossy')
    && str_contains($api, 'policy.stripComplexHtml(value)')
    && str_contains($api, 'mountAssertRevision(confirmationRevision)')
    && str_contains($quill, 'stripComplexHtml: stripComplexBlocksAndReturn')
    && str_contains($quill, 'toolbarConfig: function()')
    && str_contains($quill, 'rows.length > 12')
    && str_contains($quill, 'cells.length > 12')
    && str_contains($quill, 'tableCellNormalizedLength(cell) > 2000')
    && !str_contains(substr($quill, strpos($quill, 'const quillTags'), 500), "'hr'")
    && substr_count($quill, "tag === 'a' && name === 'target'") === 2,
    'mounted mode conversion shares the Core Quill policy and toolbar');
$check(str_contains($api, 'adapters.pickMedia')
    && str_contains($api, 'adapters.pickFile')
    && str_contains($api, 'pickerContext: Object.assign({}, options.mediaContext || {})')
    && str_contains($api, 'restoreMountedImageAttributes')
    && str_contains($api, 'data-media-id')
    && str_contains($api, 'mountedSetSelection({ mode: currentMode, from: end, to: end })'),
    'mounted toolbar exposes contextual media and file adapters');
$check(str_contains($quillTable, 'destroy: destroy')
    && str_contains($quillTable, "quill.off('selection-change', state.selectionObserver)")
    && str_contains($quillTable, "quill.root.removeEventListener('click', state.clickHandler)")
    && str_contains($api, 'window.JyavaniQuillTable.destroy(quill)'),
    'destroying a mount removes its table controls and event listeners');

$dependencyAt = strpos($registry, "'content-editor' => [");
$assetLoopAt = strpos($registry, "foreach (['css', 'js'] as \$type)", $dependencyAt ?: 0);
$check($dependencyAt !== false
    && str_contains(substr($registry, $dependencyAt, 500), "'/static/js/edit/codemirror.js'")
    && str_contains(substr($registry, $dependencyAt, 500), "'/static/js/edit/quill.js'")
    && str_contains(substr($registry, $dependencyAt, 500), "'/static/js/editor/core-api.js'")
    && !str_contains(substr($registry, $dependencyAt, 500), 'ajax_save.js'),
    'content-editor dependency publishes only reusable Core editor assets');
$check($dependencyAt !== false && $assetLoopAt !== false
    && strpos($registry, "foreach (\$p['dependencies']['js']", $dependencyAt) < $assetLoopAt,
    'Core dependencies are emitted before plugin JavaScript assets');
$check(str_contains($registry, "if (is_file(\$file)) \$url .= '?v=' . filemtime(\$file)")
    && str_contains($registry, "dirname(__DIR__) . '/public'"),
    'Core dependency assets use filesystem-derived cache versions');
$check(str_contains($registry, 'function plugin_core_js_dependency_assets(): array')
    && str_contains($registry, "'Invalid plugin Core JavaScript dependency'")
    && str_contains($registry, 'count($jsDependencies) > 16'),
    'plugin manifests accept only a bounded list of known Core JavaScript dependencies');
$check(str_contains($readme, 'content_editor_render_mount()')
    && str_contains($readme, 'JyavaniEditor.mount(root, options)')
    && str_contains($readme, 'guard?.markSaved(guardSnapshot')
    && str_contains($readme, 'never authorization'),
    'plugin-facing mount, persistence, and authorization boundaries are documented');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content editor mount contract check(s) failed.\n");
    exit(1);
}
echo "Content editor mount contract passed ({$checks} checks).\n";
