<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$profile = (string)file_get_contents($root . '/dashboard/admin/profile/index.php');
$dashboardCss = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$failures = [];

$checks = [
    'Profile page uses the shared themed editor surface' => str_contains($profile, 'class="adam-card user-editor profile-editor"')
        && substr_count($profile, 'class="adam-input user-form-control"') >= 9,
    'Profile page no longer contains light-only inline presentation' => !str_contains($profile, 'style="')
        && !str_contains($profile, 'border:1px solid #ddd')
        && !str_contains($profile, 'background:#fff'),
    'Profile details and password fields use responsive semantic grids' => str_contains($profile, 'class="profile-details-grid"')
        && str_contains($profile, 'class="profile-password-section"')
        && str_contains($profile, 'class="profile-password-grid"')
        && str_contains($dashboardCss, '.profile-details-grid{')
        && str_contains($dashboardCss, '.profile-password-grid{')
        && str_contains($dashboardCss, 'grid-template-columns:repeat(2,minmax(0,1fr));')
        && str_contains($dashboardCss, 'grid-template-columns:repeat(3,minmax(0,1fr));'),
    'Profile actions use Core Lucide icons and accessible text' => str_contains($profile, "svg_ico('save')")
        && str_contains($profile, "svg_ico('arrow-left')")
        && str_contains($profile, "svg_ico('circle-check')")
        && str_contains($profile, "svg_ico('alert-triangle')"),
    'Danger Zone uses theme tokens instead of fixed light colors' => str_contains($profile, 'class="adam-card profile-danger-zone"')
        && str_contains($dashboardCss, '.profile-danger-zone{')
        && str_contains($dashboardCss, 'color-mix(in srgb,var(--adam-danger)')
        && !str_contains($profile, '#e74c3c')
        && !str_contains($profile, '#c0392b'),
    'Account deletion modal uses the Core modal and dialog contract' => str_contains($profile, 'class="adam-modal profile-delete-modal"')
        && str_contains($profile, 'role="dialog"')
        && str_contains($profile, 'aria-modal="true"')
        && str_contains($profile, 'aria-hidden="true"')
        && str_contains($profile, "deleteModal.classList.add('is-open')")
        && str_contains($profile, "deleteModal.classList.remove('is-open')")
        && str_contains($profile, "deleteModal.setAttribute('aria-hidden', 'false')")
        && str_contains($profile, "deleteModal.setAttribute('aria-hidden', 'true')"),
    'Account deletion modal restores focus and traps keyboard navigation' => str_contains($profile, 'deleteModalReturnFocus = document.activeElement')
        && str_contains($profile, 'deleteModalReturnFocus.focus()')
        && str_contains($profile, "if (ev.key !== 'Tab') return;")
        && str_contains($profile, "if (ev.key === 'Escape')"),
    'Profile layout collapses both field grids on mobile' => str_contains($dashboardCss, '.profile-details-grid,')
        && str_contains($dashboardCss, '.profile-password-grid{ grid-template-columns:1fr; }')
        && str_contains($dashboardCss, '.profile-danger-zone{')
        && str_contains($dashboardCss, 'flex-direction:column;'),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Profile theme contract check(s) failed.\n");
    exit(1);
}

echo "Profile theme contract passed.\n";
