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
    'dashboard/admin/posts/add.php' => 'post-add',
    'dashboard/admin/posts/edit.php' => 'post-edit',
    'dashboard/admin/pages/add.php' => 'page-add',
    'dashboard/admin/pages/edit.php' => 'page-edit',
    'dashboard/admin/themes/add.php' => 'theme-add',
    'dashboard/admin/themes/edit.php' => 'theme-edit',
];

foreach ($forms as $path => $prefix) {
    $source = (string)file_get_contents($root . '/' . $path);
    $titlePosition = strpos($source, 'name="title"');
    $titleMarkup = $titlePosition !== false ? substr($source, max(0, $titlePosition - 300), 700) : '';
    $check($titlePosition !== false
        && str_contains($titleMarkup, 'field-required')
        && str_contains($titleMarkup, "_e('Required')")
        && str_contains($titleMarkup, 'required')
        && str_contains($titleMarkup, 'aria-required="true"'),
        $prefix . ' marks Title as visually and semantically required');

    $check(substr_count($source, 'class="field-required"') >= 2
        && str_contains($source, "_e('Content")
        && str_contains($source, "_e('Required')"),
        $prefix . ' marks Content as required');

    $tooltipId = $prefix . '-slug-help';
    $slugId = $prefix . '-slug';
    $tooltipText = str_starts_with($prefix, 'theme-')
        ? "__('An internal slug is a stable identifier for this theme partial. The public path below controls its web address.')"
        : "__('A slug is the URL-friendly part of the web address. Leave it empty to generate one automatically from the title.')";
    $check(str_contains($source, '<label for="' . $slugId . '">')
        && str_contains($source, 'id="' . $slugId . '"')
        && str_contains($source, 'name="slug"')
        && str_contains($source, 'class="field-help__trigger"')
        && str_contains($source, 'aria-describedby="' . $tooltipId . '"')
        && str_contains($source, 'aria-controls="' . $tooltipId . '"')
        && str_contains($source, 'aria-expanded="false"')
        && str_contains($source, 'id="' . $tooltipId . '"')
        && str_contains($source, 'role="tooltip"')
        && str_contains($source, "htmlspecialchars(__('What is a slug?'), ENT_QUOTES, 'UTF-8')")
        && str_contains($source, $tooltipText),
        $prefix . ' provides accessible novice-friendly slug help');

    $contentLabelId = $prefix . '-content-label';
    $check(str_contains($source, 'id="' . $contentLabelId . '" class="field-label" data-required-editor-label'),
        $prefix . ' identifies the required Content label for the active editor');
}

$usesSharedInput = static function (string $source, string $name): bool {
    $position = strpos($source, 'name="' . $name . '"');
    if ($position === false) return false;
    return preg_match('/class="[^"]*\binp\b[^"]*"/', substr($source, max(0, $position - 120), 700)) === 1;
};

foreach ([
    'post-edit' => 'dashboard/admin/posts/edit.php',
    'page-edit' => 'dashboard/admin/pages/edit.php',
] as $label => $path) {
    $source = (string)file_get_contents($root . '/' . $path);
    $check($usesSharedInput($source, 'created_by')
        && $usesSharedInput($source, 'created_at')
        && $usesSharedInput($source, 'updated_at')
        && substr_count($source, 'class="field-note"') >= 3,
        $label . ' metadata controls and guidance use shared theme classes');
}

$pageAdd = (string)file_get_contents($root . '/dashboard/admin/pages/add.php');
$check($usesSharedInput($pageAdd, 'created_at')
    && $usesSharedInput($pageAdd, 'updated_at'),
    'page-add date metadata controls use the shared theme class');

foreach (['page-add' => $pageAdd, 'page-edit' => (string)file_get_contents($root . '/dashboard/admin/pages/edit.php')] as $label => $source) {
    $check($usesSharedInput($source, 'sidebar_override')
        && $usesSharedInput($source, 'meta_description')
        && str_contains($source, 'class="field-note"'),
        $label . ' sidebar and metadata fields use shared theme classes');
}

$css = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$check(str_contains($css, '.field-required{')
    && str_contains($css, '.field-help__trigger:focus-visible{')
    && str_contains($css, '.field-help[data-open="true"] .field-help__tooltip{')
    && str_contains($css, '.field-help__tooltip.field-help__tooltip--portal{')
    && str_contains($css, 'z-index:2147483647;'),
    'required indicators and slug help support dashboard theming, hover, and keyboard focus');

$guidance = (string)file_get_contents($root . '/public/static/dashboard/js/field-guidance.js');
$check(str_contains($guidance, "event.key !== 'Escape'")
    && str_contains($guidance, "document.addEventListener('mouseover'")
    && str_contains($guidance, "document.addEventListener('mouseout'")
    && str_contains($guidance, "editor.setAttribute('aria-labelledby', label.id)")
    && str_contains($guidance, "editor.setAttribute('aria-required', 'true')")
    && str_contains($guidance, 'document.body.appendChild(tooltip)')
    && str_contains($guidance, 'tooltip.__fieldHelpOwner = help')
    && str_contains($guidance, 'scheduleHelpClose(portal.__fieldHelpOwner)')
    && str_contains($guidance, 'const tooltipWidth = tooltip.offsetWidth;')
    && str_contains($guidance, 'window.innerWidth - tooltipWidth - edge')
    && str_contains($guidance, 'new MutationObserver(labelRequiredEditors)'),
    'field guidance supports Escape, interactive portaled tooltips, and asynchronously initialized editors');

$translations = (string)file_get_contents($root . '/schema/translations.sql');
foreach ([
    "('default', 'Required', 'Wajib', 'id')",
    "('default', 'Required', 'Erforderlich', 'de')",
    "('default', 'What is a slug?', 'Apa itu slug?', 'id')",
    "('default', 'What is a slug?', 'Was ist ein Slug?', 'de')",
    "('default', 'A slug is the URL-friendly part of the web address. Leave it empty to generate one automatically from the title.', 'Slug adalah bagian alamat web yang ramah URL. Biarkan kosong untuk membuatnya otomatis dari judul.', 'id')",
    "('default', 'A slug is the URL-friendly part of the web address. Leave it empty to generate one automatically from the title.', 'Ein Slug ist der URL-freundliche Teil der Webadresse. Lassen Sie das Feld leer, um ihn automatisch aus dem Titel zu erstellen.', 'de')",
    "('default', 'An internal slug is a stable identifier for this theme partial. The public path below controls its web address.', 'Slug internal adalah pengenal tetap untuk partial tema ini. Jalur publik di bawah mengatur alamat webnya.', 'id')",
    "('default', 'An internal slug is a stable identifier for this theme partial. The public path below controls its web address.', 'Ein interner Slug ist eine stabile Kennung für dieses Theme-Partial. Der öffentliche Pfad darunter bestimmt seine Webadresse.', 'de')",
] as $row) {
    $check(substr_count($translations, $row) === 1, 'translation seed exists exactly once: ' . $row);
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " content editor guidance contract check(s) failed.\n");
    exit(1);
}

echo "Content editor guidance contract passed ({$checks} checks).\n";
