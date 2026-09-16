<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$widgets = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/views/widgets.php');
$styles = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$version = json_decode((string)file_get_contents($root . '/version.json'), true);
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$expected = [
    'CMS Info' => 'braces',
    'Quick Stats' => 'chart-bar',
    'System Info' => 'server',
    'Site Health' => 'shield-check',
];

$failures = [];
foreach ($expected as $widget => $icon) {
    $asset = $root . '/public/static/icons/lucide/' . $icon . '.svg';
    $validAsset = is_file($asset)
        && str_contains((string)file_get_contents($asset), '<svg')
        && str_contains((string)file_get_contents($asset), 'viewBox="0 0 24 24"');
    $usesIcon = preg_match(
        '/svg_ico\(\'' . preg_quote($icon, '/') . '\'\).*?__\(\'' . preg_quote($widget, '/') . '\'\)/s',
        $widgets
    ) === 1;
    $ok = $validAsset && $usesIcon;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $widget . ' uses the bundled Lucide ' . $icon . ' asset' . PHP_EOL;
    if (!$ok) $failures[] = $widget;
}

$externalLinkAsset = $root . '/public/static/icons/lucide/external-link.svg';
$externalLinksOk = is_file($externalLinkAsset)
    && substr_count($widgets, "svg_ico('external-link')") >= 2
    && str_contains($widgets, "['homepage']")
    && str_contains($widgets, "['changelog_url']")
    && str_contains($widgets, 'target="_blank" rel="noopener noreferrer"')
    && str_contains($widgets, '<td><a class="dw-cms-link"')
    && !str_contains($widgets, 'class="dw-card-external"')
    && str_contains($styles, '.dw-cms-link')
    && str_contains($styles, '.dw-version-link');
$checkLabel = 'CMS Info links its CMS value and version details with the bundled external-link icon';
echo ($externalLinksOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$externalLinksOk) $failures[] = $checkLabel;

$changelogUrl = is_array($version) ? (string)($version['changelog_url'] ?? '') : '';
$changelogOk = preg_match('#\Ahttps://github\.com/adammuizweb/jyavani/commit/[a-f0-9]{40}\z#D', $changelogUrl) === 1;
$checkLabel = 'version metadata links to an exact GitHub release commit';
echo ($changelogOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$changelogOk) $failures[] = $checkLabel;

$ellipsisOk = str_contains($widgets, "mb_strlen(\$rawTitle, 'UTF-8') > 40")
    && str_contains($widgets, "mb_substr(\$rawTitle, 0, 40, 'UTF-8')")
    && str_contains($widgets, ". '...'")
    && str_contains($widgets, 'class="dw-post-title"');
$checkLabel = 'Recent Posts appends an ellipsis only when a UTF-8 title is truncated';
echo ($ellipsisOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$ellipsisOk) $failures[] = $checkLabel;

$translationOk = substr_count($translations, "'Visit the official Jyavani website'") === 2
    && substr_count($translations, "'View this version on GitHub'") === 2;
$checkLabel = 'new CMS Info link labels have Indonesian and German translation seeds';
echo ($translationOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$translationOk) $failures[] = $checkLabel;

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " dashboard widget icon contract check(s) failed.\n");
    exit(1);
}

echo "Dashboard widget icon contract passed.\n";
