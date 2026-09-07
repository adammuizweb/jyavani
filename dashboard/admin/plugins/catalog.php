<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
adiwira_cosmetic_404_on_direct_open();
adiwira_require_permission($pdo, 'core.plugins.manage', true);
adiwira_require_site_owner($pdo, true);
require_once __DIR__ . '/../../../app/controllers/PluginStoreController.php';
require_once __DIR__ . '/_store_card.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    adiwira_json(['ok' => false, 'error' => __('Method not allowed')], 405);
}
$queryRaw = $_GET['q'] ?? '';
$cursorRaw = $_GET['cursor'] ?? '';
if (!is_string($queryRaw) || !is_string($cursorRaw)) {
    adiwira_json(['ok' => false, 'error' => __('Invalid catalog request.')], 400);
}
$query = trim($queryRaw);
$cursor = trim($cursorRaw);
$installed = plugins_all();
$csrf = csrf_token();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$page = PluginStoreController::fetchCatalogPage($query, $cursor, 24);
if ($page === null) {
    adiwira_json(['ok' => false, 'error' => __('Failed to load plugin list.')], 502);
}
$html = '';
foreach ($page['plugins'] as $plugin) {
    $html .= plugin_store_card_html($plugin, $installed, ADMIN_BASE_PATH, $csrf);
}
adiwira_json([
    'ok' => true,
    'html' => $html,
    'returned' => count($page['plugins']),
    'has_more' => $page['pagination']['has_more'],
    'next_cursor' => $page['pagination']['next_cursor'],
]);
