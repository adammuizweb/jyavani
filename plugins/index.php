<?php
declare(strict_types=1);
if (defined('PLUGIN_SYSTEM_LOADED')) return;
define('PLUGIN_SYSTEM_LOADED', true);
require_once dirname(__DIR__) . '/cfg/helpers/package_archive.php';
require_once dirname(__DIR__) . '/cfg/helpers/migration_helper.php';
// Plugin Registry — Jyavani CMS Plugin System v2.0
// Loaded after bootstrap in dashboard/index.php

if (!defined('PLUGIN_PATH')) define('PLUGIN_PATH', __DIR__);
if (!defined('PLUGIN_DISABLED_JSON')) {
    $configuredPluginState = trim((string)(getenv('PLUGIN_DISABLED_JSON') ?: ''));
    if ($configuredPluginState !== '' && !str_starts_with($configuredPluginState, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('PLUGIN_DISABLED_JSON must be an absolute path.');
    }
    define('PLUGIN_DISABLED_JSON', $configuredPluginState !== ''
        ? $configuredPluginState
        : BACKEND_PATH . '/var/plugins-disabled.json');
}

// --- Frontend Route Registry ---
$GLOBALS['_plugin_frontend_routes'] = [];
$GLOBALS['_plugin_frontend_route_definitions'] = [];
$GLOBALS['_plugin_frontend_route_order'] = 0;
$GLOBALS['_plugin_frontend_route_diagnostics'] = [];
$GLOBALS['_plugin_frontend_routes_sealed'] = false;
$GLOBALS['_plugin_requirement_diagnostics'] = [];
$GLOBALS['_plugin_last_error'] = '';
$GLOBALS['_plugin_active_cache'] = null;
$GLOBALS['_plugin_load_diagnostics'] = [];
$GLOBALS['_plugin_loader_ran'] = false;
$GLOBALS['_plugin_loaded_entrypoints'] = [];
$GLOBALS['_plugin_ready_permissions'] = [];
$GLOBALS['_plugin_permission_sync_errors'] = [];

function plugin_message(string $message, mixed ...$values): string {
    $translated = function_exists('__') ? __($message) : $message;
    return $values === [] ? $translated : sprintf($translated, ...$values);
}

function plugin_current_jyavani_version(): string {
    static $version = null;
    if ($version !== null) return $version;
    $file = dirname(__DIR__) . '/VERSION';
    $version = is_file($file) ? trim((string)file_get_contents($file)) : '0.0.0';
    return preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '0.0.0';
}

function plugin_parse_version_constraint(string $requirement): ?array {
    $requirement = trim($requirement);
    if ($requirement === '') return [];
    $groups = preg_split('/\s*\|\|\s*/', $requirement);
    if (!is_array($groups) || in_array('', $groups, true)) return null;
    $parsed = [];
    foreach ($groups as $group) {
        $clauses = preg_split('/\s*,\s*|\s+(?=(?:\^|~|>=|<=|>|<|==|=)?\s*\d)/', trim($group));
        if (!is_array($clauses) || $clauses === [] || in_array('', $clauses, true)) return null;
        $parsedGroup = [];
        foreach ($clauses as $clause) {
            if (preg_match('/\A(\^|~|>=|<=|>|<|==|=)?\s*(\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?)\z/', $clause, $match) !== 1) return null;
            $parsedGroup[] = [$match[1] ?? '', $match[2]];
        }
        $parsed[] = $parsedGroup;
    }
    return $parsed;
}

function plugin_version_requirement_met(string $current, string $requirement): bool {
    if (preg_match('/\A\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?\z/', $current) !== 1) return false;
    $groups = plugin_parse_version_constraint($requirement);
    if ($groups === null) return false;
    if ($groups === []) return true;
    foreach ($groups as $clauses) {
        $passed = true;
        foreach ($clauses as [$operator, $version]) {
            if ($operator === '^' || $operator === '~') {
                $numbers = array_map('intval', explode('.', preg_split('/[-+]/', $version, 2)[0]));
                $numbers = array_pad($numbers, 3, 0);
                if ($operator === '^') {
                    if ($numbers[0] > 0) $upper = ($numbers[0] + 1) . '.0.0';
                    elseif ($numbers[1] > 0) $upper = '0.' . ($numbers[1] + 1) . '.0';
                    else $upper = '0.0.' . ($numbers[2] + 1);
                } else {
                    $upper = $numbers[0] . '.' . ($numbers[1] + 1) . '.0';
                }
                if (!version_compare($current, $version, '>=') || !version_compare($current, $upper, '<')) $passed = false;
            } else {
                $comparison = $operator === '' || $operator === '=' ? '>=' : $operator;
                if (!version_compare($current, $version, $comparison)) $passed = false;
            }
            if (!$passed) break;
        }
        if ($passed) return true;
    }
    return false;
}

function plugin_canonical_version_requirement(string $requirement): ?string {
    $groups = plugin_parse_version_constraint($requirement);
    if ($groups === null) return null;
    if ($groups === []) return '';
    $canonical = [];
    foreach ($groups as $clauses) {
        $values = [];
        foreach ($clauses as [$operator, $version]) {
            $values[] = ($operator === '' || $operator === '=' ? '>=' : $operator) . $version;
        }
        $canonical[] = implode(' ', $values);
    }
    return implode(' || ', $canonical);
}

/** Normalize canonical and legacy top-level requirement metadata. */
function plugin_normalize_requirements(array $manifest): array {
    $errors = [];
    $value = $manifest['requires'] ?? [];
    $requires = is_array($value) ? $value : [];
    if (array_key_exists('requires', $manifest) && !is_array($value)) $errors[] = 'Invalid plugin requirements declaration';

    foreach (['jyavani_required' => 'jyavani', 'php_required' => 'php'] as $legacy => $canonical) {
        if (!array_key_exists($legacy, $manifest)) continue;
        $legacyValue = $manifest[$legacy];
        if (!is_string($legacyValue)) {
            $errors[] = 'Invalid ' . ($canonical === 'php' ? 'PHP' : 'Jyavani') . ' requirement';
            continue;
        }
        $legacyValue = trim($legacyValue);
        $canonicalValue = is_string($requires[$canonical] ?? null) ? plugin_canonical_version_requirement($requires[$canonical]) : null;
        $legacyCanonical = plugin_canonical_version_requirement($legacyValue);
        if (isset($requires[$canonical]) && ($canonicalValue === null || $legacyCanonical === null || $canonicalValue !== $legacyCanonical)) {
            $errors[] = 'Conflicting ' . ($canonical === 'php' ? 'PHP' : 'Jyavani') . ' requirements';
        } elseif (!isset($requires[$canonical]) && $legacyValue !== '') {
            $requires[$canonical] = $legacyValue;
        }
    }
    if (!array_key_exists('extensions', $requires) && array_key_exists('extensions_required', $manifest)) {
        $requires['extensions'] = $manifest['extensions_required'];
    }
    if (array_key_exists('plugins', $requires)) {
        $plugins = $requires['plugins'];
        if (!is_array($plugins) || ($plugins !== [] && array_is_list($plugins))) {
            $errors[] = plugin_message('Invalid plugin dependency declaration. Expected an object mapping plugin slugs to version constraints.');
        } else {
            foreach ($plugins as $slug => $constraint) {
                if (!is_string($slug) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $slug) !== 1) {
                    $errors[] = plugin_message('Invalid plugin dependency name: %s.', (string)$slug);
                } elseif (!is_string($constraint) || trim($constraint) === '' || plugin_canonical_version_requirement($constraint) === null) {
                    $errors[] = plugin_message('Invalid version constraint for required plugin "%s".', $slug);
                }
            }
        }
    }
    return ['requires' => $requires, 'errors' => array_values(array_unique($errors))];
}

/** Validate and normalize plugin-owned permissions and protected admin routes. */
function plugin_manifest_contract(array $manifest): array {
    $errors = [];
    $permissions = [];
    $routeRoles = [];
    $normalizeRoles = static function (mixed $roles): ?array {
        if (!is_array($roles) || !array_is_list($roles)) return null;
        $normalized = [];
        foreach ($roles as $role) {
            if (!is_string($role)) return null;
            $role = strtolower(trim($role));
            if (!in_array($role, ['author', 'editor', 'admin'], true)) return null;
            $normalized[$role] = true;
        }
        $normalized = array_keys($normalized);
        sort($normalized, SORT_STRING);
        return $normalized;
    };
    if (!array_key_exists('permissions', $manifest) && !array_key_exists('admin', $manifest)) {
        return ['permissions' => [], 'route_roles' => [], 'errors' => []];
    }
    $name = is_string($manifest['name'] ?? null) ? trim($manifest['name']) : '';
    if ($name === '' || strlen($name) > 100 || preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) {
        $errors[] = 'Invalid plugin name';
    }

    $declared = $manifest['permissions'] ?? [];
    if (!is_array($declared) || !array_is_list($declared)) {
        $errors[] = 'Invalid plugin permission declaration';
        $declared = [];
    }
    if ($declared !== [] && preg_match('/\A[a-z0-9_-]+\z/', $name) !== 1) {
        $errors[] = 'Plugins declaring permissions must use a lowercase name';
    }
    foreach ($declared as $entry) {
        if (!is_array($entry)) {
            $errors[] = 'Invalid plugin permission entry';
            continue;
        }
        $key = is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
        $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';
        $supportsScope = $entry['supports_scope'] ?? false;
        $isDelegable = $entry['delegable'] ?? true;
        $defaultRolesExplicit = array_key_exists('default_roles', $entry);
        $defaultRoles = $defaultRolesExplicit ? $normalizeRoles($entry['default_roles']) : [];
        $parts = explode('.', $key);
        $validSyntax = function_exists('authorization_permission_key_is_valid')
            ? authorization_permission_key_is_valid($key)
            : preg_match('/^[a-z0-9][a-z0-9._-]{2,190}$/', $key) === 1;
        $validKey = $key !== ''
            && $validSyntax
            && count($parts) >= 4
            && ($parts[0] ?? '') === 'plugin'
            && ($parts[1] ?? '') === $name;
        if (!$validKey) {
            $errors[] = 'Invalid or unowned plugin permission key: ' . ($key !== '' ? $key : '(empty)');
            continue;
        }
        if ($label === '' || strlen($label) > 191) {
            $errors[] = 'Invalid label for plugin permission: ' . $key;
            continue;
        }
        if (!is_bool($supportsScope)) {
            $errors[] = 'Invalid scope declaration for plugin permission: ' . $key;
            continue;
        }
        if (!is_bool($isDelegable)) {
            $errors[] = 'Invalid delegability declaration for plugin permission: ' . $key;
            continue;
        }
        if ($defaultRoles === null) {
            $errors[] = 'Invalid default roles for plugin permission: ' . $key;
            continue;
        }
        if (isset($permissions[$key])) {
            $errors[] = 'Duplicate plugin permission: ' . $key;
            continue;
        }
        $resource = (string)$parts[count($parts) - 2];
        $action = (string)$parts[count($parts) - 1];
        if (strlen($resource) > 100 || strlen($action) > 100) {
            $errors[] = 'Plugin permission resource or action is too long: ' . $key;
            continue;
        }
        $permissions[$key] = [
            'permission_key' => $key,
            'provider' => $name,
            'resource' => $resource,
            'action' => $action,
            'label' => $label,
            'supports_scope' => $supportsScope,
            'is_delegable' => $isDelegable,
            'default_roles' => $defaultRoles,
            '_default_roles_explicit' => $defaultRolesExplicit,
        ];
    }

    $pages = $manifest['admin']['pages'] ?? [];
    if (!is_array($pages) || !array_is_list($pages)) {
        $errors[] = 'Invalid plugin admin page declaration';
        $pages = [];
    }
    $seenRoutes = [];
    foreach ($pages as $page) {
        if (!is_array($page)) {
            $errors[] = 'Invalid plugin admin page';
            continue;
        }
        $route = is_string($page['route'] ?? null) ? trim($page['route'], '/') : '';
        $file = is_string($page['file'] ?? null) ? $page['file'] : '';
        if ($route === '' || preg_match('/\A[a-z0-9_-]+(?:\/[a-z0-9_-]+)*\z/', $route) !== 1) {
            $errors[] = 'Invalid plugin admin route: ' . ($route !== '' ? $route : '(empty)');
        } elseif (isset($seenRoutes[$route])) {
            $errors[] = 'Duplicate plugin admin route: ' . $route;
        }
        $seenRoutes[$route] = true;
        if ($file === '' || str_contains($file, "\0") || str_contains($file, '\\') || str_starts_with($file, '/')
            || in_array('', explode('/', $file), true) || in_array('.', explode('/', $file), true) || in_array('..', explode('/', $file), true)) {
            $errors[] = 'Invalid plugin admin file for route: ' . ($route !== '' ? $route : '(empty)');
        }
        if (array_key_exists('site_owner', $page) && !is_bool($page['site_owner'])) {
            $errors[] = 'Invalid Site Owner declaration for route: ' . $route;
        }
        $permission = is_string($page['permission'] ?? null) ? trim($page['permission']) : '';
        if ($permission === '') continue;
        if (($page['site_owner'] ?? false) === true) {
            $errors[] = 'A plugin route cannot combine Site Owner and permission guards: ' . $route;
            continue;
        }
        if (!isset($permissions[$permission])) {
            $errors[] = 'Unknown plugin route permission: ' . $permission;
            continue;
        }
        if ($permissions[$permission]['supports_scope'] === true) {
            $errors[] = 'Plugin route permissions cannot be scoped: ' . $permission;
            continue;
        }
        $roles = $normalizeRoles($page['roles'] ?? ['admin']);
        if ($roles === null) {
            $errors[] = 'Invalid compatibility roles for route: ' . $route;
            continue;
        }
        if (isset($routeRoles[$permission]) && $routeRoles[$permission] !== $roles) {
            $errors[] = 'Plugin routes sharing a permission must use identical compatibility roles: ' . $permission;
        } else {
            $routeRoles[$permission] = $roles;
        }
    }

    foreach ($permissions as $key => &$permission) {
        $roles = $routeRoles[$key] ?? null;
        if ($roles !== null) {
            if ($permission['_default_roles_explicit'] && $permission['default_roles'] !== $roles) {
                $errors[] = 'Plugin permission default roles conflict with route compatibility roles: ' . $key;
            } elseif (!$permission['_default_roles_explicit']) {
                $permission['default_roles'] = $roles;
            }
        }
        unset($permission['_default_roles_explicit']);
    }
    unset($permission);

    $nav = $manifest['admin']['nav'] ?? [];
    if (!is_array($nav) || !array_is_list($nav)) {
        $errors[] = 'Invalid plugin navigation declaration';
    } else {
        foreach ($nav as $item) {
            if (!is_array($item)) {
                $errors[] = 'Invalid plugin navigation item';
                continue;
            }
            if (array_key_exists('site_owner', $item) && !is_bool($item['site_owner'])) {
                $errors[] = 'Invalid Site Owner navigation declaration';
            }
        }
    }

    return [
        'permissions' => $permissions,
        'route_roles' => $routeRoles,
        'errors' => array_values(array_unique($errors)),
    ];
}

function plugin_manifest_contract_errors(array $manifest): array {
    return plugin_manifest_contract($manifest)['errors'];
}

/** Reject routes that would shadow Core or another installed plugin. */
function plugin_route_collision_errors(array $manifest, array $installed = []): array {
    $routes = [];
    foreach ($manifest['admin']['pages'] ?? [] as $page) {
        $route = is_array($page) && is_string($page['route'] ?? null) ? trim($page['route'], '/') : '';
        if ($route !== '' && preg_match('/\A[a-z0-9_-]+(?:\/[a-z0-9_-]+)*\z/', $route) === 1) $routes[$route] = true;
    }
    if ($routes === []) return [];

    $errors = [];
    $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : '';
    $dashboardRoot = defined('DASH_PATH') ? DASH_PATH : dirname(__DIR__) . '/dashboard';
    foreach (array_keys($routes) as $route) {
        if ($route === 'home' || is_file($dashboardRoot . '/' . $route . '.php')) {
            $errors[] = plugin_message('Plugin admin route "%s" conflicts with a Core dashboard route.', $route);
        }
    }

    foreach ($installed as $installedName => $otherManifest) {
        if (!is_array($otherManifest) || plugin_manifest_contract_errors($otherManifest) !== []) continue;
        $otherName = is_string($otherManifest['name'] ?? null) ? $otherManifest['name'] : (string)$installedName;
        if ($otherName === $name) continue;
        foreach ($otherManifest['admin']['pages'] ?? [] as $page) {
            $route = is_array($page) && is_string($page['route'] ?? null) ? trim($page['route'], '/') : '';
            if ($route !== '' && isset($routes[$route])) {
                $errors[] = plugin_message('Plugin admin route "%s" conflicts with plugin "%s".', $route, $otherName);
            }
        }
    }
    return array_values(array_unique($errors));
}

/** Store and package plugin dependency metadata must match exactly. */
function plugin_package_requirement_errors(array $catalog, array $package): array {
    $catalogMeta = plugin_normalize_requirements($catalog);
    $packageMeta = plugin_normalize_requirements($package);
    $errors = array_merge($catalogMeta['errors'], $packageMeta['errors']);
    foreach (['jyavani', 'php'] as $key) {
        $advertised = $catalogMeta['requires'][$key] ?? null;
        $declared = $packageMeta['requires'][$key] ?? null;
        if ($advertised !== null && $advertised !== '' && (!is_string($declared)
            || plugin_canonical_version_requirement((string)$advertised) !== plugin_canonical_version_requirement($declared))) {
            $errors[] = 'Package ' . $key . ' requirement does not match the store catalog';
        }
    }
    $catalogExtensions = $catalogMeta['requires']['extensions'] ?? [];
    $packageExtensions = $packageMeta['requires']['extensions'] ?? [];
    if (is_string($catalogExtensions)) $catalogExtensions = array_filter(array_map('trim', explode(',', $catalogExtensions)));
    if (is_string($packageExtensions)) $packageExtensions = array_filter(array_map('trim', explode(',', $packageExtensions)));
    if (is_array($catalogExtensions) && $catalogExtensions !== []) {
        $normalize = static function (array $extensions): array {
            $result = [];
            foreach ($extensions as $key => $value) $result[(string)(is_int($key) ? $value : $key)] = is_int($key) ? '' : (string)$value;
            ksort($result, SORT_STRING);
            return $result;
        };
        $advertised = $normalize($catalogExtensions);
        $declared = is_array($packageExtensions) ? $normalize($packageExtensions) : [];
        foreach ($advertised as $extension => $minimum) {
            if (!array_key_exists($extension, $declared) || $declared[$extension] !== $minimum) {
                $errors[] = 'Package extension requirements do not match the store catalog';
                break;
            }
        }
    }
    $catalogPlugins = is_array($catalogMeta['requires']['plugins'] ?? null) ? $catalogMeta['requires']['plugins'] : [];
    $packagePlugins = is_array($packageMeta['requires']['plugins'] ?? null) ? $packageMeta['requires']['plugins'] : [];
    foreach (array_unique(array_merge(array_keys($catalogPlugins), array_keys($packagePlugins))) as $slug) {
        if (!array_key_exists($slug, $catalogPlugins)) {
            $errors[] = plugin_message('Plugin dependency "%s" is declared by the package but missing from store metadata.', (string)$slug);
        } elseif (!array_key_exists($slug, $packagePlugins)) {
            $errors[] = plugin_message('Plugin dependency "%s" is declared by the store but missing from the package.', (string)$slug);
        } elseif (!is_string($catalogPlugins[$slug]) || !is_string($packagePlugins[$slug])
            || plugin_canonical_version_requirement($catalogPlugins[$slug]) !== plugin_canonical_version_requirement($packagePlugins[$slug])) {
            $errors[] = plugin_message('Plugin dependency "%s" has a package constraint that does not match store metadata.', (string)$slug);
        }
    }
    return array_values(array_unique($errors));
}

/** Return requirement checks in the same shape as plugin setup checks. */
function plugin_requirement_checks(array $manifest, bool $checkPluginState = true): array {
    $metadata = plugin_normalize_requirements($manifest);
    $requires = $metadata['requires'];
    $checks = [];
    foreach ($metadata['errors'] as $error) $checks[] = ['label' => $error, 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
    $jyavani = is_string($requires['jyavani'] ?? null) ? trim($requires['jyavani']) : '';
    if (array_key_exists('jyavani', $requires) && !is_string($requires['jyavani'])) {
        $checks[] = ['label' => 'Invalid Jyavani requirement', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
    }
    if ($jyavani !== '') {
        $checks[] = [
            'label' => 'Jyavani ' . $jyavani . ' (installed: ' . plugin_current_jyavani_version() . ')',
            'passed' => plugin_version_requirement_met(plugin_current_jyavani_version(), $jyavani),
            'command' => '', 'doc' => '', 'raw_output' => '',
        ];
    }
    $php = is_string($requires['php'] ?? null) ? trim($requires['php']) : '';
    if (array_key_exists('php', $requires) && !is_string($requires['php'])) {
        $checks[] = ['label' => 'Invalid PHP requirement', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
    }
    if ($php !== '') {
        $checks[] = [
            'label' => 'PHP ' . $php . ' (installed: ' . PHP_VERSION . ')',
            'passed' => plugin_version_requirement_met(PHP_VERSION, $php),
            'command' => '', 'doc' => '', 'raw_output' => '',
        ];
    }
    $extensions = $requires['extensions'] ?? [];
    if (is_string($extensions)) $extensions = array_filter(array_map('trim', explode(',', $extensions)));
    if (!is_array($extensions)) {
        $checks[] = ['label' => 'Invalid PHP extension requirements', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
        $extensions = [];
    }
    if (is_array($extensions)) {
        foreach ($extensions as $key => $value) {
            $extension = is_int($key) ? $value : $key;
            $minimum = is_int($key) ? '' : $value;
            if (!is_string($extension) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $extension) !== 1) {
                $checks[] = ['label' => 'Invalid PHP extension requirement', 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
                continue;
            }
            $loaded = extension_loaded($extension);
            $passed = $loaded;
            if ($loaded && is_string($minimum) && trim($minimum) !== '') {
                $extensionVersion = phpversion($extension);
                $passed = is_string($extensionVersion) && plugin_version_requirement_met($extensionVersion, $minimum);
            }
            $checks[] = [
                'label' => 'PHP extension: ' . $extension . (is_string($minimum) && $minimum !== '' ? ' ' . $minimum : ''),
                'passed' => $passed, 'command' => '', 'doc' => '', 'raw_output' => '',
            ];
        }
    }
    if ($checkPluginState && is_array($requires['plugins'] ?? null)) {
        $all = plugins_all();
        $disabled = plugin_disabled_names();
        foreach ($requires['plugins'] as $slug => $constraint) {
            if (!is_string($slug) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $slug) !== 1
                || !is_string($constraint) || plugin_canonical_version_requirement($constraint) === null) continue;
            $dependency = $all[$slug] ?? null;
            if (!is_array($dependency)) {
                $label = plugin_message('Required plugin "%s" is not installed.', $slug);
                $passed = false;
            } elseif (in_array($slug, $disabled, true)) {
                $label = plugin_message('Required plugin "%s" is inactive.', $slug);
                $passed = false;
            } else {
                $version = $dependency['version'] ?? null;
                $passed = is_string($version) && plugin_version_requirement_met($version, $constraint);
                $label = !is_string($version)
                    ? plugin_message('Required plugin "%s" has an invalid version.', $slug)
                    : plugin_message('Required plugin "%s" version %s does not satisfy %s.', $slug, $version, $constraint);
                if ($passed && !array_key_exists($slug, plugins_active())) {
                    $passed = false;
                    $label = plugin_message('Required plugin "%s" could not be loaded.', $slug);
                }
            }
            if (!$passed) $checks[] = ['label' => $label, 'passed' => false, 'command' => '', 'doc' => '', 'raw_output' => ''];
        }
    }
    return $checks;
}

function plugin_requirement_errors(array $manifest): array {
    $errors = array_values(array_map(
        static fn(array $check): string => $check['label'],
        array_filter(plugin_requirement_checks($manifest), static fn(array $check): bool => !$check['passed'])
    ));
    return array_values(array_unique(array_merge($errors, plugin_manifest_contract_errors($manifest))));
}

function plugin_requirements_error_message(array $manifest): string {
    $errors = plugin_requirement_errors($manifest);
    if ($errors === []) return '';
    $template = function_exists('__') ? __('Plugin requirements are not met: %s.') : 'Plugin requirements are not met: %s.';
    return sprintf($template, rtrim(implode('; ', $errors), '.'));
}

function plugin_install_requirements_error_message(array $manifest, bool $activate): string {
    $errors = $activate
        ? plugin_requirement_errors($manifest)
        : plugin_requirement_errors_without_plugin_state($manifest);
    $errors = array_merge($errors, plugin_route_collision_errors($manifest, plugins_all()));
    return plugin_requirements_error_message_from_errors($errors);
}

function plugin_requirement_diagnostics(): array {
    plugins_active();
    return $GLOBALS['_plugin_requirement_diagnostics'];
}

function plugin_load_diagnostics(): array {
    return $GLOBALS['_plugin_load_diagnostics'];
}

function plugin_state_directory_is_safe(string $directory): bool {
    clearstatcache(true, $directory);
    $stat = @lstat($directory);
    return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0040000
        && (($stat['mode'] ?? 0) & 0002) === 0 && !is_link($directory);
}

function plugin_disabled_names(): array {
    $directory = dirname(PLUGIN_DISABLED_JSON);
    if (!plugin_state_directory_is_safe($directory)) return array_keys(plugins_all());
    if (!is_file(PLUGIN_DISABLED_JSON)) return [];
    if (is_link(PLUGIN_DISABLED_JSON)) return array_keys(plugins_all());
    clearstatcache(true, PLUGIN_DISABLED_JSON);
    $stat = @lstat(PLUGIN_DISABLED_JSON);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (($stat['mode'] ?? 0) & 0002) !== 0 || ($stat['nlink'] ?? 0) !== 1) {
        return array_keys(plugins_all());
    }
    $raw = @file_get_contents(PLUGIN_DISABLED_JSON);
    $disabled = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($disabled) || !array_is_list($disabled)) return array_keys(plugins_all());
    $validated = [];
    foreach ($disabled as $name) {
        if (!is_string($name) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1 || isset($validated[$name])) {
            return array_keys(plugins_all());
        }
        $validated[$name] = true;
    }
    return array_keys($validated);
}

/** Persist plugin state while the caller holds the global and plugin-name lifecycle locks. */
function _plugin_write_disabled_names_already_locked(array $disabled): bool {
    if (!array_is_list($disabled)) return false;
    $validated = [];
    foreach ($disabled as $name) {
        if (!is_string($name) || preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1 || isset($validated[$name])) return false;
        $validated[$name] = true;
    }
    $disabled = array_keys($validated);
    sort($disabled, SORT_STRING);
    $file = PLUGIN_DISABLED_JSON;
    $directory = dirname($file);
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory))) return false;
    if (!plugin_state_directory_is_safe($directory)) return false;
    $directoryReal = realpath($directory);
    if ($directoryReal === false || is_link($file)) return false;
    try {
        $json = json_encode($disabled, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = $directory . '/.plugins-disabled-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) return false;
        $written = fwrite($handle, $json);
        $flushed = $written === strlen($json) && fflush($handle);
        $synced = $flushed && (!function_exists('fsync') || fsync($handle));
        $stat = fstat($handle);
        fclose($handle);
        if (!$synced || !is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || ($stat['nlink'] ?? 0) !== 1 || !@chmod($temporary, 0640) || !@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }
        plugin_sync_directory($directoryReal);
        return true;
    } catch (Throwable $error) {
        if (isset($temporary)) @unlink($temporary);
        error_log('[plugin-state] ' . $error->getMessage());
        return false;
    }
}

function plugin_reset_runtime_cache(): void {
    $GLOBALS['_plugin_active_cache'] = null;
    $GLOBALS['_plugin_requirement_diagnostics'] = [];
    $GLOBALS['_plugin_load_diagnostics'] = [];
    $GLOBALS['_plugin_loader_ran'] = false;
    $GLOBALS['_plugin_ready_permissions'] = [];
    $GLOBALS['_plugin_permission_sync_errors'] = [];
    $GLOBALS['_plugin_frontend_routes_sealed'] = false;
}

function plugin_last_error(): string {
    return (string)($GLOBALS['_plugin_last_error'] ?? '');
}

function plugin_frontend_route_normalize_path(string $path): ?string {
    if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) return null;
    $path = trim($path);
    if ($path === '' || $path === '/') return '';
    if (str_contains($path, '\\') || str_contains($path, '?') || str_contains($path, '#') || str_contains($path, '%')) return null;
    $path = trim($path, '/');
    if ($path === '' || str_contains($path, '//')) return null;
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '..') return null;
    }
    return preg_match('/\A[A-Za-z0-9._~-]+(?:\/[A-Za-z0-9._~-]+)*\z/D', $path) === 1 ? $path : null;
}

/** @return array{0:bool,1:?array} */
function plugin_frontend_route_normalize_methods(mixed $methods): array {
    if ($methods === null) return [true, null];
    if (!is_array($methods) || $methods === []) return [false, null];
    $normalized = [];
    foreach ($methods as $method) {
        if (!is_string($method)) return [false, null];
        $method = strtoupper(trim($method));
        if ($method === '' || preg_match('/\A[A-Z!#$%&\'*+.^_`|~-]+\z/D', $method) !== 1) return [false, null];
        $normalized[$method] = true;
        if ($method === 'GET') $normalized['HEAD'] = true;
    }
    return [true, array_keys($normalized)];
}

function plugin_frontend_route_diagnostic(string $message): bool {
    $GLOBALS['_plugin_frontend_route_diagnostics'][] = $message;
    error_log('[plugin-route] ' . $message);
    return false;
}

function plugin_run_frontend_init(): void {
    if (($GLOBALS['__jy_frontend_init_fired'] ?? false) === true) return;
    $GLOBALS['__jy_frontend_init_fired'] = true;
    do_action('init');
}

/**
 * Register a plugin-owned frontend route.
 *
 * Existing two-argument calls remain prefix routes for every HTTP method.
 * Options: match (prefix|exact), methods (list|null), and priority (lower runs first).
 */
function register_frontend_route(string $path, callable|string $handler, array $options = []): bool {
    if (($GLOBALS['_plugin_frontend_routes_sealed'] ?? false) === true) {
        return plugin_frontend_route_diagnostic('Frontend routes must be registered during plugin loading.');
    }
    foreach (array_keys($options) as $option) {
        if (!in_array($option, ['match', 'methods', 'priority'], true)) {
            return plugin_frontend_route_diagnostic('Rejected a frontend route with an unknown option.');
        }
    }
    $path = plugin_frontend_route_normalize_path($path);
    if ($path === null) return plugin_frontend_route_diagnostic('Rejected an invalid frontend route path.');

    $match = $options['match'] ?? 'prefix';
    if (!is_string($match) || !in_array($match, ['prefix', 'exact'], true)) {
        return plugin_frontend_route_diagnostic('Rejected a frontend route with an invalid match mode.');
    }
    if ($path === '' && $match !== 'exact') {
        return plugin_frontend_route_diagnostic('A root frontend route must use exact matching.');
    }

    [$methodsValid, $methods] = plugin_frontend_route_normalize_methods($options['methods'] ?? null);
    if (!$methodsValid) return plugin_frontend_route_diagnostic('Rejected a frontend route with invalid HTTP methods.');
    $priority = $options['priority'] ?? 10;
    if (!is_int($priority)) return plugin_frontend_route_diagnostic('Rejected a frontend route with an invalid priority.');

    foreach ($GLOBALS['_plugin_frontend_route_definitions'] as $existing) {
        if ($existing['path'] !== $path || $existing['match'] !== $match || $existing['priority'] !== $priority) continue;
        $overlaps = $existing['methods'] === null || $methods === null
            || array_intersect($existing['methods'], $methods) !== [];
        if ($overlaps) {
            if ($existing['methods'] === $methods && $existing['handler'] === $handler) return true;
            return plugin_frontend_route_diagnostic('Rejected a conflicting frontend route for "' . ($path === '' ? '/' : $path) . '".');
        }
    }

    $order = (int)$GLOBALS['_plugin_frontend_route_order'];
    $GLOBALS['_plugin_frontend_route_order'] = $order + 1;
    $GLOBALS['_plugin_frontend_route_definitions'][] = [
        'path' => $path,
        'match' => $match,
        'methods' => $methods,
        'priority' => $priority,
        'handler' => $handler,
        'order' => $order,
    ];
    if (!array_key_exists($path, $GLOBALS['_plugin_frontend_routes'])) {
        $GLOBALS['_plugin_frontend_routes'][$path] = $handler;
    }
    return true;
}

function get_frontend_routes(): array {
    return $GLOBALS['_plugin_frontend_routes'];
}

function match_frontend_route(string $prefix): callable|string|null {
    $prefix = plugin_frontend_route_normalize_path($prefix);
    return $prefix === null ? null : ($GLOBALS['_plugin_frontend_routes'][$prefix] ?? null);
}

function get_frontend_route_definitions(): array {
    return $GLOBALS['_plugin_frontend_route_definitions'];
}

function get_frontend_route_diagnostics(): array {
    return $GLOBALS['_plugin_frontend_route_diagnostics'];
}

/** @return array{handler:callable|string|null,route:array,method_allowed:bool,allowed_methods:array}|null */
function resolve_frontend_route(string $path, string $method): ?array {
    $path = plugin_frontend_route_normalize_path($path);
    if ($path === null) return null;
    $method = strtoupper(trim($method));

    $candidates = [];
    foreach ($GLOBALS['_plugin_frontend_route_definitions'] as $definition) {
        $matches = $definition['match'] === 'exact'
            ? $path === $definition['path']
            : ($path === $definition['path'] || str_starts_with($path, $definition['path'] . '/'));
        if ($matches) $candidates[] = $definition;
    }
    if ($candidates === []) return null;

    $hasExact = false;
    foreach ($candidates as $candidate) {
        if ($candidate['match'] === 'exact') {
            $hasExact = true;
            break;
        }
    }
    if ($hasExact) {
        $candidates = array_values(array_filter($candidates, static fn(array $route): bool => $route['match'] === 'exact'));
    } else {
        $longest = max(array_map(static fn(array $route): int => strlen($route['path']), $candidates));
        $candidates = array_values(array_filter($candidates, static fn(array $route): bool => strlen($route['path']) === $longest));
    }

    usort($candidates, static fn(array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: ($a['order'] <=> $b['order']));
    foreach ($candidates as $candidate) {
        if ($candidate['methods'] !== null && !in_array($method, $candidate['methods'], true)) continue;
        $route = $candidate;
        unset($route['handler']);
        return ['handler' => $candidate['handler'], 'route' => $route, 'method_allowed' => true, 'allowed_methods' => $candidate['methods'] ?? []];
    }

    $allowed = [];
    foreach ($candidates as $candidate) {
        foreach ($candidate['methods'] ?? [] as $allowedMethod) $allowed[$allowedMethod] = true;
    }
    $allowed = array_keys($allowed);
    sort($allowed, SORT_STRING);
    $route = $candidates[0];
    unset($route['handler']);
    return ['handler' => null, 'route' => $route, 'method_allowed' => false, 'allowed_methods' => $allowed];
}

// --- Plugin auto-loader: require plugin.php for each active plugin ---
function plugin_load_active(): void {
    if ($GLOBALS['_plugin_loader_ran']) return;
    $GLOBALS['_plugin_loader_ran'] = true;
    $active = plugins_active();
    $loaded = [];
    $runtimeActive = [];
    foreach ($active as $name => $p) {
        $requires = plugin_normalize_requirements($p)['requires']['plugins'] ?? [];
        $unloadedDependencies = [];
        foreach (is_array($requires) ? array_keys($requires) : [] as $dependencyName) {
            if (!isset($loaded[$dependencyName])) $unloadedDependencies[] = $dependencyName;
        }
        if ($unloadedDependencies !== []) {
            $error = plugin_message(
                'Plugin "%s" was not loaded because these dependency entrypoints failed: %s.',
                $name,
                implode(', ', $unloadedDependencies)
            );
            $GLOBALS['_plugin_load_diagnostics'][$name] = $error;
            $GLOBALS['_plugin_requirement_diagnostics'][$name] = $error;
            error_log("[plugin-loader] Plugin '{$name}' skipped: {$error}");
            continue;
        }
        $mainFile = PLUGIN_PATH . '/' . $name . '/plugin.php';
        if (is_file($mainFile)) {
            $hooksBeforeLoad = $GLOBALS['_hooks'] ?? null;
            $routesBeforeLoad = $GLOBALS['_plugin_frontend_routes'];
            $routeDefinitionsBeforeLoad = $GLOBALS['_plugin_frontend_route_definitions'];
            $routeOrderBeforeLoad = $GLOBALS['_plugin_frontend_route_order'];
            $routeDiagnosticsBeforeLoad = $GLOBALS['_plugin_frontend_route_diagnostics'];
            $mailTransportsBeforeLoad = $GLOBALS['__jy_mail_transports'] ?? null;
            $themeSlotsBeforeLoad = $GLOBALS['__jy_theme_slots'] ?? null;
            $shortcodeSourcesBeforeLoad = $GLOBALS['__jy_shortcode_source_providers'] ?? null;
            try {
                require_once $mainFile;
            } catch (\Throwable $e) {
                if (is_array($hooksBeforeLoad)) $GLOBALS['_hooks'] = $hooksBeforeLoad;
                $GLOBALS['_plugin_frontend_routes'] = $routesBeforeLoad;
                $GLOBALS['_plugin_frontend_route_definitions'] = $routeDefinitionsBeforeLoad;
                $GLOBALS['_plugin_frontend_route_order'] = $routeOrderBeforeLoad;
                $GLOBALS['_plugin_frontend_route_diagnostics'] = $routeDiagnosticsBeforeLoad;
                if (is_array($mailTransportsBeforeLoad)) $GLOBALS['__jy_mail_transports'] = $mailTransportsBeforeLoad;
                if (is_array($themeSlotsBeforeLoad)) $GLOBALS['__jy_theme_slots'] = $themeSlotsBeforeLoad;
                else unset($GLOBALS['__jy_theme_slots']);
                if (is_array($shortcodeSourcesBeforeLoad)) $GLOBALS['__jy_shortcode_source_providers'] = $shortcodeSourcesBeforeLoad;
                else unset($GLOBALS['__jy_shortcode_source_providers']);
                $error = plugin_message('Plugin "%s" entrypoint failed: %s.', $name, $e->getMessage());
                $GLOBALS['_plugin_load_diagnostics'][$name] = $error;
                $GLOBALS['_plugin_requirement_diagnostics'][$name] = $error;
                error_log("[plugin-loader] {$error}");
                continue;
            }
        }
        $loaded[$name] = true;
        $GLOBALS['_plugin_loaded_entrypoints'][$name] = true;
        $runtimeActive[$name] = $p;
    }
    $GLOBALS['_plugin_active_cache'] = $runtimeActive;
    try {
        do_action('plugins_loaded');
    } finally {
        $GLOBALS['_plugin_frontend_routes_sealed'] = true;
    }
}

function plugin_manifest(string $name): ?array {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return null;
    $file = PLUGIN_PATH . '/' . $name . '/plugin.json';
    if (!is_file($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) && ($data['name'] ?? null) === $name && plugin_manifest_contract_errors($data) === [] ? $data : null;
}

/** Resolve only the top-level image icon explicitly declared by plugin.json. */
function plugin_declared_icon_file(string $name, string $file): ?array {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1 || $file === '' || str_contains($file, "\0")
        || str_contains($file, '\\') || basename($file) !== $file) return null;
    $manifest = plugin_manifest($name);
    $declared = is_array($manifest) && is_string($manifest['icon'] ?? null) ? $manifest['icon'] : '';
    if ($declared === '' || basename($declared) !== $declared || !hash_equals($declared, $file)) return null;
    $extension = strtolower((string)pathinfo($declared, PATHINFO_EXTENSION));
    $mimeTypes = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    if (!isset($mimeTypes[$extension])) return null;
    $pluginRoot = realpath(PLUGIN_PATH);
    $pluginDir = realpath(PLUGIN_PATH . '/' . $name);
    if ($pluginRoot === false || $pluginDir === false
        || ($pluginDir !== $pluginRoot && !str_starts_with($pluginDir, $pluginRoot . DIRECTORY_SEPARATOR))) return null;
    $path = plugin_safe_path($pluginDir, $declared);
    if ($path === null || !is_file($path) || is_link($path)) return null;
    return ['path' => $path, 'mime' => $mimeTypes[$extension]];
}

function plugin_safe_path(string $root, string $relative): ?string {
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative)) return null;
    $parts = explode('/', $relative);
    if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) return null;
    $base = realpath($root);
    if ($base === false) return null;
    $parentPath = dirname($base . '/' . $relative);
    while (!file_exists($parentPath) && dirname($parentPath) !== $parentPath) $parentPath = dirname($parentPath);
    $parent = realpath($parentPath);
    if ($parent === false || ($parent !== $base && !str_starts_with($parent, $base . DIRECTORY_SEPARATOR))) return null;
    return $base . '/' . $relative;
}

/** Build and validate a fresh plugin package in a private same-parent staging directory. */
function plugin_prepare_package_stage(string $zipPath, ?string $expectedName, bool $activate, ?array $catalog = null): array {
    if (!is_file($zipPath) || is_link($zipPath) || filesize($zipPath) > PACKAGE_MAX_BYTES) {
        return ['success' => false, 'error' => plugin_message('Invalid or oversized plugin package.')];
    }
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) return ['success' => false, 'error' => plugin_message('Failed to open ZIP file.')];
    $validation = package_archive_validate($zip);
    if (!$validation['success']) { $zip->close(); return ['success' => false, 'error' => $validation['error']]; }
    $manifestIndex = $zip->locateName('plugin.json', ZipArchive::FL_NOCASE);
    if ($manifestIndex === false || (string)($zip->statIndex($manifestIndex)['name'] ?? '') !== 'plugin.json') {
        $zip->close();
        return ['success' => false, 'error' => plugin_message('plugin.json must exist at the ZIP root with exact casing.')];
    }
    $raw = $zip->getFromIndex($manifestIndex);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    $name = is_array($manifest) && is_string($manifest['name'] ?? null) ? $manifest['name'] : '';
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1 || ($expectedName !== null && !hash_equals($expectedName, $name))) {
        $zip->close();
        return ['success' => false, 'error' => plugin_message('Plugin package identity is invalid.')];
    }
    $requirementError = plugin_install_requirements_error_message($manifest, $activate);
    if ($requirementError !== '') { $zip->close(); return ['success' => false, 'error' => $requirementError]; }
    if (is_array($catalog)) {
        $metadataErrors = plugin_package_requirement_errors($catalog, $manifest);
        if ($metadataErrors !== []) {
            $zip->close();
            return ['success' => false, 'error' => plugin_message('Plugin package requirements do not match the store catalog.') . ' ' . implode('; ', $metadataErrors)];
        }
    }

    $files = [];
    $logical = [];
    foreach ($validation['entries'] as $entry) {
        if ($entry['directory']) continue;
        $relative = $entry['path'];
        $key = strtolower($relative);
        if ($key === '.store.json' || $key === '.git' || str_starts_with($key, '.git/') || isset($logical[$key])) {
            $zip->close();
            return ['success' => false, 'error' => plugin_message('Plugin package contains a reserved or duplicate target.')];
        }
        $logical[$key] = true;
        $files[] = ['source' => $entry['source'], 'relative' => $relative];
    }
    $stage = package_private_directory(PLUGIN_PATH, 'plugin-stage-' . $name);
    if ($stage === null) { $zip->close(); return ['success' => false, 'error' => plugin_message('Failed to create plugin staging directory.')]; }
    $extracted = package_archive_extract_files($zip, $files, $stage);
    $zip->close();
    if (!$extracted) {
        package_remove_tree($stage);
        return ['success' => false, 'error' => plugin_message('Failed to extract plugin package safely.')];
    }
    $extractedRaw = @file_get_contents($stage . '/plugin.json');
    $extractedManifest = is_string($extractedRaw) ? json_decode($extractedRaw, true) : null;
    if (!is_array($extractedManifest) || $extractedManifest !== $manifest || package_tree_identity($stage) === null) {
        package_remove_tree($stage);
        return ['success' => false, 'error' => plugin_message('Extracted plugin package failed verification.')];
    }
    try {
        plugin_migrations_discover($stage);
    } catch (Throwable $error) {
        package_remove_tree($stage);
        return ['success' => false, 'error' => plugin_message('Plugin migration package is invalid: %s', $error->getMessage())];
    }
    return ['success' => true, 'name' => $name, 'manifest' => $manifest, 'stage' => $stage];
}

function plugin_chmod_tree(string $directory): bool {
    if (!is_dir($directory) || is_link($directory)) return false;
    $ok = @chmod($directory, 0775);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) return false;
        $mode = $entry->isDir() ? 0775 : (strtolower((string)pathinfo($entry->getFilename(), PATHINFO_EXTENSION)) === 'sh' ? 0775 : 0664);
        if (!@chmod($entry->getPathname(), $mode)) $ok = false;
    }
    return $ok;
}

/** Publish a fully staged fresh plugin while its global-first lifecycle lock is held. */
function plugin_publish_staged_install_already_locked(array $prepared, bool $activate, ?PDO $pdo = null): array {
    $name = is_string($prepared['name'] ?? null) ? $prepared['name'] : '';
    $stage = is_string($prepared['stage'] ?? null) ? $prepared['stage'] : '';
    $manifest = is_array($prepared['manifest'] ?? null) ? $prepared['manifest'] : null;
    $pluginDir = PLUGIN_PATH . '/' . $name;
    if (!package_lifecycle_exclusive_lock_owned()) {
        return ['success' => false, 'error' => plugin_message('Global lifecycle exclusive lock is required to publish a plugin.')];
    }
    $database = $pdo ?? (($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null);
    if (!$database instanceof PDO) {
        return ['success' => false, 'error' => plugin_message('A database connection is required to install a plugin.')];
    }
    $recoveryPaths = package_publication_recovery_paths($pluginDir);
    if ($recoveryPaths !== []) {
        return [
            'success' => false,
            'error' => plugin_message('A prior plugin publication recovery artifact requires manual resolution. Inspect and restore or archive it before retrying: %s', basename($recoveryPaths[0])),
            'recovery_paths' => $recoveryPaths,
        ];
    }
    if ($manifest === null || preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1 || !is_dir($stage)
        || dirname($stage) !== realpath(PLUGIN_PATH) || file_exists($pluginDir) || is_link($pluginDir)) {
        if ($stage !== '') package_remove_tree($stage);
        return ['success' => false, 'error' => plugin_message('Invalid staged plugin publication.')];
    }
    if (!_plugin_mark_disabled_already_locked($name)) {
        package_remove_tree($stage);
        return ['success' => false, 'error' => plugin_last_error() ?: plugin_message('Failed to update plugin state.')];
    }
    if (!@rename($stage, $pluginDir)) {
        package_remove_tree($stage);
        return ['success' => false, 'error' => plugin_message('Failed to publish plugin directory atomically.')];
    }
    plugin_sync_directory((string)realpath(PLUGIN_PATH));
    if (!plugin_chmod_tree($pluginDir)) {
        package_remove_tree($pluginDir);
        plugin_advance_reconciliation_state($name);
        return ['success' => false, 'error' => plugin_message('Failed to set plugin permissions.')];
    }
    $staticCopy = $manifest['static']['copy'] ?? [];
    if (!is_array($staticCopy)) {
        package_remove_tree($pluginDir);
        plugin_advance_reconciliation_state($name);
        return ['success' => false, 'error' => plugin_message('Plugin installation failed because static.copy is invalid.')];
    }
    try {
        $migrationResult = plugin_migrations_run_pending_already_locked(
            $database,
            $name,
            is_string($manifest['version'] ?? null) && $manifest['version'] !== '' ? $manifest['version'] : '0.0.0',
            $pluginDir
        );
    } catch (Throwable $error) {
        plugin_advance_reconciliation_state($name);
        return ['success' => false, 'error' => plugin_message(
            'Plugin database migration failed: %s Database changes may remain; the plugin is inactive.',
            $error->getMessage()
        )];
    }
    $databaseChanged = $migrationResult['applied'] !== [];
    if ($staticCopy !== []) {
        $copyResult = plugin_static_copy($pluginDir, $staticCopy);
        if ($copyResult['failed'] > 0) {
            if (!$databaseChanged) package_remove_tree($pluginDir);
            plugin_advance_reconciliation_state($name);
            return ['success' => false, 'error' => plugin_message('Plugin installation failed because declared static files could not be copied.')
                . ($databaseChanged ? ' ' . plugin_message('Database changes remain; the plugin is inactive.') : '')];
        }
    }
    $installResult = plugin_run_install_script($pluginDir);
    try {
        plugin_migrations_assert_complete_already_locked(
            $database,
            $name,
            is_string($manifest['version'] ?? null) && $manifest['version'] !== '' ? $manifest['version'] : '0.0.0',
            $pluginDir
        );
    } catch (Throwable $error) {
        plugin_advance_reconciliation_state($name);
        return ['success' => false, 'error' => plugin_message(
            'Plugin migration history changed during installation: %s The plugin remains inactive.',
            $error->getMessage()
        )];
    }
    if (!$installResult['success']) {
        if (!$installResult['ran'] && !$databaseChanged) {
            if ($staticCopy !== []) plugin_static_copy($pluginDir, [], $staticCopy);
            package_remove_tree($pluginDir);
        }
        plugin_advance_reconciliation_state($name);
        return ['success' => false, 'error' => $installResult['error'] . ($installResult['ran']
            ? ' ' . plugin_message('The plugin remains installed but inactive for inspection; install.sh may have made changes outside its directory.')
            : '') . ($databaseChanged ? ' ' . plugin_message('Database changes remain; the plugin is inactive.') : '')];
    }
    $verified = plugin_manifest($name);
    if (!is_array($verified) || $verified !== $manifest || package_tree_identity($pluginDir) === null) {
        plugin_advance_reconciliation_state($name);
        return ['success' => false, 'error' => plugin_message('Installed plugin verification failed; the plugin remains inactive.')];
    }
    if (!plugin_advance_reconciliation_state($name)) {
        return ['success' => false, 'error' => plugin_message('Plugin was installed and remains inactive, but reconciliation state could not be persisted.')];
    }
    if ($activate && !_plugin_enable_already_locked($name, $database)) {
        return ['success' => false, 'error' => plugin_last_error() ?: plugin_message('Failed to activate plugin.')];
    }
    plugin_reset_runtime_cache();
    return ['success' => true, 'name' => $name, 'manifest' => $manifest, 'activated' => $activate];
}

function plugin_sync_directory(string $directory): void {
    package_sync_directory($directory);
}

function plugin_static_copy(string $pluginDir, array $entries, array $oldEntries = []): array {
    $errors = [];
    $pluginName = basename($pluginDir);
    $validated = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) { $errors[] = 'Invalid static.copy entry.'; continue; }
        $from = is_string($entry['from'] ?? null) ? $entry['from'] : '';
        $to = is_string($entry['to'] ?? null) ? $entry['to'] : '';
        $source = plugin_safe_path($pluginDir, $from);
        $dest = plugin_static_path($pluginName, $to);
        if (!$source || !$dest || !is_file($source) || is_link($source) || is_link($dest)
            || (file_exists($dest) && !is_file($dest))) {
            $errors[] = 'Invalid static copy: ' . ($to !== '' ? $to : $from);
        } elseif (isset($validated[$dest])) {
            $errors[] = 'Duplicate static copy destination: ' . $to;
        } else {
            $validated[$dest] = ['source' => $source, 'dest' => $dest];
        }
    }
    $oldDestinations = [];
    foreach ($oldEntries as $entry) {
        $to = is_array($entry) && is_string($entry['to'] ?? null) ? $entry['to'] : '';
        $dest = plugin_static_path($pluginName, $to);
        if (!$dest || is_link($dest)) $errors[] = 'Invalid previous static copy: ' . $to;
        else $oldDestinations[$dest] = true;
    }
    if ($errors !== []) return ['copied' => 0, 'removed' => 0, 'failed' => count($errors), 'rollback_incomplete' => false, 'errors' => $errors];

    $changes = [];
    $copied = 0;
    $removed = 0;
    try {
        foreach ($validated as $item) {
            $destDir = dirname($item['dest']);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) throw new RuntimeException('Could not create static directory.');
            if (is_link($destDir) || is_link($item['dest'])) throw new RuntimeException('Static destination is a symlink.');
            $token = bin2hex(random_bytes(8));
            $temporary = $destDir . '/.plugin-copy-' . $token . '.tmp';
            $backup = $destDir . '/.plugin-copy-' . $token . '.bak';
            $input = @fopen($item['source'], 'rb');
            $output = @fopen($temporary, 'x+b');
            if (!$input || !$output || stream_copy_to_stream($input, $output) === false || !fflush($output)
                || (function_exists('fsync') && !fsync($output))) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                @unlink($temporary);
                throw new RuntimeException('Could not copy declared static file.');
            }
            fclose($input);
            fclose($output);
            @chmod($temporary, 0644);
            $hadExisting = is_file($item['dest']);
            $backupHash = $hadExisting ? hash_file('sha256', $item['dest']) : null;
            if ($hadExisting && !rename($item['dest'], $backup)) {
                @unlink($temporary);
                throw new RuntimeException('Could not back up existing static file.');
            }
            $changes[] = ['action' => 'publish', 'dest' => $item['dest'], 'backup' => $hadExisting ? $backup : null, 'backup_hash' => $backupHash];
            if (!rename($temporary, $item['dest'])) {
                @unlink($temporary);
                throw new RuntimeException('Could not publish declared static file.');
            }
            plugin_sync_directory($destDir);
            $copied++;
        }
        foreach (array_diff_key($oldDestinations, $validated) as $dest => $_unused) {
            if (!is_file($dest)) continue;
            $backup = dirname($dest) . '/.plugin-copy-' . bin2hex(random_bytes(8)) . '.bak';
            $backupHash = hash_file('sha256', $dest);
            if (!rename($dest, $backup)) throw new RuntimeException('Could not stage obsolete static file removal.');
            plugin_sync_directory(dirname($dest));
            $changes[] = ['action' => 'remove', 'dest' => $dest, 'backup' => $backup, 'backup_hash' => $backupHash];
            $removed++;
        }
        foreach ($changes as $change) {
            if ($change['backup'] !== null && is_file($change['backup']) && !@unlink($change['backup'])) {
                error_log('plugin static backup cleanup failed: ' . $change['backup']);
            }
        }
        return ['copied' => $copied, 'removed' => $removed, 'failed' => 0, 'rollback_incomplete' => false, 'errors' => []];
    } catch (Throwable $error) {
        $rollbackErrors = [];
        foreach (array_reverse($changes) as $change) {
            if ($change['action'] === 'publish' && (file_exists($change['dest']) || is_link($change['dest']))) {
                if (!@unlink($change['dest']) || file_exists($change['dest']) || is_link($change['dest'])) $rollbackErrors[] = 'Could not remove replacement ' . $change['dest'];
            }
            if ($change['backup'] !== null && is_file($change['backup'])) {
                $restored = @rename($change['backup'], $change['dest']);
                $restoredHash = $restored && is_file($change['dest']) ? hash_file('sha256', $change['dest']) : false;
                if (!$restored || !is_string($restoredHash) || !is_string($change['backup_hash'])
                    || !hash_equals($change['backup_hash'], $restoredHash)) {
                    $rollbackErrors[] = 'Could not verify restored content for ' . $change['dest'];
                } else {
                    plugin_sync_directory(dirname($change['dest']));
                }
            } elseif ($change['action'] === 'publish' && (file_exists($change['dest']) || is_link($change['dest']))) {
                $rollbackErrors[] = 'New destination remains after rollback ' . $change['dest'];
            }
        }
        foreach ($rollbackErrors as $rollbackError) error_log('plugin static copy rollback failed: ' . $rollbackError);
        $errors[] = $error->getMessage();
        if ($rollbackErrors !== []) $errors[] = 'Static asset rollback was incomplete: ' . implode('; ', $rollbackErrors);
        return ['copied' => 0, 'removed' => 0, 'failed' => max(1, count($entries)), 'rollback_incomplete' => $rollbackErrors !== [], 'errors' => $errors];
    }
}

function plugin_static_path(string $name, string $relative): ?string {
    $prefix = 'static/plugins/' . $name . '/';
    if (!str_starts_with($relative, $prefix)) return null;
    $public = defined('PUBLIC_PATH') ? PUBLIC_PATH : dirname(PLUGIN_PATH) . '/public';
    return plugin_safe_path($public, $relative);
}

/** Execute only the conventional install.sh with bounded runtime and captured output. */
function plugin_run_install_script(string $pluginDir, ?int $timeoutSeconds = null, ?int $outputLimit = null): array {
    $root = realpath(PLUGIN_PATH);
    $directory = realpath($pluginDir);
    if ($root === false || $directory === false
        || ($directory !== $root && !str_starts_with($directory, $root . DIRECTORY_SEPARATOR))) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install directory is invalid.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    $script = $directory . '/install.sh';
    if (!file_exists($script)) return ['success' => true, 'ran' => false, 'error' => '', 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    if (!is_file($script) || is_link($script)) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh is not a safe regular file.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    if (!function_exists('proc_open')) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh could not run because process execution is unavailable.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    if (!is_executable($script) && !@chmod($script, 0755)) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh is not executable.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }

    $timeoutEnvironment = getenv('PLUGIN_INSTALL_TIMEOUT_SECONDS');
    $limitEnvironment = getenv('PLUGIN_INSTALL_OUTPUT_LIMIT');
    $configuredTimeout = defined('PLUGIN_INSTALL_TIMEOUT_SECONDS')
        ? (int)PLUGIN_INSTALL_TIMEOUT_SECONDS
        : (is_string($timeoutEnvironment) && ctype_digit($timeoutEnvironment) ? (int)$timeoutEnvironment : 120);
    $configuredLimit = defined('PLUGIN_INSTALL_OUTPUT_LIMIT')
        ? (int)PLUGIN_INSTALL_OUTPUT_LIMIT
        : (is_string($limitEnvironment) && ctype_digit($limitEnvironment) ? (int)$limitEnvironment : 65536);
    $timeoutSeconds = max(1, min(900, $timeoutSeconds ?? $configuredTimeout));
    $outputLimit = max(1024, min(1048576, $outputLimit ?? $configuredLimit));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $setsid = null;
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $setsid = $candidate;
            break;
        }
    }
    $usesProcessGroup = $setsid !== null && function_exists('posix_kill');
    $command = $setsid !== null ? [$setsid, $script] : [$script];
    $pipes = [];
    $process = @proc_open($command, $descriptors, $pipes, $directory, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['success' => false, 'ran' => false, 'error' => plugin_message('Plugin install.sh process could not be started.'), 'output' => '', 'timed_out' => false, 'truncated' => false, 'process_group' => false];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $truncated = false;
    $timedOut = false;
    $exitCode = null;
    $initialStatus = proc_get_status($process);
    $processId = (int)($initialStatus['pid'] ?? 0);
    if ($processId <= 0) $usesProcessGroup = false;
    $deadline = microtime(true) + $timeoutSeconds;
    $capture = static function (string $chunk) use (&$output, &$truncated, $outputLimit): void {
        if ($chunk === '') return;
        $remaining = $outputLimit - strlen($output);
        if ($remaining > 0) $output .= substr($chunk, 0, $remaining);
        if (strlen($chunk) > max(0, $remaining)) $truncated = true;
    };

    while (true) {
        foreach ([1, 2] as $pipeIndex) {
            $chunk = stream_get_contents($pipes[$pipeIndex]);
            if (is_string($chunk)) $capture($chunk);
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            if ($usesProcessGroup) @posix_kill(-$processId, 15);
            else @proc_terminate($process);
            $graceDeadline = microtime(true) + 0.5;
            do {
                usleep(50000);
                foreach ([1, 2] as $pipeIndex) {
                    $chunk = stream_get_contents($pipes[$pipeIndex]);
                    if (is_string($chunk)) $capture($chunk);
                }
                $status = proc_get_status($process);
                $groupRunning = $usesProcessGroup && @posix_kill(-$processId, 0);
            } while (microtime(true) < $graceDeadline && ($status['running'] || $groupRunning));
            if ($usesProcessGroup) @posix_kill(-$processId, 9);
            elseif ($status['running']) @proc_terminate($process, 9);
            break;
        }
        usleep(50000);
    }
    foreach ([1, 2] as $pipeIndex) {
        $chunk = stream_get_contents($pipes[$pipeIndex]);
        if (is_string($chunk)) $capture($chunk);
        fclose($pipes[$pipeIndex]);
    }
    $closeCode = proc_close($process);
    if ($exitCode === null || $exitCode < 0) $exitCode = $closeCode;
    $detail = trim($output);
    if ($truncated) $detail .= ($detail === '' ? '' : "\n") . plugin_message('[installer output truncated]');

    if ($timedOut) {
        $error = plugin_message(
            'Plugin install.sh timed out after %s seconds. The process was stopped, but changes made by the script may remain.',
            (string)$timeoutSeconds
        );
        return ['success' => false, 'ran' => true, 'error' => $error . ($detail !== '' ? ' ' . $detail : ''), 'output' => $output, 'timed_out' => true, 'truncated' => $truncated, 'process_group' => $usesProcessGroup];
    }
    if ($exitCode !== 0) {
        $error = plugin_message(
            'Plugin install.sh failed with exit code %s. Changes made outside the plugin directory may remain.',
            (string)$exitCode
        );
        return ['success' => false, 'ran' => true, 'error' => $error . ($detail !== '' ? ' ' . $detail : ''), 'output' => $output, 'timed_out' => false, 'truncated' => $truncated, 'process_group' => $usesProcessGroup];
    }
    return ['success' => true, 'ran' => true, 'error' => '', 'output' => $output, 'timed_out' => false, 'truncated' => $truncated, 'process_group' => $usesProcessGroup];
}

function plugins_all(): array {
    $locks = [];
    $globalKey = defined('THEME_LIFECYCLE_LOCK_KEY') ? (string)THEME_LIFECYCLE_LOCK_KEY : '0-theme-lifecycle';
    $alreadyLocked = isset($GLOBALS['_theme_operation_held_keys'][$globalKey]);
    if (!$alreadyLocked) {
        try {
            if (!function_exists('theme_operation_acquire')) require_once dirname(__DIR__) . '/cfg/helpers/theme_helper.php';
            $locks = theme_operation_acquire(theme_lifecycle_lock_keys(), LOCK_SH);
        } catch (Throwable $error) {
            error_log('[plugin-discovery] ' . $error->getMessage());
            return [];
        }
    }
    $plugins = [];
    try {
        foreach (glob(PLUGIN_PATH . '/*/plugin.json') as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            $folder = basename(dirname($file));
            if (is_array($data) && ($data['name'] ?? null) === $folder && preg_match('/\A[a-zA-Z0-9_-]+\z/', $folder) === 1) {
                $plugins[$data['name']] = $data;
            }
        }
    } finally {
        if ($locks !== []) theme_operation_release($locks);
    }
    return $plugins;
}

/** Resolve enabled plugins into dependency-first order without loading invalid graph branches. */
function plugin_resolve_active_plugins(array $all, array $disabled): array {
    $ordered = [];
    $diagnostics = [];
    $state = [];
    $stack = [];
    $addDiagnostic = static function (string $name, string $message) use (&$diagnostics): void {
        if ($message === '') return;
        $diagnostics[$name] = isset($diagnostics[$name])
            ? $diagnostics[$name] . ' ' . $message
            : $message;
    };
    $visit = function (string $name) use (&$visit, &$ordered, &$diagnostics, &$state, &$stack, $all, $disabled, $addDiagnostic): bool {
        if (($state[$name] ?? 0) === 2) return isset($ordered[$name]);
        if (($state[$name] ?? 0) === 1) {
            $start = array_search($name, $stack, true);
            $cycle = array_slice($stack, $start === false ? 0 : $start);
            $cycle[] = $name;
            $message = plugin_message('Plugin dependency cycle detected: %s.', implode(' -> ', $cycle));
            foreach (array_unique($cycle) as $cycleName) $addDiagnostic($cycleName, $message);
            return false;
        }
        if (!isset($all[$name]) || in_array($name, $disabled, true)) return false;

        $state[$name] = 1;
        $stack[] = $name;
        $valid = true;
        $baseErrors = plugin_requirement_errors_without_plugin_state($all[$name]);
        if ($baseErrors !== []) {
            $addDiagnostic($name, plugin_requirements_error_message_from_errors($baseErrors));
            $valid = false;
        } else {
            $requires = plugin_normalize_requirements($all[$name])['requires']['plugins'] ?? [];
            foreach (is_array($requires) ? $requires : [] as $dependencyName => $constraint) {
                if (!is_string($dependencyName) || !is_string($constraint)) continue;
                if (!isset($all[$dependencyName])) {
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([
                        plugin_message('Required plugin "%s" is not installed.', $dependencyName),
                    ]));
                    $valid = false;
                    continue;
                }
                if (in_array($dependencyName, $disabled, true)) {
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([
                        plugin_message('Required plugin "%s" is inactive.', $dependencyName),
                    ]));
                    $valid = false;
                    continue;
                }
                $dependencyVersion = $all[$dependencyName]['version'] ?? null;
                if (!is_string($dependencyVersion) || !plugin_version_requirement_met($dependencyVersion, $constraint)) {
                    $error = !is_string($dependencyVersion)
                        ? plugin_message('Required plugin "%s" has an invalid version.', $dependencyName)
                        : plugin_message('Required plugin "%s" version %s does not satisfy %s.', $dependencyName, $dependencyVersion, $constraint);
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([$error]));
                    $valid = false;
                    continue;
                }
                if (!$visit($dependencyName)) {
                    $addDiagnostic($name, plugin_requirements_error_message_from_errors([
                        plugin_message('Required plugin "%s" could not be loaded.', $dependencyName),
                    ]));
                    $valid = false;
                }
            }
        }
        array_pop($stack);
        $state[$name] = 2;
        if ($valid && !isset($diagnostics[$name])) $ordered[$name] = $all[$name];
        return isset($ordered[$name]);
    };

    foreach ($all as $name => $_manifest) {
        if (!in_array($name, $disabled, true)) $visit($name);
    }

    $collided = [];
    foreach ($ordered as $name => $manifest) {
        $routeErrors = plugin_route_collision_errors($manifest, $ordered);
        if ($routeErrors === []) continue;
        $addDiagnostic($name, plugin_requirements_error_message_from_errors($routeErrors));
        $collided[$name] = true;
    }
    foreach (array_keys($collided) as $name) unset($ordered[$name]);

    do {
        $removedDependent = false;
        foreach ($ordered as $name => $manifest) {
            $requires = plugin_normalize_requirements($manifest)['requires']['plugins'] ?? [];
            foreach (is_array($requires) ? array_keys($requires) : [] as $dependencyName) {
                if (isset($ordered[$dependencyName])) continue;
                $addDiagnostic($name, plugin_requirements_error_message_from_errors([
                    plugin_message('Required plugin "%s" could not be loaded.', (string)$dependencyName),
                ]));
                unset($ordered[$name]);
                $removedDependent = true;
                break;
            }
        }
    } while ($removedDependent);

    return ['active' => $ordered, 'diagnostics' => $diagnostics];
}

/** Order a selected set for activation, or reverse it for removal/deactivation. */
function plugin_order_names_by_dependencies(array $names, bool $dependencyFirst = true): array {
    $selected = [];
    foreach ($names as $name) {
        if (is_string($name) && preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) === 1) $selected[$name] = true;
    }
    $all = plugins_all();
    $ordered = [];
    $state = [];
    $visit = function (string $name) use (&$visit, &$ordered, &$state, $selected, $all): void {
        if (($state[$name] ?? 0) !== 0) return;
        $state[$name] = 1;
        $requires = isset($all[$name]) ? (plugin_normalize_requirements($all[$name])['requires']['plugins'] ?? []) : [];
        foreach (is_array($requires) ? array_keys($requires) : [] as $dependencyName) {
            if (isset($selected[$dependencyName])) $visit($dependencyName);
        }
        $state[$name] = 2;
        $ordered[] = $name;
    };
    foreach (array_keys($selected) as $name) $visit($name);
    return $dependencyFirst ? $ordered : array_reverse($ordered);
}

function plugin_requirement_errors_without_plugin_state(array $manifest): array {
    $errors = array_values(array_map(
        static fn(array $check): string => $check['label'],
        array_filter(plugin_requirement_checks($manifest, false), static fn(array $check): bool => !$check['passed'])
    ));
    return array_values(array_unique(array_merge($errors, plugin_manifest_contract_errors($manifest))));
}

function plugin_requirements_error_message_from_errors(array $errors): string {
    if ($errors === []) return '';
    return plugin_message('Plugin requirements are not met: %s.', rtrim(implode('; ', $errors), '.'));
}

function plugins_active(): array {
    if (is_array($GLOBALS['_plugin_active_cache'])) return $GLOBALS['_plugin_active_cache'];
    $resolved = plugin_resolve_active_plugins(plugins_all(), plugin_disabled_names());
    $GLOBALS['_plugin_active_cache'] = $resolved['active'];
    $GLOBALS['_plugin_requirement_diagnostics'] = $resolved['diagnostics'];
    foreach ($resolved['diagnostics'] as $name => $error) error_log("[plugin-loader] Plugin '{$name}' skipped: {$error}");
    return $GLOBALS['_plugin_active_cache'];
}

// --- Plugin Manager helpers ---

function plugin_enable(string $name, ?PDO $pdo = null): bool {
    $locks = plugin_lifecycle_locks($name);
    if ($locks === null) return false;
    try {
        return _plugin_enable_already_locked($name, $pdo);
    } finally {
        theme_operation_release($locks);
    }
}

function _plugin_enable_already_locked(string $name, ?PDO $pdo = null): bool {
    $manifest = plugin_manifest($name);
    if (!$manifest) {
        $GLOBALS['_plugin_last_error'] = plugin_message('Plugin manifest is invalid.');
        return false;
    }
    $disabled = plugin_disabled_names();
    $disabled = array_values(array_filter($disabled, fn($n) => $n !== $name));
    $resolved = plugin_resolve_active_plugins(plugins_all(), $disabled);
    if (!isset($resolved['active'][$name])) {
        $error = $resolved['diagnostics'][$name] ?? plugin_message('Plugin could not be activated because its dependencies are unavailable.');
        $GLOBALS['_plugin_last_error'] = $error;
        $GLOBALS['_plugin_requirement_diagnostics'][$name] = $error;
        return false;
    }
    $database = $pdo ?? (($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null);
    try {
        $migrationFiles = plugin_migrations_discover(PLUGIN_PATH . '/' . $name);
        if ($database instanceof PDO) {
            plugin_migrations_run_pending_already_locked(
                $database,
                $name,
                is_string($manifest['version'] ?? null) && $manifest['version'] !== '' ? $manifest['version'] : '0.0.0',
                PLUGIN_PATH . '/' . $name
            );
        } elseif ($migrationFiles !== []) {
            throw new RuntimeException('A database connection is required to run plugin migrations.');
        }
    } catch (Throwable $error) {
        $GLOBALS['_plugin_last_error'] = plugin_message(
            'Plugin database migration failed: %s Database changes may remain; the plugin is inactive.',
            $error->getMessage()
        );
        return false;
    }
    $ok = _plugin_write_disabled_names_already_locked($disabled);
    $GLOBALS['_plugin_last_error'] = $ok ? '' : plugin_message('Failed to update plugin state.');
    if ($ok) plugin_reset_runtime_cache();
    return $ok;
}

function plugin_active_dependents(string $name): array {
    $dependents = [];
    foreach (plugins_active() as $pluginName => $manifest) {
        if ($pluginName === $name) continue;
        $requires = plugin_normalize_requirements($manifest)['requires']['plugins'] ?? [];
        if (is_array($requires) && array_key_exists($name, $requires)) $dependents[] = $pluginName;
    }
    sort($dependents, SORT_STRING);
    return $dependents;
}

function plugin_replacement_dependency_errors(string $name, string $newVersion): array {
    if (preg_match('/\A\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?\z/', $newVersion) !== 1) {
        return [plugin_message('Plugin "%s" has an invalid replacement version.', $name)];
    }
    $errors = [];
    foreach (plugins_active() as $pluginName => $manifest) {
        if ($pluginName === $name) continue;
        $requires = plugin_normalize_requirements($manifest)['requires']['plugins'] ?? [];
        $constraint = is_array($requires) ? ($requires[$name] ?? null) : null;
        if (is_string($constraint) && !plugin_version_requirement_met($newVersion, $constraint)) {
            $errors[] = plugin_message(
                'Plugin "%s" requires %s %s, so %s cannot be installed.',
                $pluginName,
                $name,
                $constraint,
                $newVersion
            );
        }
    }
    return $errors;
}

function plugin_state_change_preflight(string $name, string $operation): bool {
    $state = ['allowed' => true, 'message' => ''];
    $hooks = $GLOBALS['_hooks']['filters']['plugin_state_change_preflight'] ?? [];
    ksort($hooks);
    foreach ($hooks as $priority => $listeners) {
        foreach ($listeners as $index => $listener) {
            try {
                $candidate = call_user_func($listener, $state, $name, $operation);
                if (!is_array($candidate) || count($candidate) !== 2
                    || !array_key_exists('allowed', $candidate) || !is_bool($candidate['allowed'])
                    || !array_key_exists('message', $candidate) || !is_string($candidate['message'])
                    || strlen($candidate['message']) > 1000
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $candidate['message']) === 1) {
                    throw new RuntimeException('Malformed plugin state change preflight output.');
                }
                if (!$state['allowed'] && $candidate['allowed']) {
                    throw new RuntimeException('Plugin state change denial cannot be reversed.');
                }
                $state = $candidate;
            } catch (Throwable $error) {
                error_log(sprintf(
                    '[plugin_state_change_preflight] priority %d listener %d: %s',
                    (int)$priority,
                    (int)$index,
                    $error->getMessage()
                ));
                if ($state['allowed']) $state = ['allowed' => false, 'message' => plugin_message('Plugin state change was denied.')];
            }
        }
    }
    if ($state['allowed']) return true;
    $message = trim($state['message']);
    $GLOBALS['_plugin_last_error'] = $message !== '' ? $message : plugin_message('Plugin state change was denied.');
    return false;
}

function plugin_lifecycle_locks(string $name): ?array {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) {
        $GLOBALS['_plugin_last_error'] = plugin_message('Invalid plugin name.');
        return null;
    }
    try {
        if (!function_exists('theme_operation_acquire')) {
            require_once dirname(__DIR__) . '/cfg/helpers/theme_helper.php';
        }
        return theme_operation_acquire(theme_lifecycle_lock_keys([$name]));
    } catch (Throwable $error) {
        error_log('[plugin_lifecycle_lock] ' . $error->getMessage());
        $GLOBALS['_plugin_last_error'] = plugin_message('Unable to lock plugin lifecycle operation.');
        return null;
    }
}

function plugin_disable(string $name): bool {
    $locks = plugin_lifecycle_locks($name);
    if ($locks === null) return false;
    try {
        return _plugin_disable_already_locked($name);
    } finally {
        theme_operation_release($locks);
    }
}

function _plugin_disable_already_locked(string $name): bool {
    if (!plugin_state_change_preflight($name, 'disable')) return false;
    $dependents = plugin_active_dependents($name);
    if ($dependents !== []) {
        $GLOBALS['_plugin_last_error'] = plugin_message(
            'Plugin "%s" cannot be deactivated because these active plugins depend on it: %s.',
            $name,
            implode(', ', $dependents)
        );
        return false;
    }
    $disabled = plugin_disabled_names();
    if (!in_array($name, $disabled, true)) $disabled[] = $name;
    $ok = _plugin_write_disabled_names_already_locked($disabled);
    $GLOBALS['_plugin_last_error'] = $ok ? '' : plugin_message('Failed to update plugin state.');
    if ($ok) plugin_reset_runtime_cache();
    return $ok;
}

/** Fail-safe state mutation for package publication; lifecycle preflight must not make staged code active. */
function _plugin_mark_disabled_already_locked(string $name): bool {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return false;
    $disabled = plugin_disabled_names();
    if (!in_array($name, $disabled, true)) $disabled[] = $name;
    $ok = _plugin_write_disabled_names_already_locked($disabled);
    $GLOBALS['_plugin_last_error'] = $ok ? '' : plugin_message('Failed to update plugin state.');
    if ($ok) plugin_reset_runtime_cache();
    return $ok;
}

function plugin_advance_reconciliation_state(string $name): bool {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return false;
    try {
        if (!class_exists('PluginStoreController', false)) require_once dirname(__DIR__) . '/app/controllers/PluginStoreController.php';
        return PluginStoreController::reconcileInstalledState($name);
    } catch (Throwable $error) {
        error_log('[plugin-reconciliation] ' . $error->getMessage());
        return false;
    }
}

function plugin_is_active(string $name): bool {
    return array_key_exists($name, plugins_active());
}

// --- Route / Nav / Asset aggregation ---

/** Reconcile plugin permission policy while preserving only delegable custom-role grants. */
function plugin_sync_permissions(PDO $pdo): array {
    if ($pdo->inTransaction()) {
        $message = 'Plugin permission synchronization requires a standalone transaction.';
        $GLOBALS['_plugin_ready_permissions'] = [];
        $GLOBALS['_plugin_permission_sync_errors'] = ['transaction' => $message];
        return [$message];
    }
    $desired = [];
    $installedPermissions = [];
    $installed = plugins_all();
    foreach ($installed as $manifest) {
        $contract = plugin_manifest_contract($manifest);
        if ($contract['errors'] === []) {
            foreach ($contract['permissions'] as $key => $permission) $installedPermissions[$key] = $permission;
        }
    }
    $GLOBALS['_plugin_permission_sync_errors'] = [];
    foreach (plugins_active() as $name => $manifest) {
        $contract = plugin_manifest_contract($manifest);
        if ($contract['errors'] !== []) {
            $GLOBALS['_plugin_permission_sync_errors'][$name] = implode('; ', $contract['errors']);
            continue;
        }
        foreach ($contract['permissions'] as $key => $permission) $desired[$key] = $permission;
    }

    $started = !$pdo->inTransaction();
    try {
        if ($started) $pdo->beginTransaction();
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $rows = $pdo->query(
            "SELECT permission_key, provider, resource, action, label, supports_scope, is_delegable, is_active
             FROM permissions
             WHERE permission_key LIKE 'plugin.%'" . $lock
        )->fetchAll(PDO::FETCH_ASSOC);
        $existing = [];
        foreach ($rows as $row) $existing[(string)$row['permission_key']] = $row;
        $oldPolicy = $existing;

        foreach ($installedPermissions + $desired as $key => $permission) {
            if (!isset($existing[$key])) continue;
            $row = $existing[$key];
            if ((string)$row['provider'] !== $permission['provider']
                || (string)$row['resource'] !== $permission['resource']
                || (string)$row['action'] !== $permission['action']
                || (int)$row['supports_scope'] !== (int)$permission['supports_scope']) {
                throw new RuntimeException('Plugin permission semantics conflict: ' . $key);
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO permissions (permission_key, provider, resource, action, label, supports_scope, is_delegable, is_active)
             VALUES (:permission_key, :provider, :resource, :action, :label, :supports_scope, :is_delegable, 1)'
        );
        $activate = $pdo->prepare('UPDATE permissions SET label = :label, is_delegable = :is_delegable, is_active = 1 WHERE permission_key = :permission_key');
        foreach ($desired as $key => $permission) {
            if (isset($existing[$key])) {
                if ((string)$existing[$key]['label'] !== $permission['label']
                    || (int)$existing[$key]['is_delegable'] !== (int)$permission['is_delegable']
                    || (int)$existing[$key]['is_active'] !== 1) {
                    $activate->execute([
                        ':label' => $permission['label'],
                        ':is_delegable' => $permission['is_delegable'] ? 1 : 0,
                        ':permission_key' => $key,
                    ]);
                }
            } else {
                $insert->execute([
                    ':permission_key' => $key,
                    ':provider' => $permission['provider'],
                    ':resource' => $permission['resource'],
                    ':action' => $permission['action'],
                    ':label' => $permission['label'],
                    ':supports_scope' => $permission['supports_scope'] ? 1 : 0,
                    ':is_delegable' => $permission['is_delegable'] ? 1 : 0,
                ]);
            }
        }

        $syncDisabledPolicy = $pdo->prepare('UPDATE permissions SET is_delegable = :is_delegable WHERE permission_key = :permission_key');
        foreach ($installedPermissions as $key => $permission) {
            if (!isset($desired[$key], $existing[$key])) {
                $syncDisabledPolicy->execute([
                    ':is_delegable' => $permission['is_delegable'] ? 1 : 0,
                    ':permission_key' => $key,
                ]);
            }
        }

        $deactivate = $pdo->prepare('UPDATE permissions SET is_active = 0 WHERE permission_key = :permission_key');
        $deletePermission = $pdo->prepare('DELETE FROM permissions WHERE permission_key = :permission_key');
        foreach ($existing as $key => $row) {
            if (isset($desired[$key])) continue;
            $provider = (string)$row['provider'];
            if (!isset($installed[$provider]) || !isset($installedPermissions[$key])) {
                $deletePermission->execute([':permission_key' => $key]);
            } elseif ((int)$row['is_active'] !== 0) {
                $deactivate->execute([':permission_key' => $key]);
            }
        }

        $roleRows = $pdo->query("SELECT id, slug, is_system FROM roles" . $lock)->fetchAll(PDO::FETCH_ASSOC);
        $systemRoles = [];
        $customRoleIds = [];
        foreach ($roleRows as $role) {
            if ((int)$role['is_system'] === 1 && in_array((string)$role['slug'], ['author', 'editor', 'admin'], true)) {
                $systemRoles[(string)$role['slug']] = (int)$role['id'];
            } elseif ((int)$role['is_system'] !== 1) {
                $customRoleIds[] = (int)$role['id'];
            }
        }
        $permissionKeys = array_values(array_unique(array_merge(array_keys($existing), array_keys($desired))));
        $deleteGrant = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id AND permission_key = :permission_key');
        $insertGrant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (:role_id, :permission_key, 'global')");
        foreach ($permissionKeys as $key) {
            foreach ($systemRoles as $roleId) $deleteGrant->execute([':role_id' => $roleId, ':permission_key' => $key]);
            foreach ($desired[$key]['default_roles'] ?? [] as $role) {
                if (isset($systemRoles[$role])) $insertGrant->execute([':role_id' => $systemRoles[$role], ':permission_key' => $key]);
            }
            $policy = $desired[$key] ?? $installedPermissions[$key] ?? null;
            if (is_array($policy) && $policy['is_delegable'] === false) {
                foreach ($customRoleIds as $roleId) $deleteGrant->execute([':role_id' => $roleId, ':permission_key' => $key]);
            }
        }

        if ($started) $pdo->commit();
        $GLOBALS['_plugin_ready_permissions'] = array_fill_keys(array_keys($desired), true);
        if ($started && function_exists('do_action')) {
            $newPolicy = [];
            foreach ($installedPermissions + $desired as $key => $permission) {
                $newPolicy[$key] = $permission + ['is_active' => isset($desired[$key])];
            }
            ksort($oldPolicy, SORT_STRING);
            ksort($newPolicy, SORT_STRING);
            // plugin_permissions_synced(old/new permission policy, actor ID, PDO)
            try {
                do_action(
                    'plugin_permissions_synced',
                    ['old' => $oldPolicy, 'new' => $newPolicy],
                    ((int)($_SESSION['user_id'] ?? 0)) ?: null,
                    $pdo
                );
            } catch (Throwable $hookError) {
                error_log('[plugin_permissions_synced] ' . $hookError->getMessage());
            }
        }
        return [];
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        $GLOBALS['_plugin_ready_permissions'] = [];
        $GLOBALS['_plugin_permission_sync_errors']['sync'] = $e->getMessage();
        error_log('[plugin-permissions] ' . $e->getMessage());
        return [$e->getMessage()];
    }
}

function plugin_admin_routes(): array {
    $routes = [];
    foreach (plugins_active() as $name => $p) {
        $pages = $p['admin']['pages'] ?? [];
        $base = PLUGIN_PATH . '/' . $name;
        foreach ($pages as $r) {
            $route = is_string($r['route'] ?? null) ? trim($r['route'], '/') : '';
            $file = is_string($r['file'] ?? null) ? plugin_safe_path($base, $r['file']) : null;
            if ($route === '' || preg_match('/\A[a-z0-9_-]+(?:\/[a-z0-9_-]+)*\z/', $route) !== 1
                || $file === null || !is_file($file) || is_link($file) || isset($routes[$route])) continue;
            $routes[$route] = [
                'route' => $route,
                'file' => $file,
                'title' => $r['title'] ?? $route,
                'roles' => $r['roles'] ?? ['admin'],
                'hidden' => $r['hidden'] ?? false,
                'permission' => is_string($r['permission'] ?? null) ? trim($r['permission']) : '',
                'site_owner' => ($r['site_owner'] ?? false) === true,
                'plugin' => $name,
            ];
        }
    }
    return $routes;
}

function plugin_nav_items(): array {
    $items = [];
    foreach (plugins_active() as $name => $p) {
        $nav = $p['admin']['nav'] ?? [];
        foreach ($nav as $n) {
            $n['plugin'] = $name;
            $items[] = $n;
        }
    }
    return $items;
}

function plugin_assets(): array {
    $assets = ['css' => [], 'js' => []];
    $coreDependencies = [
        'modal-helpers' => '/static/js/add/modal-helpers.js',
        'media-selector' => '/static/js/add/media-selector.js',
        'file-selector' => '/static/js/add/file-selector.js',
    ];
    foreach (plugins_active() as $name => $p) {
        $a = $p['assets'] ?? [];
        foreach (['css', 'js'] as $type) {
            foreach ($a[$type] ?? [] as $url) {
                $assets[$type][] = $url;
            }
        }
        foreach ($p['dependencies']['js'] ?? [] as $dependency) {
            if (is_string($dependency) && isset($coreDependencies[$dependency])) {
                $assets['js'][] = $coreDependencies[$dependency];
            }
        }
    }
    return ['css' => array_values(array_unique($assets['css'])), 'js' => array_values(array_unique($assets['js']))];
}

function plugin_resolve_route(string $route): ?array {
    $routes = plugin_admin_routes();
    return $routes[$route] ?? null;
}

/** Pure route authorization predicate used by dashboard navigation. */
function plugin_route_is_allowed(PDO $pdo, array $route, ?int $userId = null): bool {
    $actor = function_exists('authorization_actor') ? authorization_actor($pdo, $userId) : null;
    if ($actor === null) return false;
    if (($route['site_owner'] ?? false) === true) return $actor['is_site_owner'] === true;
    $permission = is_string($route['permission'] ?? null) ? trim($route['permission']) : '';
    if ($permission !== '') {
        return isset($GLOBALS['_plugin_ready_permissions'][$permission])
            && function_exists('user_can')
            && user_can($pdo, (int)$actor['id'], $permission);
    }
    if ($actor['is_site_owner'] === true) return true;
    $role = function_exists('authorization_active_legacy_role')
        ? authorization_active_legacy_role($pdo, (int)$actor['id'])
        : (string)($actor['legacy_role'] ?? 'none');
    $roles = is_array($route['roles'] ?? null) ? $route['roles'] : ['admin'];
    return in_array($role, $roles, true);
}

/**
 * Enforce Site Owner, permission, or legacy role access for a plugin route.
 */
function plugin_guard_route(PDO $pdo, array $route, bool $asJson = false): void {
    $GLOBALS['_plugin_current_route'] = $route;
    if (($route['site_owner'] ?? false) === true && function_exists('adiwira_require_site_owner')) {
        adiwira_require_site_owner($pdo, $asJson);
        return;
    }
    $permission = is_string($route['permission'] ?? null) ? trim($route['permission']) : '';
    if ($permission !== '' && function_exists('adiwira_require_permission')) {
        if (!isset($GLOBALS['_plugin_ready_permissions'][$permission])) {
            adiwira_require_permission($pdo, '__invalid_plugin_permission__', $asJson);
            return;
        }
        adiwira_require_permission($pdo, $permission, $asJson);
        $baseRoles = is_array($route['roles'] ?? null) ? $route['roles'] : ['admin'];
        $filteredRoles = apply_filters('plugin_page_roles', $baseRoles, $route);
        if ($filteredRoles !== $baseRoles && function_exists('adiwira_require_role')) {
            adiwira_require_role($pdo, is_array($filteredRoles) ? $filteredRoles : [], $asJson);
        }
        return;
    }
    $roles = $route['roles'] ?? ['admin'];
    $roles = apply_filters('plugin_page_roles', $roles, $route);

    if (function_exists('adiwira_require_role')) {
        adiwira_require_role($pdo, $roles, $asJson);
    }
}

function plugin_include_file(string $file): bool {
    if (is_file($file) && is_readable($file)) {
        require $file;
        return true;
    }
    return false;
}

// --- HTML escape helper (used by plugin admin pages) ---
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function plugin_uninstall_run_cleanup_listener(PDO $pdo, callable $listener, array $args, array $context): ?array {
    try {
        call_user_func($listener, ...$args);
        plugin_migrations_assert_clean_connection($pdo);
        return null;
    } catch (Throwable $error) {
        $recoveryError = null;
        try { plugin_migrations_recover_connection($pdo); }
        catch (Throwable $failedRecovery) { $recoveryError = $failedRecovery; }
        return $context + [
            'exception' => get_class($error),
            'message' => $error->getMessage()
                . ($recoveryError === null ? '' : ' Database recovery failed: ' . $recoveryError->getMessage()),
            'code' => $error->getCode(),
            'recovery_failed' => $recoveryError !== null,
        ];
    }
}

function plugin_uninstall_run_cleanup(PDO $pdo, string $name): array {
    $errors = [];
    foreach (array_merge(['plugin_uninstall'], _hook_legacy_aliases('plugin_uninstall')) as $hookName) {
        $hooks = $GLOBALS['_hooks']['actions'][$hookName] ?? [];
        ksort($hooks);
        foreach ($hooks as $priority => $listeners) {
            foreach ($listeners as $index => $listener) {
                $error = plugin_uninstall_run_cleanup_listener($pdo, $listener, [$name], [
                    'hook' => $hookName,
                    'priority' => (int)$priority,
                    'listener' => (int)$index,
                ]);
                if ($error !== null) $errors[] = $error;
                if (($error['recovery_failed'] ?? false) === true) return $errors;
            }
        }
    }
    try {
        plugin_migrations_assert_clean_connection($pdo);
    } catch (Throwable $error) {
        try { plugin_migrations_recover_connection($pdo); }
        catch (Throwable $recoveryError) {
            $error = new RuntimeException($error->getMessage() . ' Database recovery failed: ' . $recoveryError->getMessage(), 0, $error);
        }
        $errors[] = [
            'hook' => 'plugin_uninstall',
            'priority' => PHP_INT_MAX,
            'listener' => PHP_INT_MAX,
            'exception' => get_class($error),
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'recovery_failed' => false,
        ];
    }
    return $errors;
}

function plugin_uninstall(string $name, bool $keepData = true, ?PDO $pdo = null): bool {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return false;
    $pluginDir = PLUGIN_PATH . '/' . $name;
    if (!is_dir($pluginDir)) return false;

    $locks = plugin_lifecycle_locks($name);
    if ($locks === null) return false;
    try {
        if (!$keepData) {
            $recoveryPaths = package_publication_recovery_paths($pluginDir);
            if ($recoveryPaths !== []) {
                $GLOBALS['_plugin_last_error'] = plugin_message(
                    'Complete uninstall is blocked by a plugin publication recovery artifact: %s',
                    basename($recoveryPaths[0])
                );
                return false;
            }
        }
        if (!plugin_state_change_preflight($name, 'delete')) return false;

        $dependents = plugin_active_dependents($name);
        if ($dependents !== []) {
            $GLOBALS['_plugin_last_error'] = plugin_message(
                'Plugin "%s" cannot be uninstalled because these active plugins depend on it: %s.',
                $name,
                implode(', ', $dependents)
            );
            return false;
        }

        if (!$keepData) {
            if (!plugin_is_active($name) || ($GLOBALS['_plugin_loaded_entrypoints'][$name] ?? false) !== true) {
                $GLOBALS['_plugin_last_error'] = plugin_message(
                    'Complete uninstall requires an active plugin loaded successfully in this request. Activate it first, or uninstall while keeping data.'
                );
                return false;
            }
            $database = $pdo ?? (($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null);
            if (!$database instanceof PDO) {
                $GLOBALS['_plugin_last_error'] = plugin_message('A database connection is required for complete plugin uninstall.');
                return false;
            }
            if (!_plugin_mark_disabled_already_locked($name)) {
                return false;
            }
            try {
                // Clear history before destructive cleanup so any surviving or reinstalled package reruns schema setup.
                plugin_migrations_forget_already_locked($database, $name);
            } catch (Throwable $error) {
                $GLOBALS['_plugin_last_error'] = plugin_message('Failed to clear plugin migration history: %s', $error->getMessage());
                return false;
            }
            $hookErrors = plugin_uninstall_run_cleanup($database, $name);
            foreach ($hookErrors as $hookError) {
                error_log(sprintf(
                    "[plugin_uninstall] %s priority %d listener %d: %s",
                    $hookError['hook'],
                    $hookError['priority'],
                    $hookError['listener'],
                    $hookError['message']
                ));
            }
            if ($hookErrors !== []) {
                $GLOBALS['_plugin_last_error'] = plugin_message(
                    'Plugin data cleanup failed. Migration history was cleared and the plugin remains installed and inactive.'
                );
                return false;
            }
            try {
                plugin_migrations_assert_clean_connection($database);
            } catch (Throwable $error) {
                try { plugin_migrations_recover_connection($database); }
                catch (Throwable $recoveryError) { $error = $recoveryError; }
                $GLOBALS['_plugin_last_error'] = plugin_message('Plugin cleanup left an unsafe database state: %s', $error->getMessage());
                return false;
            }
        }

        return _plugin_delete_after_preflight($name);
    } finally {
        theme_operation_release($locks);
    }
}

// --- Delete plugin from disk ---
function plugin_delete(string $name): bool {
    if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) !== 1) return false;
    $pluginDir = PLUGIN_PATH . '/' . $name;
    if (!is_dir($pluginDir)) return false;

    $locks = plugin_lifecycle_locks($name);
    if ($locks === null) return false;
    try {
        if (!plugin_state_change_preflight($name, 'delete')) return false;
        return _plugin_delete_after_preflight($name);
    } finally {
        theme_operation_release($locks);
    }
}

function _plugin_delete_after_preflight(string $name): bool {
    $pluginDir = PLUGIN_PATH . '/' . $name;
    $pluginRoot = realpath(PLUGIN_PATH);
    $pluginReal = realpath($pluginDir);
    if ($pluginRoot === false || $pluginReal === false || is_link($pluginDir)
        || !str_starts_with($pluginReal, $pluginRoot . DIRECTORY_SEPARATOR)) return false;

    $dependents = plugin_active_dependents($name);
    if ($dependents !== []) {
        $GLOBALS['_plugin_last_error'] = plugin_message(
            'Plugin "%s" cannot be uninstalled because these active plugins depend on it: %s.',
            $name,
            implode(', ', $dependents)
        );
        return false;
    }
    if (!_plugin_mark_disabled_already_locked($name)) return false;

    // Load manifest for static.copy paths
    $manifest = plugin_manifest($name);
    $errors = [];

    // Remove static.copy files first
    if ($manifest && isset($manifest['static']['copy'])) {
        $staticEntries = $manifest['static']['copy'];
        if (!is_array($staticEntries)) {
            $errors[] = 'Invalid static.copy declaration';
        } else {
            $destinations = [];
            foreach ($staticEntries as $entry) {
                $dest = is_array($entry) && is_string($entry['to'] ?? null) ? $entry['to'] : '';
                $abs = plugin_static_path($name, $dest);
                if (!$abs || is_link($abs)) {
                    $errors[] = 'Invalid static destination ' . $dest;
                } else {
                    $destinations[$abs] = $dest;
                }
            }
            if ($errors === []) {
                foreach ($destinations as $abs => $dest) {
                    if (is_file($abs) && (!@unlink($abs) || file_exists($abs))) $errors[] = 'Failed to remove ' . $dest;
                    $parent = dirname($abs);
                    if (is_dir($parent) && count(scandir($parent)) <= 2) @rmdir($parent);
                }
            }
        }
    }
    if ($errors !== []) {
        foreach ($errors as $error) error_log("[plugin_delete] {$error}");
        $GLOBALS['_plugin_last_error'] = plugin_message('Failed to uninstall plugin.') . ' ' . implode('; ', $errors);
        return false;
    }

    // Remove the complete live tree first; partial cleanup must never remain discoverable.
    $phpDeleted = false;
    $quarantine = package_unique_publication_recovery_path($pluginDir, 'uninstall');
    if ($quarantine === null || !@rename($pluginDir, $quarantine)) {
        $errors[] = 'Failed to move the plugin tree out of service';
    } else {
        plugin_sync_directory($pluginRoot);
        $phpDeleted = package_remove_tree($quarantine);
        plugin_sync_directory($pluginRoot);
        if (!$phpDeleted) {
            $errors[] = 'Failed to remove quarantined plugin tree; recovery artifact: ' . basename($quarantine);
        }
    }

    // Remove disabled state only after the executable tree is definitely gone.
    if ($phpDeleted) {
        $disabled = plugin_disabled_names();
        $disabled = array_values(array_filter($disabled, fn($n) => $n !== $name));
        if (!_plugin_write_disabled_names_already_locked($disabled)) $errors[] = 'Failed to update plugin state';
    }
    if (!plugin_advance_reconciliation_state($name)) $errors[] = 'Failed to advance plugin reconciliation state';
    $ok = empty($errors);
    $GLOBALS['_plugin_last_error'] = $ok ? '' : plugin_message('Failed to uninstall plugin.') . ' ' . implode('; ', $errors);
    if ($ok) {
        plugin_reset_runtime_cache();
    }
    return $ok;
}

// --- Setup checks (for plugin detail page) ---
function plugin_checks(string $name): array {
    $manifest = plugin_manifest($name);
    if (!$manifest) return [];

    $results = plugin_requirement_checks($manifest);
    $pluginDir = PLUGIN_PATH . '/' . $name;
    foreach (($manifest['setup']['checks'] ?? []) as $i => $check) {
        $label = $check['label'] ?? 'Check ' . ($i + 1);
        $tip = $check['doc'] ?? '';
        $type = (string)($check['type'] ?? '');
        $path = plugin_safe_path($pluginDir, (string)($check['path'] ?? ''));
        $passed = match ($type) {
            'php_extension' => extension_loaded((string)($check['extension'] ?? '')),
            'file_exists' => $path !== null && is_file($path),
            'file_readable' => $path !== null && is_file($path) && is_readable($path),
            'file_writable' => $path !== null && is_file($path) && is_writable($path),
            'directory_exists' => $path !== null && is_dir($path),
            'directory_writable' => $path !== null && is_dir($path) && is_writable($path),
            default => false,
        };

        $results[] = [
            'label' => $label,
            'passed' => $passed,
            'command' => '',
            'doc' => $tip,
            'raw_output' => '',
        ];
    }

    return $results;
}
