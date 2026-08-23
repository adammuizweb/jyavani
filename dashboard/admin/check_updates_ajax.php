<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.updates.manage', true);
adiwira_require_site_owner($pdo, true);
require_once __DIR__ . '/../../app/controllers/UpdateStatusController.php';

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}

try {
    if ($method === 'POST') {
        $csrf = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!adiwira_csrf_validate($csrf)) {
            adiwira_json(['ok' => false, 'error' => __('CSRF invalid')], 419);
        }
        session_write_close();
        $snapshot = UpdateStatusController::checkAll($pdo);
    } else {
        $snapshot = UpdateStatusController::getSnapshot();
    }
    adiwira_json(UpdateStatusController::publicPayload($snapshot));
} catch (Throwable $error) {
    error_log('[update-status] ' . $error->getMessage());
    adiwira_json(['ok' => false, 'error' => __('Failed to check updates.')], 502);
}
