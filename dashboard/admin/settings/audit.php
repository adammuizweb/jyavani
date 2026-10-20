<?php
declare(strict_types=1);

require_once __DIR__ . '/../_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once __DIR__ . '/../_guard.php';

[$uid] = adiwira_require_permission($pdo, 'core.audit.read', false);
adiwira_require_site_owner($pdo, false);

$base = ADMIN_BASE_PATH;
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
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
$parseDate = static function (string $value): ?DateTimeImmutable {
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, app_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    return $date && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
        && $date->format('Y-m-d') === $value ? $date : null;
};
$fromDate = $filters['from'] !== '' ? $parseDate($filters['from']) : null;
$toDate = $filters['to'] !== '' ? $parseDate($filters['to']) : null;
if ($fromDate) {
    $where[] = 'occurred_at_utc >= :from_utc';
    $params[':from_utc'] = app_site_datetime_to_utc_mysql($fromDate);
} else {
    $filters['from'] = '';
}
if ($toDate) {
    $where[] = 'occurred_at_utc < :to_utc';
    $params[':to_utc'] = app_site_datetime_to_utc_mysql($toDate->modify('+1 day'));
} else {
    $filters['to'] = '';
}
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
    'SELECT actor_user_id, MAX(actor_label) AS actor_label, MAX(actor_email) AS actor_email, COUNT(*) AS total
     FROM audit_events
     WHERE actor_user_id IS NOT NULL
     GROUP BY actor_user_id
     ORDER BY actor_label ASC
     LIMIT 200'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filterQuery = array_filter($filters, static fn(mixed $value): bool => $value !== '' && $value !== 0);
$pageUrl = static function (int $target) use ($base, $filterQuery): string {
    return $base . '/?' . http_build_query(array_merge(['page' => 'admin/settings/audit'], $filterQuery, ['p' => $target]), '', '&', PHP_QUERY_RFC3986);
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
?>

<style>
.audit-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1rem}.audit-head h1{margin:0}.audit-head p{margin:.35rem 0 0;color:var(--text-muted,#64748b);max-width:70ch}.audit-filters{display:grid;grid-template-columns:minmax(220px,2fr) repeat(4,minmax(130px,1fr));gap:.7rem;align-items:end}.audit-filters label{display:grid;gap:.3rem;font-size:.84rem;font-weight:650}.audit-filters__dates{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}.audit-actions{display:flex;gap:.5rem;align-items:center}.audit-table-wrap{overflow-x:auto;margin-top:1rem}.audit-table{width:100%;border-collapse:collapse;min-width:940px}.audit-table th,.audit-table td{text-align:left;padding:.72rem;border-bottom:1px solid var(--border,#e2e8f0);vertical-align:top}.audit-table th{font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#64748b)}.audit-code{font:600 .78rem ui-monospace,SFMono-Regular,Menlo,monospace}.audit-actor small,.audit-resource small{display:block;color:var(--text-muted,#64748b);margin-top:.18rem}.audit-details summary{cursor:pointer;color:var(--accent,#2563eb);font-weight:650}.audit-details pre{max-width:560px;max-height:320px;overflow:auto;white-space:pre-wrap;background:var(--surface-soft,#f8fafc);padding:.75rem;border-radius:.55rem;font-size:.75rem}.audit-empty{padding:3rem 1rem;text-align:center;color:var(--text-muted,#64748b)}.audit-pagination{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-top:1rem}.audit-pagination__links{display:flex;gap:.45rem}@media(max-width:1100px){.audit-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.audit-filters__search{grid-column:1/-1}}@media(max-width:640px){.audit-head{display:block}.audit-filters,.audit-filters__dates{grid-template-columns:1fr}.audit-filters__search{grid-column:auto}.audit-actions{flex-wrap:wrap}.audit-pagination{align-items:flex-start;flex-direction:column}}
</style>

<section class="adam-card">
  <div class="audit-head">
    <div>
      <h1><?= svg_ico('history') ?> <?= _e('Audit Log') ?></h1>
      <p><?= _e('Review successful content mutations recorded atomically with their database changes. Known credential fields and full editor content are redacted before storage.') ?></p>
    </div>
    <span class="adam-badge"><?= _e('Site Owner') ?></span>
  </div>

  <form method="get" class="audit-filters">
    <input type="hidden" name="page" value="admin/settings/audit">
    <label class="audit-filters__search"><?= _e('Search audit events') ?>
      <input class="inp" type="search" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="<?= e(__('Event, actor, resource, or route…')) ?>">
    </label>
    <label><?= _e('Provider') ?>
      <select class="inp" name="provider"><option value=""><?= _e('All providers') ?></option><?php foreach ($providers as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['provider'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select>
    </label>
    <label><?= _e('Event') ?>
      <select class="inp" name="event"><option value=""><?= _e('All events') ?></option><?php foreach ($eventKeys as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['event'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select>
    </label>
    <label><?= _e('Resource') ?>
      <select class="inp" name="resource"><option value=""><?= _e('All resources') ?></option><?php foreach ($resourceTypes as $option): ?><option value="<?= e($option['value']) ?>" <?= $filters['resource'] === $option['value'] ? 'selected' : '' ?>><?= e($option['value']) ?> (<?= (int)$option['total'] ?>)</option><?php endforeach; ?></select>
    </label>
    <label><?= _e('Actor') ?>
      <select class="inp" name="actor"><option value="0"><?= _e('All actors') ?></option><?php foreach ($actors as $actor): ?><option value="<?= (int)$actor['actor_user_id'] ?>" <?= $filters['actor'] === (int)$actor['actor_user_id'] ? 'selected' : '' ?>><?= e($actor['actor_label']) ?> (<?= (int)$actor['total'] ?>)</option><?php endforeach; ?></select>
    </label>
    <div class="audit-filters__dates">
      <label><?= _e('From date') ?><input class="inp" type="date" name="from" value="<?= e($filters['from']) ?>"></label>
      <label><?= _e('To date') ?><input class="inp" type="date" name="to" value="<?= e($filters['to']) ?>"></label>
    </div>
    <div class="audit-actions">
      <button class="adam-button" type="submit"><?= svg_ico('search') ?> <?= _e('Filter') ?></button>
      <a class="adam-cancle" href="<?= e($base . '/?page=admin/settings/audit') ?>"><?= _e('Reset') ?></a>
    </div>
  </form>

  <div class="audit-table-wrap">
    <table class="audit-table">
      <thead><tr><th><?= _e('Time') ?></th><th><?= _e('Actor') ?></th><th><?= _e('Event') ?></th><th><?= _e('Resource') ?></th><th><?= _e('Route') ?></th><th><?= _e('Details') ?></th></tr></thead>
      <tbody>
      <?php foreach ($events as $event):
          $changes = $prettyJson($event['changes'] ?? null);
          $metadata = $prettyJson($event['metadata'] ?? null);
          $ip = is_string($event['ip_address'] ?? null) ? @inet_ntop($event['ip_address']) : false;
      ?>
        <tr>
          <td><?= e($displayTimestamp($event['occurred_at_utc'] ?? null)) ?><br><small class="audit-code">#<?= (int)$event['id'] ?></small></td>
          <td class="audit-actor"><strong><?= e($event['actor_label']) ?></strong><?php if (!empty($event['actor_email'])): ?><small><?= e($event['actor_email']) ?></small><?php endif; ?></td>
          <td><span class="audit-code"><?= e($event['event_key']) ?></span><br><small><?= e($event['provider']) ?> · <?= e($event['source']) ?></small></td>
          <td class="audit-resource"><strong><?= e($event['resource_label'] ?: $event['resource_type'] . ' #' . $event['resource_id']) ?></strong><small><?= e($event['resource_type'] . ' #' . $event['resource_id']) ?></small></td>
          <td><span class="audit-code"><?= e($event['route'] ?: $event['source']) ?></span><br><small><?= e(trim((string)($event['request_method'] ?? '') . ' ' . (string)($event['request_path'] ?? ''))) ?></small></td>
          <td><details class="audit-details"><summary><?= _e('View details') ?></summary><p><strong><?= _e('Operation ID') ?>:</strong> <span class="audit-code"><?= e($event['operation_id']) ?></span></p><?php if ($ip): ?><p><strong><?= _e('IP address') ?>:</strong> <?= e($ip) ?></p><?php endif; ?><?php if ($changes !== ''): ?><strong><?= _e('Changes') ?></strong><pre><?= e($changes) ?></pre><?php endif; ?><?php if ($metadata !== ''): ?><strong><?= _e('Metadata') ?></strong><pre><?= e($metadata) ?></pre><?php endif; ?></details></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($events === []): ?><tr><td colspan="6" class="audit-empty"><?= _e('No audit events match these filters.') ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="audit-pagination">
    <span><?= sprintf(__('Showing %d-%d of %d results'), $total > 0 ? $offset + 1 : 0, min($offset + $perPage, $total), $total) ?></span>
    <div class="audit-pagination__links"><?php if ($page > 1): ?><a class="adam-cancle" rel="prev" href="<?= e($pageUrl($page - 1)) ?>"><?= _e('Previous') ?></a><?php endif; ?><span class="adam-button"><?= sprintf(__('Page %d of %d'), $page, $totalPages) ?></span><?php if ($page < $totalPages): ?><a class="adam-cancle" rel="next" href="<?= e($pageUrl($page + 1)) ?>"><?= _e('Next') ?></a><?php endif; ?></div>
  </div>
</section>
