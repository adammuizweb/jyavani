<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$roles = (string)file_get_contents($root . '/dashboard/admin/users/roles/index.php');
$failures = [];

$checks = [
    'mobile Role Manager overrides the later desktop grid declaration' => strrpos($roles, '@media(max-width:800px)') > strrpos($roles, '.authz-role-layout{grid-template-columns:minmax(220px,270px) minmax(0,1fr)}')
        && str_contains($roles, '.authz-role-layout{display:block;width:100%;min-width:0}'),
    'mobile roles use a bounded horizontal snap rail' => str_contains($roles, 'grid-auto-flow:column;grid-auto-columns:min(78vw,260px)')
        && str_contains($roles, 'scroll-snap-type:x proximity')
        && str_contains($roles, '.authz-role-item{min-width:0;min-height:76px;'),
    'empty mobile state does not render the full unused permission editor' => str_contains($roles, 'class="authz-role-form<?= $selectedRole === null ? \' is-empty\' : \'\' ?>"')
        && str_contains($roles, '.authz-role-form.is-empty{display:none}')
        && str_contains($roles, 'class="authz-mobile-empty"'),
    'mobile role fields and permission rows use one bounded column' => str_contains($roles, '.authz-role-fields{grid-template-columns:minmax(0,1fr);')
        && str_contains($roles, '.authz-permission-row{display:grid;grid-template-columns:minmax(0,1fr);')
        && str_contains($roles, 'word-break:break-word'),
    'mobile permission tools and actions expose 44px touch targets' => str_contains($roles, '.authz-permission-tools input{min-height:44px;font-size:16px}')
        && str_contains($roles, '.authz-permission-tools button{min-height:44px;')
        && str_contains($roles, '.authz-role-actions a,.authz-role-actions button{')
        && str_contains($roles, 'min-height:44px;box-sizing:border-box;text-align:center'),
    'mobile create dialog fits the dynamic viewport' => str_contains($roles, 'max-height:calc(100dvh - 24px)')
        && str_contains($roles, '.authz-modal-close{display:grid;place-items:center;width:44px;height:44px;')
        && str_contains($roles, '.authz-modal-actions{display:grid;grid-template-columns:1fr 1fr;'),
    'mobile permission groups start compact and active role remains visible' => str_contains($roles, "groups.forEach(function(group){ group.open = false; });")
        && str_contains($roles, "activeRole.scrollIntoView({block:'nearest', inline:'center'});"),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Role Manager mobile contract check(s) failed.\n");
    exit(1);
}

echo "Role Manager mobile contract passed.\n";
