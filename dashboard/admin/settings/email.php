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

$base = ADMIN_BASE_PATH;
$selfUrl = $base . '/?page=admin/settings/email';
$actor = authorization_actor($pdo, $uid);
$errors = [];
$config = jy_mail_config($pdo);
$transport = (string)$config['transport'];
$fallbackTransport = (string)$config['fallback_transport'];
$fromName = (string)$config['from_name'];
$fromAddress = (string)$config['from_address'];
$replyTo = (string)$config['reply_to'];
$logMode = (string)$config['log'];
$savedTransport = $transport;
$savedFallbackTransport = $fallbackTransport;
$transports = jy_mail_transports();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!adiwira_csrf_validate($token)) $errors[] = __('Invalid CSRF token.');

    $scalar = static function (string $key): ?string {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : null;
    };
    $submittedTransport = $scalar('mail_transport');
    $submittedFallback = $scalar('mail_fallback_transport');
    $submittedFromName = $scalar('mail_from_name');
    $submittedFromAddress = $scalar('mail_from_address');
    $submittedReplyTo = $scalar('mail_reply_to');
    $submittedLog = $scalar('mail_log');

    if (in_array(null, [
        $submittedTransport,
        $submittedFallback,
        $submittedFromName,
        $submittedFromAddress,
        $submittedReplyTo,
        $submittedLog,
    ], true)) {
        $errors[] = __('Invalid email settings request.');
    } else {
        $transport = strtolower((string)$submittedTransport);
        $fallbackTransport = strtolower((string)$submittedFallback);
        $fromName = (string)$submittedFromName;
        $fromAddress = (string)$submittedFromAddress;
        $replyTo = (string)$submittedReplyTo;
        $logMode = strtolower((string)$submittedLog);
    }

    if (!jy_mail_header_text_is_valid($fromName, 200, false)) {
        $errors[] = __('From name is required and must not contain control characters.');
    }
    if (!jy_mail_address_is_valid($fromAddress)) {
        $errors[] = __('Enter a valid From email address.');
    }
    if ($replyTo !== '' && !jy_mail_address_is_valid($replyTo)) {
        $errors[] = __('Enter a valid Reply-To email address.');
    }
    if (!isset($transports[$transport]) && $transport !== $savedTransport) {
        $errors[] = __('Selected mail transport is not available.');
    } elseif (isset($transports[$transport]) && $transports[$transport]['available'] !== true && $transport !== $savedTransport) {
        $errors[] = __('Selected mail transport is not available.');
    }
    if ($fallbackTransport !== '') {
        if ($fallbackTransport === $transport) {
            $errors[] = __('Fallback transport must be different from the primary transport.');
        } elseif (!isset($transports[$fallbackTransport]) && $fallbackTransport !== $savedFallbackTransport) {
            $errors[] = __('Selected fallback transport is not available.');
        } elseif (isset($transports[$fallbackTransport]) && $transports[$fallbackTransport]['available'] !== true
            && $fallbackTransport !== $savedFallbackTransport) {
            $errors[] = __('Selected fallback transport is not available.');
        }
    }
    if (!in_array($logMode, ['failures', 'all', 'off'], true)) {
        $errors[] = __('Invalid mail logging selection.');
    }

    if ($errors === []) {
        $ownsTransaction = false;
        try {
            if ($pdo->inTransaction()) throw new RuntimeException('mail settings cannot join an existing transaction');
            if (!$pdo->beginTransaction()) throw new RuntimeException('mail settings transaction failed');
            $ownsTransaction = true;
            foreach ([
                'mail_transport' => $transport,
                'mail_fallback_transport' => $fallbackTransport,
                'mail_from_name' => $fromName,
                'mail_from_address' => $fromAddress,
                'mail_reply_to' => $replyTo,
                'mail_log' => $logMode,
            ] as $key => $value) {
                if (!settings_set($pdo, $key, $value, 1)) {
                    throw new RuntimeException('mail setting write failed');
                }
            }
            $pdo->commit();
            $ownsTransaction = false;
            try {
                do_action('jy_mail_settings_saved', $pdo, $uid);
            } catch (Throwable $error) {
                error_log('[jy-mail] settings observer failed');
            }
            adiwira_redirect_with_flash($selfUrl, 'success', __('Email settings saved successfully.'));
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            unset($GLOBALS['__jy_settings_autoload_cache']);
            error_log('[jy-mail] settings save failed');
            $errors[] = __('Failed to save email settings.');
        }
    }
}

$configuredStatus = $transports[$transport]['available'] ?? false;
$testRecipient = is_array($actor) ? (string)($actor['email'] ?? '') : '';
?>
<style>
.mail-settings{
  max-width:980px;
  margin:18px auto;
  padding:0;
  overflow:hidden;
  border:1px solid var(--adam-border,#e2e8f0);
  border-radius:18px;
  background:var(--adam-card,#fff);
  box-shadow:0 18px 50px rgba(15,23,42,.07);
}
.mail-settings__head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:18px;
  padding:22px 24px;
  border-bottom:1px solid var(--adam-border,#e2e8f0);
  background:linear-gradient(135deg,var(--adam-surface-3,#f8fafc),var(--adam-card,#fff) 72%);
}
.mail-settings__title{display:flex;align-items:center;gap:14px;min-width:0}
.mail-settings__title-icon{
  display:inline-flex;
  flex:0 0 46px;
  width:46px;
  height:46px;
  align-items:center;
  justify-content:center;
  border:1px solid color-mix(in srgb,var(--adam-primary,#ef3f28) 20%,transparent);
  border-radius:14px;
  background:var(--adam-primary-soft,#fff1ed);
  color:var(--adam-primary,#ef3f28);
  box-shadow:inset 0 1px rgba(255,255,255,.7);
}
.mail-settings__title-icon svg{width:22px;height:22px}
.mail-settings__head h1{margin:0;color:var(--adam-text,#0f172a);font-size:1.55rem;line-height:1.2}
.mail-settings__head p{margin:.35rem 0 0;color:var(--adam-muted,#64748b);line-height:1.5}
.mail-settings__content{padding:20px}
.mail-settings__grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(280px,.8fr);gap:18px;align-items:start}
.mail-settings__side{display:grid;gap:18px;align-content:start}
.mail-settings__panel{
  padding:20px;
  border:1px solid var(--adam-border,#e2e8f0);
  border-radius:14px;
  background:var(--adam-card,#fff);
  box-shadow:0 7px 22px rgba(15,23,42,.045);
}
.mail-settings__panel--primary{border-top:3px solid var(--adam-primary,#ef3f28)}
.mail-settings__panel h2{margin:0 0 7px;color:var(--adam-text,#0f172a);font-size:1.05rem;line-height:1.35}
.mail-settings__panel>p{margin:0 0 18px;color:var(--adam-muted,#64748b);font-size:.88rem;line-height:1.55}
.mail-settings__fields{display:grid;gap:17px}
.mail-settings__fields label{display:grid;gap:7px;min-width:0;color:var(--adam-text-3,#334155);font-size:.84rem;font-weight:750;line-height:1.35}
.mail-settings__fields small{color:var(--adam-muted,#64748b);font-size:.76rem;font-weight:400;line-height:1.45}
.mail-settings__row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start}
.mail-settings .adam-input{
  box-sizing:border-box;
  display:block;
  width:100%;
  min-width:0;
  min-height:44px;
  margin:0;
  padding:10px 12px;
  border:1px solid var(--adam-border-2,#cbd5e1);
  border-radius:10px;
  outline:0;
  background:var(--adam-surface-2,#fff);
  color:var(--adam-text,#0f172a);
  font:inherit;
  font-size:.9rem;
  font-weight:500;
  line-height:1.35;
  transition:border-color .16s ease,box-shadow .16s ease,background .16s ease;
}
.mail-settings select.adam-input{cursor:pointer;padding-right:34px}
.mail-settings .adam-input:hover:not(:disabled){border-color:color-mix(in srgb,var(--adam-primary,#ef3f28) 38%,var(--adam-border-2,#cbd5e1))}
.mail-settings .adam-input:focus,
.mail-settings .adam-input:focus-visible{
  border-color:var(--adam-primary,#ef3f28);
  background:var(--adam-card,#fff);
  box-shadow:0 0 0 3px var(--adam-focus,rgba(239,63,40,.2));
}
.mail-settings .adam-input:disabled{cursor:not-allowed;opacity:.58;background:var(--adam-surface-3,#f8fafc)}
.mail-settings .adam-input:user-invalid{border-color:var(--adam-danger,#dc2626)}
.mail-settings__actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}
.mail-settings__actions .adam-button{min-height:42px;padding-inline:15px;border-radius:10px}
.mail-settings__actions .adam-button svg{width:17px;height:17px}
.mail-settings__status{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:14px;
  border:1px solid var(--adam-border,#e2e8f0);
  border-radius:11px;
  background:linear-gradient(135deg,var(--adam-surface-3,#f8fafc),var(--adam-card,#fff));
}
.mail-settings__status b{display:block;margin-bottom:3px;color:var(--adam-text,#0f172a);font-size:.95rem}
.mail-settings__status small{color:var(--adam-muted,#64748b);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.74rem}
.mail-settings__badge{display:inline-flex;align-items:center;min-height:26px;padding:4px 9px;border-radius:999px;font-size:.72rem;font-weight:800;white-space:nowrap}
.mail-settings__badge.ok{background:var(--adam-success-soft,#dcfce7);color:var(--adam-success,#15803d)}
.mail-settings__badge.bad{background:var(--adam-danger-soft,#fee2e2);color:var(--adam-danger,#b91c1c)}
.mail-settings__notice{margin:20px 20px 0;padding:12px 14px;border:1px solid var(--adam-danger-light,#fecaca);border-radius:10px;background:var(--adam-danger-soft,#fef2f2);color:var(--adam-danger,#b91c1c)}
@media(max-width:760px){
  .mail-settings{margin:12px auto;border-radius:14px}
  .mail-settings__head{align-items:flex-start;padding:18px;flex-direction:column}
  .mail-settings__head>.adam-button{width:100%;justify-content:center}
  .mail-settings__content{padding:14px}
  .mail-settings__grid,.mail-settings__row{grid-template-columns:1fr}
  .mail-settings__panel{padding:16px}
}
</style>
<section class="adam-card mail-settings">
  <header class="mail-settings__head">
    <div class="mail-settings__title">
      <span class="mail-settings__title-icon" aria-hidden="true"><?=svg_ico('mail')?></span>
      <div>
        <h1><?=_e('Email Delivery')?></h1>
        <p><?=_e('Configure sender identity, transport selection, and outgoing email tests.')?></p>
      </div>
    </div>
    <a class="adam-button ghost" href="<?=h($base)?>/?page=admin/settings/index"><?=_e('Back to Settings')?></a>
  </header>

  <?php if ($errors !== []): ?>
    <div class="mail-settings__notice" role="alert"><ul style="margin:0;padding-left:18px"><?php foreach ($errors as $error): ?><li><?=h($error)?></li><?php endforeach;?></ul></div>
  <?php endif; ?>

  <div class="mail-settings__content">
  <div class="mail-settings__grid">
    <form id="email-settings-form" class="mail-settings__panel mail-settings__panel--primary" method="post" novalidate data-unsaved-guard<?= (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $errors) ? ' data-unsaved-guard-initial-dirty' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
      <h2><?=_e('Mail Configuration')?></h2>
      <p><?=_e('Plugins send through the Core Mail API and do not need to depend on a specific SMTP plugin.')?></p>
      <div class="mail-settings__fields">
        <div class="mail-settings__row">
          <label><?=_e('Primary transport')?>
            <select class="adam-input" name="mail_transport" required>
              <?php if (!isset($transports[$transport])): ?><option value="<?=h($transport)?>" selected><?=h($transport)?> (<?=_e('Unavailable')?>)</option><?php endif;?>
              <?php foreach ($transports as $name => $row): ?><option value="<?=h($name)?>" <?=$transport===$name?'selected':''?> <?=(!$row['available']&&$transport!==$name)?'disabled':''?>><?=h(__((string)$row['label']))?><?=$row['available']?'':' ('.__('Unavailable').')'?></option><?php endforeach;?>
            </select>
            <small><?=_e('Native transport delegates delivery to the hosting server configured for PHP.')?></small>
          </label>
          <label><?=_e('Fallback transport')?>
            <select class="adam-input" name="mail_fallback_transport">
              <option value=""><?=_e('No fallback')?></option>
              <?php if ($fallbackTransport!=='' && !isset($transports[$fallbackTransport])): ?><option value="<?=h($fallbackTransport)?>" selected><?=h($fallbackTransport)?> (<?=_e('Unavailable')?>)</option><?php endif;?>
              <?php foreach ($transports as $name => $row): ?><option value="<?=h($name)?>" <?=$fallbackTransport===$name?'selected':''?> <?=(!$row['available']&&$fallbackTransport!==$name)?'disabled':''?>><?=h(__((string)$row['label']))?><?=$row['available']?'':' ('.__('Unavailable').')'?></option><?php endforeach;?>
            </select>
            <small><?=_e('Fallback is attempted only after an unavailable or temporary transport failure.')?></small>
          </label>
        </div>
        <div class="mail-settings__row">
          <label><?=_e('From name')?>
            <input class="adam-input" name="mail_from_name" maxlength="200" value="<?=h($fromName)?>" required>
          </label>
          <label><?=_e('From email address')?>
            <input class="adam-input" type="email" name="mail_from_address" maxlength="254" value="<?=h($fromAddress)?>" required>
          </label>
        </div>
        <div class="mail-settings__row">
          <label><?=_e('Reply-To email address')?>
            <input class="adam-input" type="email" name="mail_reply_to" maxlength="254" value="<?=h($replyTo)?>">
            <small><?=_e('Optional. Leave empty when replies should use the From address.')?></small>
          </label>
          <label><?=_e('Delivery logging')?>
            <select class="adam-input" name="mail_log">
              <option value="failures" <?=$logMode==='failures'?'selected':''?>><?=_e('Failures only')?></option>
              <option value="all" <?=$logMode==='all'?'selected':''?>><?=_e('All attempts (redacted)')?></option>
              <option value="off" <?=$logMode==='off'?'selected':''?>><?=_e('Off')?></option>
            </select>
            <small><?=_e('Logs never include recipients, subjects, message bodies, credentials, or OTP values.')?></small>
          </label>
        </div>
      </div>
      <div class="mail-settings__actions"><button class="adam-button" type="submit"><?=svg_ico('save')?> <?=_e('Save Email Settings')?></button></div>
    </form>

    <div class="mail-settings__side">
      <div class="mail-settings__panel">
        <h2><?=_e('Configured Transport')?></h2>
        <p><?=_e('Transport availability only confirms that the adapter can run. Use a test email to verify acceptance.')?></p>
        <div class="mail-settings__status"><div><b><?=h($transports[$transport]['label'] ?? $transport)?></b><small><?=h($transport)?></small></div><span class="mail-settings__badge <?=$configuredStatus?'ok':'bad'?>"><?php if($configuredStatus):?><?=_e('Available')?><?php else:?><?=_e('Unavailable')?><?php endif;?></span></div>
      </div>

      <form id="email-test-form" class="mail-settings__panel" method="post" action="<?=h($base)?>/admin/settings/email_test.php">
        <input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>">
        <input type="hidden" name="return_to" value="<?=h($selfUrl)?>">
        <h2><?=_e('Send Test Email')?></h2>
        <p><?=_e('The test uses saved settings and is rate-limited. Transport acceptance does not guarantee inbox delivery.')?></p>
        <div class="mail-settings__fields"><label><?=_e('Recipient email address')?><input class="adam-input" type="email" name="recipient" maxlength="254" value="<?=h($testRecipient)?>" required></label></div>
        <div class="mail-settings__actions"><button class="adam-button" type="submit"><?=svg_ico('mail')?> <?=_e('Send Test Email')?></button></div>
      </form>
    </div>
  </div>
  </div>
</section>
