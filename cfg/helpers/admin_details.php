<?php
declare(strict_types=1);

/** Log extension failures without allowing one panel listener to break the dashboard layout. */
function admin_details_log_hook_errors(array $errors): void
{
    foreach ($errors as $error) {
        if (!is_array($error)) continue;
        error_log(sprintf(
            '[admin-details] %s priority %d listener %d: %s',
            (string)($error['hook'] ?? 'unknown'),
            (int)($error['priority'] ?? 0),
            (int)($error['listener'] ?? 0),
            (string)($error['message'] ?? 'Listener failed.')
        ));
    }
}

/**
 * Build the immutable Core portion of the schema-1 details panel context.
 * Filters may append extension-owned keys, but Core keys are always restored.
 */
function admin_details_context(PDO $pdo, string $requestedPage, int $userId = 0): array
{
    $page = trim($requestedPage, " \t\n\r\0\x0B/");
    if ($page === '') $page = 'home';
    $pageValid = preg_match('#^[a-z0-9_\-/]+$#', $page) === 1;
    if (!$pageValid) $page = '';

    $rawEntityId = $_GET['id'] ?? 0;
    $entityId = is_scalar($rawEntityId) ? max(0, (int)$rawEntityId) : 0;
    $pluginRoute = $pageValid && function_exists('plugin_resolve_route')
        ? plugin_resolve_route($page)
        : null;
    $isPluginPage = is_array($pluginRoute);
    $core = [
        'schema' => 1,
        'page' => $page,
        'page_valid' => $pageValid,
        'mode' => $page === 'admin/themes/edit' && $entityId > 0 ? 'theme_preview' : 'default',
        'entity_id' => $entityId,
        'user_id' => max(0, $userId),
        'request_method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'admin_base_path' => defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : '/dashboard',
        'is_plugin_page' => $isPluginPage,
        'plugin' => $isPluginPage && is_string($pluginRoute['plugin'] ?? null) ? $pluginRoute['plugin'] : '',
        'plugin_title' => $isPluginPage && is_string($pluginRoute['title'] ?? null) ? $pluginRoute['title'] : '',
    ];

    $result = apply_filters_isolated('admin_details_context', $core, 'is_array', $pdo);
    admin_details_log_hook_errors($result['errors']);
    $context = is_array($result['value']) ? $result['value'] : [];
    foreach ($core as $key => $value) $context[$key] = $value;
    return $context;
}

function admin_details_visible(PDO $pdo, array $context): bool
{
    $result = apply_filters_isolated('admin_details_visible', true, 'is_bool', $context, $pdo);
    admin_details_log_hook_errors($result['errors']);
    if (!is_bool($result['value'])) {
        error_log('[admin-details] admin_details_visible must return a boolean; keeping the panel visible.');
        return true;
    }
    return $result['value'];
}

function admin_details_filter_core_content(PDO $pdo, string $content, array $context): string
{
    $result = apply_filters_isolated('admin_details_core_content', $content, 'is_string', $context, $pdo);
    admin_details_log_hook_errors($result['errors']);
    if (!is_string($result['value'])) {
        error_log('[admin-details] admin_details_core_content must return a string; using Core content.');
        return $content;
    }
    return $result['value'];
}

function admin_details_run_action(string $hook, PDO $pdo, array $context): void
{
    $result = do_action_isolated_output($hook, $context, $pdo);
    admin_details_log_hook_errors($result['errors']);
    echo $result['output'];
}
