<?php
declare(strict_types=1);
ob_start();
require_once __DIR__ . '/_guard.php';
require_once dirname(__DIR__, 2) . '/cfg/helpers/dashboard_layout.php';
require_once dirname(__DIR__) . '/theme/adiwira/part/views/widgets.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.dashboard.layout.manage', true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    adiwira_json(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!csrf_check($csrf)) {
    adiwira_json(['ok' => false, 'error' => 'Invalid CSRF token'], 400);
}

$raw = (string)($_POST['layout'] ?? '');
$decoded = json_decode($raw, true);
$layoutRevision = trim((string)($_POST['layout_revision'] ?? ''));

try {
    $decoded = dashboard_layout_validate($decoded);
} catch (InvalidArgumentException $exception) {
    adiwira_json(['ok' => false, 'error' => $exception->getMessage()], 400);
}
if (preg_match('/\A[a-f0-9]{64}\z/D', $layoutRevision) !== 1) {
    adiwira_json(['ok' => false, 'error' => 'Invalid layout revision'], 400);
}

$preserveUnavailable = [];
$startedTransaction = false;
try {
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Dashboard layout endpoint cannot join an existing transaction');
    }
    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Could not start dashboard layout transaction');
    }
    $startedTransaction = true;

    $actor = function_exists('authorization_actor') ? authorization_actor($pdo, $uid) : null;
    if ($actor === null || !function_exists('user_can')
        || !user_can($pdo, $uid, 'core.dashboard.layout.manage')) {
        $pdo->rollBack();
        adiwira_json(['ok' => false, 'error' => __('Not found')], 404);
    }
    $actorWidgets = dashboard_widgets_registry($pdo, $actor);

    $seedStmt = $pdo->prepare(
        'INSERT IGNORE INTO settings (`key`, `value`, `autoload`) VALUES (:key, :value, 1)'
    );
    $seedStmt->execute([':key' => 'dashboard_widget_layout', ':value' => '']);
    $lockSql = 'SELECT `value` FROM settings WHERE `key` = :key';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $lockSql .= ' FOR UPDATE';
    $lockStmt = $pdo->prepare($lockSql);
    $lockStmt->execute([':key' => 'dashboard_widget_layout']);
    $existingRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
    $existingRaw = is_array($existingRow) && is_string($existingRow['value'] ?? null)
        ? $existingRow['value']
        : '';
    if (!hash_equals($layoutRevision, hash('sha256', $existingRaw))) {
        $pdo->rollBack();
        adiwira_json(['ok' => false, 'error' => 'Dashboard layout changed; reload and try again'], 409);
    }

    $existing = $existingRaw === '' ? [] : json_decode($existingRaw, true);
    $hasCustomizedLayout = $existingRaw !== '' && is_array($existing);
    if (!is_array($existing)) $existing = [];
    $existing = dashboard_layout_normalize_legacy($existing);
    $preservationBaseline = $hasCustomizedLayout ? $existing : dashboard_widgets_default_layout();
    $preserveUnavailable = dashboard_layout_preservation_definitions(
        $preservationBaseline,
        $actorWidgets,
        $preserveUnavailable
    );

    $decoded = dashboard_layout_restrict_submitted($decoded, $actorWidgets);

    if ($preserveUnavailable !== []) {
        // Submitted entries for hidden widgets are untrusted; merge their prior
        // values back relative to surviving neighboring widgets.
        $decoded = dashboard_layout_preserve_unavailable(
            $decoded,
            $preservationBaseline,
            $preserveUnavailable,
            $hasCustomizedLayout
        );
    }
    if ($actor !== null && $actor['is_site_owner'] === true
        && function_exists('current_user_can') && current_user_can($pdo, 'core.settings.manage')) {
        $layoutKeys = array_map(static fn(string $item): string => explode(':', $item, 2)[0], $decoded);
        if (!in_array('site_health', $layoutKeys, true)) $decoded[] = 'site_health:r';
    }

    // Server-restored and required widgets count toward the same persisted cap.
    $decoded = dashboard_layout_validate($decoded);
    $encoded = json_encode($decoded);
    if (!is_string($encoded)
        || !settings_set($pdo, 'dashboard_widget_layout', $encoded, 1)
        || !settings_set($pdo, 'dashboard_widget_layout_version', '2', 1)) {
        throw new RuntimeException('Failed to save dashboard layout');
    }
    $pdo->commit();
} catch (InvalidArgumentException $exception) {
    if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
    adiwira_json(['ok' => false, 'error' => $exception->getMessage()], 400);
} catch (Throwable $error) {
    if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[dashboard-layout] save failed: ' . $error->getMessage());
    adiwira_json(['ok' => false, 'error' => 'Failed to save layout'], 500);
}

session_write_close();
adiwira_json(['ok' => true]);
