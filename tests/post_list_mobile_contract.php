<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$postList = (string)file_get_contents($root . '/dashboard/admin/posts/index.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
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
    ) === 2 && str_contains($postList, '<?php if (!$canBulk): ?>'),
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
