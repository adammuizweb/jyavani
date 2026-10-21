<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$widgets = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/views/widgets.php');
$home = (string)file_get_contents($root . '/dashboard/theme/adiwira/part/views/home.php');
$layoutSave = (string)file_get_contents($root . '/dashboard/admin/save_dashboard_layout.php');
$layoutHelper = (string)file_get_contents($root . '/cfg/helpers/dashboard_layout.php');
$styles = (string)file_get_contents($root . '/public/static/dashboard/css/style.css');
$dashboardScript = (string)file_get_contents($root . '/public/static/dashboard/js/dashboard-widgets.js');
$version = json_decode((string)file_get_contents($root . '/version.json'), true);
$translations = (string)file_get_contents($root . '/schema/translations.sql');
$expected = [
    'CMS Info' => 'braces',
    'Quick Stats' => 'chart-bar',
    'System Info' => 'server',
    'Site Health' => 'shield-check',
    'Recent Login' => 'monitor',
    'Recent Activity' => 'history',
];

$failures = [];
foreach ($expected as $widget => $icon) {
    $asset = $root . '/public/static/icons/lucide/' . $icon . '.svg';
    $validAsset = is_file($asset)
        && str_contains((string)file_get_contents($asset), '<svg')
        && str_contains((string)file_get_contents($asset), 'viewBox="0 0 24 24"');
    $usesIcon = preg_match(
        '/svg_ico\(\'' . preg_quote($icon, '/') . '\'\).*?__\(\'' . preg_quote($widget, '/') . '\'\)/s',
        $widgets
    ) === 1;
    $ok = $validAsset && $usesIcon;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $widget . ' uses the bundled Lucide ' . $icon . ' asset' . PHP_EOL;
    if (!$ok) $failures[] = $widget;
}

$externalLinkAsset = $root . '/public/static/icons/lucide/external-link.svg';
$externalLinksOk = is_file($externalLinkAsset)
    && substr_count($widgets, "svg_ico('external-link')") >= 2
    && str_contains($widgets, "['homepage']")
    && str_contains($widgets, "['changelog_url']")
    && str_contains($widgets, 'target="_blank" rel="noopener noreferrer"')
    && str_contains($widgets, '<td><a class="dw-cms-link"')
    && !str_contains($widgets, 'class="dw-card-external"')
    && str_contains($styles, '.dw-cms-link')
    && str_contains($styles, '.dw-version-link');
$checkLabel = 'CMS Info links its CMS value and version details with the bundled external-link icon';
echo ($externalLinksOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$externalLinksOk) $failures[] = $checkLabel;

$releaseVersion = is_array($version) ? (string)($version['version'] ?? '') : '';
$changelogUrl = is_array($version) ? (string)($version['changelog_url'] ?? '') : '';
$changelogOk = $releaseVersion !== ''
    && $changelogUrl === 'https://github.com/adammuizweb/jyavani/releases/tag/v' . $releaseVersion;
$checkLabel = 'version metadata links to its exact user-facing GitHub Release';
echo ($changelogOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$changelogOk) $failures[] = $checkLabel;

$ellipsisOk = str_contains($widgets, "mb_strlen(\$rawTitle, 'UTF-8') > 40")
    && str_contains($widgets, "mb_substr(\$rawTitle, 0, 40, 'UTF-8')")
    && str_contains($widgets, ". '...'")
    && str_contains($widgets, 'class="dw-post-title"');
$checkLabel = 'Recent Posts appends an ellipsis only when a UTF-8 title is truncated';
echo ($ellipsisOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$ellipsisOk) $failures[] = $checkLabel;

$translationOk = substr_count($translations, "'Visit the official Jyavani website'") === 2
    && substr_count($translations, "'View this version on GitHub'") === 2
    && substr_count($translations, "'Recent Activity'") === 2
    && substr_count($translations, "'Data is temporarily unavailable.'") === 2;
$checkLabel = 'dashboard widget labels have Indonesian and German translation seeds';
echo ($translationOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$translationOk) $failures[] = $checkLabel;

$securityWidgetsOk = str_contains($widgets, 'function dash_widget_recent_login')
    && str_contains($widgets, "'core.sessions.manage'")
    && str_contains($widgets, 'auth_sessions_for_user($pdo, $uid')
    && str_contains($widgets, 'auth_sessions_recent($pdo')
    && str_contains($widgets, 'function dash_widget_recent_activity')
    && str_contains($widgets, "'core.audit.read'")
    && str_contains($widgets, "FROM audit_events")
    && !str_contains($widgets, "FROM access_events")
    && str_contains($widgets, "\$widgets['recent_login']")
    && str_contains($widgets, "\$widgets['recent_activity']")
    && str_contains($widgets, "'recent_login:r'")
    && str_contains($widgets, "'recent_activity:l'")
    && preg_match('/class="dw-hide-btn".*?aria-label=.*?svg_ico\(\'eye-off\'\)/s', $home) === 1
    && str_contains($home, "if (\$layoutJson === '' || !is_array(\$order))")
    && str_contains($home, 'foreach (array_slice($order, 0, 100) as $item)')
    && str_contains($home, 'isset($normalizedKeys[$itemKey])')
    && str_contains($widgets, 'function dashboard_widgets_default_layout')
    && str_contains($layoutSave, '$preservationBaseline')
    && str_contains($layoutSave, "[':key' => 'dashboard_widget_layout']")
    && str_contains($layoutSave, 'INSERT IGNORE INTO settings')
    && str_contains($layoutSave, "' FOR UPDATE'")
    && str_contains($layoutSave, '$hasCustomizedLayout')
    && str_contains($layoutHelper, 'elseif (!$hasCustomizedLayout)')
    && substr_count($layoutSave, 'dashboard_layout_validate($decoded)') === 2
    && str_contains($layoutHelper, 'count($layout) > 100')
    && str_contains($layoutHelper, "'Duplicate dashboard widget'")
    && str_contains($layoutSave, 'dashboard_layout_preserve_unavailable(')
    && str_contains($layoutSave, 'dashboard_layout_restrict_submitted($decoded, $actorWidgets)')
    && str_contains($layoutSave, "hash('sha256', \$existingRaw)")
    && str_contains($layoutSave, "'core.dashboard.layout.manage'")
    && str_contains($layoutSave, 'dashboard_widgets_registry($pdo, $actor)')
    && str_contains($layoutSave, 'dashboard_layout_preservation_definitions(')
    && str_contains($layoutSave, 'dashboard_layout_normalize_legacy($existing)')
    && str_contains($home, "is_string(\$layoutJson) ? \$layoutJson : ''")
    && str_contains($home, "name=\"layout_revision\"")
    && str_contains($home, "hash('sha256', \$layoutJson)")
    && str_contains($layoutHelper, '$submittedPositions')
    && str_contains($layoutHelper, '$previousKey')
    && str_contains($layoutHelper, '$nextKey')
    && preg_match('/if \(arrangeActive\).*?updateWidgetPanel\(\);/s', $dashboardScript) === 1
    && str_contains($home, 'data-protected="1"')
    && str_contains($home, "draggable=\"' . (\$protected ? 'false' : 'true')")
    && str_contains($home, "empty(\$definition['protected'])")
    && str_contains($styles, '.dw-hide-btn .lucide-icon')
    && str_contains($styles, 'width:14px;')
    && str_contains($styles, '.dw-feed-card')
    && str_contains($styles, "@media(max-width:420px){\n  .dw-feed-primary");
$checkLabel = 'security dashboard widgets are scoped, optional, responsive, and use bounded live data';
echo ($securityWidgetsOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$securityWidgetsOk) $failures[] = $checkLabel;

require_once $root . '/cfg/helpers/dashboard_layout.php';
$unavailableWidgets = [
    'recent_login' => [
        'item' => 'recent_login:r',
        'index' => 5,
        'previous' => 'quick_stats',
        'next' => 'site_health',
    ],
    'recent_activity' => [
        'item' => 'recent_activity:l',
        'index' => 1,
        'previous' => 'cms_info',
        'next' => 'recent_posts',
    ],
];
$existingLayout = [
    'cms_info:l',
    'recent_activity:r',
    'recent_posts:l',
    'quick_stats:r',
    'recent_login:l',
    'system_info:r',
];
$preservedLayout = dashboard_layout_preserve_unavailable(
    ['recent_posts:l', 'system_info:r', 'recent_login:r'],
    $existingLayout,
    $unavailableWidgets,
    true
);
$defaultLayout = dashboard_layout_preserve_unavailable([], [], $unavailableWidgets, false);
$emptyCustomizedLayout = dashboard_layout_preserve_unavailable([], [], $unavailableWidgets, true);
$genericDefinitions = dashboard_layout_preservation_definitions(
    ['cms_info:l', 'plugin.owner-report:r', 'recent_login:r'],
    [
        'cms_info' => ['render' => 'contract'],
        'recent_login' => ['render' => 'contract', 'protected' => true],
    ]
);
$genericPreservedLayout = dashboard_layout_preserve_unavailable(
    ['cms_info:l'],
    ['cms_info:l', 'plugin.owner-report:r', 'recent_login:r'],
    $genericDefinitions,
    true
);
$legacyLayout = dashboard_layout_normalize_legacy([
    ['w' => 'cms_info', 'col' => 1],
    ['w' => 'plugin.owner-report', 'col' => 2],
]);
$legacyDefinitions = dashboard_layout_preservation_definitions(
    $legacyLayout,
    ['cms_info' => ['render' => 'contract']]
);
$legacyPreservedLayout = dashboard_layout_preserve_unavailable(
    ['cms_info:l'],
    $legacyLayout,
    $legacyDefinitions,
    true
);
$canonicalLayout = [
    'cms_info:l',
    'quick_stats:r',
    'recent_activity:l',
    'recent_login:r',
    'recent_posts:l',
    'site_health:r',
    'system_info:l',
    'update_status:r',
];
$restrictedDefinitions = dashboard_layout_preservation_definitions(
    $canonicalLayout,
    ['cms_info' => ['render' => 'contract']]
);
$restrictedFirstSave = dashboard_layout_preserve_unavailable(
    ['cms_info:l'],
    $canonicalLayout,
    $restrictedDefinitions,
    false
);
$validEmptyLayout = dashboard_layout_validate([]) === [];
$uppercaseExtensionLayout = dashboard_layout_validate(['Plugin.Widget:r']) === ['Plugin.Widget:r'];
$restrictedSubmission = dashboard_layout_restrict_submitted(
    ['cms_info:l', 'recent_activity:r', 'recent_login:l', 'Plugin.Widget:r'],
    [
        'cms_info' => ['render' => 'contract'],
        'recent_login' => ['render' => 'contract', 'protected' => true],
        'Plugin.Widget' => ['render' => 'contract'],
    ]
);
$duplicateRejected = false;
try {
    dashboard_layout_validate(['recent_login:l', 'recent_login:r']);
} catch (InvalidArgumentException $exception) {
    $duplicateRejected = $exception->getMessage() === 'Duplicate dashboard widget';
}
$baseLayout = [];
for ($index = 0; $index < 99; $index++) $baseLayout[] = 'contract_' . $index . ':l';
$mergedAtLimit = dashboard_layout_preserve_unavailable(
    array_slice($baseLayout, 0, 98),
    [],
    $unavailableWidgets,
    false
);
$serverOverflowRejected = false;
try {
    dashboard_layout_validate(dashboard_layout_preserve_unavailable(
        $baseLayout,
        [],
        $unavailableWidgets,
        false
    ));
} catch (InvalidArgumentException $exception) {
    $serverOverflowRejected = $exception->getMessage() === 'Too many dashboard widgets';
}
$layoutBehaviorOk = $preservedLayout === [
        'recent_activity:r',
        'recent_posts:l',
        'recent_login:l',
        'system_info:r',
    ]
    && $defaultLayout === ['recent_activity:l', 'recent_login:r']
    && $emptyCustomizedLayout === []
    && $validEmptyLayout
    && $uppercaseExtensionLayout
    && $restrictedSubmission === ['cms_info:l', 'Plugin.Widget:r']
    && $duplicateRejected
    && $genericPreservedLayout === ['cms_info:l', 'plugin.owner-report:r', 'recent_login:r']
    && $legacyPreservedLayout === ['cms_info:l', 'plugin.owner-report:r']
    && $restrictedFirstSave === $canonicalLayout
    && count($mergedAtLimit) === 100
    && $serverOverflowRejected;
$checkLabel = 'unavailable widgets retain stored values and neighboring anchors without overriding an empty custom layout';
echo ($layoutBehaviorOk ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
if (!$layoutBehaviorOk) $failures[] = $checkLabel;

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    if (!defined('DASH_PATH')) define('DASH_PATH', $root . '/dashboard');
    if (!defined('ADMIN_BASE_PATH')) define('ADMIN_BASE_PATH', '/dashboard');
    if (!function_exists('__')) {
        function __(string $value): string { return $GLOBALS['widget_translations'][$value] ?? $value; }
    }
    if (!function_exists('h')) {
        function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('svg_ico')) {
        function svg_ico(string $name): string { return '<svg data-icon="' . h($name) . '"></svg>'; }
    }
    if (!function_exists('session_correlation_id')) {
        function session_correlation_id(bool $create = false): ?string { return hash('sha256', 'current-widget-session'); }
    }
    if (!function_exists('user_can')) {
        function user_can(PDO $pdo, int $userId, string $permission, array $context = []): bool
        {
            $GLOBALS['widget_permission_calls'][] = [$userId, $permission, $context];
            return ($GLOBALS['widget_permissions'][$permission] ?? false) === true;
        }
    }
    if (!function_exists('authorization_actor')) {
        function authorization_actor(PDO $pdo, ?int $userId = null): ?array
        {
            return $GLOBALS['widget_actor'] ?? null;
        }
    }
    if (!function_exists('auth_sessions_for_user')) {
        function auth_sessions_for_user(PDO $pdo, int $userId, ?string $currentHash = null, int $limit = 30): array
        {
            if (($GLOBALS['widget_session_error'] ?? false) === true) throw new RuntimeException('contract failure');
            $GLOBALS['widget_session_query'] = [$userId, $currentHash, $limit];
            return $GLOBALS['widget_sessions'] ?? [];
        }
    }
    if (!function_exists('auth_sessions_recent')) {
        function auth_sessions_recent(PDO $pdo, ?string $currentHash = null, int $limit = 30): array
        {
            if (($GLOBALS['widget_session_error'] ?? false) === true) throw new RuntimeException('contract failure');
            $GLOBALS['widget_recent_session_query'] = [$currentHash, $limit];
            return $GLOBALS['widget_recent_sessions'] ?? [];
        }
    }
    if (!function_exists('auth_sessions_device_label')) {
        function auth_sessions_device_label(?string $userAgent): string { return 'Contract Browser / Contract OS'; }
    }
    if (!function_exists('app_utc_mysql_to_site')) {
        function app_utc_mysql_to_site(?string $value): ?DateTimeImmutable
        {
            return $value ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
        }
    }
    if (!function_exists('app_display_datetime')) {
        function app_display_datetime(DateTimeInterface $value): string { return $value->format('Y-m-d H:i'); }
    }

    require_once $root . '/dashboard/theme/adiwira/part/views/widgets.php';
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE audit_events (
        id INTEGER PRIMARY KEY,
        actor_label TEXT NOT NULL,
        event_key TEXT NOT NULL,
        resource_type TEXT NOT NULL,
        resource_id TEXT NOT NULL,
        resource_label TEXT,
        occurred_at_utc TEXT NOT NULL
    )');
    $pdo->exec("INSERT INTO audit_events
        (id, actor_label, event_key, resource_type, resource_id, resource_label, occurred_at_utc)
        VALUES (1, 'Owner <script>', 'article.updated', 'article', '9', 'Contract <article>', '2026-10-21 04:00:00.000000')");
    $_SESSION['user_id'] = 7;
    $GLOBALS['widget_permissions'] = [];
    $GLOBALS['widget_actor'] = [
        'id' => 7,
        'name' => 'Contract Owner',
        'username' => 'contract-owner',
        'is_site_owner' => false,
    ];
    $GLOBALS['widget_sessions'] = [[
        'user_agent' => 'raw-private-user-agent',
        'created_at_utc' => '2026-10-21 03:00:00.000000',
        'active' => true,
        'current' => true,
    ]];

    $loginDenied = dash_widget_recent_login($pdo) === '';
    $GLOBALS['widget_permissions']['core.sessions.manage'] = true;
    $loginHtml = dash_widget_recent_login($pdo);
    $loginAllowed = str_contains($loginHtml, 'Recent Login')
        && str_contains($loginHtml, 'Contract Owner')
        && str_contains($loginHtml, 'Current session')
        && str_contains($loginHtml, 'Contract Browser / Contract OS')
        && !str_contains($loginHtml, 'raw-private-user-agent')
        && ($GLOBALS['widget_session_query'][0] ?? null) === 7
        && ($GLOBALS['widget_session_query'][2] ?? null) === 5;
    $loginScopeBound = false;
    foreach ($GLOBALS['widget_permission_calls'] ?? [] as $permissionCall) {
        if (($permissionCall[1] ?? '') === 'core.sessions.manage'
            && ($permissionCall[2]['owner_id'] ?? null) === 7) $loginScopeBound = true;
    }
    $checkLabel = 'Recent Login renders only the current user bounded privacy-safe session summary';
    $ok = $loginDenied && $loginAllowed && $loginScopeBound;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
    if (!$ok) $failures[] = $checkLabel;

    $GLOBALS['widget_actor']['is_site_owner'] = true;
    $GLOBALS['widget_recent_sessions'] = [[
        'user_id' => 9,
        'user_label' => 'Adam & Owner',
        'device_label' => 'Contract Browser / Contract OS',
        'created_at_utc' => '2026-10-21 03:30:00.000000',
        'active' => true,
        'current' => false,
    ]];
    $ownerLoginHtml = dash_widget_recent_login($pdo);
    $ownerLoginSummary = str_contains($ownerLoginHtml, 'Adam &amp; Owner')
        && str_contains($ownerLoginHtml, 'user_id=9')
        && ($GLOBALS['widget_recent_session_query'][1] ?? null) === 5;
    $checkLabel = 'Recent Login gives Site Owners a bounded cross-user identity summary without raw private metadata';
    echo ($ownerLoginSummary ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
    if (!$ownerLoginSummary) $failures[] = $checkLabel;

    $GLOBALS['widget_translations'] = [
        'Recent Login' => '<script>login</script>',
        'Manage sessions' => '<script>sessions</script>',
    ];
    $translatedLoginHtml = dash_widget_recent_login($pdo);
    $GLOBALS['widget_translations'] = [];
    $translatedLoginSafe = !str_contains($translatedLoginHtml, '<script>')
        && str_contains($translatedLoginHtml, '&lt;script&gt;login&lt;/script&gt;')
        && str_contains($translatedLoginHtml, '&lt;script&gt;sessions&lt;/script&gt;');
    $checkLabel = 'Recent Login escapes filtered translation values';
    echo ($translatedLoginSafe ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
    if (!$translatedLoginSafe) $failures[] = $checkLabel;

    $GLOBALS['widget_session_error'] = true;
    $loginUnavailableHtml = dash_widget_recent_login($pdo);
    $GLOBALS['widget_session_error'] = false;
    $loginFailureSafe = str_contains($loginUnavailableHtml, 'Recent Login')
        && str_contains($loginUnavailableHtml, 'Data is temporarily unavailable.');
    $checkLabel = 'Recent Login remains present when its data source is temporarily unavailable';
    echo ($loginFailureSafe ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
    if (!$loginFailureSafe) $failures[] = $checkLabel;

    $GLOBALS['widget_permissions']['core.audit.read'] = true;
    $GLOBALS['widget_actor']['is_site_owner'] = false;
    $activityDenied = dash_widget_recent_activity($pdo) === '';
    $GLOBALS['widget_actor']['is_site_owner'] = true;
    $activityHtml = dash_widget_recent_activity($pdo);
    $activityAllowed = str_contains($activityHtml, 'Recent Activity')
        && str_contains($activityHtml, 'article.updated')
        && str_contains($activityHtml, 'Contract &lt;article&gt;')
        && str_contains($activityHtml, 'Owner &lt;script&gt;')
        && str_contains($activityHtml, 'admin/settings/audit&amp;tab=mutations')
        && !str_contains($activityHtml, '<script>');
    $checkLabel = 'Recent Activity is Site Owner-only and escapes its bounded audit projection';
    $ok = $activityDenied && $activityAllowed;
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
    if (!$ok) $failures[] = $checkLabel;

    $GLOBALS['widget_translations'] = [
        'Recent Activity' => '<script>activity</script>',
        'Audit Log' => '<script>audit</script>',
    ];
    $translatedActivityHtml = dash_widget_recent_activity($pdo);
    $GLOBALS['widget_translations'] = [];
    $translatedActivitySafe = !str_contains($translatedActivityHtml, '<script>')
        && str_contains($translatedActivityHtml, '&lt;script&gt;activity&lt;/script&gt;')
        && str_contains($translatedActivityHtml, '&lt;script&gt;audit&lt;/script&gt;');
    $checkLabel = 'Recent Activity escapes filtered translation values';
    echo ($translatedActivitySafe ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
    if (!$translatedActivitySafe) $failures[] = $checkLabel;

    $pdo->exec('DROP TABLE audit_events');
    $activityUnavailableHtml = dash_widget_recent_activity($pdo);
    $activityFailureSafe = str_contains($activityUnavailableHtml, 'Recent Activity')
        && str_contains($activityUnavailableHtml, 'Data is temporarily unavailable.');
    $checkLabel = 'Recent Activity remains present when its data source is temporarily unavailable';
    echo ($activityFailureSafe ? 'PASS' : 'FAIL') . ' ' . $checkLabel . PHP_EOL;
    if (!$activityFailureSafe) $failures[] = $checkLabel;
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " dashboard widget icon contract check(s) failed.\n");
    exit(1);
}

echo "Dashboard widget icon contract passed.\n";
