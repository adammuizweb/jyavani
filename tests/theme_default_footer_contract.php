<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$footer = file_get_contents($root . '/public/views/themes/default/footer.php') ?: '';
$css = file_get_contents($root . '/public/views/themes/default/assets/css/style.css') ?: '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($footer, 'theme_zone_position_items($pdo, \'footer\', $position)'), 'footer reads active Customize gadget settings');
$check(str_contains($footer, "['_align_title']") && str_contains($footer, "['_align_content']"), 'footer honors title and content alignment settings');
$check(str_contains($footer, '--footer-title-align:') && str_contains($footer, '--footer-content-align:') && str_contains($footer, '--footer-flex-align:'), 'footer exposes validated alignment values to CSS');
$check(!str_contains($footer, 'grid-template-columns:repeat(auto-fit'), 'footer grid is not locked in inline markup');
$check(str_contains($css, 'grid-template-columns: repeat(3, minmax(0, 1fr));'), 'desktop footer uses three explicit columns');
$check((bool)preg_match('/@media\s*\(max-width:\s*680px\)[\s\S]*?\.footer-cols\s*\{[\s\S]*?grid-template-columns:\s*1fr;/', $css), 'mobile footer stacks positions into one column');
$check(str_contains($css, 'align-items: var(--footer-flex-align, flex-start);') && str_contains($css, 'text-align: var(--footer-content-align, left);'), 'footer column layout follows configured content alignment');
$check(str_contains($css, 'text-align: var(--footer-title-align, left);'), 'footer titles follow configured title alignment');

if ($failures) {
    fwrite(STDERR, 'RESULT: ' . count($failures) . " failure(s)\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
