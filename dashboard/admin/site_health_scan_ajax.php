<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/_guard.php';
adiwira_cosmetic_404_on_direct_open();
adiwira_require_permission($pdo, 'core.settings.manage', true);
adiwira_require_site_owner($pdo, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!adiwira_csrf_validate($csrf)) {
    adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
}

session_write_close();
try {
    $report = site_health_run_and_store_if_stale(dirname(DASH_PATH), (string)PUBLIC_PATH);
    adiwira_json([
        'ok' => true,
        'status' => (string)$report['status'],
        'completed_at' => (int)$report['completed_at'],
    ]);
} catch (Throwable $error) {
    error_log('[site-health-auto] ' . $error->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Site Health scan could not be completed.')], 503);
}
