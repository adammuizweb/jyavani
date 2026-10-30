<?php
declare(strict_types=1);

if (!isset($GLOBALS['_hooks'])) {
    $GLOBALS['_hooks'] = ['actions' => [], 'filters' => []];
}

// Legacy hook aliases — canonical Jyavani hooks (jy_*) also notify listeners
// registered under their old WordPress-style names (wp_*), for backward
// compatibility with plugins written before the jy_ rebrand.
function _hook_legacy_aliases(string $name): array {
    static $map = [
        'jy_head'   => ['wp_head'],
        'jy_footer' => ['wp_footer'],
    ];
    return $map[$name] ?? [];
}

function _do_action_exact(string $name, mixed ...$args): void {
    $hooks = $GLOBALS['_hooks']['actions'][$name] ?? [];
    ksort($hooks);
    foreach ($hooks as $priorities) {
        foreach ($priorities as $cb) {
            call_user_func($cb, ...$args);
        }
    }
}

function add_action(string $name, callable $callback, int $priority = 10): void {
    $GLOBALS['_hooks']['actions'][$name][$priority][] = $callback;
}

function do_action(string $name, mixed ...$args): void {
    _do_action_exact($name, ...$args);
    foreach (_hook_legacy_aliases($name) as $legacy) {
        _do_action_exact($legacy, ...$args);
    }
}

function frontend_entity_viewed_notify(
    PDO $pdo,
    array $resource,
    string $kind,
    string $type,
    string $route,
    ?string $canonicalUrl,
    int $page = 1
): void {
    $id = (int)($resource['id'] ?? 0);
    $validEntity = ($kind === 'post' && in_array($type, ['article', 'page', 'theme'], true)
            && ($resource['type'] ?? null) === $type && ($resource['status'] ?? null) === 'published')
        || ($kind === 'category' && $type === 'category');
    if (!$validEntity || $id <= 0 || (int)($resource['is_deleted'] ?? 0) !== 0 || $page <= 0) return;

    if ($canonicalUrl !== null) {
        $canonicalUrl = trim($canonicalUrl);
        if ($canonicalUrl === '' || strlen($canonicalUrl) > 2048 || !str_starts_with($canonicalUrl, '/')
            || str_starts_with($canonicalUrl, '//') || str_contains($canonicalUrl, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $canonicalUrl) === 1) {
            $canonicalUrl = null;
        }
    }

    $requestMethod = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
    if (preg_match('/\A[A-Z]{1,10}\z/D', $requestMethod) !== 1) $requestMethod = 'GET';

    $entity = ['kind' => $kind, 'type' => $type, 'id' => $id];
    $context = [
        'schema' => 1,
        'route' => $route,
        'status' => 'published',
        'is_public' => true,
        'canonical_url' => $canonicalUrl,
        'page' => $page,
        'request_method' => $requestMethod,
    ];
    do_action('frontend_entity_viewed', $entity, $context, $pdo);
}

/** Run all action listeners independently and return structured listener errors. */
function do_action_isolated(string $name, mixed ...$args): array {
    $errors = [];
    foreach (array_merge([$name], _hook_legacy_aliases($name)) as $hookName) {
        $hooks = $GLOBALS['_hooks']['actions'][$hookName] ?? [];
        ksort($hooks);
        foreach ($hooks as $priority => $listeners) {
            foreach ($listeners as $index => $listener) {
                try {
                    call_user_func($listener, ...$args);
                } catch (Throwable $error) {
                    $errors[] = [
                        'hook' => $hookName,
                        'priority' => (int)$priority,
                        'listener' => (int)$index,
                        'exception' => get_class($error),
                        'message' => $error->getMessage(),
                        'code' => $error->getCode(),
                    ];
                }
            }
        }
    }
    return $errors;
}

/** Run action listeners independently and retain output only from successful listeners. */
function do_action_isolated_output(string $name, mixed ...$args): array {
    $errors = [];
    $output = '';
    foreach (array_merge([$name], _hook_legacy_aliases($name)) as $hookName) {
        $hooks = $GLOBALS['_hooks']['actions'][$hookName] ?? [];
        ksort($hooks);
        foreach ($hooks as $priority => $listeners) {
            foreach ($listeners as $index => $listener) {
                $level = ob_get_level();
                // The outer quarantine retains flushed listener output until success.
                ob_start();
                ob_start();
                try {
                    call_user_func($listener, ...$args);
                    while (ob_get_level() > $level + 1) ob_end_flush();
                    $output .= (string)ob_get_clean();
                } catch (Throwable $error) {
                    while (ob_get_level() > $level) ob_end_clean();
                    $errors[] = [
                        'hook' => $hookName,
                        'priority' => (int)$priority,
                        'listener' => (int)$index,
                        'exception' => get_class($error),
                        'message' => $error->getMessage(),
                        'code' => $error->getCode(),
                    ];
                }
            }
        }
    }
    return ['output' => $output, 'errors' => $errors];
}

function add_filter(string $name, callable $callback, int $priority = 10): void {
    $GLOBALS['_hooks']['filters'][$name][$priority][] = $callback;
}

function apply_filters(string $name, mixed $value, mixed ...$args): mixed {
    $hooks = $GLOBALS['_hooks']['filters'][$name] ?? [];
    ksort($hooks);
    foreach ($hooks as $priorities) {
        foreach ($priorities as $cb) {
            $value = call_user_func($cb, $value, ...$args);
        }
    }
    return $value;
}

/** Run every filter listener independently, retaining the last valid pipeline value. */
function apply_filters_isolated(string $name, mixed $value, callable $validator, mixed ...$args): array {
    $errors = [];
    $hooks = $GLOBALS['_hooks']['filters'][$name] ?? [];
    ksort($hooks);
    foreach ($hooks as $priority => $listeners) {
        foreach ($listeners as $index => $listener) {
            try {
                $candidate = call_user_func($listener, $value, ...$args);
                if (!$validator($candidate)) {
                    throw new UnexpectedValueException('Filter returned a value outside its contract.');
                }
                $value = $candidate;
            } catch (Throwable $error) {
                $errors[] = [
                    'hook' => $name,
                    'priority' => (int)$priority,
                    'listener' => (int)$index,
                    'exception' => get_class($error),
                    'message' => $error->getMessage(),
                    'code' => $error->getCode(),
                ];
            }
        }
    }
    return ['value' => $value, 'errors' => $errors];
}

function remove_action(string $name, callable $callback, int $priority = 10): void {
    $hooks = &$GLOBALS['_hooks']['actions'][$name][$priority] ?? [];
    if (!is_array($hooks)) return;
    $hooks = array_values(array_filter($hooks, fn($cb) => $cb !== $callback));
}

function remove_filter(string $name, callable $callback, int $priority = 10): void {
    $hooks = &$GLOBALS['_hooks']['filters'][$name][$priority] ?? [];
    if (!is_array($hooks)) return;
    $hooks = array_values(array_filter($hooks, fn($cb) => $cb !== $callback));
}

function has_action(string $name, ?callable $callback = null): bool {
    $hooks = $GLOBALS['_hooks']['actions'][$name] ?? [];
    if ($callback === null) return !empty($hooks);
    foreach ($hooks as $priorities) {
        if (in_array($callback, $priorities, true)) return true;
    }
    return false;
}

function has_filter(string $name, ?callable $callback = null): bool {
    $hooks = $GLOBALS['_hooks']['filters'][$name] ?? [];
    if ($callback === null) return !empty($hooks);
    foreach ($hooks as $priorities) {
        if (in_array($callback, $priorities, true)) return true;
    }
    return false;
}

/** Build the root service worker. Core lifecycle code remains when no plugin contributes handlers. */
function core_service_worker_script(): string {
    $core = <<<'JS'
"use strict";
self.addEventListener("install", function (event) {
    event.waitUntil(self.skipWaiting());
});
self.addEventListener("activate", function (event) {
    event.waitUntil(self.clients.claim());
});
JS;
    $contribution = apply_filters('service_worker_script', '');
    if (!is_string($contribution) || trim($contribution) === '') return $core . "\n";
    return $core . "\n" . trim($contribution) . "\n";
}
