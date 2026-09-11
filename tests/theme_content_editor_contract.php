<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$edit = (string)file_get_contents($root . '/dashboard/admin/themes/edit.php');
$save = (string)file_get_contents($root . '/dashboard/admin/themes/save.php');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($edit, "json_encode(\$saveButtonHtml, \$jsFlags)")
    && !str_contains($edit, "oldLabel || '<?= svg_ico"),
    'theme editor serializes multiline save-button SVG as a JavaScript string');
$check(str_contains($edit, 'JSON_HEX_TAG')
    && str_contains($edit, "json_encode(__('Changes to this theme partial will be saved. Continue?'), \$jsFlags)"),
    'theme editor serializes translated JavaScript values with script-safe flags');
$check(substr_count($translations, "'The server returned an invalid response.'") === 2,
    'invalid editor responses have Indonesian and German translation seeds');
$check(str_contains($save, 'SELECT id, created_by FROM posts')
    && str_contains($save, '$lockedTheme = $themeLock->fetch(PDO::FETCH_ASSOC);')
    && !str_contains($save, '$lockedOwnerId <= 0'),
    'theme save distinguishes a missing row from a legacy ownerless row');
$check(str_contains($save, "user_can(\$pdo, \$user_id, 'core.theme_content.update', ['owner_id' => \$lockedOwnerId])"),
    'ownerless theme updates still require the scoped update permission under lock');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "Theme content editor contract passed ($checks checks).\n";
