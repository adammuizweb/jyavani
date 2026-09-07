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
            $html .= '<td>' . ($blocked ? '<span class="badge" style="background:rgba(229,57,53,0.12);color:var(--adam-danger);border-color:rgba(229,57,53,0.2);">' . __('Blocked') . '</span>' : '<span class="badge" style="background:rgba(30,143,74,0.08);color:var(--adam-success);border-color:rgba(30,143,74,0.18);">' . __('Active') . '</span>') . '</td>';
            $html .= '<td><button class="adam-hapus" onclick="deleteAttempt(' . $id . ')" title="' . __('Delete') . '">' . __('Delete') . '</button></td>';
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

    if ($new_admin_path === '') {
        $errors[] = __('Dashboard path is required.');
    } elseif (!preg_match('/^[a-z0-9_\/.-]+$/', $new_admin_path)) {
        $errors[] = __('Dashboard path can only contain lowercase letters, numbers, slash, dot, underscore, and dash.');
    } elseif ($new_admin_path === 'static' || $new_admin_path === 'admin' || strpos($new_admin_path, '/') === 0) {
        $errors[] = __('Dashboard path cannot be "static", "admin", or start with slash.');
    } else {
        $admin_path = trim($new_admin_path, '/');
    }

    if ($new_login_path === '') {
        $errors[] = __('Login page path is required.');
    } elseif (!preg_match('/^[a-z0-9_\/.-]+$/', $new_login_path)) {
        $errors[] = __('Login path can only contain lowercase letters, numbers, slash, dot, underscore, and dash.');
    } else {
        $login_path = trim($new_login_path, '/');
    }

    if ($new_register_path === '') {
        $errors[] = __('Registration page path is required.');
    } elseif (!preg_match('/^[a-z0-9_\/.-]+$/', $new_register_path)) {
        $errors[] = __('Registration path can only contain lowercase letters, numbers, slash, dot, underscore, and dash.');
    } else {
        $register_path = trim($new_register_path, '/');
    }

    foreach ([[$admin_path, true], [$login_path, false], [$register_path, false]] as [$authPath, $prefixMatch]) {
        if ($authPath !== '' && function_exists('content_route_conflicts_with_setting_path')
            && content_route_conflicts_with_setting_path($pdo, $authPath, $prefixMatch)) {
            $errors[] = __('Path conflicts with an existing content route.');
            break;
        }
    }

    if ($new_max_attempts === '' || (int)$new_max_attempts < 1) {
        $errors[] = __('Maximum failed login attempts must be at least 1.');
    } else {
        $bruteforce_max_attempts = (string)max(1, (int)$new_max_attempts);
    }

    if ($new_block_minutes === '' || (int)$new_block_minutes < 1) {
        $errors[] = __('Block duration must be at least 1 minute.');
    } else {
        $bruteforce_block_minutes = (string)max(1, (int)$new_block_minutes);
    }

    $recaptcha_sitekey = $new_recaptcha_sitekey;
    $recaptcha_secret  = $new_recaptcha_secret;

    if (!$errors) {
        $updated = true;
        $updated &= settings_set($pdo, 'registration_enabled', $registration_enabled, 1);
        $updated &= settings_set($pdo, 'registration_approval_required', $registration_approval, 1);
        $updated &= settings_set($pdo, 'recaptcha_enabled', $recaptcha_enabled, 1);
        $updated &= settings_set($pdo, 'recaptcha_sitekey', $recaptcha_sitekey, 1);
        $updated &= settings_set($pdo, 'recaptcha_secret', $recaptcha_secret, 1);
        $updated &= settings_set($pdo, 'login_path', $login_path, 1);
        $updated &= settings_set($pdo, 'register_path', $register_path, 1);
        $updated &= settings_set($pdo, 'admin_path', $admin_path, 1);
        $updated &= settings_set($pdo, 'bruteforce_max_attempts', $bruteforce_max_attempts, 1);
        $updated &= settings_set($pdo, 'bruteforce_block_minutes', $bruteforce_block_minutes, 1);

        if ($updated) {
            if (function_exists('adiwira_redirect_with_flash')) {
                $redirect_path = '/' . trim($admin_path, '/') . '/?page=admin/settings/auth';
                adiwira_redirect_with_flash($redirect_path, 'success', __('Login & registration settings saved successfully.'));
                exit;
            }
            $success_msg = __('Login & registration settings saved successfully.');
        } else {
            $errors[] = __('Failed to save settings.');
        }
    }
}

$show_inline_success = ($success_msg !== '' && !function_exists('adiwira_bootstrap_toasts_script'));
$show_inline_errors  = (!empty($errors) && !function_exists('adiwira_bootstrap_toasts_script'));

function auth_path_example(string $path): string {
    return '/' . trim($path, '/') . '/';
}
?>
<section class="adam-card" style="max-width:820px;margin:18px auto;">
  <h2><?=_e('Sign Up &amp; Sign In')?></h2>

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

    <h3 style="margin:1.2rem 0 .5rem;"><?=_e('User Registration')?></h3>

    <label style="display:flex;align-items:center;gap:8px;margin:.6rem 0;cursor:pointer;">
      <input type="checkbox" name="registration_enabled" value="1" <?= $registration_enabled === '1' ? 'checked' : '' ?>>
      <?=_e('Enable registration page')?>
    </label>

    <label style="display:flex;align-items:center;gap:8px;margin:.6rem 0;cursor:pointer;">
      <input type="checkbox" name="registration_approval_required" value="1" <?= $registration_approval === '1' ? 'checked' : '' ?>>
      <?=_e('New users need admin approval (account directly <code>is_locked</code>)')?>
    </label>

    <hr style="border:none;border-top:1px solid var(--adam-border);margin:1.2rem 0;">

    <h3 style="margin:1.2rem 0 .5rem;"><?=_e('Security')?></h3>

    <label style="display:flex;align-items:center;gap:8px;margin:.6rem 0;cursor:pointer;">
      <input type="checkbox" name="recaptcha_enabled" value="1" <?= $recaptcha_enabled === '1' ? 'checked' : '' ?>>
      <?=_e('Enable reCAPTCHA')?>
    </label>

    <div class="auth-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
      <label style="display:block;">
        Site Key (RECAPTCHA_SITEKEY)
        <div style="position:relative;margin-top:.35rem;">
          <input type="password" name="recaptcha_sitekey" id="recaptcha_sitekey"
            value="<?= htmlspecialchars($recaptcha_sitekey, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="off"
            style="width:100%;padding:.55rem 2.2rem .55rem .55rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);">
          <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--adam-muted);font-size:.85rem;user-select:none;" onclick="toggleField('recaptcha_sitekey')"><?=_e('Show')?></span>
        </div>
        <div style="font-size:.8rem;color:var(--adam-muted-2);margin-top:4px;">
          <?=_e('Leave empty to use <code>.env</code> configuration.')?>
        </div>
      </label>
      <label style="display:block;">
        Secret Key (RECAPTCHA_SECRET)
        <div style="position:relative;margin-top:.35rem;">
          <input type="password" name="recaptcha_secret" id="recaptcha_secret"
            value="<?= htmlspecialchars($recaptcha_secret, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="off"
            style="width:100%;padding:.55rem 2.2rem .55rem .55rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);">
          <span style="position:absolute;right:8px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--adam-muted);font-size:.85rem;user-select:none;" onclick="toggleField('recaptcha_secret')"><?=_e('Show')?></span>
        </div>
        <div style="font-size:.8rem;color:var(--adam-muted-2);margin-top:4px;">
          <?=_e('Leave empty to use <code>.env</code> configuration.')?>
        </div>
      </label>
    </div>

    <div class="auth-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
      <label style="display:block;">
        <?=_e('Maximum failed attempts before block')?>
        <input type="number" name="bruteforce_max_attempts" min="1" max="100"
          value="<?= htmlspecialchars($bruteforce_max_attempts, ENT_QUOTES, 'UTF-8') ?>"
          style="width:100%;padding:.55rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);margin-top:.35rem;">
      </label>
      <label style="display:block;">
        <?=_e('Block duration (minutes)')?>
        <input type="number" name="bruteforce_block_minutes" min="1" max="1440"
          value="<?= htmlspecialchars($bruteforce_block_minutes, ENT_QUOTES, 'UTF-8') ?>"
          style="width:100%;padding:.55rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);margin-top:.35rem;">
      </label>
    </div>

    <hr style="border:none;border-top:1px solid var(--adam-border);margin:1.2rem 0;">

    <h3 style="margin:1.2rem 0 .5rem;"><?=_e('Login & Register Page Path')?></h3>

    <p style="font-size:.85rem;color:var(--adam-muted);margin-bottom:12px;">
      <?=_e('Set a custom URL for login and registration pages. Save other settings first before changing paths to avoid being locked out.')?>
    </p>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Login page path')?>
      <input type="text" name="login_path"
        value="<?= htmlspecialchars($login_path, ENT_QUOTES, 'UTF-8') ?>"
        pattern="[a-z0-9_\/.\-]+"
        style="width:100%;padding:.55rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);margin-top:.35rem;font-family:monospace;">
      <div style="font-size:.8rem;color:var(--adam-muted-2);margin-top:4px;">
        <?=_e('Accessible at:')?> <code><?= htmlspecialchars(auth_path_example($login_path), ENT_QUOTES, 'UTF-8') ?></code>
        &middot; <?=_e('Example:')?> <code>masuk</code>, <code>login</code>, <code>pintu/oke/masuk</code>, <code>gerbang/masuk</code>
      </div>
    </label>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Register page path')?>
      <input type="text" name="register_path"
        value="<?= htmlspecialchars($register_path, ENT_QUOTES, 'UTF-8') ?>"
        pattern="[a-z0-9_\/.\-]+"
        style="width:100%;padding:.55rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);margin-top:.35rem;font-family:monospace;">
      <div style="font-size:.8rem;color:var(--adam-muted-2);margin-top:4px;">
        <?=_e('Accessible at:')?> <code><?= htmlspecialchars(auth_path_example($register_path), ENT_QUOTES, 'UTF-8') ?></code>
        &middot; <?=_e('Example:')?> <code>daftar</code>, <code>register</code>, <code>gerbang/daftar</code>, <code>buat-akun</code>
      </div>
    </label>

    <hr style="border:none;border-top:1px solid var(--adam-border);margin:1.2rem 0;">

    <h3 style="margin:1.2rem 0 .5rem;"><?=_e('Dashboard Path')?></h3>

    <p style="font-size:.85rem;color:var(--adam-muted);margin-bottom:12px;">
      <?=_e('Change with caution — if wrong, you will not be able to access the admin panel.')?> <?=_e('Save other settings first before changing this.')?>
    </p>

    <label style="display:block;margin:.6rem 0;">
      <?=_e('Dashboard path')?>
      <input type="text" name="admin_path"
        value="<?= htmlspecialchars($admin_path, ENT_QUOTES, 'UTF-8') ?>"
        pattern="[a-z0-9_\/.\-]+"
        style="width:100%;padding:.55rem;border:1px solid var(--adam-border-2);border-radius:8px;background:var(--adam-card);color:var(--adam-text);margin-top:.35rem;font-family:monospace;">
      <div style="font-size:.8rem;color:var(--adam-muted-2);margin-top:4px;">
        <?=_e('Accessible at:')?> <code>/<?= htmlspecialchars($admin_path, ENT_QUOTES, 'UTF-8') ?>/</code>
        &middot; <?=_e('Example:')?> <code>panel</code>, <code>admin</code>, <code>dashboard</code>, <code>rahasia/panel</code>
      </div>
    </label>

    <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
      <button type="submit" class="adam-button"><?=_e('Save')?></button>
      <a class="adam-cancle" href="<?= ADMIN_BASE_PATH ?>/?page=admin/settings/index"><?=_e('Back')?></a>
      <button type="button" class="adam-cancle" onclick="openAttemptModal()" style="margin-left:auto;"><?=_e('View login attempts')?></button>
    </div>
  </form>
</section>

<!-- Modal Login Attempts -->
<div id="attempt-modal" style="display:none;align-items:center;justify-content:center;pointer-events:auto;" class="adam-modal">
  <div style="width:94vw;max-width:960px;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;background:var(--adam-card);color:var(--adam-text);border:1px solid var(--adam-border);border-radius:12px;padding:1.2rem 1.2rem 1rem;box-shadow:var(--adam-shadow);box-sizing:border-box;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
      <h3 style="margin:0;color:var(--adam-text-2);"><?=_e('Login Attempts')?></h3>
      <button onclick="closeAttemptModal()" style="background:none;border:0;font-size:1.4rem;cursor:pointer;color:var(--adam-muted);line-height:1;">&times;</button>
    </div>
    <div id="attempt-table-wrap" style="overflow-y:auto;flex:1;min-height:0;">
      <table class="adam-table" style="width:100%;">
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
    <div id="attempt-pagination" style="flex-shrink:0;"></div>
  </div>
</div>

<script>
var attemptCsrfToken = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>';

function toggleField(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.type = el.type === 'password' ? 'text' : 'password';
}

function openAttemptModal() {
  document.getElementById('attempt-modal').style.display = 'flex';
  loadAttempts(1);
}

function closeAttemptModal() {
  document.getElementById('attempt-modal').style.display = 'none';
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
  if (!confirm('<?=__('Delete this login attempt data?')?>')) return;

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
        alert('Gagal: ' + (data.error || 'unknown'));
      }
    })
    .catch(function() {
      alert('Gagal menghapus data.');
    });
}

// Klik di luar modal untuk tutup
document.getElementById('attempt-modal').addEventListener('click', function(e) {
  if (e.target === this) closeAttemptModal();
});
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
