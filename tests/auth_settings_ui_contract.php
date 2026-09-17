<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$auth = (string)file_get_contents($root . '/dashboard/admin/settings/auth.php');
$css = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($auth, 'class="adam-card settings-card auth-settings"')
    && str_contains($auth, '<h2 class="edit-heading">'),
    'Authentication Settings uses the shared settings card and heading');

foreach (['registration', 'security', 'paths', 'dashboard'] as $section) {
    $check(str_contains($auth, 'settings-section--auth-' . $section)
        && str_contains($css, '.settings-section--auth-' . $section),
        $section . ' settings section has structured markup and a visual identity');
}

$check(substr_count($auth, 'class="metatags-card"') >= 3
    && substr_count($auth, 'class="metatags-toggle"') >= 3,
    'registration and reCAPTCHA controls use accessible toggle cards');
$check(str_contains($auth, 'class="auth-settings-grid"')
    && str_contains($css, '.auth-settings-grid')
    && str_contains($css, '@media (max-width:640px)'),
    'Authentication field grids are responsive');
$check(str_contains($auth, 'class="auth-secret-toggle"')
    && str_contains($auth, 'aria-controls="recaptcha_sitekey"')
    && str_contains($auth, "button.setAttribute('aria-pressed'"),
    'secret visibility controls are keyboard-operable and expose state');
$check(str_contains($auth, 'class="permalink-builder"')
    && str_contains($auth, "['login_path', 'login-path-example']")
    && str_contains($auth, "['admin_path', 'admin-path-example']"),
    'authentication paths use builders with live previews');
$check(str_contains($auth, '$display_login_path = $_SERVER[\'REQUEST_METHOD\'] === \'POST\'')
    && str_contains($auth, '$display_max_attempts = $_SERVER[\'REQUEST_METHOD\'] === \'POST\''),
    'rejected path and brute-force values remain visible for correction');
$check(str_contains($auth, 'Login, registration, and dashboard paths cannot overlap.')
    && str_contains($auth, 'Path conflicts with a reserved or configured route.')
    && str_contains($auth, "['author', 'private', 'plugins', 'posts', 'static']")
    && str_contains($auth, 'Paths cannot contain empty, current-directory, or parent-directory segments.')
    && str_contains($auth, 'Authentication paths cannot exceed 255 characters.')
    && str_contains($auth, 'posts|pages|themes'),
    'authentication paths cannot overlap each other or reserved routes');
$check(str_contains($auth, "ctype_digit(\$new_max_attempts)")
    && str_contains($auth, '(int)$new_max_attempts > 100')
    && str_contains($auth, '(int)$new_block_minutes > 1440'),
    'brute-force settings enforce whole-number server-side bounds');
$check(str_contains($auth, 'if ($pdo->inTransaction())')
    && str_contains($auth, 'if (!$pdo->beginTransaction())')
    && str_contains($auth, 'if (!$pdo->commit())')
    && str_contains($auth, '$pdo->rollBack()'),
    'authentication settings persist atomically');
$check(str_contains($auth, 'role="dialog" aria-modal="true" aria-hidden="true"')
    && str_contains($auth, "event.key === 'Escape'")
    && str_contains($auth, 'attemptModalLastFocus.focus()')
    && str_contains($auth, "firstControl.focus()"),
    'login attempts uses an accessible modal with Escape and focus restoration');
$check(str_contains($auth, "json_encode(__('Delete this login attempt data?'))")
    && !str_contains($auth, "alert('Gagal")
    && str_contains($auth, 'badge badge--danger'),
    'login-attempt feedback is safely translated and uses shared status styling');

foreach ([
    'Allow visitors to create an account from the public registration page.',
    'Require administrator approval',
    'Protect login and registration forms with reCAPTCHA.',
    'Site Key (RECAPTCHA_SITEKEY)',
    'Secret Key (RECAPTCHA_SECRET)',
    'Failed:',
    'Failed to delete data.',
    'Unknown error.',
    'Dashboard path',
    'Login, registration, and dashboard paths cannot overlap.',
    'Path conflicts with a reserved or configured route.',
    'Paths cannot contain empty, current-directory, or parent-directory segments.',
    'Authentication paths cannot exceed 255 characters.',
    'Maximum failed login attempts must be a whole number between 1 and 100.',
    'Block duration must be a whole number between 1 and 1440 minutes.',
] as $key) {
    $check(substr_count($translations, "'" . str_replace("'", "''", $key) . "'") >= 2,
        $key . ' has Indonesian and German translation seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Authentication Settings UI contract check(s) failed.\n");
    exit(1);
}
echo "Authentication Settings UI contract passed.\n";
