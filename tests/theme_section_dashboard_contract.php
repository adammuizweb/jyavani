<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'index' => $root . '/dashboard/admin/shortcodes/index.php',
    'editor' => $root . '/dashboard/admin/shortcodes/layout.php',
    'save' => $root . '/dashboard/admin/shortcodes/save_layout.php',
    'delete' => $root . '/dashboard/admin/shortcodes/delete_layout.php',
    'manager' => $root . '/dashboard/admin/shortcodes/_layout_manager.php',
    'preview' => $root . '/dashboard/admin/shortcodes/preview_layout.php',
    'preset' => $root . '/dashboard/admin/shortcodes/edit.php',
];
$source = [];
foreach ($files as $key => $file) {
    $source[$key] = (string)file_get_contents($file);
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($source['index'], "['collection', 'section']"), 'layouts index exposes explicit collection and section scopes');
$check(str_contains($source['index'], 'shortcode_layout_list($pdo, $layoutScope)') && str_contains($source['manager'], 'theme_section_theme_directory($pdo)'), 'section listing reads only the active-theme directory');
$check(str_contains($source['editor'], "adiwira_require_admin(\$pdo, false)"), 'PHP layout editor is restricted to administrators');
$check(str_contains($source['editor'], "do_action('shortcode_layout_editor_after_header'") && str_contains($source['editor'], "'editor_url' =>"), 'layout editor exposes a generic contextual extension hook');

foreach (['save', 'delete', 'preview'] as $endpoint) {
    $check(
        str_contains($source[$endpoint], "adiwira_require_admin(\$pdo, true)"),
        $endpoint . ' endpoint enforces administrator access for PHP template operations'
    );
}

$check(str_contains($source['save'], 'theme_section_name_is_valid($newName)'), 'new section files require a validated identifier');
$check(str_contains($source['save'], 'shortcode_layout_atomic_save($pdo') && str_contains($source['manager'], 'theme_section_theme_directory($pdo, true)'), 'section saves target the active-theme-owned directory through the atomic manager');
$check(str_contains($source['manager'], 'file_exists($target) || is_link($target)'), 'new section saves reject pre-existing symbolic links');
$check(str_contains($source['delete'], 'shortcode_layout_delete_files') && str_contains($source['manager'], 'theme_section_path_is_within($path, $directory)'), 'section deletes enforce directory containment');
$check(str_contains($source['preview'], 'adiwira_csrf_validate($csrf)'), 'PHP template preview requires CSRF validation');
$check(str_contains($source['preview'], 'theme_section_preview_document') && str_contains($source['editor'], 'data.document') && str_contains($source['editor'], "frame.setAttribute('sandbox', 'allow-same-origin')"), 'Theme Section preview loads its script-free theme-styled document with same-origin assets in a sandboxed frame');
$check(str_contains($source['editor'], '/static/js/edit/codemirror.js') && substr_count($source['editor'], 'data-pane-toggle=') >= 2, 'Theme Section editor initializes CodeMirror and exposes independent pane controls');
$check(str_contains($source['preview'], '$isValidatedPresetPreview'), 'editorial preview is limited to validated preset layouts');
$check(str_contains($source['preview'], "file_get_contents(\$layoutReal)"), 'preset preview replaces posted code with a validated layout file');
$check(strpos($source['preview'], 'file_put_contents($tmpFile, $content') > strpos($source['preview'], 'if ($isSectionScope) {'), 'editorial preset preview does not write posted PHP before validating its layout');
$check(str_contains($source['preview'], "__('Layout template not found.')"), 'preset preview fails closed when its validated layout is missing');
$check(str_contains($source['save'], "'application/json'"), 'AJAX layout saves explicitly negotiate a JSON response');
$check(str_contains($source['preview'], 'register_shutdown_function'), 'preview temporary files are removed even when the JSON responder exits');
$check(!str_contains($source['preview'], "\$_POST['file']"), 'preview does not accept a filesystem path from the request');
$check(str_contains($source['preset'], "fd.append('csrf_token'"), 'preset preview sends the required CSRF token');
$check(str_contains($source['save'], 'shortcode_layout_atomic_save($pdo') && str_contains($source['manager'], "shortcode_layout_directory(\$pdo, 'collection')") && str_contains($source['manager'], '/views/partials/shortcodes/post_cat'), 'collection saves use the shared canonical legacy directory');
$check(str_contains($source['manager'], '/views/partials/shortcodes/post_cat'), 'legacy collection delete path remains supported');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " assertion(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
