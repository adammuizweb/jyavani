<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'core' => (string)file_get_contents($root . '/dashboard/admin/update/index.php'),
    'plugin' => (string)file_get_contents($root . '/dashboard/admin/plugins/index.php'),
    'theme' => (string)file_get_contents($root . '/dashboard/admin/themes/assign.php'),
    'script' => (string)file_get_contents($root . '/public/static/dashboard/js/update.js'),
    'style' => (string)file_get_contents($root . '/public/static/dashboard/css/update.css'),
    'translations' => (string)file_get_contents($root . '/schema/translations.sql'),
];

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

foreach (['core', 'plugin', 'theme'] as $type) {
    $source = $sources[$type];
    $check(str_contains($source, 'class="update-process-overlay"')
        && str_contains($source, 'role="dialog"')
        && str_contains($source, 'aria-modal="true"')
        && str_contains($source, 'data-update-process-cancel disabled'), $type . ' renders an accessible blocking update process modal with initially disabled cancellation');
    $check(str_contains($source, '/admin/update/process.php?token='), $type . ' polls the centralized process endpoint');
    $check(str_contains($source, "'Do not close or leave this page while the update is running.'"), $type . ' warns the user not to leave the active update');
}

$check(substr_count($sources['script'], 'window.crypto.getRandomValues') >= 1
    && str_contains($sources['plugin'], 'window.crypto.getRandomValues')
    && str_contains($sources['theme'], 'window.crypto.getRandomValues')
    && !str_contains($sources['script'], 'Math.random')
    && !str_contains($sources['plugin'], 'Math.random'), 'Core, plugin, and theme updates use cryptographically random 32-hex tokens');
$check(str_contains($sources['script'], "window.addEventListener('beforeunload', beforeUnload)")
    && str_contains($sources['script'], "window.removeEventListener('beforeunload', beforeUnload)"), 'active updates install and terminal states remove beforeunload protection');
$check(str_contains($sources['script'], 'element.inert = true')
    && str_contains($sources['script'], "event.key === 'Escape'")
    && str_contains($sources['script'], "document.addEventListener('focusin', keepFocusInside, true)")
    && str_contains($sources['script'], 'document.activeElement === panel'), 'the shared modal makes background content inert and traps keyboard focus without Escape dismissal');
$check(str_contains($sources['script'], "body.append('action', 'cancel')")
    && str_contains($sources['script'], "body.append('csrf_token', options.csrfToken)")
    && str_contains($sources['script'], 'state.cancel_allowed === true')
    && str_contains($sources['script'], "text('finishing', 'Finishing process...')"), 'cancellation follows authoritative process state and posts CSRF credentials');
$check(str_contains($sources['script'], "['completed', 'failed', 'cancelled']")
    && str_contains($sources['script'], 'actions.replaceChildren()')
    && str_contains($sources['script'], "done.addEventListener('click'")
    && str_contains($sources['core'], "'done' => __('Done')")
    && str_contains($sources['plugin'], "'done' => __('Reload')")
    && str_contains($sources['theme'], "'done' => __('Reload')"), 'terminal outcomes remain in place and expose only a user-driven Done or Reload action');
$check(!preg_match('/setTimeout\s*\([^;]{0,300}(?:location\.(?:reload|assign)|location\.href|alert\s*\()/s', $sources['script'])
    && !preg_match('/setTimeout\s*\([^;]{0,300}(?:location\.(?:reload|assign)|location\.href|alert\s*\()/s', $sources['plugin'])
    && !preg_match('/setTimeout\s*\([^;]{0,300}(?:location\.(?:reload|assign)|location\.href|alert\s*\()/s', substr($sources['theme'], strpos($sources['theme'], '// Theme update preflight and progress') ?: 0)), 'update terminal handling has no automatic reload, redirect, or alert');
$check(str_contains($sources['script'], 'currentGeneration !== generation')
    && str_contains($sources['script'], 'new AbortController()')
    && str_contains($sources['script'], 'schedulePoll(currentGeneration)')
    && str_contains($sources['script'], 'Waiting for a confirmed result.'), 'polling is serialized, stale responses are ignored, and transient transport/watchdog errors keep the UI locked');
$check(str_contains($sources['script'], '!data.cancelled')
    && str_contains($sources['plugin'], '!data.cancelled')
    && str_contains($sources['theme'], '!data.cancelled'), 'a successful cooperative cancellation cannot race into a failed UI state');
$check(str_contains($sources['script'], 'state.found === false')
    && str_contains($sources['script'], 'missingAfterDispatch >= 3')
    && str_contains($sources['script'], 'dispatchFailed: function'), 'apply errors wait for authoritative operation polling before unlocking the UI');
$check(str_contains($sources['theme'], 'theme_update_preflight_required')
    && str_contains($sources['theme'], 'updateProcess.dismissTerminal()')
    && str_contains($sources['theme'], 'showPreflightModal(folderName, data.issues)'), 'apply-time Theme preflight changes reopen bounded choices only after the operation terminates');
$check(!str_contains($sources['theme'], 'updateProcess.notice(')
    && substr_count($sources['theme'], 'updateProcess.dispatchFailed(') >= 3, 'Theme apply transport and response failures eventually release the modal when no operation was created');
$check(!str_contains($sources['plugin'], '_pluginUpdateProcess.notice(')
    && str_contains($sources['plugin'], '_pluginUpdateProcess.dispatchFailed(')
    && !str_contains($sources['script'], '_cmsProcess.notice(')
    && str_contains($sources['script'], '_cmsProcess.dispatchFailed('), 'Core and Plugin apply transport failures eventually release the modal when no operation was created');
$check(str_contains($sources['style'], '.update-process-overlay')
    && str_contains($sources['style'], '@media (max-width: 480px)')
    && str_contains($sources['style'], '.update-process-overlay.is-failed'), 'shared updater styling is full-screen, responsive, and outcome-aware');
$check(str_contains($sources['plugin'], "if (actions) actions.style.display = 'none';")
    && str_contains($sources['plugin'], "if (_confirmAction === 'update')"), 'non-update plugin operations retain the blocking overlay without update cancellation controls');

foreach (['Cancel update', 'Cancelling...', 'Finishing process...', 'Do not close or leave this page while the update is running.',
    'CMS update complete', 'CMS update failed', 'CMS update cancelled', 'Plugin update complete', 'Plugin update failed',
    'Plugin update cancelled', 'Theme update complete', 'Theme update failed', 'Theme update cancelled',
    'The update server returned an invalid response.', 'The update request failed.',
    'Unable to request cancellation. The update is still running.',
    'The update is taking longer than expected. Waiting for a confirmed result.'] as $key) {
    $quoted = preg_quote("('default', '" . str_replace("'", "''", $key) . "'", '/');
    $check(preg_match_all('/' . $quoted . '/', $sources['translations']) === 2, $key . ' has Indonesian and German translation seeds');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " updater UI contract check(s) failed.\n");
    exit(1);
}
echo "Updater UI contract passed ({$checks} checks).\n";
