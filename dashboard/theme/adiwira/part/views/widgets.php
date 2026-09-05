<?php
declare(strict_types=1);
// Dashboard widgets — helpers for the widgetized home page

if (!function_exists('dash_widget_cms_info')):

function dash_widget_cms_info(PDO $pdo): string
{
    require_once dirname(DASH_PATH) . '/app/controllers/UpdateStatusController.php';
    $versionFile = dirname(DASH_PATH) . '/version.json';
    $ver = is_file($versionFile) ? (json_decode(file_get_contents($versionFile), true) ?: []) : [];
    $name    = $ver['name'] ?? 'Jyavani CMS';
    $version = $ver['version'] ?? '0.0.0';
    $edition = $ver['edition'] ?? '—';
    $build   = $ver['build'] ?? '—';
    $phpReq  = $ver['php_required'] ?? '8.1';
    $updateSnapshot = UpdateStatusController::getSnapshot();
    $cmsStatus = $updateSnapshot['components']['core'] ?? [];
    $cmsLatest = ($cmsStatus['state'] ?? 'unknown') === 'ok' && ($cmsStatus['has_update'] ?? false) !== true
        ? '<span class="dw-latest">' . __('Latest') . '</span>'
        : '';

    $editionBadge = $edition !== '—'
        ? '<span class="edition-badge"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>' . h($edition) . '</span>'
        : '—';

    return '
<div class="dw-card">
  <div class="dw-card-head">
    <span class="dw-card-icon">' . svg_ico('terminal') . '</span>
    <span class="dw-card-title">' . __('CMS Info') . '</span>
  </div>
  <div class="dw-card-body">
    <table class="dw-table">
      <tr><td>' . __('CMS') . '</td><td><strong>' . h($name) . '</strong></td></tr>
      <tr><td>' . __('Version') . '</td><td><strong>v' . h($version) . '</strong> <span data-cms-latest>' . $cmsLatest . '</span></td></tr>
      <tr><td>' . __('Edition') . '</td><td>' . $editionBadge . '</td></tr>
      <tr><td>' . __('Build') . '</td><td>' . h($build) . '</td></tr>
      <tr><td>' . __('PHP Required') . '</td><td>' . h($phpReq) . '+ (' . __('server:') . ' ' . PHP_VERSION . ')</td></tr>
    </table>
  </div>
</div>';
}

function dash_widget_update_status(PDO $pdo): string
{
    require_once dirname(DASH_PATH) . '/app/controllers/UpdateStatusController.php';
    $snapshot = UpdateStatusController::getSnapshot();
    $pluginUpdates = is_array($snapshot['components']['plugins']['updates'] ?? null) ? $snapshot['components']['plugins']['updates'] : [];
    $themeUpdates = is_array($snapshot['components']['themes']['updates'] ?? null) ? $snapshot['components']['themes']['updates'] : [];
    $cmsUpdate = is_array($snapshot['components']['core'] ?? null) ? $snapshot['components']['core'] : [];

    $hasCms  = ($cmsUpdate['has_update'] ?? false) === true;
    $hasPlugin = count($pluginUpdates) > 0;
    $hasTheme  = count($themeUpdates) > 0;
    $total     = ($hasCms ? 1 : 0) + count($pluginUpdates) + count($themeUpdates);

    $base = ADMIN_BASE_PATH;
    $items = '';

    if ($hasCms) {
        $items .= '<tr><td>' . svg_ico('monitor') . ' ' . __('CMS') . '</td>'
               . '<td class="dw-up">v' . h($cmsUpdate['current']) . ' → v' . h($cmsUpdate['latest']) . '</td>'
               . '<td><a href="' . h($base) . '/?page=admin/update/index" class="dw-link">' . __('Update') . '</a></td></tr>';
    }
    foreach ($pluginUpdates as $name => $info) {
        $items .= '<tr><td>' . svg_ico('puzzle') . ' ' . h($name) . '</td>'
               . '<td class="dw-up">v' . h($info['current_version']) . ' → v' . h($info['new_version']) . '</td>'
               . '<td><a href="' . h($base) . '/?page=admin/plugins/index" class="dw-link">' . __('Update') . '</a></td></tr>';
    }
    foreach ($themeUpdates as $folder => $info) {
        $items .= '<tr><td>' . svg_ico('palette') . ' ' . h($folder) . '</td>'
               . '<td class="dw-up">v' . h($info['current_version']) . ' → v' . h($info['new_version']) . '</td>'
               . '<td><a href="' . h($base) . '/?page=admin/themes/assign" class="dw-link">' . __('Update') . '</a></td></tr>';
    }

    if (!$items) {
        $emptyMessage = ($snapshot['state'] ?? 'unknown') === 'unknown'
            ? __('Updates have not been checked yet.')
            : __('Everything is up to date.');
        $items = '<tr><td colspan="3" class="dw-na">' . $emptyMessage . '</td></tr>';
    }

    $checkedAt = (int)($snapshot['checked_at'] ?? 0);
    $statusNote = $checkedAt > 0 ? __('Last checked:') . ' ' . date('Y-m-d H:i', $checkedAt) : __('Updates have not been checked yet.');
    if (($snapshot['state'] ?? 'unknown') !== 'ok' && ($snapshot['state'] ?? 'unknown') !== 'unknown') {
        $statusNote = __('Some update sources could not be reached. Showing the last known results.');
    }

    return '
<div class="dw-card dw-card-update" data-update-widget>
  <div class="dw-card-head">
    <span class="dw-card-icon">' . svg_ico('bell') . '</span>
    <span class="dw-card-title">' . __('Update Status') . '</span>
    <span class="dw-badge" data-update-total' . ($total > 0 ? '' : ' style="display:none"') . '>' . $total . '</span>
  </div>
  <div class="dw-card-body">
    <table class="dw-table">
      <thead><tr><th>' . __('Item') . '</th><th>' . __('Version') . '</th><th></th></tr></thead>
      <tbody data-update-items>' . $items . '</tbody>
    </table>
    <div class="dw-na" data-update-meta style="padding-top:.6rem">' . h($statusNote) . '</div>
  </div>
</div>';
}

function dash_widget_quick_stats(PDO $pdo): string
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $base = ADMIN_BASE_PATH;
    $stats = [];
    $countScoped = static function(
        string $table,
        string $where,
        string $ownerColumn,
        string $readPermission,
        string $prefix
    ) use ($pdo, $uid): ?int {
        $statsCondition = authorization_owner_scope_condition(
            $pdo,
            $uid,
            'core.dashboard.stats.read',
            $ownerColumn,
            $prefix . '_stats'
        );
        $readCondition = authorization_owner_scope_condition(
            $pdo,
            $uid,
            $readPermission,
            $ownerColumn,
            $prefix . '_read'
        );
        if ($statsCondition === null || $readCondition === null) return null;
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM $table WHERE $where"
            . ' AND (' . $statsCondition['sql'] . ')'
            . ' AND (' . $readCondition['sql'] . ')'
        );
        $stmt->execute(array_merge($statsCondition['params'], $readCondition['params']));
        return (int)$stmt->fetchColumn();
    };

    try {
        $resources = [
            ['posts', "type='article' AND is_deleted=0", 'posts.created_by', 'core.posts.read', 'widget_posts', 'admin/posts/index', __('Posts')],
            ['posts', "type='page' AND is_deleted=0", 'posts.created_by', 'core.pages.read', 'widget_pages', 'admin/pages/index', __('Pages')],
            ['categories', 'is_deleted=0', 'categories.created_by', 'core.categories.read', 'widget_categories', 'admin/categories/index', __('Categories')],
            ['users', 'is_deleted=0', 'users.id', 'core.users.read', 'widget_users', 'admin/users/index', __('Users')],
        ];
        foreach ($resources as [$table, $where, $ownerColumn, $permission, $prefix, $route, $label]) {
            $count = $countScoped($table, $where, $ownerColumn, $permission, $prefix);
            if ($count === null) continue;
            $stats[] = '<a href="' . h($base) . '/?page=' . h($route) . '" class="dw-stat">'
                . '<span class="dw-stat-num">' . $count . '</span>'
                . '<span class="dw-stat-label">' . h($label) . '</span></a>';
        }
    } catch (Throwable $e) {
        $stats = [];
    }
    if ($stats === []) return '';

    return '
<div class="dw-card">
  <div class="dw-card-head">
    <span class="dw-card-icon">' . svg_ico('bar-chart-3') . '</span>
    <span class="dw-card-title">' . __('Quick Stats') . '</span>
  </div>
  <div class="dw-card-body">
    <div class="dw-stats">
      ' . implode('', $stats) . '
    </div>
  </div>
</div>';
}

function dash_widget_recent_posts(PDO $pdo): string
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $statsCondition = authorization_owner_scope_condition(
        $pdo,
        $uid,
        'core.dashboard.stats.read',
        'posts.created_by',
        'widget_recent_stats'
    );
    $readCondition = authorization_owner_scope_condition(
        $pdo,
        $uid,
        'core.posts.read',
        'posts.created_by',
        'widget_recent_read'
    );
    if ($statsCondition === null || $readCondition === null) return '';
    try {
        $st = $pdo->prepare(
            "SELECT id, title, status, created_by, created_at, updated_at
             FROM posts
             WHERE type='article' AND is_deleted=0
               AND ({$statsCondition['sql']})
               AND ({$readCondition['sql']})
             ORDER BY updated_at DESC LIMIT 5"
        );
        $st->execute(array_merge($statsCondition['params'], $readCondition['params']));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }

    $base = ADMIN_BASE_PATH;
    $items = '';

    foreach ($rows as $r) {
        $status = strtolower(trim((string)($r['status'] ?? 'unknown')));
        $statusClass = in_array($status, ['published','draft','private'], true) ? $status : 'unknown';
        $ownerId = (int)($r['created_by'] ?? 0);
        $canUpdate = user_can($pdo, $uid, 'core.posts.update', ['owner_id' => $ownerId])
            && ($status === 'draft' || user_can($pdo, $uid, 'core.posts.publish', ['owner_id' => $ownerId]));
        $title = h(mb_substr((string)$r['title'], 0, 40));
        $titleCell = $canUpdate
            ? '<a href="' . h($base) . '/?page=admin/posts/edit&id=' . (int)$r['id'] . '" class="dw-link">' . $title . '</a>'
            : $title;
        $items .= '<tr>'
               . '<td>' . $titleCell . '</td>'
                . '<td><span class="adam-status ' . h($statusClass) . '"><span class="adam-status-text">' . h(__(ucfirst($status))) . '</span></span></td>'
               . '<td class="dw-muted">' . h(function_exists('format_date_ddmmyyyy_time_bracket') ? format_date_ddmmyyyy_time_bracket((string)$r['updated_at']) : (string)$r['updated_at']) . '</td>'
               . '</tr>';
    }

    if (!$items) {
        $items = '<tr><td colspan="3" class="dw-na">' . __('No posts yet.') . '</td></tr>';
    }

    return '
<div class="dw-card">
  <div class="dw-card-head">
    <span class="dw-card-icon">' . svg_ico('file-text') . '</span>
    <span class="dw-card-title">' . __('Recent Posts') . '</span>
  </div>
  <div class="dw-card-body">
    <table class="dw-table">
      <thead><tr><th>' . __('Title') . '</th><th>' . __('Status') . '</th><th>' . __('Updated') . '</th></tr></thead>
      <tbody>' . $items . '</tbody>
    </table>
  </div>
</div>';
}

function dash_widget_system_info(): string
{
    $server = $_SERVER['SERVER_SOFTWARE'] ?? '—';
    $dbDriver = 'MySQL/MariaDB';

    return '
<div class="dw-card">
  <div class="dw-card-head">
    <span class="dw-card-icon">' . svg_ico('server') . '</span>
    <span class="dw-card-title">' . __('System Info') . '</span>
  </div>
  <div class="dw-card-body">
    <table class="dw-table">
      <tr><td>' . __('Server') . '</td><td>' . h($server) . '</td></tr>
      <tr><td>' . __('PHP Version') . '</td><td>' . PHP_VERSION . '</td></tr>
      <tr><td>' . __('Database') . '</td><td>' . $dbDriver . '</td></tr>
      <tr><td>' . __('Admin Path') . '</td><td><code>' . h(ADMIN_BASE_PATH) . '</code></td></tr>
    </table>
  </div>
</div>';
}

endif;
