<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/cfg/helpers/author_helpers.php';

$failures = [];
$check = static function (bool $passed, string $label) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
};

$check(user_avatar_display_name(['name' => '', 'username' => 'editor', 'email' => 'editor@example.test']) === 'editor',
    'avatar display names use name, username, then email');
$check(user_avatar_initial('Élodie') === 'É' && user_avatar_initial('') === '?',
    'avatar initials are Unicode-safe and deterministic');
$check(user_avatar_image_url('/static/img/jyavani.svg') === ''
    && user_avatar_image_url('/static/img/person.svg') === ''
    && user_avatar_image_url('https://ui-avatars.com/api/?name=User') === '',
    'legacy logo, person asset, and remote generated avatars normalize to no photo');

$avatarHtml = user_avatar_html('/media/avatar.jpg', 'Alice', [
    'image_class' => 'avatar-photo',
    'fallback_class' => 'avatar-initial',
]);
$check(str_contains($avatarHtml, 'src="/media/avatar.jpg"')
    && preg_match('/class="user-avatar-fallback avatar-initial"[^>]*\shidden(?:\s|>)/', $avatarHtml) === 1
    && str_contains($avatarHtml, 'onerror="this.hidden=true;this.nextElementSibling.hidden=false"'),
    'stored photos expose a local initial fallback when image loading fails');

$emptyAvatarHtml = user_avatar_html('', '<Admin>', [
    'image_class' => 'avatar-photo',
    'fallback_class' => 'avatar-initial',
]);
$check(str_contains($emptyAvatarHtml, 'class="user-avatar-image avatar-photo"')
    && !str_contains($emptyAvatarHtml, 'src=')
    && str_contains($emptyAvatarHtml, '&lt;'),
    'blank photos render an escaped local initial without requesting an asset');

$profile = (string)file_get_contents($root . '/dashboard/admin/profile/index.php');
$userEditor = (string)file_get_contents($root . '/dashboard/admin/users/save.php');
$userList = (string)file_get_contents($root . '/dashboard/admin/users/index.php');
$userBin = (string)file_get_contents($root . '/dashboard/admin/bin/users/index.php');
$avatarSurfaces = $profile . $userEditor . $userList . $userBin;
$check(!str_contains($avatarSurfaces, 'ui-avatars.com')
    && !str_contains($avatarSurfaces, '/static/img/person.svg')
    && !str_contains($userEditor, "json_encode('/static/img/jyavani.svg')"),
    'dashboard user surfaces contain no remote, person asset, or brand-logo avatar fallback');

$themeFiles = [
    'public/views/themes/default/main/index/author.php',
    'public/views/themes/default/main/list/author.php',
    'public/views/themes/default/main/single/post.php',
    'public/views/themes/default/main/single/page.php',
    'cfg/helpers/theme_zones.php',
    'app/controllers/AuthorController.php',
];
$themeSource = '';
foreach ($themeFiles as $file) $themeSource .= (string)file_get_contents($root . '/' . $file);
$check(substr_count($themeSource, 'user_avatar_html(') >= 7
    && !preg_match('/strtoupper\s*\(\s*(?:mb_)?substr\s*\(/', $themeSource),
    'Core author surfaces use the shared avatar renderer instead of local initial logic');

$migration = (string)file_get_contents($root . '/schema/migrations/031-user-avatar-fallback.sql');
$check(str_contains($migration, "SET `img` = NULL")
    && str_contains($migration, '/static/img/jyavani.svg')
    && str_contains($migration, 'ui-avatars.com/api/%'),
    'upgrade migration clears fallback values previously persisted as user photos');

$check(!is_file($root . '/public/static/img/person.svg'),
    'obsolete person avatar asset is removed');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " avatar fallback contract check(s) failed.\n");
    exit(1);
}

echo "Avatar fallback contract passed.\n";
