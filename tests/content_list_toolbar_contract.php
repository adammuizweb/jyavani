<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$lists = [
    'Article' => (string)file_get_contents($root . '/dashboard/admin/posts/index.php'),
    'Page' => (string)file_get_contents($root . '/dashboard/admin/pages/index.php'),
    'Theme Content' => (string)file_get_contents($root . '/dashboard/admin/themes/index.php'),
    'Category' => (string)file_get_contents($root . '/dashboard/admin/categories/index.php'),
];
$css = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

foreach ($lists as $label => $source) {
    $check(str_contains($source, 'class="adam-card posts-list-card"')
        && str_contains($source, 'class="posts-toolbar"')
        && str_contains($source, 'class="posts-toolbar-actions"'),
        $label . ' uses the shared responsive content-list shell');
    $check(substr_count($source, "do_action('admin_content_list_filters', \$listContext, \$pdo)") === 1
        && str_contains($source, 'class="posts-toolbar-extensions"'),
        $label . ' exposes one header extension slot');
    $check(str_contains($source, 'class="inp posts-search-input"')
        && str_contains($source, 'class="posts-search-submit"')
        && str_contains($source, 'class="posts-search-clear"'),
        $label . ' provides explicit search, submit, and conditional clear controls');
    $check(str_contains($source, 'class="posts-filter-disclosure')
        && str_contains($source, 'class="posts-filter-panel')
        && str_contains($source, 'class="posts-filter-chips"'),
        $label . ' uses the shared secondary-filter disclosure and removable chips');
}

$page = $lists['Page'];
$theme = $lists['Theme Content'];
$category = $lists['Category'];

$check(str_contains($page, 'form="pagesBulkForm"')
    && str_contains($theme, 'form="themesBulkForm"')
    && str_contains($category, 'form="categoriesBulkForm"'),
    'detached list checkboxes retain their resource-specific bulk form owners');
$check(str_contains($page, 'id="selectAllPages" class="adam-choice" aria-label=')
    && str_contains($theme, 'id="selectAllThemes" class="adam-choice" aria-label=')
    && str_contains($category, 'id="selectAllCategories" class="adam-choice" aria-label='),
    'Page, Theme Content, and Category place accessible select-all controls in table headings');
$check(str_contains($page, "new Set(['col-slug', 'col-created', 'col-author'])")
    && str_contains($theme, "new Set(['col-slug', 'col-public-path', 'col-created'])")
    && substr_count($page, 'class="cols-toggle"') === 2
    && substr_count($theme, 'class="cols-toggle"') === 2,
    'Page and Theme Content preserve status while hiding secondary mobile columns for every permission mode');
$check(!str_contains($category, 'class="cols-toggle"')
    && str_contains($category, 'class="posts-command-bar posts-command-bar--no-columns"'),
    'Category omits the empty Columns control and uses the compact command grid');
$check(str_contains($theme, '$canBulkUpdate =')
    && str_contains($theme, '$canBulkDelete =')
    && str_contains($theme, 'if ($canBulkDelete)')
    && str_contains($theme, 'if ($canBulkUpdate)'),
    'Theme Content bulk choices follow their independent update and delete permissions');
$check(str_contains($category, 'WHERE c.is_deleted = 0 AND ({$readCondition[\'sql\']})')
    && str_contains($category, '$filterOptionCategories')
    && str_contains($category, '$activeParentLabel')
    && str_contains($category, '$activeAuthorLabel'),
    'Category filter choices remain stable, localized, and owner-scoped');
$check(str_contains($css, '.posts-list-card .adam-table-wrapper{')
    && str_contains($css, '.posts-command-bar--no-columns{')
    && str_contains($css, '.posts-filter-panel--single{ grid-template-columns:1fr; }')
    && str_contains($css, '.posts-bulk-action,'),
    'shared content-list CSS covers edge-to-edge mobile tables and resource-specific command layouts');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content list toolbar contract check(s) failed.\n");
    exit(1);
}
echo "Content list toolbar contract passed.\n";
