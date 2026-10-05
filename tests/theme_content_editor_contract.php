<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$add = (string)file_get_contents($root . '/dashboard/admin/themes/add.php');
$edit = (string)file_get_contents($root . '/dashboard/admin/themes/edit.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$save = (string)file_get_contents($root . '/dashboard/admin/themes/save.php');
$assign = (string)file_get_contents($root . '/dashboard/admin/themes/assign.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($edit, "json_encode(\$saveButtonHtml, \$jsFlags)")
    && !str_contains($edit, "oldLabel || '<?= svg_ico"),
    'theme editor serializes multiline save-button SVG as a JavaScript string');
$check(str_contains($edit, 'JSON_HEX_TAG')
    && str_contains($edit, "json_encode(__('Changes to this theme partial will be saved. Continue?'), \$jsFlags)"),
    'theme editor serializes translated JavaScript values with script-safe flags');
$check(substr_count($translations, "'The server returned an invalid response.'") === 2,
    'invalid editor responses have Indonesian and German translation seeds');
$check(str_contains($save, 'SELECT id, status, publish_at_utc, created_by FROM posts')
    && str_contains($save, '$lockedTheme = $themeLock->fetch(PDO::FETCH_ASSOC);')
    && !str_contains($save, '$lockedOwnerId <= 0'),
    'theme save distinguishes a missing row from a legacy ownerless row');
$check(str_contains($save, "user_can(\$pdo, \$user_id, 'core.theme_content.update', ['owner_id' => \$lockedOwnerId])"),
    'ownerless theme updates still require the scoped update permission under lock');
$check(str_contains($assign, "'page' => 'admin/themes/edit'")
    && str_contains($assign, "'id' => \$postId")
    && str_contains($assign, "'return_to' => \$selfUrl")
    && str_contains($assign, 'data-post-edit')
    && str_contains($edit, 'adiwira_safe_return_to')
    && str_contains($save, 'adiwira_safe_return_to'),
    'per-slot custom-template actions use integer IDs and retain a validated return to Theme Assign');
$check(str_contains($edit, "authorization_actor(\$pdo, \$user_id)")
    && str_contains($edit, "(\$editorActor['is_site_owner'] ?? false) === true")
    && str_contains($edit, 'editor_reference_configuration($pdo')
    && str_contains($edit, "'can_edit_executable' => true"),
    'theme editor publishes executable references only for the authenticated Site Owner');
$check(str_contains($edit, "'page' => 'admin/themes/edit'")
    && str_contains($edit, "'id' => \$id")
    && str_contains($edit, "'return_to' => \$return_to")
    && str_contains($edit, 'window.ADIWIRA_EDITOR_REFERENCES = <?= json_encode($editorReferences, $jsFlags) ?>;'),
    'theme editor builds a script-safe canonical return path for reference navigation');
$check(str_contains($edit, 'jy-editor-reference-help')
    && str_contains($edit, 'Ctrl/Cmd-click or press F12'),
    'theme editor explains discoverable hover and keyboard reference navigation');
$check(substr_count($edit, '<h2 class="edit-heading">') === 2,
    'editable and read-only theme views use the standard editor heading');
$check(str_contains($add, 'class="form-toolbar theme-content-actions"')
    && str_contains($edit, 'class="form-toolbar theme-content-actions"')
    && str_contains($edit, '<div class="theme-content-actions__meta">')
    && str_contains($dashboardCss, '.theme-content-actions{')
    && str_contains($dashboardCss, 'position:sticky;')
    && str_contains($dashboardCss, 'top:calc(var(--adam-sticky-header-offset) + .5rem);')
    && str_contains($dashboardCss, '.theme-content-actions .adam-button,')
    && str_contains($dashboardCss, '.theme-content-actions__meta{')
    && !str_contains($edit, 'color:#555'),
    'theme add and edit keep save actions reachable in a themed sticky toolbar');
$check(str_contains($edit, '<div class="adam-cm-wrap">')
    && !str_contains($edit, 'border:1px solid #ddd')
    && !str_contains($edit, '<div style="margin-top:.75rem;">'),
    'theme content editor uses the shared themed CodeMirror shell without redundant spacing');

foreach ([
    'Core fallback',
    'Create active-theme override',
    'Ctrl/Cmd-click or press F12 to open this reference in a new tab.',
    'Default theme',
    'Global sections',
    'Hover over a reference for details. Ctrl/Cmd-click or press F12 to open its editor in a new tab.',
    'Open editor',
    'Resolved section',
] as $sourceText) {
    $check(substr_count($translations, "'" . str_replace("'", "''", $sourceText) . "'") === 2,
        $sourceText . ' has Indonesian and German translation seeds');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Theme content editor contract passed ($checks checks).\n";
