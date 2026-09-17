<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'categories' => (string)file_get_contents($root . '/dashboard/admin/categories/index.php'),
    'themes' => (string)file_get_contents($root . '/dashboard/admin/themes/index.php'),
    'users' => (string)file_get_contents($root . '/dashboard/admin/users/index.php'),
    'media' => (string)file_get_contents($root . '/dashboard/admin/media/list.php'),
    'file' => (string)file_get_contents($root . '/dashboard/admin/file/list.php'),
    'css' => (string)file_get_contents($root . '/public/static/dashboard/css/style.css'),
    'translations' => (string)file_get_contents($root . '/schema/translations.sql'),
];

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

foreach ([
    'categories' => ['Categories', 'bulkCheckboxCategory', 'Category', 'Categories'],
    'themes' => ['Themes', 'bulkCheckboxTheme', 'Theme', 'Themes'],
    'users' => ['Users', 'bulkCheckbox', 'User', 'Users'],
] as $key => [$idSuffix, $checkboxClass, $singular, $plural]) {
    $source = $sources[$key];
    $check(str_contains($source, 'id="bulkSelectionCount' . $idSuffix . '"')
        && str_contains($source, 'class="bulk-selection-count" role="status" aria-live="polite"'),
        $key . ' list exposes an accessible live selection counter');
    $check(str_contains($source, 'function updateSelectionCount()')
        && str_contains($source, 'selectAll.indeterminate = count > 0 && count < checkboxes.length')
        && str_contains($source, "cb.addEventListener('change', updateSelectionCount)"),
        $key . ' list synchronizes individual, partial, and select-all state');
    $check(str_contains($source, "document.querySelectorAll('" . '.' . $checkboxClass . "')")
        && str_contains($source, "__('" . $singular . " Selected')")
        && str_contains($source, "__('" . $plural . " Selected')"),
        $key . ' list uses resource-specific singular and plural labels');
    $check(substr_count($sources['translations'], "'" . $singular . " Selected'") >= 2
        && substr_count($sources['translations'], "'" . $plural . " Selected'") >= 2,
        $key . ' selection labels have Indonesian and German seeds');
}

$check(str_contains($sources['media'], 'class="controls"') && str_contains($sources['media'], 'id="media-table"')
    && str_contains($sources['file'], 'class="controls"') && str_contains($sources['file'], 'id="media-table"'),
    'Media and File lists share the controls-to-table structure');
$check(str_contains($sources['css'], '.media-list > .controls{ margin-bottom:.75rem; }'),
    'Media and File list controls have scoped spacing before the table');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " active list selection contract check(s) failed.\n");
    exit(1);
}
echo "Active list bulk selection contract passed.\n";
