<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';

[$uid] = adiwira_require_login($pdo, false);
$targetUserId = (int)($_REQUEST['user_id'] ?? $uid);
if ($targetUserId <= 0) adiwira_render_404();
if (!user_can($pdo, $uid, 'core.sessions.manage', ['owner_id' => $targetUserId])) adiwira_render_404();

$targetStmt = $pdo->prepare(
    'SELECT id, name, username, email, is_site_owner, is_deleted
     FROM users WHERE id = :id LIMIT 1'
);
$targetStmt->execute([':id' => $targetUserId]);
$targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);
if (!$targetUser || (int)$targetUser['is_deleted'] === 1) adiwira_render_404();
$sessionActor = authorization_actor($pdo, $uid);
if ((int)$targetUser['is_site_owner'] === 1
    && ($sessionActor === null || $sessionActor['is_site_owner'] !== true)) {
    adiwira_render_404();
}

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/sessions/index&user_id=' . $targetUserId;
$currentHash = session_correlation_id(false);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) {
        adiwira_redirect_with_flash($selfUrl, 'error', __('Invalid CSRF token.'));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();
        $actorStillSiteOwner = authorization_lock_site_owner_actor($pdo, $uid);
        if (!authorization_lock_actor_permissions($pdo, $uid)
            || !user_can($pdo, $uid, 'core.sessions.manage', ['owner_id' => $targetUserId])) {
            throw new RuntimeException('Session permission changed.');
        }
        $lockTarget = $pdo->prepare('SELECT id, is_site_owner, is_deleted FROM users WHERE id = :id FOR UPDATE');
        $lockTarget->execute([':id' => $targetUserId]);
        $lockedTarget = $lockTarget->fetch(PDO::FETCH_ASSOC);
        if (!$lockedTarget || (int)$lockedTarget['is_deleted'] === 1) {
            throw new RuntimeException('Target user is unavailable.');
        }
        if ((int)$lockedTarget['is_site_owner'] === 1 && !$actorStillSiteOwner) {
            throw new RuntimeException('Site Owner authority changed.');
        }

        $revokedCurrent = false;
        if ($action === 'revoke') {
            $sessionHash = (string)($_POST['session_key_hash'] ?? '');
            $count = auth_sessions_revoke($pdo, $sessionHash, $uid, 'manual', $targetUserId);
            $revokedCurrent = $targetUserId === $uid && is_string($currentHash)
                && auth_sessions_hash_is_valid($sessionHash) && hash_equals($currentHash, $sessionHash);
        } elseif ($action === 'revoke_others' && $targetUserId === $uid && is_string($currentHash)) {
            $count = auth_sessions_revoke_user($pdo, $targetUserId, $uid, 'manual', $currentHash);
        } elseif ($action === 'revoke_all' && $targetUserId !== $uid) {
            $count = auth_sessions_revoke_user($pdo, $targetUserId, $uid, 'admin_revoked');
        } else {
            throw new InvalidArgumentException('Unknown session action.');
        }
        $pdo->commit();

        if ($revokedCurrent) {
            logout_user(false);
            $loginPath = function_exists('get_login_path') ? get_login_path($pdo) : 'login';
            header('Location: /' . trim($loginPath, '/') . '/');
            exit;
        }
        adiwira_redirect_with_flash(
            $selfUrl,
            'success',
            $count > 0 ? sprintf(__('%d session(s) revoked.'), $count) : __('No active sessions were changed.')
        );
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[sessions] ' . $error->getMessage());
        adiwira_redirect_with_flash($selfUrl, 'error', __('Failed to revoke login session.'));
    }
}

try {
    $sessions = auth_sessions_for_user($pdo, $targetUserId, is_string($currentHash) ? $currentHash : null, 30);
    $activeSessions = auth_sessions_active_for_user($pdo, $targetUserId, is_string($currentHash) ? $currentHash : null, 100);
    $activeSessionCount = auth_sessions_active_count($pdo, $targetUserId);
} catch (Throwable $error) {
    error_log('[sessions] list failed: ' . $error->getMessage());
    $sessions = [];
    $activeSessions = [];
    $activeSessionCount = 0;
}
$recentSessions = array_slice($sessions, 0, 10);
$targetLabel = user_avatar_display_name($targetUser, __('User'));
$isOwn = $targetUserId === $uid;

$displayUtc = static function (?string $value): string {
    $date = function_exists('app_utc_mysql_to_site') ? app_utc_mysql_to_site($value) : null;
    return $date ? app_display_datetime($date) : '-';
};
?>
<section class="adam-card auth-sessions-page">
  <div class="auth-sessions-heading">
    <div>
      <p class="auth-sessions-eyebrow"><?= _e('Account security') ?></p>
      <h2><?= _e('Active Sessions') ?></h2>
      <p><?= $isOwn
          ? _e('Review where your account is signed in and revoke access you no longer recognize.')
          : sprintf(__('Review and revoke login sessions for %s.'), htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8')) ?></p>
    </div>
    <?php if ($isOwn && $activeSessionCount > 1): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="revoke_others">
        <input type="hidden" name="user_id" value="<?= $targetUserId ?>">
        <button type="submit" class="auth-session-button auth-session-button--danger"><?= _e('Revoke other sessions') ?></button>
      </form>
    <?php elseif (!$isOwn && $activeSessionCount > 0): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="revoke_all">
        <input type="hidden" name="user_id" value="<?= $targetUserId ?>">
        <button type="submit" class="auth-session-button auth-session-button--danger"><?= _e('Revoke all active sessions') ?></button>
      </form>
    <?php endif; ?>
  </div>

  <div class="auth-session-list">
    <?php if ($activeSessions === []): ?>
      <div class="auth-session-empty"><?= _e('No active login sessions.') ?></div>
    <?php else: foreach ($activeSessions as $item): ?>
      <article class="auth-session-item<?= $item['current'] ? ' is-current' : '' ?>">
        <div class="auth-session-icon"><?= svg_ico('monitor') ?></div>
        <div class="auth-session-copy">
          <div class="auth-session-title">
            <strong><?= htmlspecialchars(auth_sessions_device_label($item['user_agent']), ENT_QUOTES, 'UTF-8') ?></strong>
            <?php if ($item['current']): ?><span><?= _e('Current session') ?></span><?php endif; ?>
          </div>
          <p><?= sprintf(__('Last active: %s'), htmlspecialchars($displayUtc($item['last_seen_at_utc']), ENT_QUOTES, 'UTF-8')) ?></p>
          <small><?= htmlspecialchars((string)($item['ip_address'] ?? __('Unknown IP')), ENT_QUOTES, 'UTF-8') ?></small>
        </div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="user_id" value="<?= $targetUserId ?>">
          <input type="hidden" name="session_key_hash" value="<?= htmlspecialchars((string)$item['session_key_hash'], ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" class="auth-session-button"><?= $item['current'] ? _e('Sign out') : _e('Revoke') ?></button>
        </form>
      </article>
    <?php endforeach; endif; ?>
  </div>
</section>

<section class="adam-card auth-sessions-page auth-sessions-recent">
  <div class="auth-sessions-heading">
    <div>
      <p class="auth-sessions-eyebrow"><?= _e('Security history') ?></p>
      <h2><?= _e('Recent Login') ?></h2>
      <p><?= _e('Recent sign-ins are retained briefly for account review.') ?></p>
    </div>
  </div>
  <div class="adam-table-wrapper">
    <table class="adam-table auth-sessions-table">
      <thead><tr><th><?= _e('Device') ?></th><th><?= _e('Signed in') ?></th><th><?= _e('Last active') ?></th><th><?= _e('Status') ?></th></tr></thead>
      <tbody>
      <?php if ($recentSessions === []): ?>
        <tr><td colspan="4" class="empty-state"><?= _e('No login history yet.') ?></td></tr>
      <?php else: foreach ($recentSessions as $item): ?>
        <tr>
          <td><strong><?= htmlspecialchars(auth_sessions_device_label($item['user_agent']), ENT_QUOTES, 'UTF-8') ?></strong><br><small><?= htmlspecialchars((string)($item['ip_address'] ?? __('Unknown IP')), ENT_QUOTES, 'UTF-8') ?></small></td>
          <td><?= htmlspecialchars($displayUtc($item['created_at_utc']), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars($displayUtc($item['last_seen_at_utc']), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="auth-session-status <?= $item['active'] ? 'is-active' : 'is-ended' ?>"><?= $item['active'] ? _e('Active') : _e('Ended') ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>
