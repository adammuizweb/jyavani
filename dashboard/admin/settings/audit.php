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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['audit_action'] ?? '') === 'save_audit_logging') {
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
        'actions_enabled' => isset($_POST['actions_enabled']),
        'dashboard_enabled' => isset($_POST['dashboard_enabled']),
        'retention_days' => $retention,
    ];
    if ($errors === []) {
        $before = [
            'dashboard_actions' => $accessSettings['actions_enabled'],
            'dashboard_navigation' => $accessSettings['dashboard_enabled'],
            'retention_days' => $accessSettings['retention_days'],
        ];
        $after = [
            'dashboard_actions' => $requested['actions_enabled'],
            'dashboard_navigation' => $requested['dashboard_enabled'],
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
                    'audit_dashboard_actions_enabled' => $requested['actions_enabled'] ? '1' : '0',
                    'audit_dashboard_navigation_enabled' => $requested['dashboard_enabled'] ? '1' : '0',
                    'audit_access_retention_days' => (string)$requested['retention_days'],
                ] as $key => $value) {
                    if (!settings_set($pdo, $key, $value, 1)) throw new RuntimeException('Failed to persist audit setting.');
                }
                audit_log_record($pdo, [
                    'provider' => 'core',
                    'event_key' => 'audit.settings_changed',
                    'actor_user_id' => $uid,
                    'resource_type' => 'audit_settings',
                    'resource_id' => 'dashboard_logging',
                    'resource_label' => 'Dashboard logging',
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
                adiwira_redirect_with_flash($target, 'success', __('Audit logging settings saved successfully.'));
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
        'actor' => max(0, (int)($_GET['actor'] ?? 0)),
        'from' => $accessFromDate->format('Y-m-d'),
        'to' => $accessToDate->format('Y-m-d'),
    ];
    if (strlen($filters['q']) > 100) $filters['q'] = mb_strcut($filters['q'], 0, 100, 'UTF-8');
    $where = ['surface = :access_surface'];
    $params = [':access_surface' => 'dashboard'];
    if ($filters['q'] !== '') {
        $where[] = "LOCATE(:search, CONCAT_WS(' ', event_key, actor_label, COALESCE(actor_email, ''), route, request_path, COALESCE(user_agent, ''))) > 0";
        $params[':search'] = $filters['q'];
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
         FROM access_events WHERE surface = :surface AND actor_user_id IS NOT NULL AND occurred_at_utc >= :cutoff
         GROUP BY actor_user_id ORDER BY actor_label ASC LIMIT 200'
    );
    $actorStmt->execute([':surface' => 'dashboard', ':cutoff' => $actorCutoff]);
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
$filterUrlWithout = static function (string $key) use ($base, $tab): string {
    $query = $_GET;
    unset($query[$key], $query['p']);
    $query['page'] = 'admin/settings/audit';
    $query['tab'] = $tab;
    return $base . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$activeFilterChips = [];
$searchActive = ($filters['q'] ?? '') !== '';
if ($tab === 'mutations') {
    foreach (['provider' => __('Provider'), 'event' => __('Event'), 'resource' => __('Resource')] as $key => $label) {
        if (($filters[$key] ?? '') !== '') $activeFilterChips[$key] = [$label, (string)$filters[$key]];
    }
    if (($filters['actor'] ?? 0) > 0) {
        $actorLabel = '#' . (int)$filters['actor'];
        foreach ($actors as $actor) {
            if ((int)$actor['actor_user_id'] === (int)$filters['actor']) {
                $actorLabel = (string)$actor['actor_label'];
                break;
            }
        }
        $activeFilterChips['actor'] = [__('Actor'), $actorLabel];
    }
    foreach (['from' => __('From date'), 'to' => __('To date')] as $key => $label) {
        if (($filters[$key] ?? '') !== '') $activeFilterChips[$key] = [$label, (string)$filters[$key]];
    }
} elseif ($tab === 'access') {
    if (($filters['actor'] ?? 0) > 0) {
        $actorLabel = '#' . (int)$filters['actor'];
        foreach ($actors as $actor) {
            if ((int)$actor['actor_user_id'] === (int)$filters['actor']) {
                $actorLabel = (string)$actor['actor_label'];
                break;
            }
        }
        $activeFilterChips['actor'] = [__('Actor'), $actorLabel];
    }
    $defaultAccessTo = $accessToday->format('Y-m-d');
    if (isset($_GET['from']) && $filters['from'] !== $defaultAccessFrom) {
        $activeFilterChips['from'] = [__('From date'), (string)$filters['from']];
    }
    if (isset($_GET['to']) && $filters['to'] !== $defaultAccessTo) {
        $activeFilterChips['to'] = [__('To date'), (string)$filters['to']];
    }
}
$activeFilterCount = count($activeFilterChips);
$hasActiveQuery = $searchActive || $activeFilterCount > 0;
?>

<style>
.audit-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem}.audit-head h1{margin:0}.audit-head p{margin:.35rem 0 0;color:var(--text-muted,#64748b);max-width:76ch}.audit-tabs{display:flex;gap:.45rem;flex-wrap:wrap;margin-bottom:1rem}.audit-tab{padding:.55rem .8rem;border:1px solid var(--border,#dbe3ee);border-radius:.55rem;text-decoration:none;color:inherit}.audit-tab.is-active{background:var(--accent,#2563eb);border-color:var(--accent,#2563eb);color:#fff}.audit-filters{display:grid;grid-template-columns:minmax(220px,2fr) repeat(4,minmax(130px,1fr));gap:.7rem;align-items:end}.audit-filters label{display:grid;gap:.3rem;font-size:.84rem;font-weight:650}.audit-filters__dates{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}.audit-actions{display:flex;gap:.5rem;align-items:center}.audit-table-wrap{overflow-x:auto;margin-top:1rem}.audit-table{width:100%;border-collapse:collapse;min-width:940px}.audit-table th,.audit-table td{text-align:left;padding:.72rem;border-bottom:1px solid var(--border,#e2e8f0);vertical-align:top}.audit-table th{font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#64748b)}.audit-code{font:600 .78rem ui-monospace,SFMono-Regular,Menlo,monospace}.audit-actor small,.audit-resource small{display:block;color:var(--text-muted,#64748b);margin-top:.18rem}.audit-details summary{cursor:pointer;color:var(--accent,#2563eb);font-weight:650}.audit-details pre{max-width:560px;max-height:320px;overflow:auto;white-space:pre-wrap;background:var(--surface-soft,#f8fafc);padding:.75rem;border-radius:.55rem;font-size:.75rem}.audit-empty{padding:3rem 1rem;text-align:center;color:var(--text-muted,#64748b)}.audit-pagination{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-top:1rem}.audit-pagination__links{display:flex;gap:.45rem}.audit-settings{display:grid;gap:1rem;max-width:760px}.audit-setting{display:flex;gap:.8rem;padding:1rem;border:1px solid var(--border,#dbe3ee);border-radius:.7rem;align-items:flex-start}.audit-setting input[type=checkbox]{margin-top:.2rem}.audit-setting strong,.audit-setting small{display:block}.audit-setting small{margin-top:.25rem;color:var(--text-muted,#64748b);line-height:1.45}.audit-settings__retention{display:grid;gap:.35rem;max-width:280px}.audit-note{padding:.9rem 1rem;background:var(--surface-soft,#f8fafc);border-radius:.65rem;color:var(--text-muted,#64748b)}.audit-error{padding:.75rem 1rem;margin-bottom:1rem;border-radius:.55rem;background:#fee2e2;color:#991b1b}@media(max-width:1100px){.audit-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.audit-filters__search{grid-column:1/-1}}@media(max-width:640px){.audit-head{display:block}.audit-filters,.audit-filters__dates{grid-template-columns:1fr}.audit-filters__search{grid-column:auto}.audit-actions{flex-wrap:wrap}.audit-pagination{align-items:flex-start;flex-direction:column}}
.audit-page{max-width:1180px;margin:18px auto;color:var(--adam-text)}
.audit-page__topline{display:flex;align-items:center;margin-bottom:10px}.audit-page__back{display:inline-flex;align-items:center;gap:6px;color:var(--adam-muted);font-size:.83rem;font-weight:700;text-decoration:none}.audit-page__back:hover{color:var(--adam-primary)}.audit-page__back svg{width:15px;height:15px}
.audit-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.25rem 1.35rem;border-color:color-mix(in srgb,var(--adam-primary) 16%,var(--adam-border));background:linear-gradient(135deg,var(--adam-card),color-mix(in srgb,var(--adam-primary) 5%,var(--adam-card)))}
.audit-hero__identity{display:flex;align-items:flex-start;gap:.9rem;min-width:0}.audit-hero__icon{display:grid;width:44px;height:44px;flex:0 0 44px;place-items:center;border-radius:13px;background:var(--adam-primary-soft);color:var(--adam-primary)}.audit-hero__icon svg{width:22px;height:22px}.audit-hero h1{margin:0;font-size:1.45rem;letter-spacing:-.02em}.audit-hero p{max-width:78ch;margin:.35rem 0 0;color:var(--adam-muted);font-size:.88rem;line-height:1.55}.audit-hero .badge{flex:0 0 auto;font-weight:750}
.audit-page__tabs{margin:12px 0}.audit-page__tabs .tab{display:inline-flex;min-width:0;align-items:center;gap:.35rem;text-decoration:none}.audit-page__tabs .tab svg{width:14px;height:14px}
.audit-panel{padding:1rem}.audit-alert{margin:0 0 1rem;padding:.75rem .9rem;border:1px solid color-mix(in srgb,var(--adam-danger) 35%,var(--adam-border));border-radius:9px;background:color-mix(in srgb,var(--adam-danger) 10%,var(--adam-card));color:var(--adam-danger)}.audit-alert ul{margin:0;padding-left:1.1rem}
.audit-filter-shell{position:relative}.audit-filter-bar{--posts-toolbar-control-size:38px;display:grid;grid-template-columns:minmax(240px,1fr) auto 38px;gap:.5rem;align-items:start}.audit-filter-bar .posts-search-control{height:38px}.audit-filter-bar .posts-search-input.inp{height:36px}.audit-filter-bar .posts-filter-trigger{height:38px}.audit-filter-bar .posts-filter-disclosure{position:static}.audit-filter-bar .posts-filter-panel{right:0;left:0;width:auto;grid-template-columns:repeat(3,minmax(0,1fr))}.audit-filter-bar .posts-filter-actions{margin-top:.1rem}.audit-filter-bar .posts-filter-chip{overflow-wrap:anywhere}.audit-filter-chips{margin-top:.65rem;padding-bottom:.15rem}.audit-filter-reset-all{display:inline-flex;flex:0 0 auto;align-items:center;gap:.28rem;color:var(--adam-primary);font-size:.76rem;font-weight:800;text-decoration:none}.audit-filter-reset-all svg{width:13px;height:13px}.audit-columns .cols-toggle-btn{width:38px;height:38px;box-sizing:border-box;border-radius:8px}.audit-columns .cols-toggle-btn:focus-visible{outline:2px solid var(--adam-primary);outline-offset:2px}.audit-columns .cols-dropdown{min-width:170px}.audit-columns .cols-opt{padding:.42rem .65rem}.audit-table th.col-hidden,.audit-table td.col-hidden{display:none!important}
.audit-table-wrap{margin-top:1rem;border:1px solid var(--adam-border);border-radius:12px}.audit-table{min-width:980px;box-shadow:none}.audit-table th{font-size:.72rem;letter-spacing:.035em;text-transform:uppercase}.audit-table td{vertical-align:top}.audit-table tr:last-child td{border-bottom:0}.audit-primary{font-weight:750;color:var(--adam-text-2)}.audit-secondary{display:block;margin-top:.2rem;color:var(--adam-muted);font-size:.76rem;line-height:1.4}.audit-code{font:650 .76rem ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.audit-event{display:inline-block;padding:.18rem .45rem;border:1px solid color-mix(in srgb,var(--adam-primary) 22%,var(--adam-border));border-radius:7px;background:color-mix(in srgb,var(--adam-primary) 6%,var(--adam-card));color:var(--adam-text-2)}.audit-session{display:inline-flex;max-width:100%;align-items:center;gap:.3rem;margin-top:.4rem;padding:.2rem .42rem;border:1px solid color-mix(in srgb,var(--adam-primary) 38%,var(--adam-border));border-radius:999px;background:color-mix(in srgb,var(--adam-primary) 12%,var(--adam-card));color:var(--adam-primary);font-size:.68rem;font-weight:850;line-height:1;letter-spacing:.025em}.audit-session svg{width:12px;height:12px;flex:0 0 auto}.audit-session code{color:inherit;font:inherit;letter-spacing:.055em}.audit-details summary{display:inline-flex;align-items:center;gap:.3rem;color:var(--adam-primary);font-size:.8rem;font-weight:800;cursor:pointer;list-style:none}.audit-details summary::-webkit-details-marker{display:none}.audit-details summary svg{width:14px;height:14px}.audit-details[open] summary{margin-bottom:.65rem}.audit-details p{margin:.45rem 0;color:var(--adam-text-3);font-size:.8rem}.audit-details pre{width:min(520px,60vw);max-height:320px;box-sizing:border-box;overflow:auto;padding:.75rem;border:1px solid var(--adam-border);border-radius:8px;background:var(--adam-surface-3);color:var(--adam-text-2);font-size:.74rem;white-space:pre-wrap;overflow-wrap:anywhere}.audit-empty{padding:3.5rem 1rem!important;text-align:center}.audit-empty__content{display:grid;max-width:520px;margin:0 auto;justify-items:center;text-align:center}.audit-empty__icon{display:grid;width:42px;height:42px;margin:0 0 .7rem;place-items:center;border-radius:12px;background:var(--adam-surface-3);color:var(--adam-muted)}.audit-empty__icon svg{width:20px;height:20px}.audit-empty strong{display:block;color:var(--adam-text-2)}.audit-empty p{margin:.3rem auto .8rem;color:var(--adam-muted);font-size:.84rem}.audit-empty .adam-cancle{display:inline-flex;align-items:center;gap:.3rem;text-decoration:none}
.audit-detail-trigger{display:inline-flex;align-items:center;gap:.3rem;padding:0;border:0;background:transparent;color:var(--adam-primary);font:inherit;font-size:.8rem;font-weight:800;cursor:pointer}.audit-detail-trigger:hover{text-decoration:underline}.audit-detail-trigger:focus-visible{outline:2px solid var(--adam-primary);outline-offset:3px;border-radius:3px}.audit-detail-trigger svg{width:14px;height:14px}.audit-detail-modal{padding:1rem}.audit-detail-modal__panel{display:flex;width:min(720px,calc(100vw - 2rem));max-width:720px;max-height:calc(100dvh - 2rem);padding:0;overflow:hidden;flex-direction:column;text-align:left}.audit-detail-modal__header{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.9rem 1rem;border-bottom:1px solid var(--adam-border);background:var(--adam-surface-3)}.audit-detail-modal__heading{display:flex;align-items:center;gap:.65rem;min-width:0}.audit-detail-modal__icon{display:grid;width:34px;height:34px;flex:0 0 34px;place-items:center;border-radius:9px;background:var(--adam-primary-soft);color:var(--adam-primary)}.audit-detail-modal__icon svg{width:17px;height:17px}.audit-detail-modal__title{margin:0;color:var(--adam-text-2);font-size:1rem;font-weight:800}.audit-detail-modal__close{display:grid;width:36px;height:36px;flex:0 0 36px;place-items:center;border:1px solid var(--adam-border);border-radius:9px;background:var(--adam-card);color:var(--adam-muted);cursor:pointer}.audit-detail-modal__close:hover{color:var(--adam-text-2);background:var(--adam-hover)}.audit-detail-modal__close:focus-visible{outline:2px solid var(--adam-primary);outline-offset:2px}.audit-detail-modal__close svg{width:17px;height:17px}.audit-detail-modal__body{overflow:auto;padding:1rem;overscroll-behavior:contain}.audit-detail-list{display:grid;gap:.65rem;margin:0}.audit-detail-list>div{display:grid;grid-template-columns:minmax(110px,.3fr) minmax(0,1fr);gap:.75rem;padding:.7rem .8rem;border:1px solid var(--adam-border);border-radius:9px;background:var(--adam-card)}.audit-detail-list dt{color:var(--adam-muted);font-size:.73rem;font-weight:800;letter-spacing:.035em;text-transform:uppercase}.audit-detail-list dd{min-width:0;margin:0;color:var(--adam-text-2);font-size:.82rem;overflow-wrap:anywhere}.audit-detail-section{margin-top:.9rem}.audit-detail-section h3{margin:0 0 .4rem;color:var(--adam-text-2);font-size:.82rem}.audit-detail-section pre{max-height:300px;margin:0;overflow:auto;padding:.75rem;border:1px solid var(--adam-border);border-radius:9px;background:var(--adam-surface-3);color:var(--adam-text-2);font-size:.74rem;white-space:pre-wrap;overflow-wrap:anywhere}
.audit-pager{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:.9rem;color:var(--adam-muted);font-size:.8rem}.audit-pager .adam-pagination{display:flex;align-items:center;gap:.2rem;margin:0}.audit-pager .adam-pagination a,.audit-pager .adam-pagination strong{margin:0}.audit-pager .adam-pagination a{border:1px solid var(--adam-border);background:var(--adam-card)}
.audit-settings-panel{padding:1rem}.audit-settings{display:grid;max-width:none;gap:.85rem}.audit-settings-group{overflow:hidden;border:1px solid var(--adam-border);border-radius:12px;background:var(--adam-card)}.audit-settings-group__head{display:flex;align-items:flex-start;gap:.7rem;padding:.8rem .9rem;border-bottom:1px solid var(--adam-border);background:var(--adam-surface-3)}.audit-settings-group__icon{display:grid;width:32px;height:32px;flex:0 0 32px;place-items:center;border-radius:9px;background:var(--adam-primary-soft);color:var(--adam-primary)}.audit-settings-group__icon svg{width:16px;height:16px}.audit-settings-group h2{margin:0;color:var(--adam-text-2);font-size:.92rem}.audit-settings-group__head p{margin:.18rem 0 0;color:var(--adam-muted);font-size:.78rem;line-height:1.45}.audit-settings-group__body{padding:.9rem}.audit-toggle-stack{display:grid;gap:.7rem}.audit-toggle-card{--sec-clr:var(--adam-primary);--sec-clr-soft:var(--adam-primary-soft);--sec-clr-border:color-mix(in srgb,var(--adam-primary) 24%,var(--adam-border))}.audit-retention-grid{display:grid;grid-template-columns:minmax(220px,320px) minmax(0,1fr);gap:1rem;align-items:start}.audit-retention-grid .form-group{margin:0}.audit-retention-grid .inp{width:100%}.audit-retention-note{display:flex;align-items:flex-start;gap:.55rem;padding:.75rem;border:1px solid color-mix(in srgb,var(--adam-primary) 17%,var(--adam-border));border-radius:9px;background:color-mix(in srgb,var(--adam-primary) 4%,var(--adam-card));color:var(--adam-text-3);font-size:.8rem;line-height:1.55}.audit-retention-note svg{width:16px;height:16px;flex:0 0 auto;margin-top:.1rem;color:var(--adam-primary)}.audit-settings-guide{margin:.75rem 0 0}.audit-settings-guide code{display:block;max-width:100%;overflow-x:auto;padding:.55rem .65rem;border:1px solid var(--adam-border-2);border-radius:7px;background:var(--adam-surface-2);color:var(--adam-text-2);white-space:nowrap}.audit-settings-actions{display:flex;justify-content:flex-end}.audit-settings-actions .adam-button{display:inline-flex;align-items:center;gap:.35rem}
@media(max-width:700px){.audit-hero{align-items:flex-start}.audit-hero .badge{display:none}.audit-retention-grid{grid-template-columns:1fr}.audit-filter-bar .posts-filter-trigger{min-width:38px;justify-content:center;padding:.5rem}.audit-filter-bar .posts-filter-trigger>span:not(.posts-filter-count):not(.posts-filter-chevron){position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.audit-filter-bar .posts-filter-chevron{display:none}.audit-filter-bar .posts-filter-panel{grid-template-columns:1fr}.audit-filter-bar .posts-filter-actions{grid-column:1}.audit-pager{align-items:flex-start;flex-direction:column}.audit-pager .adam-pagination{width:100%;justify-content:space-between}.audit-pager .adam-pagination strong{flex:1;text-align:center}}
@media(max-width:640px){.audit-page{margin:10px auto}.audit-hero{padding:1rem}.audit-hero__icon{width:38px;height:38px;flex-basis:38px}.audit-hero h1{font-size:1.25rem}.audit-panel,.audit-settings-panel{padding:.75rem}.audit-page__tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.35rem}.audit-page__tabs .tab{justify-content:center;padding:.7rem .35rem;line-height:1.2;text-align:center;white-space:normal;overflow-wrap:anywhere}.audit-table-wrap{overflow:visible;border:0}.audit-table-wrap .adam-table,.audit-table-wrap .adam-table tbody{display:block;width:100%;min-width:0;background:transparent;box-shadow:none}.audit-table-wrap .adam-table thead{display:none}.audit-table-wrap .adam-table tbody{display:grid;gap:.7rem}.audit-table-wrap .adam-table tr{display:block;overflow:hidden;border:1px solid var(--adam-border);border-radius:13px;background:var(--adam-card);box-shadow:0 4px 14px rgba(15,23,42,.05)}.audit-table-wrap .adam-table td{display:grid;grid-template-columns:minmax(78px,.32fr) minmax(0,1fr);align-items:start;gap:.35rem .75rem;width:100%;box-sizing:border-box;padding:.65rem .75rem;border-bottom:1px solid var(--adam-border);text-align:left}.audit-table-wrap .adam-table td::before{content:attr(data-label);grid-column:1;grid-row:1;color:var(--adam-muted);font-size:.66rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.audit-table-wrap .adam-table td>*{grid-column:2}.audit-table-wrap .adam-table td:last-child{border-bottom:0}.audit-table-wrap .adam-table tr:hover td{background:transparent}.audit-table-wrap .audit-details pre{width:100%;max-width:100%}.audit-table-wrap .audit-empty{display:block;padding:2.5rem 1rem!important;border:0}.audit-table-wrap .audit-empty::before{display:none}.audit-settings-actions .adam-button{width:100%;justify-content:center;min-height:42px}}
@media(max-width:640px){.audit-detail-modal{align-items:flex-end;padding:.5rem}.audit-detail-modal__panel{width:100%;max-height:calc(100dvh - 1rem);border-radius:14px}.audit-detail-modal__header{padding:.75rem}.audit-detail-modal__body{padding:.75rem}.audit-detail-list>div{grid-template-columns:1fr;gap:.25rem}}
@media(max-width:420px){.audit-hero__identity{gap:.65rem}.audit-hero__icon{display:none}.audit-page__tabs .tab svg{display:none}.audit-filter-bar{grid-template-columns:minmax(0,1fr) 38px 38px}.audit-table-wrap .adam-table td{grid-template-columns:70px minmax(0,1fr);gap:.55rem}.audit-settings-group__head{padding:.75rem}.audit-settings-group__body{padding:.75rem}}
</style>

<section class="audit-page">
  <div class="audit-page__topline"><a class="audit-page__back" href="<?= e($base . '/?page=admin/settings/index') ?>"><?= svg_ico('arrow-left') ?> <?= _e('Settings') ?></a></div>
  <header class="adam-card audit-hero">
    <div class="audit-hero__identity">
      <span class="audit-hero__icon" aria-hidden="true"><?= svg_ico('history') ?></span>
      <div>
        <h1><?= _e('Audit Log') ?></h1>
        <p><?php if ($tab === 'mutations'): ?><?= _e('Review successful dashboard actions recorded atomically with their database changes. Known credential fields and full editor content are redacted before storage.') ?><?php elseif ($tab === 'access'): ?><?= _e('Review successful dashboard navigation without query strings or form data.') ?><?php else: ?><?= _e('Control dashboard action and navigation logging. These settings are available only to the Site Owner.') ?><?php endif; ?></p>
      </div>
    </div>
    <span class="badge"><?= _e('Site Owner') ?></span>
  </header>

  <nav class="tabs audit-page__tabs" aria-label="<?= e(__('Audit Log')) ?>">
    <a class="tab<?= $tab === 'mutations' ? ' active' : '' ?>" href="<?= e($tabUrl('mutations')) ?>"<?= $tab === 'mutations' ? ' aria-current="page"' : '' ?>><?= svg_ico('file-text') ?> <?= _e('Actions') ?></a>
    <a class="tab<?= $tab === 'access' ? ' active' : '' ?>" href="<?= e($tabUrl('access')) ?>"<?= $tab === 'access' ? ' aria-current="page"' : '' ?>><?= svg_ico('monitor') ?> <?= _e('Access') ?></a>
    <a class="tab<?= $tab === 'settings' ? ' active' : '' ?>" href="<?= e($tabUrl('settings')) ?>"<?= $tab === 'settings' ? ' aria-current="page"' : '' ?>><?= svg_ico('settings') ?> <?= _e('Logging settings') ?></a>
  </nav>

  <?php foreach ($errors as $error): ?><div class="adam-error audit-alert" role="alert"><ul><li><?= e($error) ?></li></ul></div><?php endforeach; ?>

  <?php if ($tab !== 'settings'): ?>
  <div class="adam-card audit-panel">
    <form method="get" class="audit-filter-shell">
      <input type="hidden" name="page" value="admin/settings/audit"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="audit-filter-bar">
        <div class="posts-search-control">
          <label class="sr-only" for="audit-search"><?= e($tab === 'access' ? __('Search access events') : __('Search audit events')) ?></label>
          <input id="audit-search" class="inp posts-search-input" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="<?= e($tab === 'access' ? __('Actor, event, route, path, or user agent…') : __('Event, actor, resource, or route…')) ?>">
          <?php if ($searchActive): ?><a class="posts-search-clear" href="<?= e($filterUrlWithout('q')) ?>" aria-label="<?= e(__('Reset') . ' ' . __('Search')) ?>" title="<?= e(__('Reset') . ' ' . __('Search')) ?>"><?= svg_ico('x') ?></a><?php endif; ?>
          <button type="submit" class="posts-search-submit" aria-label="<?= e(__('Search')) ?>" title="<?= e(__('Search')) ?>"><?= svg_ico('search') ?></button>
        </div>
        <details class="posts-filter-disclosure<?= $activeFilterCount > 0 ? ' has-active' : '' ?>">
          <summary class="posts-filter-trigger"><?= svg_ico('list-collapse') ?><span><?= _e('Filters') ?></span><?php if ($activeFilterCount > 0): ?><span class="posts-filter-count"><?= $activeFilterCount ?></span><?php endif; ?><span class="posts-filter-chevron" aria-hidden="true"><?= svg_ico('chevron-down') ?></span></summary>
          <div class="posts-filter-panel">
            <?php if ($tab === 'mutations'): ?>
              <label class="posts-filter-field"><span><?= _e('Provider') ?></span><select class="inp" name="provider"><option value=""><?= _e('All providers') ?></option><?php foreach ($providers as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['provider'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select></label>
              <label class="posts-filter-field"><span><?= _e('Event') ?></span><select class="inp" name="event"><option value=""><?= _e('All events') ?></option><?php foreach ($eventKeys as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['event'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select></label>
              <label class="posts-filter-field"><span><?= _e('Resource') ?></span><select class="inp" name="resource"><option value=""><?= _e('All resources') ?></option><?php foreach ($resourceTypes as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['resource'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <label class="posts-filter-field"><span><?= _e('Actor') ?></span><select class="inp" name="actor"><option value="0"><?= _e('All actors') ?></option><?php foreach ($actors as $actor): ?><option value="<?= (int)$actor['actor_user_id'] ?>" <?= $filters['actor'] === (int)$actor['actor_user_id'] ? 'selected' : '' ?>><?= e($actor['actor_label']) ?> (<?= (int)$actor['total'] ?>)</option><?php endforeach; ?></select></label>
            <label class="posts-filter-field"><span><?= _e('From date') ?></span><input class="inp" type="date" name="from" value="<?= e($filters['from']) ?>"></label>
            <label class="posts-filter-field"><span><?= _e('To date') ?></span><input class="inp" type="date" name="to" value="<?= e($filters['to']) ?>"></label>
            <div class="posts-filter-actions"><button type="submit" class="adam-button posts-filter-apply"><?= svg_ico('circle-check') ?><span><?= _e('Apply filters') ?></span></button><?php if ($hasActiveQuery): ?><a class="adam-cancle posts-filter-reset" href="<?= e($tabUrl($tab)) ?>"><?= svg_ico('rotate-ccw') ?><span><?= _e('Reset') ?></span></a><?php endif; ?></div>
          </div>
        </details>
        <div class="cols-toggle audit-columns">
          <button type="button" class="cols-toggle-btn" title="<?= e(__('Columns')) ?>" aria-label="<?= e(__('Columns')) ?>" aria-expanded="false" aria-controls="audit-columns-menu"><?= svg_ico('columns-2') ?></button>
          <div class="cols-dropdown" id="audit-columns-menu">
            <label class="cols-opt"><input type="checkbox" class="adam-choice" data-audit-column="audit-col-actor" checked> <?= _e('Actor') ?></label>
            <?php if ($tab === 'mutations'): ?>
              <label class="cols-opt"><input type="checkbox" class="adam-choice" data-audit-column="audit-col-resource" checked> <?= _e('Resource') ?></label>
              <label class="cols-opt"><input type="checkbox" class="adam-choice" data-audit-column="audit-col-route" checked> <?= _e('Route') ?></label>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </form>
    <?php if ($activeFilterChips !== []): ?><div class="posts-filter-chips audit-filter-chips" aria-label="<?= e(__('Filters')) ?>"><?php foreach ($activeFilterChips as $key => [$label, $value]): ?><a class="posts-filter-chip" href="<?= e($filterUrlWithout($key)) ?>" title="<?= e(__('Reset') . ' ' . $label) ?>"><span><?= e($label) ?>:</span><strong><?= e($value) ?></strong><?= svg_ico('x') ?></a><?php endforeach; ?><a class="audit-filter-reset-all" href="<?= e($tabUrl($tab)) ?>"><?= svg_ico('rotate-ccw') ?> <?= _e('Reset') ?></a></div><?php endif; ?>
  <?php else: ?>
  <div class="adam-card audit-settings-panel">
  <form method="post" class="audit-settings" id="audit-settings-form" data-unsaved-guard data-unsaved-floating-save<?= $errors !== [] ? ' data-unsaved-guard-initial-dirty' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="audit_action" value="save_audit_logging">
    <section class="audit-settings-group">
      <header class="audit-settings-group__head"><span class="audit-settings-group__icon" aria-hidden="true"><?= svg_ico('monitor') ?></span><div><h2><?= _e('Logging settings') ?></h2><p><?= _e('Choose which successful dashboard activity is recorded.') ?></p></div></header>
      <div class="audit-settings-group__body audit-toggle-stack">
        <div class="metatags-card audit-toggle-card"><div class="metatags-row"><div class="metatags-info"><label class="metatags-label" for="audit-actions-enabled"><?= _e('Record dashboard actions') ?></label><p class="metatags-desc"><?= _e('Records successful dashboard actions such as create, update, delete, restore, and bulk operations.') ?></p></div><label class="metatags-toggle"><input type="checkbox" name="actions_enabled" id="audit-actions-enabled" value="1" <?= $accessSettings['actions_enabled'] ? 'checked' : '' ?>><span class="slider"></span></label></div></div>
        <div class="metatags-card audit-toggle-card"><div class="metatags-row"><div class="metatags-info"><label class="metatags-label" for="audit-dashboard-enabled"><?= _e('Record dashboard navigation') ?></label><p class="metatags-desc"><?= _e('Records successful authenticated dashboard document views. AJAX, polling, assets, and failed routes are excluded.') ?></p></div><label class="metatags-toggle"><input type="checkbox" name="dashboard_enabled" id="audit-dashboard-enabled" value="1" <?= $accessSettings['dashboard_enabled'] ? 'checked' : '' ?>><span class="slider"></span></label></div></div>
      </div>
    </section>
    <section class="audit-settings-group">
      <header class="audit-settings-group__head"><span class="audit-settings-group__icon" aria-hidden="true"><?= svg_ico('timer') ?></span><div><h2><?= _e('Access log retention') ?></h2><p><?= _e('Older access events are pruned asynchronously. Action audit events are not affected.') ?></p></div></header>
      <div class="audit-settings-group__body">
        <div class="audit-retention-grid"><div class="form-group"><label for="audit-retention-days"><?= _e('Access log retention') ?></label><select class="inp" id="audit-retention-days" name="retention_days"><?php foreach (ACCESS_LOG_RETENTION_DAYS as $days): ?><option value="<?= $days ?>" <?= $accessSettings['retention_days'] === $days ? 'selected' : '' ?>><?= sprintf(__('%d days'), $days) ?></option><?php endforeach; ?></select></div><div class="audit-retention-note"><?= svg_ico('shield-check') ?><span><?= _e('Query strings and form data are never stored. IP addresses use the direct peer and user agents are stored as bounded text.') ?></span></div></div>
        <details class="settings-details audit-settings-guide"><summary><?= _e('Retention worker') ?></summary><div class="inner"><p><?= _e('Older access events are pruned asynchronously. Action audit events are not affected.') ?></p><code>php tools/prune-access-log.php</code></div></details>
      </div>
    </section>
    <div class="audit-settings-actions"><button class="adam-button settings-floating-save" type="submit"><?= svg_ico('save') ?> <?= _e('Save logging settings') ?></button></div>
  </form>
  </div>
  <?php endif; ?>

  <?php if ($tab !== 'settings'): ?>
  <div class="adam-table-wrapper audit-table-wrap"><table class="adam-table audit-table"><thead><tr><th><?= _e('Time') ?></th><th class="audit-col-actor"><?= _e('Actor') ?></th><th><?= _e('Event') ?></th><?php if ($tab === 'mutations'): ?><th class="audit-col-resource"><?= _e('Resource') ?></th><?php endif; ?><th<?= $tab === 'mutations' ? ' class="audit-col-route"' : '' ?>><?= _e('Route') ?></th><th><?= _e('Details') ?></th></tr></thead><tbody>
  <?php foreach ($events as $event): $ip = is_string($event['ip_address'] ?? null) ? @inet_ntop($event['ip_address']) : false; $detailTemplateId = 'audit-detail-' . (int)$event['id']; $sessionCorrelationId = is_string($event['session_correlation_id'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $event['session_correlation_id']) === 1 ? $event['session_correlation_id'] : null; $sessionCode = $sessionCorrelationId === null ? null : strtoupper(implode('-', str_split(substr($sessionCorrelationId, 0, 12), 4))); ?>
    <tr>
      <td data-label="<?= e(__('Time')) ?>"><span class="audit-primary"><?= e($displayTimestamp($event['occurred_at_utc'] ?? null)) ?></span><span class="audit-secondary audit-code">#<?= (int)$event['id'] ?></span></td>
      <td class="audit-col-actor" data-label="<?= e(__('Actor')) ?>"><span class="audit-primary"><?= e($event['actor_label']) ?></span><?php if (!empty($event['actor_email'])): ?><span class="audit-secondary"><?= e($event['actor_email']) ?></span><?php endif; ?><?php if ($sessionCode !== null): ?><span class="audit-session" title="<?= e(__('Session correlation ID') . ': ' . $sessionCorrelationId) ?>"><?= svg_ico('monitor') ?><span><?= _e('Session') ?></span><code><?= e($sessionCode) ?></code></span><?php endif; ?></td>
      <?php if ($tab === 'mutations'): $changes = $prettyJson($event['changes'] ?? null); $metadata = $prettyJson($event['metadata'] ?? null); ?>
      <td data-label="<?= e(__('Event')) ?>"><span class="audit-event audit-code"><?= e($event['event_key']) ?></span><span class="audit-secondary"><?= e($event['provider']) ?> · <?= e($event['source']) ?></span></td>
      <td class="audit-col-resource" data-label="<?= e(__('Resource')) ?>"><span class="audit-primary"><?= e($event['resource_label'] ?: $event['resource_type'] . ' #' . $event['resource_id']) ?></span><span class="audit-secondary"><?= e($event['resource_type'] . ' #' . $event['resource_id']) ?></span></td>
      <td class="audit-col-route" data-label="<?= e(__('Route')) ?>"><span class="audit-code"><?= e($event['route'] ?: $event['source']) ?></span><span class="audit-secondary"><?= e(trim((string)($event['request_method'] ?? '') . ' ' . (string)($event['request_path'] ?? ''))) ?></span></td>
      <td data-label="<?= e(__('Details')) ?>"><button type="button" class="audit-detail-trigger" data-audit-detail-template="<?= e($detailTemplateId) ?>" aria-haspopup="dialog" aria-controls="audit-detail-modal" aria-label="<?= e(__('View') . ': ' . (string)$event['event_key']) ?>"><?= svg_ico('eye') ?> <?= _e('View') ?></button><template id="<?= e($detailTemplateId) ?>"><dl class="audit-detail-list"><div><dt><?= _e('Actor') ?></dt><dd><span class="audit-primary"><?= e($event['actor_label']) ?></span><?php if (!empty($event['actor_email'])): ?><span class="audit-secondary"><?= e($event['actor_email']) ?></span><?php endif; ?></dd></div><?php if ($sessionCorrelationId !== null): ?><div><dt><?= _e('Session correlation ID') ?></dt><dd class="audit-code"><?= e($sessionCorrelationId) ?></dd></div><?php endif; ?><div><dt><?= _e('Resource') ?></dt><dd><span class="audit-primary"><?= e($event['resource_label'] ?: $event['resource_type'] . ' #' . $event['resource_id']) ?></span><span class="audit-secondary"><?= e($event['resource_type'] . ' #' . $event['resource_id']) ?></span></dd></div><div><dt><?= _e('Route') ?></dt><dd><span class="audit-code"><?= e($event['route'] ?: $event['source']) ?></span><span class="audit-secondary"><?= e(trim((string)($event['request_method'] ?? '') . ' ' . (string)($event['request_path'] ?? ''))) ?></span></dd></div><div><dt><?= _e('Operation ID') ?></dt><dd class="audit-code"><?= e($event['operation_id']) ?></dd></div><?php if ($ip): ?><div><dt><?= _e('IP address') ?></dt><dd><?= e($ip) ?></dd></div><?php endif; ?></dl><?php if ($changes !== ''): ?><section class="audit-detail-section"><h3><?= _e('Changes') ?></h3><pre><?= e($changes) ?></pre></section><?php endif; ?><?php if ($metadata !== ''): ?><section class="audit-detail-section"><h3><?= _e('Metadata') ?></h3><pre><?= e($metadata) ?></pre></section><?php endif; ?></template></td>
      <?php else: ?>
      <td data-label="<?= e(__('Event')) ?>"><span class="audit-event audit-code"><?= e($event['event_key']) ?></span><span class="audit-secondary">HTTP <?= (int)$event['response_status'] ?></span></td>
      <td data-label="<?= e(__('Route')) ?>"><span class="audit-code"><?= e($event['route']) ?></span><span class="audit-secondary audit-code"><?= e($event['request_path']) ?></span></td>
      <td data-label="<?= e(__('Details')) ?>"><button type="button" class="audit-detail-trigger" data-audit-detail-template="<?= e($detailTemplateId) ?>" aria-haspopup="dialog" aria-controls="audit-detail-modal" aria-label="<?= e(__('View') . ': ' . (string)$event['event_key']) ?>"><?= svg_ico('eye') ?> <?= _e('View') ?></button><template id="<?= e($detailTemplateId) ?>"><dl class="audit-detail-list"><div><dt><?= _e('Actor') ?></dt><dd><span class="audit-primary"><?= e($event['actor_label']) ?></span><?php if (!empty($event['actor_email'])): ?><span class="audit-secondary"><?= e($event['actor_email']) ?></span><?php endif; ?></dd></div><?php if ($sessionCorrelationId !== null): ?><div><dt><?= _e('Session correlation ID') ?></dt><dd class="audit-code"><?= e($sessionCorrelationId) ?></dd></div><?php endif; ?><div><dt><?= _e('Request ID') ?></dt><dd class="audit-code"><?= e($event['request_id']) ?></dd></div><?php if ($ip): ?><div><dt><?= _e('IP address') ?></dt><dd><?= e($ip) ?></dd></div><?php endif; ?><?php if (!empty($event['user_agent'])): ?><div><dt><?= _e('User agent') ?></dt><dd class="audit-code"><?= e($event['user_agent']) ?></dd></div><?php endif; ?></dl></template></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($events === []): ?><tr><td colspan="<?= $tab === 'access' ? 5 : 6 ?>" class="audit-empty"><div class="audit-empty__content"><span class="audit-empty__icon" aria-hidden="true"><?= svg_ico('history') ?></span><strong><?= e($hasActiveQuery ? ($tab === 'access' ? __('No access events match these filters.') : __('No action events match these filters.')) : ($tab === 'access' ? __('No access events have been recorded yet.') : __('No action events have been recorded yet.'))) ?></strong><?php if ($hasActiveQuery): ?><p><?= _e('Adjust or clear the active filters to see more results.') ?></p><a class="adam-cancle" href="<?= e($tabUrl($tab)) ?>"><?= svg_ico('rotate-ccw') ?> <?= _e('Reset') ?></a><?php endif; ?></div></td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($total > 0): ?><div class="audit-pager"><span><?= sprintf(__('Showing %d-%d of %d results'), $offset + 1, min($offset + $perPage, $total), $total) ?></span><nav class="adam-pagination" aria-label="<?= e(__('Pagination')) ?>"><?php if ($page > 1): ?><a rel="prev" href="<?= e($pageUrl($page - 1)) ?>"><?= _e('Previous') ?></a><?php endif; ?><strong aria-current="page"><?= sprintf(__('Page %d of %d'), $page, $totalPages) ?></strong><?php if ($page < $totalPages): ?><a rel="next" href="<?= e($pageUrl($page + 1)) ?>"><?= _e('Next') ?></a><?php endif; ?></nav></div><?php endif; ?>
  </div>
  <div id="audit-detail-modal" class="adam-modal audit-detail-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="audit-detail-modal-title">
    <div class="adam-modal__panel audit-detail-modal__panel" tabindex="-1">
      <header class="audit-detail-modal__header"><div class="audit-detail-modal__heading"><span class="audit-detail-modal__icon" aria-hidden="true"><?= svg_ico('eye') ?></span><h2 id="audit-detail-modal-title" class="audit-detail-modal__title"><?= e(__('Details') . ': ' . ($tab === 'access' ? __('Access') : __('Actions'))) ?></h2></div><button type="button" class="audit-detail-modal__close" data-audit-detail-close aria-label="<?= e(__('Close')) ?>" title="<?= e(__('Close')) ?>"><?= svg_ico('x') ?></button></header>
      <div class="audit-detail-modal__body" data-audit-detail-body></div>
    </div>
  </div>
  <script>
  (function(){
    var toggle = document.querySelector('.audit-columns .cols-toggle-btn');
    var menu = document.getElementById('audit-columns-menu');
    if (!toggle || !menu) return;
    var filterDisclosure = document.querySelector('.audit-filter-bar .posts-filter-disclosure');
    var storageKey = <?= json_encode('audit_columns_' . $tab, JSON_UNESCAPED_SLASHES) ?>;
    var checkboxes = menu.querySelectorAll('[data-audit-column]');

    function loadState(){
      try {
        var saved = localStorage.getItem(storageKey);
        var state = saved ? JSON.parse(saved) : null;
        return state && typeof state === 'object' && !Array.isArray(state) ? state : {};
      } catch (error) {
        return {};
      }
    }

    function saveState(state){
      try { localStorage.setItem(storageKey, JSON.stringify(state)); }
      catch (error) {}
    }

    function applyColumn(checkbox){
      var column = checkbox.getAttribute('data-audit-column') || '';
      if (!column) return;
      document.querySelectorAll('.audit-table .' + column).forEach(function(cell){
        cell.classList.toggle('col-hidden', !checkbox.checked);
      });
    }

    function setMenuOpen(open){
      menu.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    var state = loadState();
    checkboxes.forEach(function(checkbox){
      var column = checkbox.getAttribute('data-audit-column') || '';
      checkbox.checked = state[column] !== false;
      applyColumn(checkbox);
      checkbox.addEventListener('change', function(){
        applyColumn(checkbox);
        var nextState = loadState();
        nextState[column] = checkbox.checked;
        saveState(nextState);
      });
    });

    toggle.addEventListener('click', function(event){
      event.stopPropagation();
      var willOpen = !menu.classList.contains('open');
      if (willOpen && filterDisclosure) filterDisclosure.open = false;
      setMenuOpen(willOpen);
    });
    if (filterDisclosure) filterDisclosure.addEventListener('toggle', function(){
      if (filterDisclosure.open) setMenuOpen(false);
    });
    menu.addEventListener('click', function(event){ event.stopPropagation(); });
    document.addEventListener('click', function(){ setMenuOpen(false); });
    document.addEventListener('keydown', function(event){
      if (event.key !== 'Escape' || !menu.classList.contains('open')) return;
      setMenuOpen(false);
      toggle.focus();
    });
  })();

  (function(){
    var modal = document.getElementById('audit-detail-modal');
    if (!modal) return;
    var panel = modal.querySelector('.audit-detail-modal__panel');
    var body = modal.querySelector('[data-audit-detail-body]');
    var closeButton = modal.querySelector('[data-audit-detail-close]');
    var lastFocus = null;
    var documentOverflow = '';
    var bodyOverflow = '';

    function closeModal(){
      if (modal.getAttribute('aria-hidden') !== 'false') return;
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.style.overflow = documentOverflow;
      document.body.style.overflow = bodyOverflow;
      if (body) body.replaceChildren();
      if (lastFocus && lastFocus.isConnected && typeof lastFocus.focus === 'function') lastFocus.focus();
      lastFocus = null;
    }

    document.querySelectorAll('[data-audit-detail-template]').forEach(function(trigger){
      trigger.addEventListener('click', function(){
        var templateId = trigger.getAttribute('data-audit-detail-template') || '';
        var template = templateId ? document.getElementById(templateId) : null;
        if (!template || !(template instanceof HTMLTemplateElement) || !body) return;
        lastFocus = trigger;
        body.replaceChildren(template.content.cloneNode(true));
        documentOverflow = document.documentElement.style.overflow;
        bodyOverflow = document.body.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        modal.setAttribute('aria-hidden', 'false');
        if (closeButton) closeButton.focus();
        else if (panel) panel.focus();
      });
    });

    if (closeButton) closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function(event){
      if (event.target === modal) closeModal();
    });
    modal.addEventListener('keydown', function(event){
      if (modal.getAttribute('aria-hidden') !== 'false') return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal();
        return;
      }
      if (event.key !== 'Tab') return;
      var focusable = Array.prototype.slice.call(modal.querySelectorAll('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'));
      if (focusable.length === 0) {
        event.preventDefault();
        if (panel) panel.focus();
        return;
      }
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
  <?php endif; ?>
</section>
