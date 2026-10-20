<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once __DIR__ . '/../_guard.php';

[$uid] = adiwira_require_permission($pdo, 'core.audit.read', false);
adiwira_require_site_owner($pdo, false);

$base = ADMIN_BASE_PATH;
$tab = is_scalar($_GET['tab'] ?? null) ? (string)$_GET['tab'] : 'mutations';
if (!in_array($tab, ['mutations', 'access', 'settings'], true)) $tab = 'mutations';
$errors = [];
$accessSettings = access_log_settings($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['audit_action'] ?? '') === 'save_access_logging') {
    $tab = 'settings';
    if (!user_can($pdo, $uid, 'core.audit.manage')) adiwira_admin_404();
    if (!adiwira_csrf_validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '')) {
        $errors[] = __('Invalid CSRF token.');
    }
    $retention = (int)($_POST['retention_days'] ?? 0);
    if (!in_array($retention, ACCESS_LOG_RETENTION_DAYS, true)) {
        $errors[] = __('Select a valid access log retention period.');
        $retention = $accessSettings['retention_days'];
    }
    $requested = [
        'dashboard_enabled' => isset($_POST['dashboard_enabled']),
        'public_enabled' => isset($_POST['public_enabled']),
        'retention_days' => $retention,
    ];
    if ($errors === []) {
        $before = [
            'dashboard_navigation' => $accessSettings['dashboard_enabled'],
            'public_page_views' => $accessSettings['public_enabled'],
            'retention_days' => $accessSettings['retention_days'],
        ];
        $after = [
            'dashboard_navigation' => $requested['dashboard_enabled'],
            'public_page_views' => $requested['public_enabled'],
            'retention_days' => $requested['retention_days'],
        ];
        if ($before !== $after) {
            $cacheWasLoaded = isset($GLOBALS['__jy_settings_autoload_cache']) && is_array($GLOBALS['__jy_settings_autoload_cache']);
            $cacheSnapshot = $cacheWasLoaded ? $GLOBALS['__jy_settings_autoload_cache'] : null;
            try {
                if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                    throw new RuntimeException('Audit settings require an independent transaction.');
                }
                if (!authorization_lock_site_owner_actor($pdo, $uid)
                    || !authorization_lock_actor_permissions($pdo, $uid)
                    || !user_can($pdo, $uid, 'core.audit.manage')) {
                    throw new DomainException('Audit settings authority changed.');
                }
                foreach ([
                    'audit_dashboard_navigation_enabled' => $requested['dashboard_enabled'] ? '1' : '0',
                    'audit_public_page_views_enabled' => $requested['public_enabled'] ? '1' : '0',
                    'audit_access_retention_days' => (string)$requested['retention_days'],
                ] as $key => $value) {
                    if (!settings_set($pdo, $key, $value, 1)) throw new RuntimeException('Failed to persist audit setting.');
                }
                audit_log_record($pdo, [
                    'provider' => 'core',
                    'event_key' => 'audit.settings_changed',
                    'actor_user_id' => $uid,
                    'resource_type' => 'audit_settings',
                    'resource_id' => 'access_logging',
                    'resource_label' => 'Access logging',
                    'source' => 'admin.settings.audit',
                    'changes' => audit_log_changes($before, $after),
                    'metadata' => [],
                ]);
                if (!$pdo->commit()) throw new RuntimeException('Failed to commit audit settings.');
                $accessSettings = $requested;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($cacheWasLoaded) $GLOBALS['__jy_settings_autoload_cache'] = $cacheSnapshot;
                error_log('[audit-settings] Save failed: ' . $error->getMessage());
                $errors[] = __('Failed to save audit settings.');
            }
        } else {
            $accessSettings = $requested;
        }
        if ($errors === []) {
            try {
                $deleted = access_log_prune($pdo, $accessSettings['retention_days'], 5000);
                if ($deleted === 5000) access_log_mark_prune_due($pdo);
            } catch (Throwable $error) {
                error_log('[access-log] Settings saved but pruning failed: ' . $error->getMessage());
            }
            $target = $base . '/?page=admin/settings/audit&tab=settings';
            if (function_exists('adiwira_redirect_with_flash')) {
                adiwira_redirect_with_flash($target, 'success', __('Access logging settings saved successfully.'));
                exit;
            }
            header('Location: ' . $target, true, 302);
            exit;
        }
    }
    $accessSettings = $requested;
}

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$events = [];
$actors = [];
$providers = [];
$eventKeys = [];
$resourceTypes = [];
$total = 0;
$totalPages = 1;
$offset = 0;
$filters = [];

$parseDate = static function (string $value): ?DateTimeImmutable {
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, app_timezone());
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date && ($dateErrors === false || (($dateErrors['warning_count'] ?? 0) === 0 && ($dateErrors['error_count'] ?? 0) === 0))
        && $date->format('Y-m-d') === $value ? $date : null;
};
$addDateFilters = static function (array &$where, array &$params, array &$activeFilters) use ($parseDate): void {
    $fromDate = $activeFilters['from'] !== '' ? $parseDate($activeFilters['from']) : null;
    $toDate = $activeFilters['to'] !== '' ? $parseDate($activeFilters['to']) : null;
    if ($fromDate) {
        $where[] = 'occurred_at_utc >= :from_utc';
        $params[':from_utc'] = app_site_datetime_to_utc_mysql($fromDate);
    } else {
        $activeFilters['from'] = '';
    }
    if ($toDate) {
        $where[] = 'occurred_at_utc < :to_utc';
        $params[':to_utc'] = app_site_datetime_to_utc_mysql($toDate->modify('+1 day'));
    } else {
        $activeFilters['to'] = '';
    }
};

if ($tab === 'mutations') {
    $filters = [
        'q' => is_scalar($_GET['q'] ?? null) ? trim((string)$_GET['q']) : '',
        'provider' => is_scalar($_GET['provider'] ?? null) ? trim((string)$_GET['provider']) : '',
        'event' => is_scalar($_GET['event'] ?? null) ? trim((string)$_GET['event']) : '',
        'resource' => is_scalar($_GET['resource'] ?? null) ? trim((string)$_GET['resource']) : '',
        'actor' => max(0, (int)($_GET['actor'] ?? 0)),
        'from' => is_scalar($_GET['from'] ?? null) ? trim((string)$_GET['from']) : '',
        'to' => is_scalar($_GET['to'] ?? null) ? trim((string)$_GET['to']) : '',
    ];
    if (strlen($filters['q']) > 100) $filters['q'] = mb_strcut($filters['q'], 0, 100, 'UTF-8');
    foreach (['provider' => 100, 'event' => 191, 'resource' => 100] as $key => $limit) {
        if ($filters[$key] !== '' && (strlen($filters[$key]) > $limit
            || preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $filters[$key]) !== 1)) $filters[$key] = '';
    }
    $where = [];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = "LOCATE(:search, CONCAT_WS(' ', event_key, actor_label, COALESCE(actor_email, ''), resource_id, COALESCE(resource_label, ''), source, COALESCE(route, ''))) > 0";
        $params[':search'] = $filters['q'];
    }
    foreach (['provider' => 'provider', 'event' => 'event_key', 'resource' => 'resource_type'] as $filter => $column) {
        if ($filters[$filter] === '') continue;
        $where[] = "$column = :$filter";
        $params[":$filter"] = $filters[$filter];
    }
    if ($filters['actor'] > 0) {
        $where[] = 'actor_user_id = :actor';
        $params[':actor'] = $filters['actor'];
    }
    $addDateFilters($where, $params, $filters);
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_events' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare('SELECT * FROM audit_events' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $optionRows = static function (PDO $pdo, string $column, int $limit = 100): array {
        if (!in_array($column, ['provider', 'event_key', 'resource_type'], true)) return [];
        return $pdo->query("SELECT $column AS value, COUNT(*) AS total FROM audit_events GROUP BY $column ORDER BY $column ASC LIMIT $limit")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };
    $providers = $optionRows($pdo, 'provider');
    $eventKeys = $optionRows($pdo, 'event_key', 200);
    $resourceTypes = $optionRows($pdo, 'resource_type');
    $actors = $pdo->query(
        'SELECT actor_user_id, MAX(actor_label) AS actor_label, COUNT(*) AS total
         FROM audit_events WHERE actor_user_id IS NOT NULL GROUP BY actor_user_id ORDER BY actor_label ASC LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($tab === 'access') {
    $accessToday = new DateTimeImmutable('today', app_timezone());
    $defaultAccessFrom = $accessToday->modify('-7 days')->format('Y-m-d');
    $requestedAccessFrom = is_scalar($_GET['from'] ?? null) ? trim((string)$_GET['from']) : '';
    $requestedAccessTo = is_scalar($_GET['to'] ?? null) ? trim((string)$_GET['to']) : $accessToday->format('Y-m-d');
    $accessFromDate = $parseDate($requestedAccessFrom);
    $accessToDate = $parseDate($requestedAccessTo);
    if ($accessToDate === null || $accessToDate > $accessToday) $accessToDate = $accessToday;
    if ($accessFromDate === null || $accessFromDate > $accessToDate) {
        $accessFromDate = $accessToDate->modify('-7 days');
    }
    if ($accessFromDate < $accessToDate->modify('-31 days')) {
        $accessFromDate = $accessToDate->modify('-31 days');
    }
    $filters = [
        'q' => is_scalar($_GET['q'] ?? null) ? trim((string)$_GET['q']) : '',
        'surface' => is_scalar($_GET['surface'] ?? null) ? trim((string)$_GET['surface']) : '',
        'actor' => max(0, (int)($_GET['actor'] ?? 0)),
        'from' => $accessFromDate->format('Y-m-d'),
        'to' => $accessToDate->format('Y-m-d'),
    ];
    if (strlen($filters['q']) > 100) $filters['q'] = mb_strcut($filters['q'], 0, 100, 'UTF-8');
    if (!in_array($filters['surface'], ['', 'dashboard', 'frontend'], true)) $filters['surface'] = '';
    $where = [];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = "LOCATE(:search, CONCAT_WS(' ', event_key, actor_label, COALESCE(actor_email, ''), route, request_path, COALESCE(page_title, ''), COALESCE(resource_label, ''))) > 0";
        $params[':search'] = $filters['q'];
    }
    if ($filters['surface'] !== '') {
        $where[] = 'surface = :surface';
        $params[':surface'] = $filters['surface'];
    }
    if ($filters['actor'] > 0) {
        $where[] = 'actor_user_id = :actor';
        $params[':actor'] = $filters['actor'];
    }
    $addDateFilters($where, $params, $filters);
    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM access_events' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = min(200, max(1, (int)ceil($total / $perPage)));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare('SELECT * FROM access_events' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $actorCutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-30 days')->format('Y-m-d H:i:s.u');
    $actorStmt = $pdo->prepare(
        'SELECT actor_user_id, MAX(actor_label) AS actor_label, COUNT(*) AS total
         FROM access_events WHERE actor_user_id IS NOT NULL AND occurred_at_utc >= :cutoff
         GROUP BY actor_user_id ORDER BY actor_label ASC LIMIT 200'
    );
    $actorStmt->execute([':cutoff' => $actorCutoff]);
    $actors = $actorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$filterQuery = array_filter($filters, static fn(mixed $value): bool => $value !== '' && $value !== 0);
$pageUrl = static function (int $target) use ($base, $tab, $filterQuery): string {
    return $base . '/?' . http_build_query(array_merge(
        ['page' => 'admin/settings/audit', 'tab' => $tab],
        $filterQuery,
        ['p' => $target]
    ), '', '&', PHP_QUERY_RFC3986);
};
$displayTimestamp = static function (?string $value): string {
    $raw = trim((string)$value);
    $utc = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $raw, new DateTimeZone('UTC'));
    if (!$utc) $utc = app_parse_utc_mysql($raw);
    return $utc ? app_display_datetime($utc->setTimezone(app_timezone())) : $raw;
};
$prettyJson = static function (?string $value): string {
    if ($value === null || $value === '') return '';
    try {
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return '';
    }
};
$tabUrl = static fn(string $target): string => $base . '/?page=admin/settings/audit&tab=' . rawurlencode($target);
?>

<style>
.audit-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem}.audit-head h1{margin:0}.audit-head p{margin:.35rem 0 0;color:var(--text-muted,#64748b);max-width:76ch}.audit-tabs{display:flex;gap:.45rem;flex-wrap:wrap;margin-bottom:1rem}.audit-tab{padding:.55rem .8rem;border:1px solid var(--border,#dbe3ee);border-radius:.55rem;text-decoration:none;color:inherit}.audit-tab.is-active{background:var(--accent,#2563eb);border-color:var(--accent,#2563eb);color:#fff}.audit-filters{display:grid;grid-template-columns:minmax(220px,2fr) repeat(4,minmax(130px,1fr));gap:.7rem;align-items:end}.audit-filters label{display:grid;gap:.3rem;font-size:.84rem;font-weight:650}.audit-filters__dates{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}.audit-actions{display:flex;gap:.5rem;align-items:center}.audit-table-wrap{overflow-x:auto;margin-top:1rem}.audit-table{width:100%;border-collapse:collapse;min-width:940px}.audit-table th,.audit-table td{text-align:left;padding:.72rem;border-bottom:1px solid var(--border,#e2e8f0);vertical-align:top}.audit-table th{font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#64748b)}.audit-code{font:600 .78rem ui-monospace,SFMono-Regular,Menlo,monospace}.audit-actor small,.audit-resource small{display:block;color:var(--text-muted,#64748b);margin-top:.18rem}.audit-details summary{cursor:pointer;color:var(--accent,#2563eb);font-weight:650}.audit-details pre{max-width:560px;max-height:320px;overflow:auto;white-space:pre-wrap;background:var(--surface-soft,#f8fafc);padding:.75rem;border-radius:.55rem;font-size:.75rem}.audit-empty{padding:3rem 1rem;text-align:center;color:var(--text-muted,#64748b)}.audit-pagination{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-top:1rem}.audit-pagination__links{display:flex;gap:.45rem}.audit-settings{display:grid;gap:1rem;max-width:760px}.audit-setting{display:flex;gap:.8rem;padding:1rem;border:1px solid var(--border,#dbe3ee);border-radius:.7rem;align-items:flex-start}.audit-setting input[type=checkbox]{margin-top:.2rem}.audit-setting strong,.audit-setting small{display:block}.audit-setting small{margin-top:.25rem;color:var(--text-muted,#64748b);line-height:1.45}.audit-settings__retention{display:grid;gap:.35rem;max-width:280px}.audit-note{padding:.9rem 1rem;background:var(--surface-soft,#f8fafc);border-radius:.65rem;color:var(--text-muted,#64748b)}.audit-error{padding:.75rem 1rem;margin-bottom:1rem;border-radius:.55rem;background:#fee2e2;color:#991b1b}@media(max-width:1100px){.audit-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.audit-filters__search{grid-column:1/-1}}@media(max-width:640px){.audit-head{display:block}.audit-filters,.audit-filters__dates{grid-template-columns:1fr}.audit-filters__search{grid-column:auto}.audit-actions{flex-wrap:wrap}.audit-pagination{align-items:flex-start;flex-direction:column}}
</style>

<section class="adam-card">
  <div class="audit-head">
    <div>
      <h1><?= svg_ico('history') ?> <?= _e('Audit Log') ?></h1>
      <p><?php if ($tab === 'mutations'): ?><?= _e('Review successful content mutations recorded atomically with their database changes. Known credential fields and full editor content are redacted before storage.') ?><?php elseif ($tab === 'access'): ?><?= _e('Review successful dashboard navigation and public page views without query strings or form data.') ?><?php else: ?><?= _e('Control access logging and retention. These settings are available only to the Site Owner.') ?><?php endif; ?></p>
    </div>
    <span class="adam-badge"><?= _e('Site Owner') ?></span>
  </div>

  <nav class="audit-tabs" aria-label="<?= e(__('Audit Log')) ?>">
    <a class="audit-tab<?= $tab === 'mutations' ? ' is-active' : '' ?>" href="<?= e($tabUrl('mutations')) ?>"><?= _e('Mutations') ?></a>
    <a class="audit-tab<?= $tab === 'access' ? ' is-active' : '' ?>" href="<?= e($tabUrl('access')) ?>"><?= _e('Access') ?></a>
    <a class="audit-tab<?= $tab === 'settings' ? ' is-active' : '' ?>" href="<?= e($tabUrl('settings')) ?>"><?= _e('Logging settings') ?></a>
  </nav>

  <?php foreach ($errors as $error): ?><div class="audit-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

  <?php if ($tab === 'mutations'): ?>
  <form method="get" class="audit-filters">
    <input type="hidden" name="page" value="admin/settings/audit"><input type="hidden" name="tab" value="mutations">
    <label class="audit-filters__search"><?= _e('Search audit events') ?><input class="inp" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="<?= e(__('Event, actor, resource, or route…')) ?>"></label>
    <label><?= _e('Provider') ?><select class="inp" name="provider"><option value=""><?= _e('All providers') ?></option><?php foreach ($providers as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['provider'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select></label>
    <label><?= _e('Event') ?><select class="inp" name="event"><option value=""><?= _e('All events') ?></option><?php foreach ($eventKeys as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['event'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select></label>
    <label><?= _e('Resource') ?><select class="inp" name="resource"><option value=""><?= _e('All resources') ?></option><?php foreach ($resourceTypes as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['resource'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select></label>
    <label><?= _e('Actor') ?><select class="inp" name="actor"><option value="0"><?= _e('All actors') ?></option><?php foreach ($actors as $actor): ?><option value="<?= (int)$actor['actor_user_id'] ?>" <?= $filters['actor'] === (int)$actor['actor_user_id'] ? 'selected' : '' ?>><?= e($actor['actor_label']) ?> (<?= (int)$actor['total'] ?>)</option><?php endforeach; ?></select></label>
    <div class="audit-filters__dates"><label><?= _e('From date') ?><input class="inp" type="date" name="from" value="<?= e($filters['from']) ?>"></label><label><?= _e('To date') ?><input class="inp" type="date" name="to" value="<?= e($filters['to']) ?>"></label></div>
    <div class="audit-actions"><button class="adam-button" type="submit"><?= svg_ico('search') ?> <?= _e('Filter') ?></button><a class="adam-cancle" href="<?= e($tabUrl('mutations')) ?>"><?= _e('Reset') ?></a></div>
  </form>
  <?php elseif ($tab === 'access'): ?>
  <form method="get" class="audit-filters">
    <input type="hidden" name="page" value="admin/settings/audit"><input type="hidden" name="tab" value="access">
    <label class="audit-filters__search"><?= _e('Search access events') ?><input class="inp" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="<?= e(__('Actor, page, route, or path…')) ?>"></label>
    <label><?= _e('Surface') ?><select class="inp" name="surface"><option value=""><?= _e('All surfaces') ?></option><option value="dashboard" <?= $filters['surface'] === 'dashboard' ? 'selected' : '' ?>><?= _e('Dashboard') ?></option><option value="frontend" <?= $filters['surface'] === 'frontend' ? 'selected' : '' ?>><?= _e('Public website') ?></option></select></label>
    <label><?= _e('Actor') ?><select class="inp" name="actor"><option value="0"><?= _e('All actors') ?></option><?php foreach ($actors as $actor): ?><option value="<?= (int)$actor['actor_user_id'] ?>" <?= $filters['actor'] === (int)$actor['actor_user_id'] ? 'selected' : '' ?>><?= e($actor['actor_label']) ?> (<?= (int)$actor['total'] ?>)</option><?php endforeach; ?></select></label>
    <div class="audit-filters__dates"><label><?= _e('From date') ?><input class="inp" type="date" name="from" value="<?= e($filters['from']) ?>"></label><label><?= _e('To date') ?><input class="inp" type="date" name="to" value="<?= e($filters['to']) ?>"></label></div>
    <div class="audit-actions"><button class="adam-button" type="submit"><?= svg_ico('search') ?> <?= _e('Filter') ?></button><a class="adam-cancle" href="<?= e($tabUrl('access')) ?>"><?= _e('Reset') ?></a></div>
  </form>
  <?php else: ?>
  <form method="post" class="audit-settings" id="audit-settings-form" data-unsaved-guard<?= $errors !== [] ? ' data-unsaved-guard-initial-dirty' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="audit_action" value="save_access_logging">
    <label class="audit-setting"><input type="checkbox" name="dashboard_enabled" value="1" <?= $accessSettings['dashboard_enabled'] ? 'checked' : '' ?>><span><strong><?= _e('Record dashboard navigation') ?></strong><small><?= _e('Records successful authenticated dashboard document views. AJAX, polling, assets, and failed routes are excluded.') ?></small></span></label>
    <label class="audit-setting"><input type="checkbox" name="public_enabled" value="1" <?= $accessSettings['public_enabled'] ? 'checked' : '' ?>><span><strong><?= _e('Record public page views') ?></strong><small><?= _e('Records successful public HTML page views, including anonymous visitors.') ?></small></span></label>
    <label class="audit-settings__retention"><strong><?= _e('Access log retention') ?></strong><select class="inp" name="retention_days"><?php foreach (ACCESS_LOG_RETENTION_DAYS as $days): ?><option value="<?= $days ?>" <?= $accessSettings['retention_days'] === $days ? 'selected' : '' ?>><?= sprintf(__('%d days'), $days) ?></option><?php endforeach; ?></select></label>
    <p class="audit-note"><?= _e('Older access events are pruned asynchronously. Mutation audit events are not affected.') ?><br><?= _e('Query strings and form data are never stored. IP addresses use the direct peer and user agents are hashed.') ?><br><code>php tools/prune-access-log.php</code></p>
    <div><button class="adam-button" type="submit"><?= svg_ico('save') ?> <?= _e('Save logging settings') ?></button></div>
  </form>
  <?php endif; ?>

  <?php if ($tab !== 'settings'): ?>
  <div class="audit-table-wrap"><table class="audit-table"><thead><tr><th><?= _e('Time') ?></th><th><?= _e('Actor') ?></th><th><?= _e('Event') ?></th><th><?= e($tab === 'access' ? __('Page') : __('Resource')) ?></th><th><?= _e('Route') ?></th><th><?= _e('Details') ?></th></tr></thead><tbody>
  <?php foreach ($events as $event): $ip = is_string($event['ip_address'] ?? null) ? @inet_ntop($event['ip_address']) : false; ?>
    <tr>
      <td><?= e($displayTimestamp($event['occurred_at_utc'] ?? null)) ?><br><small class="audit-code">#<?= (int)$event['id'] ?></small></td>
      <td class="audit-actor"><strong><?= e($event['actor_label']) ?></strong><?php if (!empty($event['actor_email'])): ?><small><?= e($event['actor_email']) ?></small><?php endif; ?></td>
      <?php if ($tab === 'mutations'): $changes = $prettyJson($event['changes'] ?? null); $metadata = $prettyJson($event['metadata'] ?? null); ?>
      <td><span class="audit-code"><?= e($event['event_key']) ?></span><br><small><?= e($event['provider']) ?> · <?= e($event['source']) ?></small></td>
      <td class="audit-resource"><strong><?= e($event['resource_label'] ?: $event['resource_type'] . ' #' . $event['resource_id']) ?></strong><small><?= e($event['resource_type'] . ' #' . $event['resource_id']) ?></small></td>
      <td><span class="audit-code"><?= e($event['route'] ?: $event['source']) ?></span><br><small><?= e(trim((string)($event['request_method'] ?? '') . ' ' . (string)($event['request_path'] ?? ''))) ?></small></td>
      <td><details class="audit-details"><summary><?= _e('View details') ?></summary><p><strong><?= _e('Operation ID') ?>:</strong> <span class="audit-code"><?= e($event['operation_id']) ?></span></p><?php if ($ip): ?><p><strong><?= _e('IP address') ?>:</strong> <?= e($ip) ?></p><?php endif; ?><?php if ($changes !== ''): ?><strong><?= _e('Changes') ?></strong><pre><?= e($changes) ?></pre><?php endif; ?><?php if ($metadata !== ''): ?><strong><?= _e('Metadata') ?></strong><pre><?= e($metadata) ?></pre><?php endif; ?></details></td>
      <?php else: ?>
      <td><span class="audit-code"><?= e($event['event_key']) ?></span><br><small><?= e($event['surface']) ?> · HTTP <?= (int)$event['response_status'] ?></small></td>
      <td class="audit-resource"><strong><?= e($event['page_title'] ?: $event['route']) ?></strong><?php if (!empty($event['resource_type'])): ?><small><?= e($event['resource_type'] . ' #' . (string)$event['resource_id']) ?></small><?php endif; ?></td>
      <td><span class="audit-code"><?= e($event['route']) ?></span><br><small><?= e($event['request_path']) ?></small></td>
      <td><details class="audit-details"><summary><?= _e('View details') ?></summary><p><strong><?= _e('Request ID') ?>:</strong> <span class="audit-code"><?= e($event['request_id']) ?></span></p><?php if ($ip): ?><p><strong><?= _e('IP address') ?>:</strong> <?= e($ip) ?></p><?php endif; ?><?php if (!empty($event['user_agent_hash'])): ?><p><strong><?= _e('User-agent hash') ?>:</strong> <span class="audit-code"><?= e($event['user_agent_hash']) ?></span></p><?php endif; ?></details></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($events === []): ?><tr><td colspan="6" class="audit-empty"><?= e($tab === 'access' ? __('No access events match these filters.') : __('No audit events match these filters.')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="audit-pagination"><span><?= sprintf(__('Showing %d-%d of %d results'), $total > 0 ? $offset + 1 : 0, min($offset + $perPage, $total), $total) ?></span><div class="audit-pagination__links"><?php if ($page > 1): ?><a class="adam-cancle" rel="prev" href="<?= e($pageUrl($page - 1)) ?>"><?= _e('Previous') ?></a><?php endif; ?><span class="adam-button"><?= sprintf(__('Page %d of %d'), $page, $totalPages) ?></span><?php if ($page < $totalPages): ?><a class="adam-cancle" rel="next" href="<?= e($pageUrl($page + 1)) ?>"><?= _e('Next') ?></a><?php endif; ?></div></div>
  <?php endif; ?>
</section>
