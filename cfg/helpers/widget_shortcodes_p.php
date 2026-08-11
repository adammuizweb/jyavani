<?php
declare(strict_types=1);
// lokasi file cfg/helpers/widget_shortcodes_p.php

if (defined('POST_CAT_SHORTCODES_INCLUDED')) return;
define('POST_CAT_SHORTCODES_INCLUDED', true);

/**
 * Engine shortcode konten utama CMS.
 *
 * Fokus utama:
 * - posts/content shortcode (aktif dipakai sekarang)
 * - future-ready hook untuk source shop (opsional, tidak aktif jika helper shop belum ada)
 *
 * Contoh:
 * [post_cat_shortcode category="news" layout="cards" limit="3" include_children="1" class_prefix="inter-news"]
 * [post_cat_shortcode category="agenda" layout="list" limit="8"]
 *
 * Future-ready:
 * [post_cat_shortcode source="shop_products" category="boneka" layout="cards" limit="5" fetch="7" slider="1" infinite="1"]
 * [post_cat_shortcode source="shop_categories" category="boneka" layout="collections" limit="3"]
 * [post_cat_shortcode source="shop_categories" layout="collections_main" slugs="aksesoris,boneka,dekorasi,fashion" limit="4"]
 * 
 *   - per shortcode: post_path="/"
 *   - via context map: $ctx['post_cat_path_map'] = ['news'=>'/','agenda'=>'/']
 *   - global fallback: $ctx['post_path'] (default '/')
 */

function post_cat__parse_attrs(string $attrRaw): array {
  $attrs = [];
  if ($attrRaw !== '') {
    preg_match_all('/(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))/', $attrRaw, $mm, PREG_SET_ORDER);
    foreach ($mm as $a) {
      $k = strtolower((string)$a[1]);
      $v = $a[3] ?? $a[4] ?? $a[5] ?? '';
      $attrs[$k] = $v;
    }
  }
  return $attrs;
}

function post_cat_shortcode_normalize_brackets(string $content): string {
  return (string)preg_replace_callback(
    '/&#(?:0*91|x0*5b|0*93|x0*5d);/i',
    static fn(array $match): string => preg_match('/(?:91|5b);$/i', $match[0]) === 1 ? '[' : ']',
    $content
  );
}

/** Return direct and widget collection shortcodes with their explicitly parsed attrs. */
function post_cat_shortcode_references(string $content): array {
  $content = post_cat_shortcode_normalize_brackets($content);
  $references = [];
  if (preg_match_all('/\[post_cat_shortcode\b([^\]]*)\]/i', $content, $direct, PREG_SET_ORDER)) {
    foreach ($direct as $match) {
      $references[] = ['name' => 'post_cat_shortcode', 'attrs' => post_cat__parse_attrs(trim((string)($match[1] ?? '')))];
    }
  }
  if (preg_match_all('/\[\[\s*widget:(post_cat_shortcode|post_list|post_cards|post_slider)\s*([^\]]*)\]\]/i', $content, $widgets, PREG_SET_ORDER)) {
    foreach ($widgets as $match) {
      $attributeText = trim((string)($match[2] ?? ''));
      $references[] = [
        'name' => strtolower((string)$match[1]),
        'attrs' => function_exists('widget_parse_attrs') ? widget_parse_attrs($attributeText) : post_cat__parse_attrs($attributeText),
      ];
    }
  }
  return $references;
}

function post_cat__bool($v, bool $default = false): bool {
  if ($v === null) return $default;
  $s = strtolower(trim((string)$v));
  if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;
  if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
  return $default;
}

function post_cat__slug(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (function_exists('cms_slugify')) return (string)cms_slugify($s);

  $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  $s = preg_replace('~[^\pL\pN]+~u', '-', $s);
  return trim((string)$s, '-');
}

function post_cat__safe_layout(string $layout): string {
  $layout = trim($layout);
  $valid = function_exists('shortcode_collection_layout_name_is_valid')
    ? shortcode_collection_layout_name_is_valid($layout)
    : ($layout !== '' && strlen($layout) <= 40 && preg_match('/\A[a-z0-9_-]+\z/', $layout) === 1);
  return $valid ? $layout : 'cards';
}

function post_cat__safe_source(string $source, array $context = [], ?PDO $pdo = null): string {
  $source = strtolower(trim($source));
  $sources = function_exists('shortcode_preset_sources')
    ? shortcode_preset_sources($context, $pdo)
    : ['posts', 'shop_products', 'shop_categories'];
  return in_array($source, $sources, true) ? $source : 'posts';
}

function post_cat__resolve_kicker(array $attrs, string $category): string {
  if (array_key_exists('kicker', $attrs)) return trim((string)$attrs['kicker']);
  $category = trim($category);
  if ($category === '') return '';
  $key = post_cat__slug($category);
  if ($key === '') $key = strtolower($category);
  return strtoupper(str_replace(['-', '_'], ' ', $key));
}

function post_cat__excerpt(string $html, int $maxLen): string {
  $noBlock = (string)preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
  $noBlock = (string)preg_replace('/<style[^>]*>.*?<\/style>/si', '', $noBlock);
  $txt = trim((string)preg_replace('/\s+/', ' ', strip_tags($noBlock)));
  if ($maxLen < 10) $maxLen = 10;

  if (function_exists('mb_strlen')) {
    if (mb_strlen($txt, 'UTF-8') > $maxLen) {
      return mb_substr($txt, 0, $maxLen - 1, 'UTF-8') . '…';
    }
    return $txt;
  }

  if (strlen($txt) > $maxLen) return substr($txt, 0, $maxLen - 1) . '…';
  return $txt;
}

function post_cat__join_url(string $baseUrl, string $path, string $slug): string {
  $baseUrl = rtrim($baseUrl, '/');
  $path = '/' . trim($path, '/');
  $path = rtrim($path, '/') . '/';

  $slug = trim($slug, '/');
  if ($slug === '') {
    return $baseUrl . $path;
  }

  return $baseUrl . $path . rawurlencode($slug) . '/';
}

function post_cat__empty_html(string $message, string $classPrefix = ''): string {
  $cls = 'pcat__empty';
  if ($classPrefix !== '') {
    $cls .= ' ' . htmlspecialchars($classPrefix, ENT_QUOTES, 'UTF-8');
  }
  return '<div class="' . $cls . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * Cari template layout shortcode.
 * Prioritas:
 * 1) views/partials/shortcodes/post_cat/<layout>.php   (GLOBAL)
 * 2) views/themes/<folder>/partials/shortcodes/post_cat/<layout>.php  (theme override)
 */
function post_cat__find_layout_template(PDO $pdo, string $layout): ?string {
  if (post_cat__safe_layout($layout) !== $layout) return null;
  $rel = 'partials/shortcodes/post_cat/' . $layout . '.php';

  // 1) Global path
  $globalBase = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : null;
  if ($globalBase) {
    $publicReal = realpath($globalBase);
    $globalDirectory = rtrim($globalBase, "/\\") . DIRECTORY_SEPARATOR
      . 'views' . DIRECTORY_SEPARATOR
      . 'partials' . DIRECTORY_SEPARATOR . 'shortcodes' . DIRECTORY_SEPARATOR . 'post_cat';
    $directoryReal = !is_link($globalDirectory) ? realpath($globalDirectory) : false;
    $globalPath = $globalDirectory . DIRECTORY_SEPARATOR . $layout . '.php';
    $real = realpath($globalPath);
    $withinPublic = $publicReal && $directoryReal
      && ($directoryReal === $publicReal || str_starts_with($directoryReal, rtrim($publicReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
    $withinDirectory = $directoryReal && $real
      && str_starts_with($real, rtrim($directoryReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    if ($withinPublic && $withinDirectory && !is_link($globalPath) && is_file($real)) {
      return $real;
    }
  }

  // 2) Theme-specific fallback
  if (!defined('VIEWS_BASE')) return null;

  $folders = [];
  if (function_exists('get_relevant_theme_folders')) {
    $folders = get_relevant_theme_folders($pdo, null);
    $folders = array_reverse($folders);
  } else {
    $folders = [defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default'];
  }

  $baseReal = realpath((string)VIEWS_BASE) ?: null;

  foreach ($folders as $folder) {
    $candidate = function_exists('path_candidate')
      ? path_candidate((string)VIEWS_BASE, (string)$folder, $rel)
      : rtrim((string)VIEWS_BASE, "/\\") . DIRECTORY_SEPARATOR
          . trim((string)$folder, "/\\") . DIRECTORY_SEPARATOR
          . str_replace('/', DIRECTORY_SEPARATOR, $rel);

    $candidateDirectory = dirname($candidate);
    $directoryReal = !is_link($candidateDirectory) ? realpath($candidateDirectory) : false;
    $real = !is_link($candidate) ? realpath($candidate) : false;
    if ($real && $baseReal && $directoryReal
        && str_starts_with($directoryReal, rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        && str_starts_with($real, rtrim($directoryReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        && is_file($real)) {
      return $real;
    }
  }

  return null;
}

function post_cat__layout_names(PDO $pdo): array {
  $names = shortcode_collection_layout_builtin_names();
  $directories = [defined('PUBLIC_PATH') ? realpath(PUBLIC_PATH . '/views/partials/shortcodes/post_cat') : false];
  if (defined('VIEWS_BASE')) {
    $folders = function_exists('get_relevant_theme_folders')
      ? get_relevant_theme_folders($pdo, null)
      : [defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default'];
    foreach ($folders as $folder) {
      $directories[] = realpath(rtrim((string)VIEWS_BASE, '/\\') . '/' . $folder . '/partials/shortcodes/post_cat');
    }
  }

  foreach (array_filter(array_unique($directories)) as $directory) {
    if (is_link($directory)) continue;
    foreach (scandir($directory) ?: [] as $filename) {
      $name = shortcode_collection_layout_name_from_filename($filename);
      if ($name !== null) $names[] = $name;
    }
  }

  $names = array_values(array_unique(array_filter(
    $names,
    static fn(string $name): bool => post_cat__find_layout_template($pdo, $name) !== null
  )));
  sort($names, SORT_STRING);
  return $names;
}

function post_cat__render_template(string $path, array $vars): string {
  if (!is_file($path)) return '';

  ob_start();
  (function($__path, $__vars) {
    extract($__vars, EXTR_SKIP);
    include $__path;
  })($path, $vars);

  return (string)ob_get_clean();
}

function post_cat_shortcode_render(PDO $pdo, array $attrs, array $ctx = []): string {
  $source = post_cat__safe_source((string)($attrs['source'] ?? 'posts'), ['scope' => 'runtime', 'attrs' => $attrs], $pdo);

  $catRaw = (string)($attrs['category'] ?? 'news');
  $catKey = post_cat__slug($catRaw);
  if ($catKey === '') $catKey = strtolower(trim($catRaw)) ?: 'news';

  $layoutMap = (isset($ctx['post_cat_layout_map']) && is_array($ctx['post_cat_layout_map']))
    ? $ctx['post_cat_layout_map']
    : [];

  $layoutRaw = (string)($attrs['layout'] ?? ($layoutMap[$catKey] ?? 'cards'));
  $layoutSan = post_cat__safe_layout($layoutRaw);

  $tplCandidate = post_cat__find_layout_template($pdo, $layoutSan);
  $layout = $tplCandidate ? $layoutSan : 'cards';

  $limitVisible = (int)($attrs['limit'] ?? $attrs['visible'] ?? $attrs['slides_per_view'] ?? 3);
  if ($limitVisible < 1) $limitVisible = 3;

  $fetch = (int)($attrs['fetch'] ?? $attrs['max_show'] ?? $attrs['max'] ?? 0);
  if ($fetch < 0) $fetch = 0;

  $limit = max($limitVisible, $fetch);
  if ($limit < 1) $limit = 6;
  $limit = min(200, $limit);

  $offset = max(0, (int)($attrs['offset'] ?? 0));

  $classPrefix = trim((string)($attrs['class_prefix'] ?? ''));
  $wrap = post_cat__bool($attrs['wrap'] ?? '1', true);

  $sliderEnabled = post_cat__bool(
    $attrs['slider'] ?? $attrs['carousel'] ?? ($source === 'shop_products' ? '1' : '0'),
    $source === 'shop_products'
  );

  $infinite = post_cat__bool($attrs['infinite'] ?? '1', true);

  $baseUrl = rtrim((string)($ctx['base_url'] ?? ''), '/');
  $kicker = post_cat__resolve_kicker($attrs, $catRaw);

  $excerptLen = (int)($attrs['excerpt'] ?? $attrs['excerpt_len'] ?? 90);
  if ($excerptLen < 20) $excerptLen = 90;

  $dateFormat = (string)($attrs['date_format'] ?? 'd M Y');
  $items = [];

  /**
   * SOURCE: CONTENT POSTS (utama CMS)
   */
  if ($source === 'posts') {
    if (!function_exists('cms_posts_by_category')) {
      return post_cat__empty_html('Helper konten belum tersedia.', $classPrefix);
    }

      $postType = (($attrs['type'] ?? 'article') === 'page') ? 'page' : 'article';
      $posts = cms_posts_by_category($pdo, $catRaw, [
        'type' => $postType,
        'status' => 'published',
        'include_children' => (($attrs['include_children'] ?? '1') !== '0'),
        'limit' => $limit,
        'offset' => $offset,
        'order_by' => $attrs['order_by'] ?? 'created_at',
        'order_dir' => $attrs['order_dir'] ?? 'DESC',
        'created_by' => isset($attrs['author']) ? (int)$attrs['author'] : (isset($attrs['created_by']) ? (int)$attrs['created_by'] : null),
        'date_from' => $attrs['date_from'] ?? $attrs['date_after'] ?? null,
        'date_to' => $attrs['date_to'] ?? $attrs['date_before'] ?? null,
        'collection_context' => [
          'scope' => 'post_category_shortcode',
          'table_alias' => 'p',
          'required_translation_fields' => ['title', 'slug', 'content'],
          'category' => $catRaw,
          'layout' => $layout,
          'source' => $source,
        ],
      ]);

    if (!$posts) {
      return post_cat__empty_html('Belum ada konten.', $classPrefix);
    }

    $pathMap = (isset($ctx['post_cat_path_map']) && is_array($ctx['post_cat_path_map']))
      ? $ctx['post_cat_path_map']
      : [];

    $postPath = (string)($attrs['post_path'] ?? ($pathMap[$catKey] ?? ($ctx['post_path'] ?? '/')));

    foreach ($posts as $p) {
      $titleRaw = (string)($p['title'] ?? '');
      $slug = (string)($p['slug'] ?? '');
      $url = $slug !== ''
          ? ($postType === 'page' && function_exists('get_page_permalink')
              ? $baseUrl . get_page_permalink($p)
              : (function_exists('get_post_permalink') ? $baseUrl . get_post_permalink($p) : post_cat__join_url($baseUrl, $postPath, $slug)))
          : '#';
      if ($url !== '#' && function_exists('collection_url')) {
        $url = collection_url($url, $postType, [
          'scope' => 'post_category_shortcode',
          'item' => $p,
          'category' => $catRaw,
          'layout' => $layout,
        ]);
      }

      $thumb = trim((string)($p['thumbnail'] ?? ''));
      if ($thumb !== '' && $baseUrl !== '' && isset($thumb[0]) && $thumb[0] === '/') {
        $thumb = $baseUrl . $thumb;
      }

      $desc = post_cat__excerpt((string)($p['content'] ?? ''), $excerptLen);

      $dateIso = '';
      $dateLabel = '';
      $createdAt = (string)($p['created_at'] ?? '');
      if ($createdAt !== '') {
        $ts = strtotime($createdAt);
        if ($ts) {
          $dateIso = date('c', $ts);
          $dateLabel = date($dateFormat, $ts);
        }
      }

      $items[] = [
        'kind' => 'post',
        'title' => $titleRaw,
        'url' => $url,
        'thumb' => $thumb,
        'desc' => $desc,
        'date_iso' => $dateIso,
        'date_label' => $dateLabel,
        'raw' => $p,
      ];
    }
  }

  /**
   * SOURCE: SHOP PRODUCTS (future-ready, optional)
   */
  elseif ($source === 'shop_products') {
    if (!function_exists('cms_shop_products_by_category')) {
      return post_cat__empty_html('Source shop belum tersedia.', $classPrefix);
    }

    $rows = cms_shop_products_by_category($pdo, $catRaw, [
      'status' => $attrs['status'] ?? 'active',
      'include_children' => (($attrs['include_children'] ?? '1') !== '0'),
      'limit' => $limit,
      'offset' => $offset,
      'order_by' => $attrs['order_by'] ?? 'sort_order',
      'order_dir' => $attrs['order_dir'] ?? 'ASC',
    ]);

    if (!$rows) {
      return post_cat__empty_html('Belum ada produk.', $classPrefix);
    }

    $variantPricePrefix = (string)($attrs['variant_price_prefix'] ?? 'Mulai ');

    foreach ($rows as $p) {
      $displayPrice = $p['price'] ?? null;
      $displaySale  = $p['sale_price'] ?? null;

      if (function_exists('cms_shop_product_display_price')) {
        [$displayPrice, $displaySale] = cms_shop_product_display_price($p);
      }

      $thumb = trim((string)($p['cover_url'] ?? ''));
      if ($thumb !== '' && $baseUrl !== '' && isset($thumb[0]) && $thumb[0] === '/') {
        $thumb = $baseUrl . $thumb;
      }

      $url = function_exists('cms_shop_product_url')
        ? cms_shop_product_url((string)($p['slug'] ?? ''), $baseUrl)
        : '#';

      $isVariantRange = (
        (string)($p['product_mode'] ?? 'single') === 'variant'
        && (int)($p['variants_count'] ?? 0) > 1
      );

      $priceText = function_exists('cms_shop_money')
        ? cms_shop_money($displayPrice)
        : (string)$displayPrice;

      $saleText = (
        $displaySale !== null
        && $displaySale !== ''
        && (float)$displaySale > 0
      )
        ? (function_exists('cms_shop_money') ? cms_shop_money($displaySale) : (string)$displaySale)
        : '';

      $items[] = [
        'kind' => 'shop_product',
        'title' => (string)($p['name'] ?? ''),
        'url' => $url,
        'thumb' => $thumb,
        'desc' => '',
        'date_iso' => '',
        'date_label' => '',
        'brand' => (string)($p['brand_name'] ?? ''),
        'price_text' => $priceText,
        'sale_price_text' => $saleText,
        'price_prefix' => $isVariantRange ? $variantPricePrefix : '',
        'raw' => $p,
      ];
    }
  }

  /**
   * SOURCE: SHOP CATEGORIES (future-ready, optional)
   * - mode A: slugs="aksesoris,boneka,dekorasi,fashion"
   * - mode B: category="boneka" => child categories
   */
  elseif ($source === 'shop_categories') {
    $slugsRaw = trim((string)($attrs['slugs'] ?? ''));
    $slugs = array_values(array_filter(array_map('trim', explode(',', $slugsRaw)), function($v) {
      return $v !== '';
    }));

    // mode kategori utama
    if (!empty($slugs)) {
      if (!function_exists('cms_shop_categories_featured')) {
        return post_cat__empty_html('Source kategori shop belum tersedia.', $classPrefix);
      }

      $rows = cms_shop_categories_featured($pdo, $slugs, [
        'limit' => $limit,
      ]);

      if (!$rows) {
        return post_cat__empty_html('Belum ada kategori.', $classPrefix);
      }

      foreach ($rows as $row) {
        $thumb = trim((string)($row['cover_url'] ?? ''));
        if ($thumb !== '' && $baseUrl !== '' && isset($thumb[0]) && $thumb[0] === '/') {
          $thumb = $baseUrl . $thumb;
        }

        $url = function_exists('cms_shop_category_url')
          ? cms_shop_category_url((string)($row['slug'] ?? ''), $baseUrl)
          : '#';

        $items[] = [
          'kind' => 'shop_category',
          'title' => (string)($row['name'] ?? ''),
          'url' => $url,
          'thumb' => $thumb,
          'desc' => '',
          'date_iso' => '',
          'date_label' => '',
          'count_label' => '',
          'raw' => $row,
        ];
      }
    }

    // mode child categories
    else {
      if (!function_exists('cms_shop_child_categories')) {
        return post_cat__empty_html('Source kategori shop belum tersedia.', $classPrefix);
      }

      $rows = cms_shop_child_categories($pdo, $catRaw, [
        'limit' => $limit,
        'offset' => $offset,
        'order_by' => $attrs['order_by'] ?? 'sort_order',
        'order_dir' => $attrs['order_dir'] ?? 'ASC',
      ]);

      if (!$rows) {
        return post_cat__empty_html('Belum ada collection.', $classPrefix);
      }

      foreach ($rows as $row) {
        $thumb = trim((string)($row['cover_url'] ?? ''));
        if ($thumb !== '' && $baseUrl !== '' && isset($thumb[0]) && $thumb[0] === '/') {
          $thumb = $baseUrl . $thumb;
        }

        $url = function_exists('cms_shop_category_url')
          ? cms_shop_category_url((string)($row['slug'] ?? ''), $baseUrl)
          : '#';

        $items[] = [
          'kind' => 'shop_category',
          'title' => (string)($row['name'] ?? ''),
          'url' => $url,
          'thumb' => $thumb,
          'desc' => '',
          'date_iso' => '',
          'date_label' => '',
          'count_label' => ((int)($row['products_count'] ?? 0) > 0)
            ? ((int)$row['products_count'] . ' produk')
            : '',
          'raw' => $row,
        ];
      }
    }
  }

  $tpl = post_cat__find_layout_template($pdo, $layout);
  if (!$tpl && $layout !== 'cards') {
    $tpl = post_cat__find_layout_template($pdo, 'cards');
    $layout = 'cards';
  }

  $instanceId = 'pcat-' . (function_exists('random_bytes')
    ? bin2hex(random_bytes(6))
    : substr(md5(uniqid('', true)), 0, 12));

  $vars = [
    'items' => $items,
    'attrs' => $attrs,
    'ctx' => $ctx,
    'layout' => $layout,
    'source' => $source,
    'category' => $catRaw,
    'cat_key' => $catKey,
    'kicker' => $kicker,
    'class_prefix' => $classPrefix,
    'wrap' => $wrap,
    'slider_enabled' => $sliderEnabled,
    'infinite' => $infinite,
    'limit_visible' => $limitVisible,
    'fetch_limit' => $limit,
    'instance_id' => $instanceId,
    'esc' => function($v) {
      return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    },
  ];

  if ($tpl) {
    return post_cat__render_template($tpl, $vars);
  }

  return post_cat__empty_html('Layout template not found: ' . $layout, $classPrefix);
}

// Register widget shortcode handlers
if (function_exists('register_widget_shortcode_handler')) {
    $renderFn = function(PDO $pdo, array $attrs, array $ctx = []): string {
        $attrs['__widget_name'] = $attrs['__widget_name'] ?? ($ctx['__widget_name'] ?? '');
        return post_cat_shortcode_render($pdo, $attrs, $ctx);
    };

    register_widget_shortcode_handler('post_cat_shortcode', $renderFn, ['source' => 'posts']);

    register_widget_shortcode_handler('post_list', $renderFn, [
        'source' => 'posts',
        'layout' => 'list',
    ]);

    register_widget_shortcode_handler('post_cards', $renderFn, [
        'source' => 'posts',
        'layout' => 'cards',
    ]);

    register_widget_shortcode_handler('post_slider', $renderFn, [
        'source' => 'posts',
        'layout' => 'cards',
        'slider' => '1',
    ]);
}

function post_cat_shortcode_expand($html, $pdo, array $ctx = []) {
  if (!($pdo instanceof PDO)) return $html;

  $html = (string)$html;

  $html = post_cat_shortcode_normalize_brackets($html);

  if (strpos($html, '[post_cat_shortcode') === false) {
    return $html;
  }

  return (string)preg_replace_callback('/\[post_cat_shortcode\b([^\]]*)\]/i', function($m) use ($pdo, $ctx) {
    $attrs = post_cat__parse_attrs(trim((string)($m[1] ?? '')));
    return post_cat_shortcode_render($pdo, $attrs, $ctx);
  }, $html);
}

$___builderHelper = __DIR__ . '/shortcode_builder.php';
if (is_file($___builderHelper)) {
    require_once $___builderHelper;
}
unset($___builderHelper);
