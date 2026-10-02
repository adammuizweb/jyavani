<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$userEditor = (string)file_get_contents($root . '/dashboard/admin/users/save.php');
$profileEditor = (string)file_get_contents($root . '/dashboard/admin/profile/index.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$failures = [];

$checks = [
    'User Editor uses the shared themed control class' => substr_count($userEditor, 'class="adam-input user-form-control"') >= 7,
    'User Editor no longer hardcodes light-only input borders' => !str_contains($userEditor, 'border:1px solid #ddd'),
    'User Editor controls use dashboard surface and text tokens' => str_contains($dashboardCss, '.user-editor .adam-input{')
        && str_contains($dashboardCss, 'background:var(--adam-surface-2);')
        && str_contains($dashboardCss, 'color:var(--adam-text);'),
    'User Editor disabled fields retain explicit themed contrast' => str_contains($dashboardCss, '.user-editor .adam-input:disabled{')
        && str_contains($dashboardCss, '-webkit-text-fill-color:var(--adam-muted);'),
    'User role choices use theme surfaces instead of fixed gray colors' => str_contains($userEditor, 'class="user-role-option')
        && str_contains($dashboardCss, '.user-role-option{')
        && !str_contains($userEditor, 'color:#667085'),
    'Native checkbox and radio controls receive the dashboard accent' => str_contains(
        $dashboardCss,
        'input[type="checkbox"], input[type="radio"]{ accent-color:var(--adam-primary); }'
    ),
    'User Editor checkbox and radio controls use a themed custom appearance' => str_contains($dashboardCss, '.user-editor input[type="checkbox"],')
        && str_contains($dashboardCss, 'appearance:none;')
        && str_contains($dashboardCss, 'background:var(--adam-card);')
        && str_contains($dashboardCss, 'border-color:var(--adam-primary);')
        && str_contains($dashboardCss, '@media (forced-colors:active){'),
    'User Editor password controls suppress the native Edge reveal button' => str_contains(
        $dashboardCss,
        '.user-editor .pw-wrap input::-ms-reveal{ display:none; }'
    ) && str_contains(
        $dashboardCss,
        '.user-editor .pw-wrap input::-ms-clear{ display:none; }'
    ),
    'Profile actions use themed Lucide icon buttons' => str_contains($userEditor, "svg_ico('image')")
        && str_contains($userEditor, "svg_ico('trash-2')")
        && str_contains($userEditor, "svg_ico('external-link')")
        && substr_count($userEditor, 'class="profile-action ') === 3
        && substr_count($profileEditor, 'class="profile-action ') === 3
        && str_contains($dashboardCss, '.profile-action--primary{')
        && str_contains($dashboardCss, '.profile-action--danger{')
        && str_contains($dashboardCss, '.profile-action--secondary{'),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " User Editor theme contract check(s) failed.\n");
    exit(1);
}
echo "User Editor theme contract passed.\n";
