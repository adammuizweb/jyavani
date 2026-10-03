<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$users = (string)file_get_contents($root . '/dashboard/admin/users/index.php');
$roles = (string)file_get_contents($root . '/dashboard/admin/users/roles/index.php');
$menus = (string)file_get_contents($root . '/dashboard/admin/menus/index.php');
$sidebar = (string)file_get_contents($root . '/dashboard/admin/settings/sidebar.php');
$sidebarManager = (string)file_get_contents($root . '/dashboard/admin/sidebar/index.php');
$site = (string)file_get_contents($root . '/dashboard/admin/settings/site.php');
$assetBin = (string)file_get_contents($root . '/dashboard/admin/bin/_asset_index.php');
$shortcodes = (string)file_get_contents($root . '/dashboard/admin/shortcodes/index.php');
$themes = (string)file_get_contents($root . '/dashboard/admin/themes/index.php');
$failures = [];

$checks = [
    'Shared dashboard choice controls define complete themed states' => str_contains($css, '.adam-choice{')
        && str_contains($css, 'appearance:none;')
        && str_contains($css, '.adam-choice[type="checkbox"]::before{')
        && str_contains($css, '.adam-choice[type="radio"]::before{')
        && str_contains($css, '.adam-choice:indeterminate{')
        && str_contains($css, '.adam-choice:focus-visible{')
        && str_contains($css, '@media (forced-colors:active){'),
    'Unchecked and disabled shared choices use neutral theme surfaces' => str_contains($css, 'border:1.5px solid var(--adam-border-2);')
        && str_contains($css, 'background:var(--adam-surface-2);')
        && str_contains($css, '.adam-choice:disabled{')
        && str_contains($css, 'border-color:var(--adam-border);')
        && str_contains($css, 'background:var(--adam-surface-3);'),
    'User Management selection and column controls use the shared choice' => substr_count($users, 'adam-choice') >= 7
        && str_contains($users, 'id="selectAll" class="adam-choice"')
        && str_contains($users, 'class="bulkCheckbox adam-choice"'),
    'Role Manager permission controls use the shared choice' => substr_count($roles, 'authz-permission-toggle adam-choice') === 2,
    'Menu Manager target controls use the shared choice' => substr_count($menus, 'type="checkbox" class="adam-choice"') === 2,
    'Menu Manager moves row actions below labels on mobile without widening the grid' => substr_count($menus, 'class="menu-item-actions"') === 2
        && str_contains($menus, '.menus-grid > * {')
        && str_contains($menus, 'grid-template-columns:auto minmax(0,1fr) auto;')
        && str_contains($menus, 'grid-column:1 / -1;')
        && str_contains($menus, 'white-space:normal;'),
    'Menu Manager uses accessible Core Lucide actions with mobile touch targets' => str_contains($menus, "svg_ico('eye')")
        && str_contains($menus, "svg_ico('eye-off')")
        && str_contains($menus, "svg_ico('chevron-right')")
        && str_contains($menus, "svg_ico('chevron-left')")
        && str_contains($menus, "svg_ico('pen')")
        && str_contains($menus, "svg_ico('trash-2')")
        && str_contains($menus, 'aria-label=')
        && str_contains($menus, 'width:44px;')
        && str_contains($menus, 'height:44px;')
        && !preg_match('/&#(?:128064|128065|8594|8592|9998|10005|9776);/', $menus),
    'Menu Manager keeps the mobile item editor centered within the dynamic viewport' => str_contains($menus, '.menu-item-edit-modal{ align-items:center;padding:12px; }')
        && str_contains($menus, 'max-height:calc(100dvh - 24px);')
        && !str_contains($menus, 'align-items:flex-end'),
    'Menu Manager places creation tools between the active menu context and item list on mobile' => str_contains($menus, 'class="menus-structure"')
        && str_contains($menus, 'class="menus-tools"')
        && str_contains($menus, '.menus-tools{ display:contents; }')
        && str_contains($menus, '#menu-add-item-form{ order:3; }')
        && str_contains($menus, '.menu-create-card{ order:4;')
        && str_contains($menus, '.menus-empty-state{ order:5; }'),
    'Menu Manager reveals its isolated create form only from the selector' => str_contains($menus, '<option value="__create__">+')
        && str_contains($menus, 'class="menu-create-card"')
        && str_contains($menus, 'background:var(--adam-surface-4);" hidden>')
        && str_contains($menus, 'id="cancelCreateMenu"')
        && str_contains($menus, "createMenuCard.hidden = !active;")
        && str_contains($menus, "menusGrid.classList.toggle('is-create-mode', active)")
        && str_contains($menus, '.menus-grid.is-create-mode #menu-add-item-form,')
        && str_contains($menus, '.menus-grid.is-create-mode #menuItemsContainer,'),
    'Menu Manager inline icon and translation data cannot terminate its script element' => substr_count($menus, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT') >= 3,
    'Sidebar Settings checkbox and radio controls use shared themed choices' => substr_count($sidebar, 'class="adam-choice"') === 3
        && str_contains($sidebar, 'class="adam-choice adam-choice--danger"')
        && !str_contains($sidebar, 'accent-color:var(--adam-primary)')
        && !str_contains($sidebar, 'accent-color:var(--adam-danger)'),
    'Sidebar Manager separates widget identity and actions on mobile' => str_contains($sidebarManager, 'class="sw-header-main"')
        && str_contains($sidebarManager, 'class="sw-header-actions"')
        && str_contains($sidebarManager, '@media (max-width:600px)')
        && str_contains($sidebarManager, '.sw-header { flex-direction:column;')
        && str_contains($sidebarManager, '.sw-badge { max-width:52%;white-space:normal !important;')
        && str_contains($sidebarManager, '.sw-up,.sw-down,.sw-delete { width:44px;height:44px;'),
    'Sidebar Manager widget expander exposes and updates accessible state' => str_contains($sidebarManager, 'aria-controls="sw-body-<?= $itemId ?>"')
        && str_contains($sidebarManager, 'id="sw-body-<?= $itemId ?>"')
        && str_contains($sidebarManager, "header.setAttribute('aria-expanded', expanded ? 'true' : 'false');"),
    'Site Settings radios use shared choices without changing slider switches' => substr_count($site, 'type="radio" class="adam-choice"') === 2
        && substr_count($site, 'class="metatags-toggle"') >= 3
        && !preg_match('/<input[^>]*class="adam-choice"[^>]*id="(?:content_scheduling_enabled|search_engines_enabled|enable_custom_meta)"/', $site),
    'Shared asset Bin selection controls use the themed choice' => str_contains($assetBin, 'id="assetBinSelectAll" class="adam-choice"')
        && str_contains($assetBin, 'class="asset-bin-checkbox adam-choice"'),
    'Shortcode Preset and Collection Layout controls use shared themed choices' => str_contains($shortcodes, 'id="preset-select-all" class="adam-choice"')
        && str_contains($shortcodes, 'class="preset-row-check adam-choice"')
        && str_contains($shortcodes, 'id="layout-select-all" class="adam-choice adam-choice--danger"')
        && str_contains($shortcodes, 'class="layout-row-check adam-choice adam-choice--danger"')
        && str_contains($shortcodes, 'class="adam-choice" disabled'),
    'Theme Manager selection and column controls use shared themed choices' => str_contains($themes, 'id="selectAllThemes" class="adam-choice"')
        && str_contains($themes, 'class="bulkCheckboxTheme adam-choice"')
        && substr_count($themes, 'class="adam-choice" data-col=') === 8,
];

$completeChoiceSurfaces = [
    'File list' => 'dashboard/admin/file/list.php',
    'File add' => 'dashboard/admin/file/add.php',
    'File detail' => 'dashboard/admin/file/single.php',
    'Media list' => 'dashboard/admin/media/list.php',
    'Media add' => 'dashboard/admin/media/add.php',
    'Media detail' => 'dashboard/admin/media/single.php',
    'Page list' => 'dashboard/admin/pages/index.php',
    'Page add' => 'dashboard/admin/pages/add.php',
    'Page edit' => 'dashboard/admin/pages/edit.php',
    'Category list' => 'dashboard/admin/categories/index.php',
    'Post list' => 'dashboard/admin/posts/index.php',
    'Post add' => 'dashboard/admin/posts/add.php',
    'Post edit' => 'dashboard/admin/posts/edit.php',
    'Theme Assign' => 'dashboard/admin/themes/assign.php',
    'File modal upload' => 'dashboard/admin/modal_file/add_modal.php',
    'File modal detail' => 'dashboard/admin/modal_file/single_modal.php',
    'Media modal upload' => 'dashboard/admin/modal_img/add_modal.php',
    'Media modal detail' => 'dashboard/admin/modal_img/single_modal.php',
];
foreach ($completeChoiceSurfaces as $label => $path) {
    $source = (string)file_get_contents($root . '/' . $path);
    preg_match_all('/<input\b[^>]*\btype="(?:checkbox|radio)"[^>]*>/i', $source, $matches);
    $controls = $matches[0] ?? [];
    $checks[$label . ' has no unthemed checkbox or radio controls'] = $controls !== []
        && array_reduce(
            $controls,
            static fn(bool $valid, string $control): bool => $valid && str_contains($control, 'adam-choice'),
            true
        );
}

$binContracts = [
    'article' => ['selectAllBinArticle', 'bulkCheckboxBinArticle adam-choice'],
    'page' => ['selectAllBinPage', 'bulkCheckboxBinPage adam-choice'],
    'category' => ['selectAllBinCategory', 'bulkCheckboxBinCategory adam-choice'],
    'theme' => ['selectAllBinTheme', 'bulkCheckboxBinTheme adam-choice'],
    'users' => ['selectAllBinUsers', 'bulkCheckboxBinUsers adam-choice'],
];
foreach ($binContracts as $folder => [$selectAllId, $rowClass]) {
    $source = (string)file_get_contents($root . '/dashboard/admin/bin/' . $folder . '/index.php');
    $checks[ucfirst($folder) . ' Bin uses shared themed selection controls'] = str_contains($source, 'id="' . $selectAllId . '" class="adam-choice"')
        && str_contains($source, 'class="' . $rowClass . '"');
}

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " dashboard choice theme contract check(s) failed.\n");
    exit(1);
}

echo "Dashboard choice theme contract passed.\n";
