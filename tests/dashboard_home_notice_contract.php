<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', $root . '/public');
if (!function_exists('__')) {
    function __(string $key, mixed ...$values): string
    {
        return $values === [] ? $key : vsprintf($key, $values);
    }
}
require_once $root . '/cfg/helpers/hooks.php';
require_once $root . '/cfg/helpers/null_helpers.php';
require_once $root . '/cfg/helpers/dashboard_home_notices.php';

final class DashboardHomeNoticeContractPdo extends PDO
{
    public function __construct() {}
}

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$condition) $failures[] = $label;
};
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$notices = dashboard_home_notices_normalize([
    [
        'id' => 'example.setup',
        'revision' => '2',
        'type' => 'info',
        'icon' => 'shield-check',
        'title' => '<Setup>',
        'message' => "Finish <script>alert(1)</script>\nwhen ready.",
        'action' => ['label' => 'Open settings', 'url' => '/dashboard/?page=plugin/example/settings'],
    ],
    ['id' => 'example.setup', 'message' => 'Duplicate must be ignored.'],
    [
        'id' => 'example.required',
        'type' => 'warning',
        'message' => 'Required notice.',
        'dismissible' => false,
        'action' => ['label' => 'Unsafe', 'url' => 'javascript:alert(1)'],
    ],
    [
        'id' => 'example.docs',
        'type' => 'success',
        'message' => 'Documentation is ready.',
        'action' => ['label' => 'Read docs', 'url' => 'https://example.com/docs'],
    ],
    ['id' => '../invalid', 'message' => 'Invalid identity.'],
]);

$check(count($notices) === 3, 'notice declarations require unique bounded identities');
$check($notices[0]['action']['external'] === false && $notices[2]['action']['external'] === true,
    'notice actions accept safe local and HTTPS destinations');
$check($notices[1]['action'] === null, 'unsafe notice actions are discarded without hiding the message');
$check($notices[0]['icon'] === 'shield-check', 'notices accept only bounded Core icons');
$check(dashboard_home_notice_action_url('/dashboard/%2e%2e/private') === null,
    'notice action validation rejects encoded traversal');

$html = dashboard_home_notices_render($notices, ['user_id' => 42]);
$check(str_contains($html, 'data-user-id="42"') && str_contains($html, 'data-notice-revision="2"'),
    'rendered notices expose only bounded dismissal identities');
$check(str_contains($html, '&lt;Setup&gt;') && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;')
    && !str_contains($html, '<script>'), 'notice titles and messages are escaped');
$check(substr_count($html, 'class="dw-notice-dismiss"') === 2,
    'only dismissible notices render a close control');
$check(str_contains($html, 'target="_blank" rel="noopener noreferrer"'),
    'external notice actions use an isolated browsing context');

$many = [];
for ($i = 0; $i < 20; $i++) $many[] = ['id' => 'example.notice-' . $i, 'message' => 'Notice'];
$check(count(dashboard_home_notices_normalize($many)) === 12, 'dashboard home renders at most twelve notices');

add_filter('dashboard_home_notices', static fn(array $items): array => [], 5);
add_filter('dashboard_home_notices', static function (array $items): array {
    $items[] = ['id' => 'plugin.example', 'message' => 'Plugin notice'];
    return $items;
}, 10);
add_filter('dashboard_home_notices', static function (): array {
    throw new RuntimeException('Expected isolated notice listener failure.');
}, 20);
$collected = dashboard_home_notices_collect(
    new DashboardHomeNoticeContractPdo(),
    ['user_id' => 42],
    [['id' => 'core.required', 'message' => 'Core notice', 'dismissible' => false]]
);
$check(array_column($collected, 'id') === ['core.required', 'plugin.example'],
    'throwing listeners are isolated and filters cannot remove Core notices');

$home = $read('dashboard/theme/adiwira/part/views/home.php');
$renderPosition = strpos($home, 'dashboard_home_notices_render($homeNotices, $homeNoticeContext)');
$gridPosition = strpos($home, '<div class="dw-grid" id="dw-grid">');
$check(str_contains($home, "dashboard_home_notices_collect(\$pdo, \$homeNoticeContext, \$coreHomeNotices)")
    && $renderPosition !== false && $gridPosition !== false && $renderPosition < $gridPosition,
    'dashboard home collects notices and renders them between its heading and widget grid');
$check(str_contains($home, "'id' => 'core.site-health-reminder'")
    && str_contains($home, "'revision' => '2'")
    && str_contains($home, "'icon' => 'shield-check'")
    && str_contains($home, "'url' => \$base . '/?page=admin/settings/health'")
    && str_contains($home, 'if ($siteHealthRequired) {'),
    'Core provides a dismissible Site Health reminder only to authorized Site Owners');

$translations = $read('schema/translations.sql');
$siteHealthNoticeStrings = [
    'Keep an eye on Site Health',
    'Review Site Health regularly to spot unexpected file changes and other security signals.',
    'Open Site Health',
];
$siteHealthNoticeTranslated = true;
foreach ($siteHealthNoticeStrings as $source) {
    if (substr_count($translations, "'" . $source . "'") < 2) $siteHealthNoticeTranslated = false;
}
$check($siteHealthNoticeTranslated, 'the Core Site Health notice has Indonesian and German translations');
$check(str_contains($home, "dashboard-widgets.js?v=<?=\$dashboardWidgetsVersion?>"),
    'dashboard home cache-busts its notice and widget behavior script');

$dashboardIndex = $read('dashboard/index.php');
$check(str_contains($dashboardIndex, "Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0")
    && str_contains($dashboardIndex, "header('Pragma: no-cache')"),
    'authenticated dashboard documents cannot be restored from a stale HTTP cache');

$helper = $read('cfg/helpers/dashboard_home_notices.php');
$check(str_contains($helper, "apply_filters_isolated(\n        'dashboard_home_notices'")
    && str_contains($helper, 'array_merge($coreNotices, $filtered)'),
    'notice listeners are isolated and cannot remove Core notices');

$script = $read('public/static/dashboard/js/dashboard-widgets.js');
$noticeInit = strpos($script, "var notices   = document.getElementById('dw-notices');");
$widgetReturn = strpos($script, 'if (!grid || !toggle) return;');
$check($noticeInit !== false && $widgetReturn !== false && $noticeInit < $widgetReturn
    && str_contains($script, "'jyavani-dashboard-home-notice:' + basePath + ':' + noticeUser")
    && str_contains($script, "localStorage.setItem(noticeStorageKey(notice), '1')"),
    'notice dismissal works without layout permission and persists per user and revision');

$css = $read('public/static/dashboard/css/style.css');
$check(str_contains($css, '.dw-notice--success') && str_contains($css, '.dw-notice--warning')
    && str_contains($css, '.dw-notice--error') && str_contains($css, '.dw-notice-dismiss'),
    'dashboard notices provide themed status and close-control styles');
$check(str_contains($css, '.dw-notice--info{ --dw-notice-color:#2563eb;')
    && str_contains($css, 'linear-gradient(115deg,')
    && str_contains($css, '.dw-notice-action:focus-visible'),
    'info notices use a dedicated blue layered treatment with a pill action');

$agents = $read('AGENTS.md');
$readme = $read('README.md');
$check(str_contains($agents, "dashboard_home_notices") && str_contains($readme, "dashboard_home_notices"),
    'the dashboard home notice extension contract is documented');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " dashboard home notice contract check(s) failed.\n");
    exit(1);
}
echo "Dashboard home notice contract passed.\n";
