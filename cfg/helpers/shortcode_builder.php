<?php
declare(strict_types=1);
// cfg/helpers/shortcode_builder.php

if (defined('SHORTCODE_BUILDER_INCLUDED')) return;
define('SHORTCODE_BUILDER_INCLUDED', true);

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

    $stmt = $pdo->prepare("SELECT slug, meta FROM posts WHERE type = 'sc_preset' AND status = 'published' AND is_deleted = 0");
    $stmt->execute();
    $presets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($presets as $p) {
      $slug = (string)($p['slug'] ?? '');
      if ($slug === '') continue;

      $config = json_decode((string)($p['meta'] ?? '{}'), true);
      if (!is_array($config)) $config = [];

      if (function_exists('register_widget_shortcode_handler')) {
        register_widget_shortcode_handler($slug, function(PDO $pdo, array $vars, array $ctx = []) use ($config) {
          if (!function_exists('post_cat_shortcode_render')) {
            $helper = __DIR__ . '/widget_shortcodes_p.php';
            if (is_file($helper)) require_once $helper;
          }
          if (!function_exists('post_cat_shortcode_render')) return '';
          return post_cat_shortcode_render($pdo, array_merge($config, $vars), $ctx);
        }, $config);
      }
    }
    $loadedConnections[$connectionId] = true;
  }
}
