<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.themes.manage', true);
adiwira_require_site_owner($pdo, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
$folder = (string)($_GET['folder'] ?? '');
$file = (string)($_GET['file'] ?? '');
try {
    adiwira_json(['ok' => true, 'revisions' => theme_source_service($pdo)->revisions($folder, $file)]);
} catch (Throwable) {
    adiwira_json(['ok' => false, 'error' => __('Source revisions are unavailable.')], 400);
}
