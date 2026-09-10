<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$widgets = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/views/widgets.php');
$expected = [
    'CMS Info' => 'braces',
    'Quick Stats' => 'chart-bar',
    'System Info' => 'server',
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

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " dashboard widget icon contract check(s) failed.\n");
    exit(1);
}

echo "Dashboard widget icon contract passed.\n";
