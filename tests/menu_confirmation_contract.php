<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$menuManager = (string)file_get_contents($root . '/dashboard/admin/menus/index.php');
$confirmComponent = (string)file_get_contents($root . '/public/static/components/confirm/confirm.js');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$check(str_contains($menuManager, 'function askDanger(options)')
    && str_contains($menuManager, "typeof window.NewNotifConfirm.danger === 'function'")
    && str_contains($menuManager, 'return window.NewNotifConfirm.danger(options);')
    && substr_count($menuManager, 'window.confirm(') === 1,
    'Menu Manager routes destructive confirmation through the shared Core modal with one fallback');

$check(str_contains($menuManager, "title: MENU_CONFIRM_LABELS.removeItemTitle")
    && str_contains($menuManager, 'message: formatMenuConfirmLabel(MENU_CONFIRM_LABELS.removeItemMessage, label)')
    && str_contains($menuManager, "confirmText: MENU_CONFIRM_LABELS.remove")
    && str_contains($menuManager, "focus: 'cancel'")
    && str_contains($menuManager, 'if (!confirmed || !li.isConnected) return;'),
    'Removing a menu item uses a labelled danger modal and mutates the draft only after confirmation');

$check(str_contains($menuManager, "submitActionForm(document.getElementById('menu-delete-form'), null, {")
    && str_contains($menuManager, 'message: formatMenuConfirmLabel(MENU_CONFIRM_LABELS.deleteMenuMessage,')
    && str_contains($menuManager, 'var decision = confirmation ? askDanger(confirmation) : Promise.resolve(true);')
    && str_contains($menuManager, 'return confirmOtherDrafts(ownDraft);')
    && str_contains($menuManager, 'form.submit();'),
    'Deleting an entire menu confirms through Core before preserving the existing unsaved-draft guard');

$check(str_contains($confirmComponent, "danger: function(opts)")
    && str_contains($confirmComponent, "{ variant: 'danger' }")
    && str_contains($confirmComponent, "(opts.focus === 'cancel') ? state.cancelBtn : state.confirmBtn"),
    'The shared confirmation component supports the requested danger treatment and safe initial focus');

foreach (['Remove', 'Remove menu item?', 'Remove "%s" from this menu? Child items nested under it will also be removed.', 'Delete menu "%s"? All items in this menu will be deleted.'] as $source) {
    $check(substr_count($translations, "'" . $source . "'") === 2, 'Translations include Indonesian and German values for: ' . $source);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Menu confirmation contract passed.\n";
