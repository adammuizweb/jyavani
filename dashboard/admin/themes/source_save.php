<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_notify.php';
adiwira_cosmetic_404_on_direct_open();
[$uid] = adiwira_require_permission($pdo, 'core.themes.manage', false);
adiwira_require_site_owner($pdo, false);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') adiwira_render_404();
if (!adiwira_csrf_validate((string)($_POST['csrf_token'] ?? ''))) adiwira_render_404();

$folder = (string)($_POST['folder'] ?? '');
$fileId = (string)($_POST['file'] ?? '');
$return = ADMIN_BASE_PATH . '/?' . http_build_query(['page' => 'admin/themes/source', 'folder' => $folder, 'file' => $fileId]);
try {
    $service = theme_source_service($pdo);
    $state = $service->editState($folder, ['actor_id' => (int)$uid, 'operation' => 'save']);
    if (empty($state['allowed'])) throw new RuntimeException((string)$state['message']);
    if (empty($_POST['ack_direct'])) throw new RuntimeException(__('Direct PHP editing requires explicit risk acknowledgement.'));
    if ((!empty($state['active']) || !empty($state['assigned'])) && empty($_POST['ack_live'])) throw new RuntimeException(__('Live theme editing requires explicit risk acknowledgement.'));
    if (!empty($state['store']) && empty($_POST['ack_store'])) throw new RuntimeException(__('Store-managed theme editing requires explicit risk acknowledgement.'));
    $result = $service->savePhp($folder, $fileId, (string)($_POST['expected_hash'] ?? ''), (string)($_POST['target_token'] ?? ''),
        (string)($_POST['source'] ?? ''), (int)$uid, [
            'direct' => !empty($_POST['ack_direct']),
            'live' => !empty($_POST['ack_live']),
            'store' => !empty($_POST['ack_store']),
        ], (string)($_POST['note'] ?? ''));
    if (empty($result['success'])) {
        throw new RuntimeException(($result['code'] ?? '') === 'stale_source'
            ? __('Theme source changed. Reload the editor before saving.')
            : (string)($result['error'] ?? __('PHP source could not be saved.')));
    }
    adiwira_redirect_with_flash($return, 'success', !empty($result['unchanged']) ? __('PHP source was unchanged.') : __('PHP source saved and the previous bytes were preserved as a revision.'));
} catch (Throwable $error) {
    adiwira_redirect_with_flash($return, 'error', $error->getMessage());
}
