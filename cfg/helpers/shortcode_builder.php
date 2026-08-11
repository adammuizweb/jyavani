<?php
declare(strict_types=1);
// cfg/helpers/shortcode_builder.php

if (defined('SHORTCODE_BUILDER_INCLUDED')) return;
define('SHORTCODE_BUILDER_INCLUDED', true);

final class ShortcodePresetDeletionBlockedException extends RuntimeException
{
}

final class ShortcodeCollectionLayoutLockException extends RuntimeException
{
}

/**
 * Serialize preset/layout decisions. MySQL connections use a connection-scoped
 * advisory lock; non-MySQL and fake PDOs use a deterministic process-local lock.
 */
function shortcode_collection_layout_with_lock(PDO $pdo, callable $operation, int $timeout = 5): mixed {
  static $localLocks = [];
  $timeout = max(0, min(30, $timeout));
  $lockName = 'jyavani:collection-layout:v1';
  $busyMessage = function_exists('__') ? __('Collection layout manager is busy. Try again shortly.') : 'Collection layout manager is busy. Try again shortly.';
  $driver = '';
  try {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  } catch (Throwable $error) {
    // Constructor-free test PDOs cannot expose driver attributes.
  }

  $advisory = $driver === 'mysql';
  $localKey = $lockName;
  $acquired = false;
  try {
    if ($advisory) {
      $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, :lock_timeout)');
      if (!$stmt || !$stmt->execute([':lock_name' => $lockName, ':lock_timeout' => $timeout]) || (int)$stmt->fetchColumn() !== 1) {
        throw new ShortcodeCollectionLayoutLockException($busyMessage);
      }
    } else {
      if (isset($localLocks[$localKey])) {
        throw new ShortcodeCollectionLayoutLockException($busyMessage);
      }
      $localLocks[$localKey] = true;
    }
    $acquired = true;
    return $operation();
  } finally {
    if ($acquired && $advisory) {
      try {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        if (!$release || !$release->execute([':lock_name' => $lockName]) || (int)$release->fetchColumn() !== 1) {
          error_log('collection layout advisory lock release was not confirmed');
        }
      } catch (Throwable $error) {
        error_log('collection layout advisory lock release failed: ' . $error->getMessage());
      }
    } elseif ($acquired) {
      unset($localLocks[$localKey]);
    }
  }
}

/**
 * Content writers acquire the collection lock before opening any DB transaction.
 * Hooks run after this boundary so listeners may safely perform layout operations.
 */
function shortcode_collection_layout_content_mutation(PDO $pdo, callable $operation): mixed {
  if ($pdo->inTransaction()) {
    throw new LogicException('Collection layout content mutations must acquire the layout lock before starting a transaction.');
  }
  return shortcode_collection_layout_with_lock($pdo, $operation);
}

function shortcode_collection_layout_builtin_names(): array {
  return ['cards', 'list', 'card2', 'sliderpage', 'grid', 'mini'];
}

function shortcode_collection_layout_name_is_valid(string $name): bool {
  return $name !== ''
    && strlen($name) <= 40
    && preg_match('/\A[a-z0-9_-]+\z/', $name) === 1;
}

function shortcode_collection_layout_filename(string $name): ?string {
  return shortcode_collection_layout_name_is_valid($name) ? $name . '.php' : null;
}

function shortcode_collection_layout_name_from_filename(string $filename): ?string {
  if ($filename === '' || basename($filename) !== $filename || !str_ends_with($filename, '.php')) return null;
  $name = substr($filename, 0, -4);
  return shortcode_collection_layout_filename($name) === $filename ? $name : null;
}

function shortcode_preset_default_config(?PDO $pdo = null): array {
  $defaults = [
    'source' => 'posts',
    'type' => 'article',
    'category' => '',
    'author' => null,
    'limit' => 5,
    'offset' => 0,
    'order_by' => 'created_at',
    'order_dir' => 'DESC',
    'layout' => 'list',
    'include_children' => '1',
    'excerpt_len' => 90,
    'class_prefix' => '',
    'wrap' => '1',
    'date_from' => null,
    'date_to' => null,
  ];
  $filtered = apply_filters('shortcode_preset_defaults', $defaults, $pdo);
  return is_array($filtered) ? $filtered : $defaults;
}

function shortcode_preset_config_loaded(string|array|null $stored, array $preset = [], ?PDO $pdo = null): array {
  $decoded = is_array($stored) ? $stored : json_decode((string)$stored, true);
  if (!is_array($decoded)) $decoded = [];
  $config = array_merge(shortcode_preset_default_config($pdo), $decoded);
  $filtered = apply_filters('shortcode_preset_config_loaded', $config, $preset, $pdo);
  return is_array($filtered) ? $filtered : $config;
}

function shortcode_preset_preview_config(array $config, array $context = [], ?PDO $pdo = null): array {
  $filtered = apply_filters('shortcode_preset_preview_config', $config, $context, $pdo);
  if (is_array($filtered)) $config = $filtered;
  do_action('shortcode_preset_preview_configured', $config, $context, $pdo);
  return $config;
}

/** Validate with the caller's role before exposing config to preview hooks. */
function shortcode_preset_prepare_preview_config(array $input, bool $isAdmin, array $context = [], ?PDO $pdo = null): array {
  $validation = shortcode_preset_validate_config($input, $isAdmin, $pdo, $context);
  if ($validation['errors'] !== []) return $validation;
  $previewConfig = shortcode_preset_preview_config($validation['config'], $context, $pdo);
  return shortcode_preset_validate_config($previewConfig, $isAdmin, $pdo, $context);
}

/** Plugins may return ['html' => string, 'mode' => string] for their own source. */
function shortcode_preset_preview_result(?array $result, array $config, array $context = [], ?PDO $pdo = null): ?array {
  $filtered = apply_filters('shortcode_preset_preview_result', $result, $config, $context, $pdo);
  if ($filtered === null) return null;
  if (!is_array($filtered) || !is_string($filtered['html'] ?? null)) {
    throw new UnexpectedValueException('Preset preview result filters must return null or an array containing html.');
  }
  $filtered['mode'] = is_string($filtered['mode'] ?? null) && $filtered['mode'] !== ''
    ? $filtered['mode']
    : 'preset_source';
  return $filtered;
}

function shortcode_preset_before_delete(PDO $pdo, int $presetId): void {
  if ($presetId <= 0 || !$pdo->inTransaction()) {
    throw new LogicException('Preset pre-delete hooks require an active transaction.');
  }
  try {
    do_action('admin_shortcode_preset_before_delete', $presetId, $pdo);
  } catch (Throwable $error) {
    error_log('admin_shortcode_preset_before_delete listener failed: ' . $error->getMessage());
    $message = function_exists('__')
      ? __('A dependent plugin prevented preset deletion.')
      : 'A dependent plugin prevented preset deletion.';
    throw new ShortcodePresetDeletionBlockedException($message, 0, $error);
  }
}

function shortcode_preset_slug_is_valid(string $slug): bool {
  return $slug !== '' && strlen($slug) <= 191 && preg_match('/\A[a-z0-9_-]+\z/', $slug) === 1;
}

function shortcode_preset_slugify(string $value): string {
  $value = trim($value);
  if (function_exists('mb_strtolower')) $value = mb_strtolower($value, 'UTF-8');
  else $value = strtolower($value);
  if (function_exists('iconv')) {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii)) $value = $ascii;
  }
  $value = strtolower((string)preg_replace('/[^a-z0-9_-]+/i', '-', $value));
  $value = trim((string)preg_replace('/[-_]{2,}/', '-', $value), '-_');
  return substr($value, 0, 191);
}

function shortcode_preset_list_filters(array $input, bool $isAdmin): array {
  $rawPage = is_string($input['p'] ?? null) ? trim($input['p']) : '1';
  $page = preg_match('/\A[1-9][0-9]*\z/', $rawPage) === 1 ? (int)$rawPage : 1;
  $status = is_string($input['status'] ?? null) ? $input['status'] : '';
  if (!in_array($status, ['draft', 'published', 'private'], true)) $status = '';
  $rawOwner = is_string($input['owner'] ?? null) ? $input['owner'] : '';
  $owner = $isAdmin && preg_match('/\A[1-9][0-9]*\z/', $rawOwner) === 1 ? (int)$rawOwner : 0;
  $query = is_string($input['q'] ?? null) ? trim($input['q']) : '';
  return [
    'p' => max(1, min(1000000, $page)),
    'q' => substr($query, 0, 191),
    'status' => $status,
    'owner' => $owner,
  ];
}

function shortcode_preset_list_spec(array $filters, int $uid, string $role): array {
  $where = ["p.type = 'sc_preset'", 'p.is_deleted = 0'];
  $params = [];
  if ($role !== 'admin') {
    $where[] = 'p.created_by = :uid';
    $params[':uid'] = $uid;
  } elseif ((int)($filters['owner'] ?? 0) > 0) {
    $where[] = 'p.created_by = :owner';
    $params[':owner'] = (int)$filters['owner'];
  }
  if (($filters['status'] ?? '') !== '') {
    $where[] = 'p.status = :status';
    $params[':status'] = (string)$filters['status'];
  }
  if (($filters['q'] ?? '') !== '') {
    $where[] = "(p.title LIKE :search ESCAPE '\\\\' OR p.slug LIKE :search ESCAPE '\\\\')";
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$filters['q']);
    $params[':search'] = '%' . $escaped . '%';
  }
  return ['where' => implode(' AND ', $where), 'params' => $params];
}

function shortcode_preset_date_is_valid(mixed $value): bool {
  if ($value === null || $value === '') return true;
  if (!is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) return false;
  return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Plugins may append parser-safe source identifiers; Core sources cannot be removed. */
function shortcode_preset_sources(array $context = [], ?PDO $pdo = null): array {
  $coreSources = ['posts', 'shop_products', 'shop_categories'];
  $filtered = apply_filters('shortcode_preset_sources', $coreSources, $context, $pdo);
  if (!is_array($filtered)) $filtered = [];
  $sources = $coreSources;
  foreach ($filtered as $source) {
    $source = strtolower(trim((string)$source));
    if (strlen($source) <= 64 && preg_match('/\A[a-z0-9_-]+\z/', $source) === 1) $sources[] = $source;
  }
  return array_values(array_unique($sources));
}

function shortcode_preset_validate_config(array $input, bool $isAdmin, ?PDO $pdo = null, array $context = []): array {
  $message = static fn(string $text): string => function_exists('__') ? __($text) : $text;
  $config = array_merge(shortcode_preset_default_config($pdo), $input);
  $errors = [];
  $source = is_string($config['source'] ?? null) ? strtolower(trim($config['source'])) : '';
  if (!in_array($source, shortcode_preset_sources($context, $pdo), true)) {
    $errors[] = $message('Invalid preset source.');
  }
  if (!$isAdmin && $source !== 'posts') {
    $errors[] = $message('Only administrators can use non-Core preset sources.');
  }
  $config['source'] = $source;

  $type = is_string($config['type'] ?? null) ? $config['type'] : '';
  if (!in_array($type, ['article', 'page'], true)) $errors[] = $message('Invalid post type.');
  $config['type'] = $type;

  foreach ([['limit', 1, 200], ['offset', 0, 1000000], ['excerpt_len', 10, 1000]] as [$key, $min, $max]) {
    $value = $config[$key] ?? null;
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) {
      $errors[] = sprintf($message('Invalid value for %s.'), $key);
    } else {
      $config[$key] = (int)$value;
    }
  }

  foreach (['include_children', 'wrap'] as $key) {
    $value = is_string($config[$key] ?? null) || is_int($config[$key] ?? null)
      ? (string)$config[$key]
      : '';
    if (!in_array($value, ['0', '1'], true)) $errors[] = sprintf($message('Invalid value for %s.'), $key);
    $config[$key] = $value;
  }

  $orderBy = is_string($config['order_by'] ?? null) ? $config['order_by'] : '';
  if (!in_array($orderBy, ['created_at', 'updated_at', 'sort_order', 'id', 'title', 'RAND()'], true)) {
    $errors[] = $message('Invalid preset ordering.');
  }
  $config['order_by'] = $orderBy;
  if ($orderBy === 'RAND()') {
    unset($config['order_dir']);
  } else {
    $orderDir = is_string($config['order_dir'] ?? null) ? strtoupper($config['order_dir']) : '';
    if (!in_array($orderDir, ['ASC', 'DESC'], true)) $errors[] = $message('Invalid preset order direction.');
    $config['order_dir'] = $orderDir;
  }

  $layout = is_string($config['layout'] ?? null) ? trim($config['layout']) : '';
  if (!shortcode_collection_layout_name_is_valid($layout)) {
    $errors[] = $message('Invalid layout name.');
  } elseif ($pdo instanceof PDO && function_exists('post_cat__find_layout_template') && !post_cat__find_layout_template($pdo, $layout)) {
    $errors[] = $message('Layout template not found.');
  }
  $config['layout'] = $layout;

  $category = is_string($config['category'] ?? null) ? trim($config['category']) : '';
  if ($category !== '' && (strlen($category) > 191 || preg_match('/[\x00-\x1F\x7F]/', $category) === 1)) {
    $errors[] = $message('Invalid category slug.');
  }
  $config['category'] = $category;

  $prefix = is_string($config['class_prefix'] ?? null) ? trim($config['class_prefix']) : '';
  if (strlen($prefix) > 80 || ($prefix !== '' && preg_match('/\A[a-zA-Z0-9_-]+\z/', $prefix) !== 1)) {
    $errors[] = $message('Invalid class prefix.');
  }
  $config['class_prefix'] = $prefix;

  foreach (['date_from', 'date_to'] as $key) {
    $value = $config[$key] ?? null;
    if (!shortcode_preset_date_is_valid($value)) $errors[] = sprintf($message('Invalid value for %s.'), $key);
    $config[$key] = ($value === '' ? null : $value);
  }
  if (($config['date_from'] ?? null) !== null && ($config['date_to'] ?? null) !== null && $config['date_from'] > $config['date_to']) {
    $errors[] = $message('Preset start date must not be after its end date.');
  }

  $author = $config['author'] ?? null;
  if ($author !== null && $author !== '') {
    if (!$isAdmin) {
      $errors[] = $message('Only administrators can set a preset author filter.');
    } elseif (filter_var($author, FILTER_VALIDATE_INT) === false || (int)$author <= 0) {
      $errors[] = $message('Invalid author.');
    } else {
      $config['author'] = (int)$author;
    }
  } else {
    $config['author'] = null;
  }

  $filtered = apply_filters('shortcode_preset_validation_errors', $errors, $config, $context, $pdo);
  return ['config' => $config, 'errors' => is_array($filtered) ? array_values($filtered) : $errors];
}

function shortcode_preset_slug_collision(PDO $pdo, string $slug, int $excludeId = 0): ?string {
  $sql = "SELECT id FROM posts WHERE slug = :slug AND type = 'sc_preset' AND is_deleted = 0";
  $params = [':slug' => $slug];
  if ($excludeId > 0) {
    $sql .= ' AND id != :id';
    $params[':id'] = $excludeId;
  }
  $stmt = $pdo->prepare($sql . ' LIMIT 1');
  $stmt->execute($params);
  if ($stmt->fetchColumn()) return 'preset';

  $registered = $GLOBALS['_widget_shortcode_handlers'][$slug] ?? null;
  if (is_array($registered) && (($registered['origin'] ?? 'static') !== 'preset' || (int)($registered['origin_id'] ?? 0) !== $excludeId)) {
    return 'registered_widget';
  }
  if (function_exists('widget_find_view') && widget_find_view($slug, $pdo) !== null) return 'widget_view';
  return null;
}

class ShortcodeQuery {
  private array $props = [
    'source' => 'posts',
    'type' => 'article',
    'status' => 'published',
    'limit' => 5,
    'offset' => 0,
    'layout' => 'list',
    'category' => null,
    'author' => null,
    'created_by' => null,
    'order_by' => 'created_at',
    'order_dir' => 'DESC',
    'include_children' => '1',
    'excerpt_len' => 90,
    'date_from' => null,
    'date_to' => null,
    'class_prefix' => '',
    'wrap' => '1',
  ];

  public static function posts(): self {
    return new self();
  }

  public function type(string $type): self {
    $this->props['type'] = in_array($type, ['article', 'page'], true) ? $type : 'article';
    return $this;
  }

  public function category(?string $slug): self {
    $this->props['category'] = ($slug !== null && $slug !== '') ? $slug : null;
    return $this;
  }

  public function author(int $userId): self {
    $this->props['author'] = $userId;
    $this->props['created_by'] = $userId;
    return $this;
  }

  public function limit(int $n): self {
    $this->props['limit'] = max(1, min(200, $n));
    return $this;
  }

  public function offset(int $n): self {
    $this->props['offset'] = max(0, $n);
    return $this;
  }

  public function random(): self {
    $this->props['order_by'] = 'RAND()';
    return $this;
  }

  public function latest(): self {
    $this->props['order_by'] = 'created_at';
    $this->props['order_dir'] = 'DESC';
    return $this;
  }

  public function oldest(): self {
    $this->props['order_by'] = 'created_at';
    $this->props['order_dir'] = 'ASC';
    return $this;
  }

  public function orderBy(string $column, string $dir = 'DESC'): self {
    $this->props['order_by'] = $column;
    $this->props['order_dir'] = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
    return $this;
  }

  public function dateFrom(string $date): self {
    $this->props['date_from'] = $date;
    return $this;
  }

  public function dateTo(string $date): self {
    $this->props['date_to'] = $date;
    return $this;
  }

  public function excerpt(int $len): self {
    $this->props['excerpt_len'] = max(10, min(1000, $len));
    return $this;
  }

  public function kicker(?string $text): self {
    $this->props['kicker'] = $text;
    return $this;
  }

  public function layout(string $layout): self {
    $this->props['layout'] = $layout;
    return $this;
  }

  public function status(string $status): self {
    $this->props['status'] = $status;
    return $this;
  }

  public function includeChildren(bool $v): self {
    $this->props['include_children'] = $v ? '1' : '0';
    return $this;
  }

  public function classPrefix(string $prefix): self {
    $this->props['class_prefix'] = $prefix;
    return $this;
  }

  public function wrap(bool $v): self {
    $this->props['wrap'] = $v ? '1' : '0';
    return $this;
  }

  public function buildAttrs(): array {
    $out = [];
    foreach ($this->props as $k => $v) {
      if ($v !== null) {
        $out[$k] = $v;
      }
    }
    return $out;
  }

  public function render(?PDO $pdo = null, array $ctx = []): string {
    $pdo = $pdo ?? (function_exists('widget_get_pdo') ? widget_get_pdo() : null);
    if (!$pdo instanceof PDO) return '';

    if (!function_exists('post_cat_shortcode_render')) {
      $helper = __DIR__ . '/widget_shortcodes_p.php';
      if (is_file($helper)) require_once $helper;
    }

    if (!function_exists('post_cat_shortcode_render')) return '';

    return post_cat_shortcode_render($pdo, $this->buildAttrs(), $ctx);
  }

  public function registerWidget(string $name): void {
    if (!function_exists('register_widget_shortcode_handler')) {
      $helper = __DIR__ . '/widget_helper.php';
      if (is_file($helper)) require_once $helper;
    }
    if (!function_exists('register_widget_shortcode_handler')) return;

    $attrs = $this->buildAttrs();
    register_widget_shortcode_handler($name, function(PDO $pdo, array $vars, array $ctx = []) use ($attrs) {
      if (!function_exists('post_cat_shortcode_render')) {
        $helper = __DIR__ . '/widget_shortcodes_p.php';
        if (is_file($helper)) require_once $helper;
      }
      if (!function_exists('post_cat_shortcode_render')) return '';
      return post_cat_shortcode_render($pdo, array_merge($attrs, $vars), $ctx);
    }, $attrs);
  }
}

if (!function_exists('load_preset_widgets')) {
  function load_preset_widgets(PDO $pdo): void {
    static $loadedConnections = [];
    $connectionId = spl_object_id($pdo);
    if (isset($loadedConnections[$connectionId])) return;

    $stmt = $pdo->prepare("SELECT id, slug, meta FROM posts WHERE type = 'sc_preset' AND status = 'published' AND is_deleted = 0 ORDER BY id ASC");
    $stmt->execute();
    $presets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($presets as $p) {
      $slug = (string)($p['slug'] ?? '');
      $presetId = (int)($p['id'] ?? 0);
      if (!shortcode_preset_slug_is_valid($slug)) continue;

      $registered = $GLOBALS['_widget_shortcode_handlers'][$slug] ?? null;
      if (is_array($registered) && ($registered['origin'] ?? 'static') !== 'preset') continue;
      if (is_array($registered)) continue;

      $config = shortcode_preset_config_loaded((string)($p['meta'] ?? '{}'), $p, $pdo);

      if (function_exists('register_widget_shortcode_handler')) {
        register_widget_shortcode_handler($slug, function(PDO $pdo, array $vars, array $ctx = []) use ($config, $p) {
          if (!function_exists('post_cat_shortcode_render')) {
            $helper = __DIR__ . '/widget_shortcodes_p.php';
            if (is_file($helper)) require_once $helper;
          }
          if (!function_exists('post_cat_shortcode_render')) return '';
          $runtimeConfig = apply_filters('shortcode_preset_runtime_config', $config, $p, $pdo, $ctx);
          if (!is_array($runtimeConfig)) $runtimeConfig = $config;
          do_action('shortcode_preset_runtime', $p, $runtimeConfig, $pdo, $ctx);
          return post_cat_shortcode_render($pdo, array_merge($runtimeConfig, $vars), $ctx);
        }, $config, 'preset', $presetId);
      }
    }
    $loadedConnections[$connectionId] = true;
  }
}
