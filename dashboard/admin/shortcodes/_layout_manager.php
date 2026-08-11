<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../cfg/helpers/shortcode_builder.php';

final class ShortcodeLayoutManagerException extends RuntimeException
{
}

function shortcode_layout_message(string $message, mixed ...$args): string
{
    $translated = function_exists('__') ? __($message) : $message;
    return $args === [] ? $translated : sprintf($translated, ...$args);
}

function shortcode_layout_builtin_names(): array
{
    return shortcode_collection_layout_builtin_names();
}

function shortcode_layout_name_is_valid(string $name, string $scope): bool
{
    if ($scope === 'section') {
        return function_exists('theme_section_name_is_valid') && theme_section_name_is_valid($name);
    }

    return shortcode_collection_layout_name_is_valid($name);
}

function shortcode_layout_file_is_valid(string $file, string $scope): bool
{
    if ($scope === 'collection') {
        return shortcode_collection_layout_name_from_filename($file) !== null;
    }
    if ($file === '' || basename($file) !== $file || !str_ends_with($file, '.php')) return false;
    $name = substr($file, 0, -4);
    return $file === $name . '.php' && shortcode_layout_name_is_valid($name, $scope);
}

function shortcode_layout_list_filters(array $input, string $scope): array
{
    $rawPage = is_string($input['p'] ?? null) ? trim($input['p']) : '1';
    $page = preg_match('/\A[1-9][0-9]*\z/', $rawPage) === 1 ? (int)$rawPage : 1;
    $query = is_string($input['q'] ?? null) ? trim($input['q']) : '';
    $query = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $query);
    $query = function_exists('mb_substr') ? mb_substr($query, 0, 120, 'UTF-8') : substr($query, 0, 120);
    $allowedFilters = $scope === 'section'
        ? ['registered', 'unregistered']
        : ['builtin', 'custom'];
    $filter = is_string($input['filter'] ?? null) ? $input['filter'] : '';
    if (!in_array($filter, $allowedFilters, true)) $filter = '';

    return [
        'p' => max(1, min(1000000, $page)),
        'q' => $query,
        'filter' => $filter,
    ];
}

function shortcode_layout_directory(PDO $pdo, string $scope): ?string
{
    if ($scope === 'section') {
        return function_exists('theme_section_theme_directory')
            ? theme_section_theme_directory($pdo)
            : null;
    }

    if (!defined('PUBLIC_PATH')) return null;
    $publicRoot = realpath((string)PUBLIC_PATH);
    if (!$publicRoot || !is_dir($publicRoot)) return null;

    $path = rtrim((string)PUBLIC_PATH, '/\\') . '/views/partials/shortcodes/post_cat';
    if (is_link($path)) return null;
    $real = realpath($path);
    return $real && is_dir($real) && shortcode_layout_path_is_within($real, $publicRoot) ? $real : null;
}

function shortcode_layout_path_is_within(string $path, string $directory): bool
{
    if (function_exists('theme_section_path_is_within')) {
        return theme_section_path_is_within($path, $directory);
    }
    $directory = rtrim($directory, DIRECTORY_SEPARATOR);
    return $path === $directory || str_starts_with($path, $directory . DIRECTORY_SEPARATOR);
}

function shortcode_layout_sync_directory(string $directory): void
{
    if (!function_exists('fsync')) return;
    $handle = @fopen($directory, 'rb');
    if (!is_resource($handle)) return;
    @fsync($handle);
    fclose($handle);
}

function shortcode_layout_installed_themes_root(): ?string
{
    if (!defined('VIEWS_BASE') || is_link((string)VIEWS_BASE)) return null;
    $root = realpath((string)VIEWS_BASE);
    if (!$root || !is_dir($root)) return null;
    $public = defined('PUBLIC_PATH') ? realpath((string)PUBLIC_PATH) : false;
    return $public && shortcode_layout_path_is_within($root, $public) ? $root : null;
}

function shortcode_layout_path_chain_has_symlink(string $path, string $root): bool
{
    if (!shortcode_layout_path_is_within($path, $root)) return true;
    $relative = ltrim(substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
    $candidate = rtrim($root, DIRECTORY_SEPARATOR);
    foreach ($relative === '' ? [] : explode(DIRECTORY_SEPARATOR, $relative) as $part) {
        $candidate .= DIRECTORY_SEPARATOR . $part;
        if (is_link($candidate)) return true;
    }
    return false;
}

function shortcode_layout_section_identity(string $directory): ?array
{
    $themesRoot = shortcode_layout_installed_themes_root();
    $directoryReal = !is_link($directory) ? realpath($directory) : false;
    if (!$themesRoot || !$directoryReal || !is_dir($directoryReal)
        || shortcode_layout_path_chain_has_symlink($directoryReal, $themesRoot)) return null;

    $relative = ltrim(substr($directoryReal, strlen($themesRoot)), DIRECTORY_SEPARATOR);
    $parts = explode(DIRECTORY_SEPARATOR, $relative);
    if (count($parts) !== 4 || array_slice($parts, 1) !== ['partials', 'shortcodes', 'section']) return null;
    $folder = $parts[0];
    if ($folder === '' || basename($folder) !== $folder || preg_match('/\A[a-zA-Z0-9_-]+\z/', $folder) !== 1) return null;
    return ['themes_root' => $themesRoot, 'theme_folder' => $folder];
}

function shortcode_layout_manifest_directory(PDO $pdo, string $scope, array $manifest): ?string
{
    if ($scope === 'collection') return shortcode_layout_directory($pdo, 'collection');
    if ($scope !== 'section') return null;
    $owner = is_array($manifest['owner'] ?? null) ? $manifest['owner'] : [];
    $root = shortcode_layout_installed_themes_root();
    $folder = is_string($owner['theme_folder'] ?? null) ? $owner['theme_folder'] : '';
    if (!$root || !is_string($owner['themes_root'] ?? null) || $owner['themes_root'] !== $root
        || $folder === '' || basename($folder) !== $folder || preg_match('/\A[a-zA-Z0-9_-]+\z/', $folder) !== 1) return null;
    $candidate = $root . DIRECTORY_SEPARATOR . $folder . '/partials/shortcodes/section';
    $identity = shortcode_layout_section_identity($candidate);
    return $identity === $owner ? realpath($candidate) ?: null : null;
}

function shortcode_layout_resolve_file(string $directory, string $file, string $scope): ?string
{
    if (!shortcode_layout_file_is_valid($file, $scope)) return null;
    $candidate = $directory . DIRECTORY_SEPARATOR . $file;
    if (is_link($candidate)) return null;
    $realPath = realpath($candidate);
    if (!$realPath || !is_file($realPath) || !shortcode_layout_path_is_within($realPath, $directory)) return null;
    return $realPath;
}

function shortcode_layout_collection_dependencies(PDO $pdo, array $names): array
{
    $names = array_values(array_unique(array_filter(
        $names,
        static fn(mixed $name): bool => is_string($name) && shortcode_collection_layout_name_is_valid($name)
    ), SORT_STRING));
    if ($names === []) return [];

    $stmt = $pdo->prepare("SELECT id, title, slug, type, meta, content FROM posts WHERE is_deleted = 0 AND type IN ('sc_preset', 'article', 'page', 'theme')");
    if (!$stmt || !$stmt->execute()) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Could not verify layout dependencies.'));
    }

    $dependencies = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $post) {
        if (($post['type'] ?? 'sc_preset') === 'sc_preset') {
            $config = json_decode((string)($post['meta'] ?? ''), true);
            $layout = is_array($config) && is_string($config['layout'] ?? null) ? $config['layout'] : '';
            if (in_array($layout, $names, true)) {
                $dependencies[$layout][] = [
                    'kind' => 'preset',
                    'id' => (int)($post['id'] ?? 0),
                    'title' => (string)($post['title'] ?? $post['slug'] ?? ''),
                ];
            }
        }

        $content = is_string($post['content'] ?? null) ? $post['content'] : '';
        if ($content === '' || !function_exists('post_cat_shortcode_references')) continue;
        foreach (post_cat_shortcode_references($content) as $reference) {
            $attrs = is_array($reference['attrs'] ?? null) ? $reference['attrs'] : [];
            $layout = is_string($attrs['layout'] ?? null) ? $attrs['layout'] : '';
            if (!in_array($layout, $names, true)) continue;
            $dependencies[$layout][] = [
                'kind' => (string)($reference['name'] ?? 'post_cat_shortcode'),
                'id' => (int)($post['id'] ?? 0),
                'title' => (string)($post['title'] ?? $post['slug'] ?? ''),
            ];
        }
    }

    // Dynamic sources can declare dependencies without pretending their data is Core content.
    $declared = apply_filters('shortcode_collection_layout_dependency_names', [], $names, $pdo);
    if (is_array($declared)) {
        foreach ($declared as $layout) {
            if (is_string($layout) && in_array($layout, $names, true)) {
                $dependencies[$layout][] = ['kind' => 'declared', 'id' => 0, 'title' => 'plugin'];
            }
        }
    }
    $filtered = apply_filters('shortcode_collection_layout_dependencies', $dependencies, $names, $pdo);
    if (is_array($filtered)) $dependencies = $filtered;
    foreach (array_keys($dependencies) as $layout) {
        if (!in_array($layout, $names, true) || !is_array($dependencies[$layout]) || $dependencies[$layout] === []) {
            unset($dependencies[$layout]);
        }
    }
    return $dependencies;
}

function shortcode_layout_quarantine_directory(): string
{
    $projectRoot = realpath(defined('SHORTCODE_LAYOUT_PROJECT_PATH')
        ? (string)SHORTCODE_LAYOUT_PROJECT_PATH
        : __DIR__ . '/../../..');
    $publicRoot = defined('PUBLIC_PATH') ? realpath((string)PUBLIC_PATH) : false;
    $configured = defined('SHORTCODE_LAYOUT_QUARANTINE_PATH')
        ? (string)SHORTCODE_LAYOUT_QUARANTINE_PATH
        : __DIR__ . '/../../../cfg/var/layout-quarantine';

    if (!$projectRoot || !$publicRoot || is_link($configured)) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout quarantine is unavailable.'));
    }
    if (!is_dir($configured) && !mkdir($configured, 0770, true) && !is_dir($configured)) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout quarantine is unavailable.'));
    }

    $real = realpath($configured);
    if (!$real || !is_dir($real) || !is_writable($real)
        || !shortcode_layout_path_is_within($real, $projectRoot)
        || shortcode_layout_path_is_within($real, $publicRoot)) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout quarantine is unavailable.'));
    }
    return $real;
}

function shortcode_layout_atomic_json_write(string $path, array $data): void
{
    $directory = realpath(dirname($path));
    if (!$directory || !is_dir($directory) || is_link($path)) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Failed to update layout quarantine operation.'));
    }
    $temporary = null;
    try {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . '.manifest-' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = @fopen($candidate, 'x+b');
            if (is_resource($handle)) {
                $temporary = $candidate;
                break;
            }
        }
        if (!isset($handle) || !is_resource($handle) || $temporary === null) {
            throw new RuntimeException('Could not create manifest temporary file.');
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $offset = 0;
        while ($offset < strlen($json)) {
            $written = fwrite($handle, substr($json, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Could not write complete manifest.');
            $offset += $written;
        }
        if (!fflush($handle)) throw new RuntimeException('Could not flush manifest.');
        if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Could not sync manifest.');
        @chmod($temporary, 0600);
        if (!fclose($handle)) throw new RuntimeException('Could not close manifest.');
        unset($handle);
        if (!rename($temporary, $path)) throw new RuntimeException('Could not publish manifest.');
        $temporary = null;
        shortcode_layout_sync_directory($directory);
    } catch (Throwable $error) {
        if (isset($handle) && is_resource($handle)) fclose($handle);
        if ($temporary !== null) @unlink($temporary);
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Failed to update layout quarantine operation.'), 0, $error);
    }
}

function shortcode_layout_remove_quarantine_stage(string $stage): void
{
    foreach (scandir($stage) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $stage . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path) && !is_link($path)) @unlink($path);
    }
    @rmdir($stage);
}

/** Must be called while holding shortcode_collection_layout_with_lock(). */
function shortcode_layout_recover_locked(PDO $pdo): void
{
    $root = shortcode_layout_quarantine_directory();
    foreach (scandir($root) ?: [] as $operation) {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $operation)) continue;
        $stage = $root . DIRECTORY_SEPARATOR . $operation;
        $manifestPath = $stage . '/manifest.json';
        if (!is_dir($stage) || is_link($stage) || !is_file($manifestPath) || is_link($manifestPath)) continue;
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest) || ($manifest['operation'] ?? '') !== $operation) continue;
        if (!in_array($manifest['state'] ?? '', ['staging', 'recovery_failed'], true)) continue;

        $scope = is_string($manifest['scope'] ?? null) ? $manifest['scope'] : '';
        $directory = shortcode_layout_manifest_directory($pdo, $scope, $manifest);
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $failed = !$directory || $files === [];
        foreach (array_reverse($files) as $entry) {
            $file = is_array($entry) && is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $source = $file !== '' && $directory ? $directory . DIRECTORY_SEPARATOR . $file : '';
            if ($source === '' && $scope === 'collection') {
                $source = is_array($entry) && is_string($entry['source'] ?? null) ? $entry['source'] : '';
                $file = basename($source);
            }
            $retained = is_array($entry) && is_string($entry['quarantined'] ?? null) ? $entry['quarantined'] : '';
            if ($source === '' || !preg_match('/\A[a-f0-9]{64}\.layout\z/', $retained)
                || !$directory || dirname($source) !== $directory || !shortcode_layout_file_is_valid($file, $scope)
                || shortcode_layout_path_chain_has_symlink($directory, $scope === 'section' ? (shortcode_layout_installed_themes_root() ?: '') : $directory)) {
                $failed = true;
                continue;
            }
            $staged = $stage . DIRECTORY_SEPARATOR . $retained;
            if (is_file($source)) {
                if (is_file($staged)) $failed = true;
                continue;
            }
            if (!is_file($staged) || is_link($staged) || !rename($staged, $source)) {
                $failed = true;
            } else {
                shortcode_layout_sync_directory($directory);
            }
        }
        if ($failed) {
            $manifest['state'] = 'recovery_failed';
            shortcode_layout_atomic_json_write($manifestPath, $manifest);
            error_log('shortcode layout quarantine recovery remains incomplete: ' . $operation);
        } else {
            @unlink($manifestPath);
            shortcode_layout_remove_quarantine_stage($stage);
        }
    }

    // Keep completed recoverable groups for 30 days, with at most 100 retained groups.
    $completed = [];
    foreach (scandir($root) ?: [] as $operation) {
        $stage = $root . DIRECTORY_SEPARATOR . $operation;
        $manifestPath = $stage . '/manifest.json';
        if (!preg_match('/\A[a-f0-9]{32}\z/', $operation) || !is_file($manifestPath)) continue;
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (is_array($manifest) && ($manifest['state'] ?? '') === 'quarantined') {
            $completed[$stage] = filemtime($manifestPath) ?: time();
        }
    }
    arsort($completed, SORT_NUMERIC);
    $position = 0;
    foreach ($completed as $stage => $modified) {
        $position++;
        if ($position > 100 || $modified < time() - 30 * 86400) shortcode_layout_remove_quarantine_stage($stage);
    }
}

function shortcode_layout_list(PDO $pdo, string $scope): array
{
    return shortcode_collection_layout_with_lock($pdo, static function () use ($pdo, $scope): array {
        shortcode_layout_recover_locked($pdo);
        $directory = shortcode_layout_directory($pdo, $scope);
        if (!$directory) return [];

        $definitions = $scope === 'section' && function_exists('theme_section_definitions')
            ? theme_section_definitions()
            : [];
        $layouts = [];
        foreach (scandir($directory) ?: [] as $file) {
            if (!shortcode_layout_file_is_valid($file, $scope)) continue;
            $path = shortcode_layout_resolve_file($directory, $file, $scope);
            if (!$path) continue;
            $name = substr($file, 0, -4);
            $layouts[] = [
                'file' => $file,
                'name' => $name,
                'path' => $path,
                'size' => filesize($path) ?: 0,
                'builtin' => $scope === 'collection' && in_array($name, shortcode_layout_builtin_names(), true),
                'registered' => $scope === 'section' && array_key_exists($name, $definitions),
            ];
        }
        usort($layouts, static fn(array $a, array $b): int => strcmp($a['file'], $b['file']));
        return $layouts;
    });
}

function shortcode_layout_atomic_save(PDO $pdo, string $scope, string $existingFile, string $newName, string $content): array
{
    return shortcode_collection_layout_with_lock($pdo, static function () use ($pdo, $scope, $existingFile, $newName, $content): array {
        shortcode_layout_recover_locked($pdo);
        if (!in_array($scope, ['collection', 'section'], true) || trim($content) === '') {
            throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid layout content.'));
        }
        $directory = $scope === 'section' && function_exists('theme_section_theme_directory')
            ? theme_section_theme_directory($pdo, true)
            : shortcode_layout_directory($pdo, 'collection');
        $directory = $directory ? realpath($directory) : false;
        if (!$directory || !is_dir($directory) || is_link($directory) || !is_writable($directory)) {
            throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout directory is unavailable.'));
        }

        $isNew = $existingFile === '';
        if ($isNew) {
            if (!shortcode_layout_name_is_valid($newName, $scope)) {
                throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid layout name.'));
            }
            $file = $scope === 'collection' ? shortcode_collection_layout_filename($newName) : $newName . '.php';
            if (!is_string($file)) throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid layout name.'));
            $target = $directory . DIRECTORY_SEPARATOR . $file;
            if (file_exists($target) || is_link($target)) {
                throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout file "%s" already exists.', $file));
            }
            $mode = 0644;
        } else {
            if (!shortcode_layout_file_is_valid($existingFile, $scope)) {
                throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid file path.'));
            }
            $target = shortcode_layout_resolve_file($directory, $existingFile, $scope);
            if (!$target || is_link($target)) throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid file path.'));
            $file = $existingFile;
            $mode = ((int)fileperms($target) & 0666) & ~0002;
            if ($mode === 0) $mode = 0644;
        }

        $temporary = null;
        try {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $candidate = $directory . DIRECTORY_SEPARATOR . '.layout-save-' . bin2hex(random_bytes(8)) . '.tmp';
                $handle = @fopen($candidate, 'x+b');
                if (is_resource($handle)) {
                    $temporary = $candidate;
                    break;
                }
            }
            if (!isset($handle) || !is_resource($handle) || $temporary === null) throw new RuntimeException('Could not create layout temporary file.');
            $offset = 0;
            while ($offset < strlen($content)) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Could not write complete layout.');
                $offset += $written;
            }
            if (!fflush($handle)) throw new RuntimeException('Could not flush layout.');
            if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Could not sync layout.');
            if (!chmod($temporary, $mode)) throw new RuntimeException('Could not set safe layout permissions.');
            if (!fclose($handle)) throw new RuntimeException('Could not close layout.');
            unset($handle);

            if (($isNew && (file_exists($target) || is_link($target)))
                || (!$isNew && (is_link($target) || realpath($target) !== $target))) {
                throw new RuntimeException('Layout target changed during save.');
            }
            if (!rename($temporary, $target)) throw new RuntimeException('Could not atomically publish layout.');
            $temporary = null;
            shortcode_layout_sync_directory($directory);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
        } catch (Throwable $error) {
            if (isset($handle) && is_resource($handle)) fclose($handle);
            if ($temporary !== null) @unlink($temporary);
            throw new ShortcodeLayoutManagerException(shortcode_layout_message('Failed to save layout file.'), 0, $error);
        }
        return ['file' => $file, 'name' => substr($file, 0, -4), 'path' => $target, 'new' => $isNew];
    });
}

/**
 * Validate every target, then move files under one operation lock. Group visibility
 * is recoverable, not filesystem-atomic: interrupted staging is restored next time.
 */
function shortcode_layout_delete_files(PDO $pdo, string $scope, array $files): int
{
    return shortcode_collection_layout_with_lock($pdo, static function () use ($pdo, $scope, $files): int {
    shortcode_layout_recover_locked($pdo);
    if (!in_array($scope, ['collection', 'section'], true)) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid layout scope.'));
    }
    if ($files === [] || count($files) > 1000) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid layout selection.'));
    }

    foreach ($files as $file) {
        if (!is_string($file)) throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid layout selection.'));
    }
    $files = array_values(array_unique($files, SORT_STRING));
    $directory = shortcode_layout_directory($pdo, $scope);
    if (!$directory || !is_dir($directory) || !is_writable($directory)) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout directory is unavailable.'));
    }

    $targets = [];
    foreach ($files as $file) {
        if (!shortcode_layout_file_is_valid($file, $scope)) {
            throw new ShortcodeLayoutManagerException(shortcode_layout_message('Invalid layout selection.'));
        }
        $name = substr($file, 0, -4);
        if ($scope === 'collection' && in_array($name, shortcode_layout_builtin_names(), true)) {
            throw new ShortcodeLayoutManagerException(shortcode_layout_message('Default layout cannot be deleted.'));
        }
        $realPath = shortcode_layout_resolve_file($directory, $file, $scope);
        if (!$realPath) throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout file not found.'));
        $targets[$file] = $realPath;
    }

    if ($scope === 'collection') {
        $dependencies = shortcode_layout_collection_dependencies(
            $pdo,
            array_map(static fn(string $file): string => substr($file, 0, -4), array_keys($targets))
        );
        if ($dependencies !== []) {
            $layout = (string)array_key_first($dependencies);
            $kind = (string)($dependencies[$layout][0]['kind'] ?? 'dependency');
            throw new ShortcodeLayoutManagerException(shortcode_layout_message(
                $kind === 'preset'
                    ? 'Layout "%s" is used by an active preset and cannot be removed.'
                    : 'Layout "%s" has an active content or plugin dependency and cannot be removed.',
                $layout
            ));
        }
    }

    $quarantineRoot = shortcode_layout_quarantine_directory();
    $operationId = bin2hex(random_bytes(16));
    $stage = $quarantineRoot . DIRECTORY_SEPARATOR . $operationId;
    if (!mkdir($stage, 0700) || !is_dir($stage) || !shortcode_layout_path_is_within($stage, $quarantineRoot)) {
        throw new ShortcodeLayoutManagerException(shortcode_layout_message('Failed to create layout quarantine operation.'));
    }
    $manifestFiles = [];
    foreach ($targets as $file => $source) {
        $manifestFiles[] = ['file' => $file, 'source' => $source, 'quarantined' => hash('sha256', $file) . '.layout'];
    }
    $manifest = [
        'operation' => $operationId,
        'scope' => $scope,
        'state' => 'staging',
        'files' => $manifestFiles,
    ];
    if ($scope === 'section') {
        $owner = shortcode_layout_section_identity($directory);
        if ($owner === null) {
            shortcode_layout_remove_quarantine_stage($stage);
            throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout directory is outside the installed theme root.'));
        }
        $manifest['owner'] = $owner;
    }
    try {
        shortcode_layout_atomic_json_write($stage . '/manifest.json', $manifest);
    } catch (Throwable $error) {
        @rmdir($stage);
        throw $error;
    }

    $moved = [];
    try {
        foreach ($manifestFiles as $entry) {
            $source = $entry['source'];
            $staged = $stage . DIRECTORY_SEPARATOR . $entry['quarantined'];
            if (!rename($source, $staged)) {
                throw new ShortcodeLayoutManagerException(shortcode_layout_message('Failed to move layouts to quarantine.'));
            }
            shortcode_layout_sync_directory($directory);
            shortcode_layout_sync_directory($stage);
            $moved[$source] = $staged;
        }

        $manifest['state'] = 'quarantined';
        shortcode_layout_atomic_json_write($stage . '/manifest.json', $manifest);
    } catch (Throwable $error) {
        $rollbackFailed = false;
        foreach (array_reverse($moved, true) as $source => $staged) {
            if (is_file($staged) && !file_exists($source) && !rename($staged, $source)) {
                error_log('shortcode layout delete rollback failed: ' . $source);
                $rollbackFailed = true;
            } elseif (is_file($source)) {
                shortcode_layout_sync_directory($directory);
            }
        }
        if ($rollbackFailed) {
            $manifest['state'] = 'recovery_failed';
            try {
                shortcode_layout_atomic_json_write($stage . '/manifest.json', $manifest);
            } catch (Throwable $manifestError) {
                error_log('shortcode layout recovery manifest update failed: ' . $manifestError->getMessage());
            }
            throw new ShortcodeLayoutManagerException(shortcode_layout_message('Layout removal failed and rollback was incomplete.'), 0, $error);
        }
        @unlink($stage . '/manifest.json');
        shortcode_layout_remove_quarantine_stage($stage);
        throw $error;
    }
    return count($targets);
    });
}
