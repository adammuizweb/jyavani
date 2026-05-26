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
  $layout = strtolower(trim($layout));
  $layout = preg_replace('/[^a-z0-9_-]/', '', $layout);
  $layout = substr((string)$layout, 0, 40);
  return $layout !== '' ? $layout : 'cards';
}

function post_cat__safe_source(string $source): string {
  $source = strtolower(trim($source));
  return in_array($source, ['posts', 'shop_products', 'shop_categories'], true) ? $source : 'posts';
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
  $layout = post_cat__safe_layout($layout);
  $rel = 'partials/shortcodes/post_cat/' . $layout . '.php';

  // 1) Global path
  $globalBase = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : null;
  if ($globalBase) {
    $globalPath = rtrim($globalBase, "/\\") . DIRECTORY_SEPARATOR
      . 'views' . DIRECTORY_SEPARATOR
      . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $real = realpath($globalPath);
    if ($real && is_file($real)) {
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

    $real = realpath($candidate);
    if ($real && $baseReal && strpos($real, $baseReal) === 0 && is_file($real)) {
      return $real;
    }
  }

  return null;
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
  $source = post_cat__safe_source((string)($attrs['source'] ?? 'posts'));

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

  $offset = max(0, (int)($attrs['offset'] ?? 0));

  $classPrefix = trim((string)($attrs['class_prefix'] ?? ''));
  $wrap = post_cat__bool($attrs['wrap'] ?? '1', true);

  $sliderEnabled = post_cat__bool(
    $attrs['slider'] ?? $attrs['carousel'] ?? ($source === 'shop_products' ? '1' : '0'),
    $source === 'shop_products'
  );

  $infinite = post_cat__bool($attrs['infinite'] ?? '1', true);

  $baseUrl = rtrim((string)($ctx['base_url'] ?? ''), '/');
  $kicker = trim((string)($attrs['kicker'] ?? ''));
  if ($kicker === '') $kicker = strtoupper(str_replace(['-','_'], ' ', $catKey));

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

      $posts = cms_posts_by_category($pdo, $catRaw, [
        'type' => $attrs['type'] ?? 'article',
        'status' => $attrs['status'] ?? 'published',
        'include_children' => (($attrs['include_children'] ?? '1') !== '0'),
        'limit' => $limit,
        'offset' => $offset,
        'order_by' => $attrs['order_by'] ?? 'created_at',
        'order_dir' => $attrs['order_dir'] ?? 'DESC',
        'created_by' => isset($attrs['author']) ? (int)$attrs['author'] : (isset($attrs['created_by']) ? (int)$attrs['created_by'] : null),
        'date_from' => $attrs['date_from'] ?? $attrs['date_after'] ?? null,
        'date_to' => $attrs['date_to'] ?? $attrs['date_before'] ?? null,
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
      $url = $slug !== '' ? post_cat__join_url($baseUrl, $postPath, $slug) : '#';

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

  $html = str_replace(
    ['&#91;', '&#93;', '&#x5B;', '&#x5D;', '&#091;', '&#093;'],
    ['[', ']', '[', ']', '[', ']'],
    $html
  );

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