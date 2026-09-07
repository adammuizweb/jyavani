<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$forms = [
    'dashboard/admin/posts/add.php' => 'post-add-form',
    'dashboard/admin/posts/edit.php' => 'post-edit-form',
    'dashboard/admin/pages/add.php' => 'page-add-form',
    'dashboard/admin/pages/edit.php' => 'page-edit-form',
    'dashboard/admin/themes/add.php' => 'theme-add-form',
    'dashboard/admin/themes/edit.php' => 'theme-edit-form',
    'dashboard/admin/categories/add.php' => 'category-add-form',
    'dashboard/admin/categories/edit.php' => 'category-edit-form',
    'dashboard/admin/media/single.php' => 'media-edit-form',
    'dashboard/admin/modal_img/single_modal.php' => 'mdlib-media-edit-form',
    'dashboard/admin/file/single.php' => 'file-edit-form',
    'dashboard/admin/modal_file/single_modal.php' => 'mdlib-file-edit-form',
    'dashboard/admin/settings/site.php' => 'site-settings-form',
    'dashboard/admin/settings/email.php' => 'email-settings-form',
    'dashboard/admin/settings/auth.php' => 'auth-settings-form',
    'dashboard/admin/shortcodes/edit.php' => 'sc-form',
    'dashboard/admin/shortcodes/layout.php' => 'layout-form',
];

foreach ($forms as $path => $id) {
    $source = (string)file_get_contents($root . '/' . $path);
    $idPosition = strpos($source, 'id="' . $id . '"');
    $formMarkup = $idPosition !== false ? substr($source, max(0, $idPosition - 100), 1400) : '';
    $check($idPosition !== false && str_contains($formMarkup, 'data-unsaved-guard'),
        $id . ' opts into the shared unsaved-change guard');
}

$emailSettings = (string)file_get_contents($root . '/dashboard/admin/settings/email.php');
$emailTestPosition = strpos($emailSettings, 'id="email-test-form"');
$emailTestMarkup = $emailTestPosition !== false ? substr($emailSettings, $emailTestPosition, 300) : '';
$check($emailTestPosition !== false && !str_contains($emailTestMarkup, 'data-unsaved-guard'),
    'Send Test Email remains an action form rather than an independently guarded settings form');
$check(str_contains($emailSettings, "data-unsaved-guard-initial-dirty' : ''")
    && str_contains((string)file_get_contents($root . '/dashboard/admin/settings/auth.php'), "data-unsaved-guard-initial-dirty' : ''"),
    'Email and Auth settings preserve guard state after server-side validation errors');

$layout = (string)file_get_contents($root . '/dashboard/theme/adiwira/layout.php');
$confirmScript = strpos($layout, '/static/components/confirm/confirm.js');
$guardScript = strpos($layout, '/static/dashboard/js/unsaved-guard.js');
$check($confirmScript !== false && $guardScript !== false && $confirmScript < $guardScript,
    'the dashboard loads the shared guard after the Core confirmation component');
$check(str_contains($layout, "'badge' => __('Confirmation required')")
    && str_contains($layout, "'title' => __('Unsaved changes')")
    && str_contains($layout, "'message' => __('Discard unsaved changes?')")
    && str_contains($layout, "'confirm' => __('Discard changes')")
    && str_contains($layout, "'cancel' => __('Keep editing')"),
    'guard modal labels use existing translated Core strings');

$guard = (string)file_get_contents($root . '/public/static/dashboard/js/unsaved-guard.js');
$check(str_contains($guard, 'Array.from(form.elements || [])')
    && str_contains($guard, "new Set(['csrf_token', 'save_nonce', 'return_to', 'id', 'ajax'])")
    && str_contains($guard, 'control.disabled')
    && str_contains($guard, "name === 'content' && hasManagedContentEditor")
    && str_contains($guard, "type === 'checkbox' || type === 'radio'")
    && str_contains($guard, "type === 'select-multiple'")
    && str_contains($guard, "type === 'file'"),
    'state snapshots cover successful form controls while excluding server-only fields');
$check(str_contains($guard, "form.hasAttribute('data-unsaved-guard-initial-dirty')")
    && str_contains($guard, 'state.forcedDirty.add(form)')
    && str_contains($guard, 'state.forcedDirty.delete(target)'),
    'server-rejected form values remain dirty until a successful save');
$check(str_contains($guard, 'window.__adam_quill_instance.root.innerHTML')
    && str_contains($guard, 'editQuill.getInstance()')
    && str_contains($guard, 'helper.getInstance()')
    && str_contains($guard, "input[name=\"editor_mode\"]:checked")
    && str_contains($guard, 'if (selectedMode) return String(canonical.value);'),
    'snapshots read add/edit Quill, CodeMirror, and extension-owned canonical editor state');
$check(str_contains($guard, "document.addEventListener('click', handleClick)")
    && str_contains($guard, 'event.defaultPrevented')
    && str_contains($guard, 'event.metaKey || event.ctrlKey || event.shiftKey || event.altKey')
    && str_contains($guard, "link.hasAttribute('download')")
    && str_contains($guard, ".startsWith('#')")
    && str_contains($guard, "target !== '_self'"),
    'delegated navigation guard preserves handled, modified, download, hash, and new-context links');
$confirm = (string)file_get_contents($root . '/public/static/components/confirm/confirm.js');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$check(str_contains($guard, "badgeText: labels.badge || 'Confirmation required'")
    && str_contains($confirm, 'opts.badgeText')
    && str_contains($translations, "('default', 'Confirmation required', 'Perlu konfirmasi', 'id')")
    && str_contains($translations, "('default', 'Confirmation required', 'Bestätigung erforderlich', 'de')"),
    'the confirmation badge uses English source text with Indonesian and German seeds');
$check(str_contains($guard, 'window.NewNotifConfirm.warning(options)')
    && str_contains($guard, "focus: 'cancel'")
    && str_contains($guard, 'event.preventDefault();')
    && str_contains($guard, 'if (state.confirming) return Promise.resolve(false);')
    && str_contains($guard, 'confirmDiscardForm().then(function (confirmed)')
    && str_contains($guard, 'window.location.assign(url.href)'),
    'current-tab navigation uses one accessible Core warning modal and blocks repeated clicks before leaving');
$check(str_contains($confirm, 'ev.stopImmediatePropagation();'),
    'Escape dismisses only the active confirmation without closing its parent asset modal');
$check(str_contains($translations, "('default', 'Insert this file without saving its metadata changes?', 'Sisipkan file ini tanpa menyimpan perubahan metadatanya?', 'id')")
    && str_contains($translations, "('default', 'Insert this file without saving its metadata changes?', 'Diese Datei einfügen, ohne die Metadatenänderungen zu speichern?', 'de')")
    && str_contains($translations, "('default', 'Insert without saving', 'Sisipkan tanpa menyimpan', 'id')")
    && str_contains($translations, "('default', 'Insert without saving', 'Ohne Speichern einfügen', 'de')"),
    'File Insert warning has Indonesian and German translation seeds');
$check(str_contains($guard, "window.addEventListener('beforeunload', handleBeforeUnload)")
    && str_contains($guard, 'event.preventDefault()')
    && str_contains($guard, "event.returnValue = ''"),
    'tab close, browser close, reload, and document history use the native beforeunload guard');
$check(str_contains($guard, "form.addEventListener('submit', handleNativeSubmit)")
    && str_contains($guard, 'if (event.defaultPrevented) return;')
    && str_contains($guard, 'setTimeout(function () { state.bypass = false; }, 1000);'),
    'only uncancelled native submissions temporarily bypass the unload warning');
$check(str_contains($guard, 'setTimeout(mount, 0);'),
    'the initial baseline waits for existing DOMContentLoaded editor initializers');
$check(str_contains($guard, 'state.forms.push(form)')
    && str_contains($guard, 'baselines: new WeakMap()')
    && str_contains($guard, 'new MutationObserver(function (mutations)')
    && str_contains($guard, "node.matches('form[data-unsaved-guard]')")
    && str_contains($guard, 'function pruneForms()')
    && str_contains($guard, 'if (!state.form || state.form.isConnected) return;')
    && str_contains($guard, 'function unregister(form)'),
    'the guard registers dynamic modal forms and restores previously active guarded forms');

$ajaxSave = (string)file_get_contents($root . '/public/static/js/edit/ajax_save.js');
$check(str_contains($ajaxSave, "formEl.querySelector('input[name=\"editor_mode\"]:checked')")
    && substr_count($ajaxSave, "mode !== 'quill' && mode !== 'codemirror'") >= 3
    && str_contains($ajaxSave, 'formEl.requestSubmit();'),
    'the Core AJAX saver leaves extension-owned editor modes and native submit synchronization intact');
$postEdit = (string)file_get_contents($root . '/dashboard/admin/posts/edit.php');
$pageEdit = (string)file_get_contents($root . '/dashboard/admin/pages/edit.php');
$check(str_contains($postEdit, "if (mode !== 'quill' && mode !== 'codemirror') return;")
    && str_contains($pageEdit, "if (mode !== 'quill' && mode !== 'codemirror') return;"),
    'legacy Post and Page fallback savers also defer to extension-owned editor modes');
$categoryAdd = (string)file_get_contents($root . '/dashboard/admin/categories/add.php');
$categoryEdit = (string)file_get_contents($root . '/dashboard/admin/categories/edit.php');
$check(str_contains($categoryAdd, 'confirmed = true;')
    && str_contains($categoryAdd, 'setTimeout(function(){ form.requestSubmit(); }, 0);')
    && !str_contains($categoryAdd, 'form.submit();')
    && str_contains($categoryEdit, 'confirmed = true;')
    && str_contains($categoryEdit, 'setTimeout(function(){ form.requestSubmit(); }, 0);')
    && !str_contains($categoryEdit, 'form.submit();'),
    'Category save confirmation redispatches outside the original submit task so the guard can allow approved navigation');
$check(str_contains($ajaxSave, 'const submittedSnapshot = unsavedGuard')
    && str_contains($ajaxSave, 'unsavedGuard.capture(formEl)')
    && str_contains($ajaxSave, 'slugEl.value === submittedSlug')
    && str_contains($ajaxSave, 'unsavedGuard.markSaved(submittedSnapshot, canonicalSlug ? { slug: canonicalSlug } : null, formEl);')
    && str_contains($guard, 'function rebaseSnapshot(snapshotValue, canonicalValues)'),
    'AJAX success rebases the submitted state while preserving newer edits and canonical slug state');
$themeEdit = (string)file_get_contents($root . '/dashboard/admin/themes/edit.php');
$check(str_contains($themeEdit, 'const submittedSnapshot = unsavedGuard')
    && str_contains($themeEdit, 'unsavedGuard.capture(form)')
    && str_contains($themeEdit, 'slugField.value === submittedSlug')
    && str_contains($themeEdit, 'unsavedGuard.markSaved(submittedSnapshot, canonicalSlug ? { slug: canonicalSlug } : null, form);'),
    'Theme AJAX success also rebases submitted state and preserves edits made during the request');
$layoutEditor = (string)file_get_contents($root . '/dashboard/admin/shortcodes/layout.php');
$check(str_contains($layoutEditor, 'var submittedSnapshot = unsavedGuard')
    && str_contains($layoutEditor, 'unsavedGuard.capture(form)')
    && str_contains($layoutEditor, 'hiddenName.value === submittedName')
    && str_contains($layoutEditor, 'unsavedGuard.markSaved(submittedSnapshot, canonicalName ? { layout_name: canonicalName } : null, form);'),
    'Layout AJAX success rebases submitted PHP and the canonical layout name while preserving newer edits');

$mediaIndex = (string)file_get_contents($root . '/dashboard/admin/media/index.php');
$fileIndex = (string)file_get_contents($root . '/dashboard/admin/file/index.php');
$mediaModal = (string)file_get_contents($root . '/dashboard/admin/modal_img/single_modal.php');
$fileModal = (string)file_get_contents($root . '/dashboard/admin/modal_file/single_modal.php');
$fileSingle = (string)file_get_contents($root . '/dashboard/admin/file/single.php');
$modalHelpers = (string)file_get_contents($root . '/public/static/js/add/modal-helpers.js');
$check(str_contains($mediaIndex, 'guard.capture(form)') && str_contains($mediaIndex, 'guard.markSaved(submittedSnapshot, null, form)')
    && str_contains($fileIndex, 'guard.capture(form)') && str_contains($fileIndex, 'guard.markSaved(submittedSnapshot, null, form)')
    && str_contains($mediaModal, 'guard.capture(form)') && str_contains($mediaModal, 'guard.markSaved(submittedSnapshot, null, form)')
    && str_contains($fileModal, 'guard.capture(form)') && str_contains($fileModal, 'guard.markSaved(submittedSnapshot, null, form)')
    && str_contains($fileSingle, 'guard.capture(form)') && str_contains($fileSingle, 'guard.markSaved(submittedSnapshot, null, form)'),
    'Media and File single AJAX saves rebase the submitted form while preserving newer edits');
$check(str_contains($mediaIndex, 'guard.confirmDiscardForm(form)')
    && str_contains($mediaIndex, 'if (e.target === modalBackdrop)')
    && str_contains($mediaIndex, 'window.adamModalClose();')
    && str_contains($fileIndex, 'guard.confirmDiscardForm(form)')
    && str_contains($modalHelpers, 'guard.confirmDiscardForm(form)')
    && str_contains($mediaModal, 'guard.confirmDiscardForm(form)')
    && str_contains($mediaModal, 'guard.capture(form) !== departureSnapshot')
    && str_contains($fileModal, "__('Insert this file without saving its metadata changes?')")
    && str_contains($fileModal, "__('Insert without saving')")
    && str_contains((string)file_get_contents($root . '/dashboard/admin/modal_file/index.php'), 'guard.confirmDiscardForm(form)'),
    'single detail close, Back, and Insert paths confirm before discarding dirty metadata');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " unsaved content guard contract check(s) failed.\n");
    exit(1);
}

echo "Unsaved content guard contract passed ({$checks} checks).\n";
