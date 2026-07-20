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
