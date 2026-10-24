<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$menuManager = (string)file_get_contents($root . '/dashboard/admin/menus/index.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];
$dragStartAt = strpos($menuManager, "container.addEventListener('dragstart'");
$dragOverAt = strpos($menuManager, "container.addEventListener('dragover'");
$spacingAt = strpos($menuManager, "container.classList.add('menu-drag-active')");

$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$check(str_contains($menuManager, 'className = \'menu-drop-preview\'')
    && str_contains($menuManager, 'dropDestination = { list: ul, beforeNode: beforeNode, parentTarget: parentTarget }')
    && str_contains($menuManager, 'destinationList.insertBefore(dragSrcLi, dropDestination.beforeNode)'),
    'Menu drag and drop keeps the visible indicator and final structural destination synchronized');

$check(str_contains($menuManager, "container.classList.add('menu-drag-active')")
    && str_contains($menuManager, '#menuItemsContainer.menu-drag-active .menu-item-admin {')
    && str_contains($menuManager, 'margin-top:30px;')
    && str_contains($menuManager, 'position:absolute;')
    && str_contains($menuManager, 'pointer-events:none;'),
    'Dragging creates stable gaps once while the drop indicator floats without changing document flow');

$check($dragStartAt !== false && $dragOverAt !== false && $spacingAt !== false
    && $dragStartAt < $dragOverAt
    && $spacingAt > $dragOverAt,
    'Native dragstart changes only drag state; layout spacing starts after the browser emits dragover');

$check(str_contains($menuManager, 'function closestDirectItem(ul, clientY)')
    && str_contains($menuManager, 'Math.abs(clientY - (rect.top + rect.height / 2))')
    && !str_contains($menuManager, '--menu-drop-preview-height'),
    'Pointer movement through widened gaps resolves the nearest sibling without inserting layout space');

$check(str_contains($menuManager, "li.querySelector(':scope > .menu-item-bar')")
    && str_contains($menuManager, 'var edge = rect.height * 0.3;')
    && !str_contains($menuManager, 'targetLi.getBoundingClientRect()'),
    'Drop zones are measured from the item bar instead of a parent row including descendants');

$check(str_contains($menuManager, 'dragSrcLi.contains(li)')
    && str_contains($menuManager, 'dragSrcLi.contains(ul)')
    && str_contains($menuManager, 'cleanupEmptyNestedList'),
    'Menu drag preview prevents hierarchy cycles and cleans temporary child lists');

$check(str_contains($menuManager, "__('Parent / top level - level 1')")
    && str_contains($menuManager, "__('Child of \"%s\" - level 2')")
    && str_contains($menuManager, "__('Grandchild of \"%s\" - level 3')")
    && str_contains($menuManager, "__('Descendant of \"%s\" - level %d')"),
    'Drop preview describes parent, child, grandchild, and deeper destinations');

$check(str_contains($menuManager, "__('Before \"%s\"')")
    && str_contains($menuManager, "__('After \"%s\"')")
    && str_contains($menuManager, "__('Inside \"%s\"')")
    && str_contains($menuManager, "__('At the end of this level')")
    && str_contains($menuManager, "querySelector('.menu-drop-preview-position').textContent"),
    'Drop preview names the exact insertion relation as well as its hierarchy level');

$check(str_contains($menuManager, '.menu-drop-preview-bar {')
    && str_contains($menuManager, '.menu-drop-preview::before {')
    && str_contains($menuManager, 'height:3px;')
    && str_contains($menuManager, 'border:1px solid var(--adam-primary);')
    && str_contains($menuManager, 'z-index:3;')
    && str_contains($menuManager, 'width:calc(100% - 26px);')
    && str_contains($menuManager, 'min-height:38px;')
    && str_contains($menuManager, '.menu-drop-preview-copy {')
    && str_contains($menuManager, 'display:grid;')
    && str_contains($menuManager, '.menu-item-admin.drop-parent-target > .menu-item-bar {'),
    'Drop preview keeps a wide two-line label above the insertion line and highlights its intended parent');

foreach (['Place here', 'Before "%s"', 'After "%s"', 'Inside "%s"', 'At the end of this level', 'Parent / top level - level 1', 'Child of "%s" - level 2', 'Grandchild of "%s" - level 3', 'Descendant of "%s" - level %d'] as $source) {
    $check(substr_count($translations, "'" . $source . "'") === 2, 'Translations include Indonesian and German values for: ' . $source);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Menu drag preview contract passed.\n";
