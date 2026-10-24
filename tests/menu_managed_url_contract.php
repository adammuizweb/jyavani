<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$menuManager = (string)file_get_contents($root . '/dashboard/admin/menus/index.php');
$saveEndpoint = (string)file_get_contents($root . '/dashboard/admin/menus/items_save.php');
$urlEndpoint = (string)file_get_contents($root . '/dashboard/admin/menus/item_url.php');
$menuHelper = (string)file_get_contents($root . '/cfg/helpers/menu_helper.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$check(str_contains($menuHelper, "if (\$item['type'] === 'custom')")
    && str_contains($menuHelper, "case 'article':")
    && str_contains($menuHelper, "case 'page':")
    && str_contains($menuHelper, "case 'theme':")
    && str_contains($menuHelper, "case 'category':"),
    'Runtime menu URLs use custom input only for custom links and resolve managed content from its target');

$check(str_contains($menuManager, 'function configureItemUrlField(data, localized)')
    && str_contains($menuManager, "var isCustom = data.type === 'custom';")
    && str_contains($menuManager, 'input.readOnly = !isCustom;')
    && str_contains($menuManager, "input.classList.toggle('is-managed', !isCustom);")
    && str_contains($menuManager, 'help.hidden = isCustom;')
    && str_contains($menuManager, "input.removeAttribute('name');"),
    'The item editor makes managed URLs visibly read-only while custom links remain editable');

$check(str_contains($menuManager, "const ITEM_URL = <?= json_encode(\$base . '/admin/menus/item_url.php') ?>;")
    && str_contains($menuManager, 'function loadManagedItemUrl(li, data)')
    && str_contains($menuManager, "li.setAttribute('data-resolved-url', url);")
    && str_contains($menuManager, "document.getElementById('editItemUrl').value = url;"),
    'The editor lazily resolves and caches the current canonical managed URL');

$check(str_contains($urlEndpoint, "adiwira_require_permission(\$pdo, 'core.menus.manage', true);")
    && str_contains($urlEndpoint, "['article', 'page', 'theme', 'category']")
    && str_contains($urlEndpoint, "status = 'published'")
    && str_contains($urlEndpoint, 'menu_resolve_url')
    && str_contains($urlEndpoint, 'menu_url_is_safe'),
    'The URL resolver is permission-gated, type-bounded, and does not expose unpublished content');

$check(substr_count($menuManager, 'configureItemUrlField(data,') === 3
    && str_contains($menuManager, "var customUrl = data.type === 'custom' ? document.getElementById('editItemUrl').value.trim() : '';")
    && str_contains($menuManager, "li.setAttribute('data-url', data.type === 'custom' ? (data.url || '') : '');"),
    'Base and translated editor states cannot retain a managed URL draft');

$check(str_contains($saveEndpoint, "\$url = \$type === 'custom' ? (string)(\$item['url'] ?? '') : '';")
    && str_contains($saveEndpoint, "if (\$type !== 'custom') {")
    && str_contains($saveEndpoint, "\$translation['url'] = '';"),
    'The save endpoint discards submitted base and translated URLs for managed item types');

foreach (['Generated from the selected content', 'Current URL is unavailable', 'Resolving current URL...', 'This URL follows the selected content and cannot be changed here.', 'URL (managed automatically)'] as $source) {
    $check(substr_count($translations, "'" . $source . "'") === 2, 'Translations include Indonesian and German values for: ' . $source);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Menu managed URL contract passed.\n";
