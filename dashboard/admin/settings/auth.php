<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';

if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) {
    adiwira_admin_404();
}

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_permission($pdo, 'core.settings.manage', false);
adiwira_require_site_owner($pdo, false);

$errors = [];
$success_msg = '';

$page_toasts = function_exists('adiwira_collect_query_toasts')
    ? adiwira_collect_query_toasts()
    : [];

if (function_exists('adiwira_flash_pull')) {
    $flash = adiwira_flash_pull();
    if (is_array($flash)) {
        foreach ($flash as $f) {
            $type = isset($f['type']) ? (string)$f['type'] : 'info';
            $text = isset($f['text']) ? (string)$f['text'] : (isset($f['message']) ? (string)$f['message'] : '');
            if ($text !== '') {
                $page_toasts[] = [
                    'type' => $type,
                    'message' => $text,
                ];
            }
        }
    }
}

// --- AJAX: list login attempts ---
$action = (string)($_GET['action'] ?? '');
if ($action === 'list_attempts') {
    $page = max(1, (int)($_GET['p'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $totalStmt = $pdo->query("SELECT COUNT(*) FROM login_attempts");
    $total = (int)$totalStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM login_attempts ORDER BY last_attempt DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '';
    if (empty($rows)) {
        $html = '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--adam-muted);">' . __('No login attempts data.') . '</td></tr>';
    } else {
        foreach ($rows as $r) {
            $email = htmlspecialchars($r['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $ip = htmlspecialchars($r['ip_address'] ?? '', ENT_QUOTES, 'UTF-8');
            $attempts = (int)($r['attempts'] ?? 0);
            $last = htmlspecialchars($r['last_attempt'] ?? '', ENT_QUOTES, 'UTF-8');
            $blocked = !empty($r['blocked_until']) && strtotime($r['blocked_until']) > time();
            $id = (int)($r['id'] ?? 0);
            $html .= '<tr>';
            $html .= '<td>' . $email . '</td>';
            $html .= '<td>' . $ip . '</td>';
            $html .= '<td>' . $attempts . '</td>';
            $html .= '<td>' . $last . '</td>';
            $html .= '<td>' . ($blocked ? '<span class="badge badge--danger">' . h(__('Blocked')) . '</span>' : '<span class="badge badge--ok">' . h(__('Active')) . '</span>') . '</td>';
            $html .= '<td><button class="adam-hapus" onclick="deleteAttempt(' . $id . ')" title="' . h(__('Delete')) . '">' . h(__('Delete')) . '</button></td>';
            $html .= '</tr>';
        }
    }

    $totalPages = max(1, (int)ceil($total / $perPage));
    $pagination = '';
    if ($totalPages > 1) {
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === $page) {
                $pagination .= '<strong>' . $i . '</strong>';
            } else {
                $pagination .= '<a href="#" onclick="loadAttempts(' . $i . ');return false;">' . $i . '</a>';
            }
        }
    }

    adiwira_json([
        'html' => $html,
        'pagination' => '<div class="adam-pagination" style="margin-top:1rem;">' . $pagination . '</div>',
        'total' => $total,
    ]);
    exit;
}

// --- AJAX: delete login attempt ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_attempt') {
    $id = (int)($_POST['id'] ?? 0);
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!function_exists('adiwira_csrf_validate') || !adiwira_csrf_validate($token)) {
        adiwira_json(['ok' => false, 'error' => __('Invalid CSRF token.')]);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE id = ?");
    $stmt->execute([$id]);
    adiwira_json(['ok' => true]);
    exit;
}

// ---------- load settings ----------
$registration_enabled      = function_exists('settings_get') ? (settings_get($pdo, 'registration_enabled', '0') ?? '0') : '0';
$registration_approval     = function_exists('settings_get') ? (settings_get($pdo, 'registration_approval_required', '1') ?? '1') : '1';
$recaptcha_enabled         = function_exists('settings_get') ? (settings_get($pdo, 'recaptcha_enabled', '0') ?? '0') : '0';
$recaptcha_sitekey         = function_exists('settings_get') ? (settings_get($pdo, 'recaptcha_sitekey', '') ?? '') : '';
$recaptcha_secret          = function_exists('settings_get') ? (settings_get($pdo, 'recaptcha_secret', '') ?? '') : '';
$login_path                = function_exists('get_login_path') ? get_login_path($pdo) : 'adiwira/gerbank/melbu';
$register_path             = function_exists('get_register_path') ? get_register_path($pdo) : 'adiwira/gerbank/daptar';
$admin_path                = function_exists('get_admin_path') ? trim(get_admin_path($pdo), '/') : 'adiwira';
$bruteforce_max_attempts   = function_exists('settings_get') ? (settings_get($pdo, 'bruteforce_max_attempts', '5') ?? '5') : '5';
$bruteforce_block_minutes  = function_exists('settings_get') ? (settings_get($pdo, 'bruteforce_block_minutes', '15') ?? '15') : '15';

// ---------- migrate from old login_slug to login_path/register_path ----------
$oldLoginSlug = function_exists('settings_get') ? (settings_get($pdo, 'login_slug', '') ?? '') : '';
if ($oldLoginSlug !== '' && $oldLoginSlug !== 'gerbank') {
    $currentLoginPath = $login_path;
    $currentRegisterPath = $register_path;
    if ($currentLoginPath === 'adiwira/gerbank/melbu') {
        settings_set($pdo, 'login_path', 'adiwira/' . $oldLoginSlug . '/melbu', 1);
        $login_path = 'adiwira/' . $oldLoginSlug . '/melbu';
    }
    if ($currentRegisterPath === 'adiwira/gerbank/daptar') {
        settings_set($pdo, 'register_path', 'adiwira/' . $oldLoginSlug . '/daptar', 1);
        $register_path = 'adiwira/' . $oldLoginSlug . '/daptar';
    }
    settings_set($pdo, 'login_slug', '', 1);
}

$base = ADMIN_BASE_PATH;
$self_url = $base . '/?page=admin/settings/auth';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!function_exists('adiwira_csrf_validate') || !adiwira_csrf_validate($token)) {
        $errors[] = __('Invalid CSRF token.');
    }

    $registration_enabled  = !empty($_POST['registration_enabled']) ? '1' : '0';
    $registration_approval = !empty($_POST['registration_approval_required']) ? '1' : '0';
    $recaptcha_enabled     = !empty($_POST['recaptcha_enabled']) ? '1' : '0';
    $new_recaptcha_sitekey = trim((string)($_POST['recaptcha_sitekey'] ?? ''));
    $new_recaptcha_secret  = trim((string)($_POST['recaptcha_secret'] ?? ''));
    $new_login_path        = trim((string)($_POST['login_path'] ?? ''));
    $new_register_path     = trim((string)($_POST['register_path'] ?? ''));
    $new_admin_path        = trim((string)($_POST['admin_path'] ?? ''));
    $new_max_attempts      = trim((string)($_POST['bruteforce_max_attempts'] ?? ''));
    $new_block_minutes     = trim((string)($_POST['bruteforce_block_minutes'] ?? ''));
    $pathErrorCount = count($errors);

    if ($new_admin_path === '') {
        $errors[] = __('Dashboard path is required.');
    } elseif (strlen($new_admin_path) > 255) {
        $errors[] = __('Authentication paths cannot exceed 255 characters.');
    } elseif (!preg_match('/^[a-z0-9_\/.-]+$/', $new_admin_path)) {
        $errors[] = __('Dashboard path can only contain lowercase letters, numbers, slash, dot, underscore, and dash.');
    } elseif (trim($new_admin_path, '/') === '' || str_contains(trim($new_admin_path, '/'), '//') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', trim($new_admin_path, '/'))) {
        $errors[] = __('Paths cannot contain empty, current-directory, or parent-directory segments.');
    } elseif ($new_admin_path === 'static' || $new_admin_path === 'admin' || strpos($new_admin_path, '/') === 0) {
        $errors[] = __('Dashboard path cannot be "static", "admin", or start with slash.');
    } else {
        $admin_path = trim($new_admin_path, '/');
    }

    if ($new_login_path === '') {
        $errors[] = __('Login page path is required.');
    } elseif (strlen($new_login_path) > 255) {
        $errors[] = __('Authentication paths cannot exceed 255 characters.');
    } elseif (!preg_match('/^[a-z0-9_\/.-]+$/', $new_login_path)) {
        $errors[] = __('Login path can only contain lowercase letters, numbers, slash, dot, underscore, and dash.');
    } elseif (trim($new_login_path, '/') === '' || str_contains(trim($new_login_path, '/'), '//') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', trim($new_login_path, '/'))) {
        $errors[] = __('Paths cannot contain empty, current-directory, or parent-directory segments.');
    } else {
        $login_path = trim($new_login_path, '/');
    }

    if ($new_register_path === '') {
        $errors[] = __('Registration page path is required.');
    } elseif (strlen($new_register_path) > 255) {
        $errors[] = __('Authentication paths cannot exceed 255 characters.');
    } elseif (!preg_match('/^[a-z0-9_\/.-]+$/', $new_register_path)) {
        $errors[] = __('Registration path can only contain lowercase letters, numbers, slash, dot, underscore, and dash.');
    } elseif (trim($new_register_path, '/') === '' || str_contains(trim($new_register_path, '/'), '//') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', trim($new_register_path, '/'))) {
        $errors[] = __('Paths cannot contain empty, current-directory, or parent-directory segments.');
    } else {
        $register_path = trim($new_register_path, '/');
    }

    if (count($errors) === $pathErrorCount) {
        $authPaths = [[$admin_path, true], [$login_path, false], [$register_path, false]];
        for ($i = 0; $i < count($authPaths); $i++) {
            for ($j = $i + 1; $j < count($authPaths); $j++) {
                [$leftPath, $leftIsPrefix] = $authPaths[$i];
                [$rightPath, $rightIsPrefix] = $authPaths[$j];
                if ($leftPath === $rightPath
                    || ($leftIsPrefix && str_starts_with($rightPath, $leftPath . '/'))
                    || ($rightIsPrefix && str_starts_with($leftPath, $rightPath . '/'))) {
                    $errors[] = __('Login, registration, and dashboard paths cannot overlap.');
                    break 2;
                }
            }
        }

        $configuredRoutes = [];
        foreach ([['posts_list_path', 'artikel'], ['pages_list_path', 'halaman'], ['category_path', 'category']] as [$key, $default]) {
            $route = trim((string)(settings_get($pdo, $key, $default) ?? $default), '/');
            if ($route !== '') $configuredRoutes[] = $route;
        }
        $publicRoot = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : dirname(__DIR__, 3) . '/public';
        foreach ([[$admin_path, true], [$login_path, false], [$register_path, false]] as [$authPath, $prefixMatch]) {
            $firstSegment = explode('/', $authPath, 2)[0];
            $reserved = in_array($firstSegment, ['author', 'private', 'plugins', 'posts', 'static'], true)
                || preg_match('/^\d{4}$/', $firstSegment) === 1
                || in_array($authPath, ['sw.js', 'robots.txt', 'content_list.xml'], true)
                || preg_match('/^sitemap(?:_(?:(?:[a-z]{2,3}(?:-[a-z0-9]{2,8})*)_)?(?:posts|pages|themes)_\d+)?\.xml$/', $authPath) === 1
                || file_exists(rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $firstSegment)
                || is_link(rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $firstSegment);

            foreach ($configuredRoutes as $configuredRoute) {
                if ($authPath === $configuredRoute
                    || str_starts_with($authPath, $configuredRoute . '/')
                    || ($prefixMatch && str_starts_with($configuredRoute, $authPath . '/'))) {
                    $reserved = true;
                    break;
                }
            }
            if (!$reserved && function_exists('get_frontend_route_definitions')) {
                foreach ((array)get_frontend_route_definitions() as $route) {
                    $routePath = is_array($route) ? trim((string)($route['path'] ?? ''), '/') : '';
                    $routeIsPrefix = !is_array($route) || (string)($route['match'] ?? 'prefix') !== 'exact';
                    if ($routePath !== '' && ($authPath === $routePath
                        || ($routeIsPrefix && str_starts_with($authPath, $routePath . '/'))
                        || ($prefixMatch && str_starts_with($routePath, $authPath . '/')))) {
                        $reserved = true;
                        break;
                    }
                }
            }
            if ($reserved) {
                $errors[] = __('Path conflicts with a reserved or configured route.');
                break;
            }
            if (function_exists('content_route_conflicts_with_setting_path')
                && content_route_conflicts_with_setting_path($pdo, $authPath, $prefixMatch)) {
                $errors[] = __('Path conflicts with an existing content route.');
                break;
            }
        }
    }

    if (!ctype_digit($new_max_attempts) || (int)$new_max_attempts < 1 || (int)$new_max_attempts > 100) {
        $errors[] = __('Maximum failed login attempts must be a whole number between 1 and 100.');
    } else {
        $bruteforce_max_attempts = (string)(int)$new_max_attempts;
    }

    if (!ctype_digit($new_block_minutes) || (int)$new_block_minutes < 1 || (int)$new_block_minutes > 1440) {
        $errors[] = __('Block duration must be a whole number between 1 and 1440 minutes.');
    } else {
        $bruteforce_block_minutes = (string)(int)$new_block_minutes;
    }

    $recaptcha_sitekey = $new_recaptcha_sitekey;
    $recaptcha_secret  = $new_recaptcha_secret;

    if (!$errors) {
        $settings = [
            'registration_enabled' => $registration_enabled,
            'registration_approval_required' => $registration_approval,
            'recaptcha_enabled' => $recaptcha_enabled,
            'recaptcha_sitekey' => $recaptcha_sitekey,
            'recaptcha_secret' => $recaptcha_secret,
            'login_path' => $login_path,
            'register_path' => $register_path,
            'admin_path' => $admin_path,
            'bruteforce_max_attempts' => $bruteforce_max_attempts,
            'bruteforce_block_minutes' => $bruteforce_block_minutes,
        ];
        $cacheWasLoaded = isset($GLOBALS['__jy_settings_autoload_cache']) && is_array($GLOBALS['__jy_settings_autoload_cache']);
        $cacheSnapshot = $cacheWasLoaded ? $GLOBALS['__jy_settings_autoload_cache'] : null;
        $transaction = null;
        $saved = false;
        try {
            if ($pdo->inTransaction()) {
                throw new RuntimeException('Authentication settings require an independent transaction.');
            }
            if (!$pdo->beginTransaction()) {
                throw new RuntimeException('Failed to start authentication settings transaction.');
            }
            $transaction = ['owned' => true, 'savepoint' => null];
            foreach ($settings as $key => $value) {
                if (!settings_set($pdo, $key, $value, 1)) {
                    throw new RuntimeException('Failed to persist authentication setting.');
                }
            }
            if (!$pdo->commit()) throw new RuntimeException('Failed to commit authentication settings.');
            $saved = true;
        } catch (Throwable $e) {
            if (is_array($transaction) && $pdo->inTransaction()) $pdo->rollBack();
            if ($cacheWasLoaded) $GLOBALS['__jy_settings_autoload_cache'] = $cacheSnapshot;
            error_log('[auth-settings] Save failed: ' . $e->getMessage());
            $errors[] = __('Failed to save settings.');
        }

        if ($saved) {
            if (function_exists('adiwira_redirect_with_flash')) {
                $redirect_path = '/' . trim($admin_path, '/') . '/?page=admin/settings/auth';
                adiwira_redirect_with_flash($redirect_path, 'success', __('Login & registration settings saved successfully.'));
                exit;
            }
            $success_msg = __('Login & registration settings saved successfully.');
        }
    }
}

$show_inline_success = ($success_msg !== '' && !function_exists('adiwira_bootstrap_toasts_script'));
$show_inline_errors  = (!empty($errors) && !function_exists('adiwira_bootstrap_toasts_script'));
$display_login_path = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['login_path'] ?? '') : $login_path;
$display_register_path = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['register_path'] ?? '') : $register_path;
$display_admin_path = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['admin_path'] ?? '') : $admin_path;
$display_max_attempts = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['bruteforce_max_attempts'] ?? '') : $bruteforce_max_attempts;
$display_block_minutes = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['bruteforce_block_minutes'] ?? '') : $bruteforce_block_minutes;

function auth_path_example(string $path): string {
    return '/' . trim($path, '/') . '/';
}
?>
<section class="adam-card settings-card auth-settings">
  <h2 class="edit-heading"><?= svg_ico('shield-check') ?> <?=_e('Sign Up &amp; Sign In')?></h2>

  <?php if ($show_inline_success): ?>
    <div class="adam-success" style="margin:10px 0;">
      <?= svg_ico('circle-check') ?> <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <?php if ($show_inline_errors): ?>
    <div class="adam-error" style="margin:10px 0;">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form id="auth-settings-form" method="post" novalidate data-unsaved-guard<?= ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) ? ' data-unsaved-guard-initial-dirty' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

    <div class="settings-section settings-section--auth-registration" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('user') ?> <?=_e('User Registration')?>
        <span class="chevron" aria-hidden="true">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="auth-toggle-stack">
          <div class="metatags-card">
            <div class="metatags-row">
              <div class="metatags-info">
                <label class="metatags-label" for="registration_enabled"><?=_e('Enable registration page')?></label>
                <p class="metatags-desc"><?=_e('Allow visitors to create an account from the public registration page.')?></p>
              </div>
              <label class="metatags-toggle">
                <input type="checkbox" name="registration_enabled" id="registration_enabled" value="1" <?= $registration_enabled === '1' ? 'checked' : '' ?>>
                <span class="slider"></span>
              </label>
            </div>
          </div>
          <div class="metatags-card">
            <div class="metatags-row">
              <div class="metatags-info">
                <label class="metatags-label" for="registration_approval_required"><?=_e('Require administrator approval')?></label>
                <p class="metatags-desc"><?=_e('New users need admin approval (account directly <code>is_locked</code>)')?></p>
              </div>
              <label class="metatags-toggle">
                <input type="checkbox" name="registration_approval_required" id="registration_approval_required" value="1" <?= $registration_approval === '1' ? 'checked' : '' ?>>
                <span class="slider"></span>
              </label>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="settings-section settings-section--auth-security" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('shield-check') ?> <?=_e('Security')?>
        <span class="chevron" aria-hidden="true">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="metatags-card">
          <div class="metatags-row">
            <div class="metatags-info">
              <label class="metatags-label" for="recaptcha_enabled"><?=_e('Enable reCAPTCHA')?></label>
              <p class="metatags-desc"><?=_e('Protect login and registration forms with reCAPTCHA.')?></p>
            </div>
            <label class="metatags-toggle">
              <input type="checkbox" name="recaptcha_enabled" id="recaptcha_enabled" value="1" <?= $recaptcha_enabled === '1' ? 'checked' : '' ?>>
              <span class="slider"></span>
            </label>
          </div>
        </div>

        <div class="auth-settings-grid">
          <div class="form-group">
            <label for="recaptcha_sitekey"><?=_e('Site Key (RECAPTCHA_SITEKEY)')?></label>
            <div class="auth-secret-field">
              <input type="password" name="recaptcha_sitekey" id="recaptcha_sitekey" value="<?= htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" class="inp inp-w100">
              <button type="button" class="auth-secret-toggle" data-secret-toggle="recaptcha_sitekey" aria-controls="recaptcha_sitekey" aria-pressed="false"><?=_e('Show')?></button>
            </div>
            <span class="field-note"><?=_e('Leave empty to use <code>.env</code> configuration.')?></span>
          </div>
          <div class="form-group">
            <label for="recaptcha_secret"><?=_e('Secret Key (RECAPTCHA_SECRET)')?></label>
            <div class="auth-secret-field">
              <input type="password" name="recaptcha_secret" id="recaptcha_secret" value="<?= htmlspecialchars($recaptcha_secret, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" class="inp inp-w100">
              <button type="button" class="auth-secret-toggle" data-secret-toggle="recaptcha_secret" aria-controls="recaptcha_secret" aria-pressed="false"><?=_e('Show')?></button>
            </div>
            <span class="field-note"><?=_e('Leave empty to use <code>.env</code> configuration.')?></span>
          </div>
        </div>

        <div class="auth-settings-grid">
          <div class="form-group">
            <label for="bruteforce_max_attempts"><?=_e('Maximum failed attempts before block')?></label>
            <input type="number" name="bruteforce_max_attempts" id="bruteforce_max_attempts" min="1" max="100" value="<?= htmlspecialchars($display_max_attempts, ENT_QUOTES, 'UTF-8') ?>" class="inp inp-w100">
          </div>
          <div class="form-group">
            <label for="bruteforce_block_minutes"><?=_e('Block duration (minutes)')?></label>
            <input type="number" name="bruteforce_block_minutes" id="bruteforce_block_minutes" min="1" max="1440" value="<?= htmlspecialchars($display_block_minutes, ENT_QUOTES, 'UTF-8') ?>" class="inp inp-w100">
          </div>
        </div>
        <button type="button" class="adam-cancle auth-attempts-button" onclick="openAttemptModal()"><?= svg_ico('list') ?> <?=_e('View login attempts')?></button>
      </div>
    </div>

    <div class="settings-section settings-section--auth-paths" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('link') ?> <?=_e('Login & Register Page Path')?>
        <span class="chevron" aria-hidden="true">▸</span>
      </button>
      <div class="settings-section-body">
        <p class="settings-desc"><?=_e('Set a custom URL for login and registration pages. Save other settings first before changing paths to avoid being locked out.')?></p>
        <div class="auth-settings-grid">
          <div class="form-group">
            <label for="login_path"><?=_e('Login page path')?></label>
            <div class="permalink-builder">
              <span class="permalink-prefix">/</span>
              <input type="text" name="login_path" id="login_path" value="<?= htmlspecialchars($display_login_path, ENT_QUOTES, 'UTF-8') ?>" pattern="[a-z0-9_\/.\-]+" class="permalink-input">
              <span class="permalink-suffix">/</span>
            </div>
            <div class="permalink-example" id="login-path-example"><?=_e('Accessible at:')?> <code><?= htmlspecialchars(auth_path_example($display_login_path), ENT_QUOTES, 'UTF-8') ?></code></div>
            <span class="field-note"><?=_e('Example:')?> <code>masuk</code>, <code>login</code>, <code>pintu/oke/masuk</code></span>
          </div>
          <div class="form-group">
            <label for="register_path"><?=_e('Register page path')?></label>
            <div class="permalink-builder">
              <span class="permalink-prefix">/</span>
              <input type="text" name="register_path" id="register_path" value="<?= htmlspecialchars($display_register_path, ENT_QUOTES, 'UTF-8') ?>" pattern="[a-z0-9_\/.\-]+" class="permalink-input">
              <span class="permalink-suffix">/</span>
            </div>
            <div class="permalink-example" id="register-path-example"><?=_e('Accessible at:')?> <code><?= htmlspecialchars(auth_path_example($display_register_path), ENT_QUOTES, 'UTF-8') ?></code></div>
            <span class="field-note"><?=_e('Example:')?> <code>daftar</code>, <code>register</code>, <code>buat-akun</code></span>
          </div>
        </div>
      </div>
    </div>

    <div class="settings-section settings-section--auth-dashboard" data-open="1">
      <button type="button" class="settings-section-toggle" aria-expanded="true">
        <?= svg_ico('settings') ?> <?=_e('Dashboard Path')?>
        <span class="chevron" aria-hidden="true">▸</span>
      </button>
      <div class="settings-section-body">
        <div class="auth-settings-warning" role="note"><?= svg_ico('alert-triangle') ?><span><?=_e('Change with caution — if wrong, you will not be able to access the admin panel.')?> <?=_e('Save other settings first before changing this.')?></span></div>
        <div class="form-group">
          <label for="admin_path"><?=_e('Dashboard path')?></label>
          <div class="permalink-builder">
            <span class="permalink-prefix">/</span>
            <input type="text" name="admin_path" id="admin_path" value="<?= htmlspecialchars($display_admin_path, ENT_QUOTES, 'UTF-8') ?>" pattern="[a-z0-9_\/.\-]+" class="permalink-input">
            <span class="permalink-suffix">/</span>
          </div>
          <div class="permalink-example" id="admin-path-example"><?=_e('Accessible at:')?> <code>/<?= htmlspecialchars(trim($display_admin_path, '/'), ENT_QUOTES, 'UTF-8') ?>/</code></div>
          <span class="field-note"><?=_e('Example:')?> <code>panel</code>, <code>dashboard</code>, <code>rahasia/panel</code></span>
        </div>
      </div>
    </div>

    <div class="btn-row auth-settings-actions">
      <button type="submit" class="adam-button"><?= svg_ico('save') ?> <?=_e('Save')?></button>
      <a class="adam-cancle" href="<?= ADMIN_BASE_PATH ?>/?page=admin/settings/index"><?=_e('Back')?></a>
    </div>
  </form>
</section>

<div id="attempt-modal" class="adam-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="attempt-modal-title">
  <div class="adam-modal__panel auth-attempt-modal-panel" tabindex="-1">
    <div class="auth-attempt-modal-header">
      <h3 id="attempt-modal-title" class="adam-modal-title"><?=_e('Login Attempts')?></h3>
      <button type="button" class="auth-attempt-modal-close" onclick="closeAttemptModal()" aria-label="<?= h(__('Close')) ?>">&times;</button>
    </div>
    <div id="attempt-table-wrap" class="adam-table-wrapper auth-attempt-table-wrap">
      <table class="adam-table">
        <thead>
          <tr>
            <th><?=_e('Email')?></th>
            <th><?=_e('IP')?></th>
            <th><?=_e('Attempts')?></th>
            <th><?=_e('Last Attempt')?></th>
            <th><?=_e('Status')?></th>
            <th style="width:70px;"><?=_e('Actions')?></th>
          </tr>
        </thead>
        <tbody id="attempt-tbody">
          <tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--adam-muted);"><?=_e('Loading...')?></td></tr>
        </tbody>
      </table>
    </div>
    <div id="attempt-pagination" class="auth-attempt-pagination"></div>
  </div>
</div>

<script>
var attemptCsrfToken = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>';
var attemptModalLastFocus = null;

function toggleField(id) {
  var el = document.getElementById(id);
  if (!el) return;
  var visible = el.type === 'password';
  el.type = visible ? 'text' : 'password';
  var button = document.querySelector('[data-secret-toggle="' + id + '"]');
  if (button) {
    button.textContent = visible ? <?= json_encode(__('Hide')) ?> : <?= json_encode(__('Show')) ?>;
    button.setAttribute('aria-pressed', visible ? 'true' : 'false');
  }
}

function openAttemptModal() {
  var modal = document.getElementById('attempt-modal');
  if (!modal) return;
  attemptModalLastFocus = document.activeElement;
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
  var firstControl = modal.querySelector('button:not(:disabled), a[href], input:not(:disabled)');
  if (firstControl) firstControl.focus();
  loadAttempts(1);
}

function closeAttemptModal() {
  var modal = document.getElementById('attempt-modal');
  if (!modal) return;
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  if (attemptModalLastFocus && typeof attemptModalLastFocus.focus === 'function') attemptModalLastFocus.focus();
}

function loadAttempts(page) {
  var tbody = document.getElementById('attempt-tbody');
  var pagination = document.getElementById('attempt-pagination');
  tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--adam-muted);"><?=_e('Loading...')?></td></tr>';
  pagination.innerHTML = '';

  var url = window.location.pathname + window.location.search.replace(/[&?]action=[^&]*/g, '').replace(/[&?]p=\d+/g, '');
  url += (url.indexOf('?') === -1 ? '?' : '&') + 'action=list_attempts&p=' + page;

  fetch(url)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      tbody.innerHTML = data.html;
      pagination.innerHTML = data.pagination;
    })
    .catch(function() {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--adam-danger);">' + <?=json_encode(__('Failed to load data.'))?> + '</td></tr>';
    });
}

function deleteAttempt(id) {
  if (!confirm(<?= json_encode(__('Delete this login attempt data?')) ?>)) return;

  var form = new FormData();
  form.append('action', 'delete_attempt');
  form.append('id', id);
  form.append('csrf_token', attemptCsrfToken);

  fetch(window.location.href, { method: 'POST', body: form })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.ok) {
        loadAttempts(1);
      } else {
        alert(<?= json_encode(__('Failed:')) ?> + ' ' + (data.error || <?= json_encode(__('Unknown error.')) ?>));
      }
    })
    .catch(function() {
      alert(<?= json_encode(__('Failed to delete data.')) ?>);
    });
}

(function(){
  document.querySelectorAll('.auth-secret-toggle').forEach(function(button){
    button.addEventListener('click', function(){ toggleField(this.getAttribute('data-secret-toggle') || ''); });
  });

  document.querySelectorAll('.auth-settings .settings-section[data-open]').forEach(function(section){
    var toggle = section.querySelector('.settings-section-toggle');
    var body = section.querySelector('.settings-section-body');
    if (!toggle || !body) return;
    toggle.addEventListener('click', function(){
      var open = section.getAttribute('data-open') === '1';
      section.setAttribute('data-open', open ? '0' : '1');
      body.hidden = open;
      toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    });
  });

  [['login_path', 'login-path-example'], ['register_path', 'register-path-example'], ['admin_path', 'admin-path-example']].forEach(function(pair){
    var input = document.getElementById(pair[0]);
    var output = document.getElementById(pair[1]);
    if (!input || !output) return;
    input.addEventListener('input', function(){
      var path = String(input.value || '').replace(/^\/+|\/+$/g, '');
      var code = output.querySelector('code');
      if (code) code.textContent = '/' + path + '/';
    });
  });

  var modal = document.getElementById('attempt-modal');
  if (!modal) return;
  modal.addEventListener('click', function(event){
    if (event.target === modal) closeAttemptModal();
  });
  document.addEventListener('keydown', function(event){
    if (modal.getAttribute('aria-hidden') !== 'false') return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeAttemptModal();
      return;
    }
    if (event.key !== 'Tab') return;
    var focusable = Array.prototype.slice.call(modal.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), [tabindex]:not([tabindex="-1"])'));
    if (focusable.length === 0) return;
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
})();
</script>

<?php
if (function_exists('adiwira_bootstrap_toasts_script')) {
    $toast_items = $page_toasts;
    if ($success_msg !== '') {
        $toast_items[] = ['type' => 'success', 'message' => $success_msg];
    }
    foreach ($errors as $msg) {
        $toast_items[] = ['type' => 'error', 'message' => (string)$msg];
    }
    if (!empty($toast_items)) {
        echo adiwira_bootstrap_toasts_script($toast_items);
    }
}
