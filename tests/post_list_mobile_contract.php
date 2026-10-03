<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$postList = (string)file_get_contents($root . '/dashboard/admin/posts/index.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];

$checks = [
    'Post List exposes a dedicated table layout scope' => str_contains($postList, 'class="adam-card posts-list-card"'),
    'Post List retains the standard left-aligned table headings' => !str_contains(
        $dashboardCss,
        '.posts-list-card .adam-table thead th'
    ),
    'Post List table consumes the card gutter on mobile' => str_contains(
        $dashboardCss,
        ".posts-list-card .adam-table-wrapper{\n    width:calc(100% + 1.5rem);\n    margin-inline:-.75rem;"
    ),
    'Post List mobile table removes its nested edge radius' => str_contains(
        $dashboardCss,
        ".posts-list-card .adam-table{\n    border-radius:0;"
    ),
    'Post List hides only secondary columns by default on mobile' => str_contains(
        $postList,
        "new Set(['col-categories', 'col-created', 'col-author'])"
    ) && !str_contains($postList, "new Set(['col-status'"),
    'Post List responsive defaults follow the mobile breakpoint' => str_contains(
        $postList,
        "window.matchMedia('(max-width: 640px)')"
    ) && str_contains($postList, 'mobileColumns.matches && mobileDefaultHidden.has(col)'),
    'Post List preserves explicit column preferences over responsive defaults' => str_contains(
        $postList,
        'Object.assign(defaultColState(), loadColState() || {})'
    ) && str_contains($postList, "mobileColumns.addEventListener('change'"),
    'Post List exposes column controls without bulk permission' => substr_count(
        $postList,
        'class="cols-toggle"'
    ) === 2 && str_contains($postList, 'class="content-list-display-controls posts-list-display-controls"'),
    'Post List separates page actions from its search-first filter form' => str_contains(
        $postList,
        'class="posts-toolbar-head"'
    ) && str_contains($postList, 'class="toolbar-filter posts-filter-shell" id="posts-list-filter"')
      && strpos($postList, 'class="posts-toolbar-actions"') < strpos($postList, 'class="toolbar-filter posts-filter-shell"'),
    'Post List places its single extension filter slot before New Article' => substr_count(
        $postList,
        "do_action('admin_content_list_filters', \$listContext, \$pdo)"
    ) === 1 && strpos($postList, 'class="posts-toolbar-extensions"') < strpos($postList, "_e('New Article')")
      && str_contains($dashboardCss, '.posts-toolbar-extensions:empty{ display:none; }'),
    'Post List heading summarizes accessible posts by editorial status' => str_contains(
        $postList,
        "\$postSummaryCounts = ['published' => 0, 'private' => 0, 'draft' => 0, 'scheduled' => 0]"
    ) && str_contains($postList, 'class="posts-toolbar-counts"')
      && str_contains($postList, 'posts-toolbar-count--published')
      && str_contains($postList, 'posts-toolbar-count--private')
      && str_contains($postList, 'posts-toolbar-count--draft')
      && str_contains($postList, "\$postSummaryCounts['scheduled'] > 0"),
    'Post List status labels expand from compact accessible number badges' => substr_count(
        $postList,
        'class="posts-toolbar-count posts-toolbar-count--'
    ) >= 5 && substr_count($postList, 'tabindex="0" aria-label=') >= 5
      && str_contains($dashboardCss, '.posts-toolbar-count:hover>span,')
      && str_contains($dashboardCss, '.posts-toolbar-count:focus-visible>span{')
      && str_contains($dashboardCss, 'max-width:8rem;')
      && str_contains($dashboardCss, '@media (prefers-reduced-motion:reduce)'),
    'Post List keeps nonzero count badges inside its title group' => str_contains(
        $postList,
        "<div class=\"posts-toolbar-title\">\n        <h2 class=\"page-heading\"><?=_e('Post')?></h2>\n        <div class=\"posts-toolbar-counts\""
    ) && str_contains($postList, 'if ($postSummaryTotal > 0)')
      && str_contains($postList, "if (\$postSummaryCounts['published'] > 0)")
      && str_contains($postList, "if (\$postSummaryCounts['private'] > 0)")
      && str_contains($postList, "if (\$postSummaryCounts['draft'] > 0)"),
    'Post List status summary follows search and category without collapsing to the active status' => strpos(
        $postList,
        '$summaryWhereSql = implode('
    ) < strpos($postList, "if (\$filter_status !== '')")
      && str_contains($postList, 'GROUP BY summary_status'),
    'Post List search has explicit submit and conditional clear actions' => str_contains(
        $postList,
        'class="posts-search-submit"'
    ) && str_contains($postList, "svg_ico('search')")
      && str_contains($postList, 'class="posts-search-clear"')
      && str_contains($postList, "\$filterUrlWithout('q')"),
    'Post List exposes secondary filters through an accessible disclosure' => str_contains(
        $postList,
        'class="posts-filter-disclosure'
    ) && str_contains($postList, '<summary class="posts-filter-trigger">')
      && str_contains($postList, "svg_ico('list-collapse')")
      && str_contains($postList, 'class="posts-filter-panel"'),
    'Post List distinguishes filter submission and renders removable active chips' => str_contains(
        $postList,
        "_e('Apply filters')"
    ) && str_contains($postList, 'class="posts-filter-chips"')
      && str_contains($postList, "\$filterUrlWithout('status')")
      && str_contains($postList, "\$filterUrlWithout('category')"),
    'Post List actions use trusted Core icons with restrained hierarchy' => str_contains(
        $postList,
        "svg_ico('plus')"
    ) && str_contains($postList, "_e('New Article')")
      && str_contains($postList, 'class="adam-att toolbar-trash posts-toolbar-trash"')
      && str_contains($dashboardCss, '.posts-toolbar-trash-label'),
    'Post List filter panel is compact on desktop and full-width on mobile' => str_contains(
        $dashboardCss,
        '.posts-filter-panel{'
    ) && str_contains($dashboardCss, 'grid-template-columns:1fr 1fr;')
      && str_contains($dashboardCss, 'width:min(360px,calc(100vw - 2rem));')
      && str_contains($dashboardCss, "min-height:34px;\n  padding:.35rem .45rem;")
      && str_contains($dashboardCss, ".posts-filter-panel{\n    right:0;\n    left:0;\n    width:auto;\n    grid-template-columns:1fr;"),
    'Post List toolbar uses compact desktop and touch-friendly mobile scales' => str_contains(
        $dashboardCss,
        '--posts-toolbar-control-size:34px;'
    ) && substr_count($dashboardCss, 'height:var(--posts-toolbar-control-size);') >= 3
       && str_contains($dashboardCss, '.posts-toolbar{ --posts-toolbar-control-size:38px; }')
       && str_contains($dashboardCss, 'grid-template-columns:minmax(180px,1fr) auto minmax(145px,190px) auto auto;'),
    'Post List places its accessible select-all control in the narrow table heading' => str_contains(
        $postList,
        '<th class="th-narrow"><?php if ($canBulk): ?><input type="checkbox" id="selectAll" class="adam-choice" aria-label='
    ) && !str_contains($postList, 'class="posts-bulk-selection"')
      && !str_contains($postList, 'id="posts-bulk-help"')
      && str_contains($postList, 'selectAll.indeterminate = count > 0 && count < checkboxes.length;'),
    'Post List keeps search, filter, bulk, apply, and Columns in one adaptive command row' => strpos(
        $postList,
        'class="posts-search-control"'
    ) < strpos($postList, 'class="posts-filter-disclosure')
      && strpos($postList, 'class="posts-filter-disclosure') < strpos($postList, 'id="bulkAction"')
      && strpos($postList, 'id="bulkAction"') < strpos($postList, 'class="adam-button posts-bulk-apply"')
      && strpos($postList, 'class="adam-button posts-bulk-apply"') < strpos($postList, 'class="posts-bulk-end"')
      && str_contains($dashboardCss, ".posts-filter-shell,\n.posts-bulk-form,\n.posts-bulk-bar{\n  display:contents;")
      && str_contains($dashboardCss, 'grid-template-columns:minmax(0,1fr) 38px minmax(86px,100px) auto 38px;')
      && str_contains($dashboardCss, '.posts-bulk-apply .lucide-icon{ display:block; }'),
    'Post List renders action-specific bulk fields in a separate contextual panel' => str_contains(
        $postList,
        'id="bulkOptionsPanel" class="posts-bulk-options" hidden'
    ) && str_contains($postList, 'id="bulkStatusOption" class="posts-bulk-option" hidden')
      && str_contains($postList, 'id="bulkCategoriesOption" class="posts-bulk-option posts-bulk-option--categories" hidden')
      && str_contains($postList, 'const activeOption = v === \'change_status\' ? bulkStatusOption')
      && str_contains($postList, 'if (bulkOptionsPanel) bulkOptionsPanel.hidden = !activeOption;')
      && str_contains($dashboardCss, '.posts-bulk-options[hidden],')
      && str_contains($dashboardCss, '.posts-bulk-option--categories{ grid-template-columns:1fr; }'),
    'Post List toolbar labels have Indonesian and German seeds' => substr_count($translations, "'Filters'") >= 2
      && substr_count($translations, "'Apply filters'") >= 2
      && substr_count($translations, "'New Article'") >= 2,
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Post List mobile contract check(s) failed.\n");
    exit(1);
}
echo "Post List mobile contract passed.\n";
