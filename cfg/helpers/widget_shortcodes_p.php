<?php
declare(strict_types=1);
// lokasi file cfg/helpers/widget_shortcodes_p.php

if (defined('POST_CAT_SHORTCODES_INCLUDED')) return;
define('POST_CAT_SHORTCODES_INCLUDED', true);

/**
 * Engine shortcode konten utama CMS.
 *
 * Contoh:
 * [post_cat_shortcode category="news" layout="cards" limit="3" include_children="1" class_prefix="inter-news"]
 * [post_cat_shortcode category="agenda" layout="list" limit="8"]
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
  if ($source === '') return 'posts';
  $sources = function_exists('shortcode_preset_sources')
    ? shortcode_preset_sources($context, $pdo)
    : ['posts'];
  return in_array($source, $sources, true) ? $source : '';
}

function post_cat__safe_provider_url(mixed $value, string $fallback): string {
  if (!is_string($value)) return $fallback;
  $value = trim($value);
  if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) return $fallback;
  if ($value === '#' || (str_starts_with($value, '/') && !str_starts_with($value, '//'))) return $value;
  $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
  return in_array($scheme, ['http', 'https'], true) ? $value : $fallback;
}

function post_cat__normalize_provider_result(mixed $result, int $limit): ?array {
  if (!is_array($result) || !is_array($result['items'] ?? null)) return null;
  $items = [];
  foreach (array_slice($result['items'], 0, max(1, min(200, $limit))) as $item) {
    if (!is_array($item)) continue;
    foreach (['title', 'desc', 'date_iso', 'date_label'] as $key) {
      $item[$key] = is_scalar($item[$key] ?? '') ? (string)$item[$key] : '';
    }
    $kind = is_string($item['kind'] ?? null) ? strtolower(trim($item['kind'])) : '';
    $item['kind'] = preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $kind) === 1 ? $kind : 'item';
    $item['url'] = post_cat__safe_provider_url($item['url'] ?? '#', '#');
    $item['thumb'] = post_cat__safe_provider_url($item['thumb'] ?? '', '');
    $items[] = $item;
  }
  $emptyMessage = is_string($result['empty_message'] ?? null) && trim($result['empty_message']) !== ''
    ? trim($result['empty_message'])
    : (function_exists('__') ? __('No items are available for this source.') : 'No items are available for this source.');
  return ['items' => $items, 'empty_message' => $emptyMessage];
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
  if ($maxLen === 0) return '';
  $noBlock = (string)preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
  $noBlock = (string)preg_replace('/<style[^>]*>.*?<\/style>/si', '', $noBlock);
  $plain = html_entity_decode(strip_tags($noBlock), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $txt = trim((string)preg_replace('/\s+/', ' ', $plain));
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

function post_cat__pagination_key(array $ctx, array $attrs = []): string {
  $identity = is_string($ctx['preset_slug'] ?? null) ? strtolower(trim($ctx['preset_slug'])) : '';
  if ($identity === '' && (int)($ctx['preset_id'] ?? 0) > 0) $identity = 'preset-' . (int)$ctx['preset_id'];
  if ($attrs !== []) {
    $queryIdentity = [];
    $identityKeys = array_fill_keys([
      'source', 'type', 'category', 'author', 'created_by', 'limit', 'max_items', 'offset', 'order_by', 'order_dir',
      'layout', 'include_children', 'date_from', 'date_to', 'date_after', 'date_before',
    ], true);
    foreach (array_intersect_key($attrs, $identityKeys) as $key => $value) {
      if (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null) continue;
      $queryIdentity[$key] = $value;
    }
    ksort($queryIdentity, SORT_STRING);
    $encoded = json_encode($queryIdentity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($encoded)) {
      $queryHash = substr(hash('sha256', $encoded), 0, 16);
      $identity = $identity !== '' ? $identity . '-' . $queryHash : 'query-' . $queryHash;
    }
  }
  if ($identity === '' || preg_match('/\A[a-z0-9_-]+\z/', $identity) !== 1) return '';
  if (strlen($identity) > 48) $identity = substr($identity, 0, 48) . '_' . substr(hash('sha256', $identity), 0, 8);
  return 'pcat_page_' . $identity;
}

function post_cat__pagination_page(string $key): int {
  if ($key === '') return 1;
  $value = $_GET[$key] ?? null;
  if (!is_string($value) && !is_int($value)) return 1;
  $value = (string)$value;
  return preg_match('/\A[1-9][0-9]{0,5}\z/', $value) === 1 ? (int)$value : 1;
}

function post_cat__pagination_seed_key(string $paginationKey): string {
  if (!str_starts_with($paginationKey, 'pcat_page_')) return '';
  $identity = substr($paginationKey, strlen('pcat_page_'));
  return $identity !== '' ? 'pcat_seed_' . $identity : '';
}

function post_cat__pagination_seed(string $paginationKey): int {
  $key = post_cat__pagination_seed_key($paginationKey);
  if ($key === '') return 0;
  $value = $_GET[$key] ?? null;
  if (is_string($value) || is_int($value)) {
    $value = (string)$value;
    if (preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1 && (int)$value <= 2147483647) {
      return (int)$value;
    }
  }
  static $generated = [];
  if (!isset($generated[$key])) $generated[$key] = random_int(1, 2147483647);
  return $generated[$key];
}

function post_cat__pagination_url(string $key, int $page, array $persistentQuery = []): string {
  $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
  $path = parse_url($requestUri, PHP_URL_PATH);
  $queryString = parse_url($requestUri, PHP_URL_QUERY);
  $path = is_string($path) && $path !== '' ? $path : '/';
  $query = [];
  if (is_string($queryString) && $queryString !== '') parse_str($queryString, $query);
  unset($query[$key]);
  if ($page > 1) $query[$key] = $page;
  foreach ($persistentQuery as $name => $value) {
    if (!is_string($name) || preg_match('/\A[a-z0-9_-]+\z/', $name) !== 1
        || (!is_string($value) && !is_int($value))) continue;
    $query[$name] = (string)$value;
  }
  $encoded = http_build_query($query);
  return $path . ($encoded !== '' ? '?' . $encoded : '');
}

function post_cat__pagination_html(string $key, int $currentPage, int $totalPages, ?callable $urlForPage = null): string {
  if ($key === '' || $totalPages < 2) return '';
  $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  $pageUrl = static fn(int $page): string => $urlForPage !== null
    ? (string)$urlForPage($page)
    : post_cat__pagination_url($key, $page);
  $label = function_exists('__') ? __('Pagination') : 'Pagination';
  $previous = function_exists('__') ? __('Previous') : 'Previous';
  $next = function_exists('__') ? __('Next') : 'Next';
  $status = function_exists('__') ? sprintf(__('Page %d of %d'), $currentPage, $totalPages) : sprintf('Page %d of %d', $currentPage, $totalPages);
  $start = max(1, $currentPage - 2);
  $end = min($totalPages, $currentPage + 2);
  if ($start === 2) $start = 1;
  if ($end === $totalPages - 1) $end = $totalPages;

  $html = '<nav class="pcat-pagination" aria-label="' . $esc($label) . '">';
  $html .= $currentPage > 1
    ? '<a class="pcat-pagination__edge" rel="prev" href="' . $esc($pageUrl($currentPage - 1)) . '">' . $esc($previous) . '</a>'
    : '<span class="pcat-pagination__edge is-disabled" aria-disabled="true">' . $esc($previous) . '</span>';
  if ($start > 1) {
    $html .= '<a href="' . $esc($pageUrl(1)) . '">1</a>';
    if ($start > 2) $html .= '<span class="pcat-pagination__dots" aria-hidden="true">…</span>';
  }
  for ($page = $start; $page <= $end; $page++) {
    $html .= $page === $currentPage
      ? '<span class="is-current" aria-current="page">' . $page . '</span>'
      : '<a href="' . $esc($pageUrl($page)) . '">' . $page . '</a>';
  }
  if ($end < $totalPages) {
    if ($end < $totalPages - 1) $html .= '<span class="pcat-pagination__dots" aria-hidden="true">…</span>';
    $html .= '<a href="' . $esc($pageUrl($totalPages)) . '">' . $totalPages . '</a>';
  }
  $html .= $currentPage < $totalPages
    ? '<a class="pcat-pagination__edge" rel="next" href="' . $esc($pageUrl($currentPage + 1)) . '">' . $esc($next) . '</a>'
    : '<span class="pcat-pagination__edge is-disabled" aria-disabled="true">' . $esc($next) . '</span>';
  $html .= '<span class="pcat-pagination__status">' . $esc($status) . '</span></nav>';
  $html .= '<style>
.pcat-pagination-root{transition:opacity .15s ease}
.pcat-pagination-root[aria-busy="true"]{opacity:.55;pointer-events:none}
.pcat-pagination-root[data-pcat-pagination-mode="slider"].is-pcat-slider-pagination-ready>.pcat-pagination{display:none}
.pcat-pagination{display:flex;align-items:center;justify-content:center;gap:.35rem;flex-wrap:wrap;margin:1.25rem 0 0}
.pcat-pagination a,.pcat-pagination>span:not(.pcat-pagination__status):not(.pcat-pagination__dots){display:inline-flex;align-items:center;justify-content:center;min-width:2.25rem;min-height:2.25rem;padding:.35rem .6rem;border:1px solid var(--border,#dbe2e8);border-radius:.55rem;background:var(--bg,#fff);color:var(--text,#172033);font-size:.82rem;font-weight:700;text-decoration:none;transition:background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease,transform .16s ease}
.pcat-pagination a:hover,.pcat-pagination a:focus-visible{border-color:var(--accent,#00a89e);background:var(--accent,#00a89e);color:#fff;background:color-mix(in srgb,var(--accent,#00a89e) 12%,var(--bg,#fff));color:var(--accent,#00a89e)}
.pcat-pagination .is-current{border-color:var(--accent,#00a89e);background:var(--accent,#00a89e);color:#fff;font-weight:900;box-shadow:0 2px 6px rgba(15,23,42,.16)}
.pcat-pagination a:focus-visible{outline:2px solid var(--accent,#00a89e);outline-offset:2px}
.pcat-pagination .is-disabled{opacity:.45}
.pcat-pagination__dots{padding:.35rem;color:var(--muted,#64748b)}
.pcat-pagination__status{width:100%;color:var(--muted,#64748b);font-size:.75rem;text-align:center}
@media (prefers-reduced-motion:reduce){.pcat-pagination a,.pcat-pagination>span{transition:none}}
</style>';
  return $html;
}

function post_cat__pagination_state(
  PDO $pdo,
  string $category,
  array $collectionOptions,
  int $limit,
  int $offset,
  int $maxItems,
  string $paginationKey,
  int $requestedPage = 1
): array {
  $limit = max(1, $limit);
  $offset = max(0, $offset);
  $maxItems = max(1, $maxItems);
  $page = 1;
  $pages = 1;
  $fetchLimit = $limit;

  if ($paginationKey !== '' && function_exists('cms_posts_count_by_category')) {
    $matchingItems = min($maxItems, max(0, cms_posts_count_by_category($pdo, $category, $collectionOptions) - $offset));
    $pages = max(1, (int)ceil($matchingItems / $limit));
    $page = min(max(1, $requestedPage), $pages);
    $fetchLimit = max(1, min($limit, $maxItems - (($page - 1) * $limit)));
  }

  return [
    'page' => $page,
    'pages' => $pages,
    'limit' => $fetchLimit,
    'offset' => $offset + (($page - 1) * $limit),
  ];
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
  $runtimeTrust = ($ctx['trust'] ?? '') === 'persisted_preset' ? 'persisted_preset' : 'public_runtime';
  $sourceContext = ['scope' => 'runtime', 'trust' => $runtimeTrust, 'attrs' => $attrs];
  $source = post_cat__safe_source((string)($attrs['source'] ?? 'posts'), $sourceContext, $pdo);
  if ($source === '') {
    return post_cat__empty_html(function_exists('__') ? __('Source provider is unavailable.') : 'Source provider is unavailable.');
  }
  $provider = function_exists('shortcode_source_provider')
    ? shortcode_source_provider($source, $sourceContext, $pdo)
    : null;
  if ($provider !== null) {
    if ($runtimeTrust !== 'persisted_preset') {
      $attrs = shortcode_preset_normalize_public_provider_attributes($attrs, $provider);
    }
    $validation = shortcode_preset_validate_config(
      $attrs,
      false,
      $pdo,
      array_merge($ctx, [
        'scope' => 'runtime',
        'source' => $source,
        'trust' => $runtimeTrust,
        'allow_provider_binding' => $runtimeTrust !== 'persisted_preset',
      ])
    );
    if ($validation['errors'] !== []) {
      return post_cat__empty_html(function_exists('__') ? __('Source provider is unavailable.') : 'Source provider is unavailable.');
    }
    $attrs = $validation['config'];
  } elseif ($source !== 'posts') {
    return post_cat__empty_html(function_exists('__') ? __('Source provider is unavailable.') : 'Source provider is unavailable.');
  }

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
  $maxItems = max(1, min(10000, (int)($attrs['max_items'] ?? $limit)));
  $isRandomOrder = strtoupper((string)($attrs['order_by'] ?? 'created_at')) === 'RAND()';
  $paginationIdentity = array_merge($attrs, [
    'source' => $source,
    'type' => (($attrs['type'] ?? 'article') === 'page') ? 'page' : 'article',
    'category' => $catRaw,
    'layout' => $layout,
    'limit' => $limit,
    'max_items' => $maxItems,
    'offset' => $offset,
  ]);
  $paginationKey = $source === 'posts' && (string)($attrs['pagination'] ?? '0') === '1'
    && $maxItems > $limit
    ? post_cat__pagination_key($ctx, $paginationIdentity)
    : '';
  $paginationPage = post_cat__pagination_page($paginationKey);
  $paginationSeedKey = $isRandomOrder ? post_cat__pagination_seed_key($paginationKey) : '';
  $paginationSeed = $paginationSeedKey !== '' ? post_cat__pagination_seed($paginationKey) : 0;
  $paginationPages = 1;
  $pageFetchLimit = $limit;

  $classPrefix = trim((string)($attrs['class_prefix'] ?? ''));
  $wrap = post_cat__bool($attrs['wrap'] ?? '1', true);

  $sliderEnabled = post_cat__bool($attrs['slider'] ?? $attrs['carousel'] ?? '0', false);

  $infinite = post_cat__bool($attrs['infinite'] ?? '1', true);

  $baseUrl = rtrim((string)($ctx['base_url'] ?? ''), '/');
  $kicker = post_cat__resolve_kicker($attrs, $catRaw);

  $excerptLen = (int)($attrs['excerpt'] ?? $attrs['excerpt_len'] ?? 90);
  if ($excerptLen !== 0 && $excerptLen < 10) $excerptLen = 90;

  $dateFormat = (string)($attrs['date_format'] ?? 'd M Y');
  $items = [];

  if ($provider !== null) {
    $providerAttrs = array_merge($attrs, [
      'source' => $source,
      'category' => $catRaw,
      'layout' => $layout,
      'limit' => $limit,
      'offset' => $offset,
      'excerpt_len' => $excerptLen,
    ]);
    try {
      $providerResult = post_cat__normalize_provider_result(
        ($provider['fetch'])(
          $pdo,
          $providerAttrs,
          array_merge($ctx, ['scope' => 'shortcode_source', 'source' => $source, 'attrs' => $providerAttrs])
        ),
        $limit
      );
    } catch (Throwable $error) {
      error_log('shortcode source provider failed for ' . $source . ': ' . $error->getMessage());
      $providerResult = null;
    }
    if ($providerResult === null) {
      return post_cat__empty_html(
        function_exists('__') ? __('Source provider is unavailable.') : 'Source provider is unavailable.',
        $classPrefix
      );
    }
    $items = $providerResult['items'];
    if ($items === []) return post_cat__empty_html($providerResult['empty_message'], $classPrefix);
  }

  /**
   * SOURCE: CONTENT POSTS (utama CMS)
   */
  elseif ($source === 'posts') {
    if (!function_exists('cms_posts_by_category')) {
      return post_cat__empty_html('Helper konten belum tersedia.', $classPrefix);
    }

      $postType = (($attrs['type'] ?? 'article') === 'page') ? 'page' : 'article';
      $collectionOptions = [
        'type' => $postType,
        'status' => 'published',
        'include_children' => (($attrs['include_children'] ?? '1') !== '0'),
        'limit' => $limit,
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
      ];
      if ($paginationSeed > 0) $collectionOptions['random_seed'] = $paginationSeed;
      $paginationState = post_cat__pagination_state(
        $pdo,
        $catRaw,
        $collectionOptions,
        $limit,
        $offset,
        $maxItems,
        $paginationKey,
        $paginationPage
      );
      $paginationPage = $paginationState['page'];
      $paginationPages = $paginationState['pages'];
      $pageFetchLimit = $paginationState['limit'];
      $collectionOptions['limit'] = $paginationState['limit'];
      $collectionOptions['offset'] = $paginationState['offset'];
      $posts = cms_posts_by_category($pdo, $catRaw, $collectionOptions);

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

  else {
    return post_cat__empty_html(
      function_exists('__') ? __('Source provider is unavailable.') : 'Source provider is unavailable.',
      $classPrefix
    );
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
    'fetch_limit' => $pageFetchLimit,
    'instance_id' => $instanceId,
    'esc' => function($v) {
      return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    },
  ];

  if ($tpl) {
    $layoutHtml = post_cat__render_template($tpl, $vars);
    $sliderPagination = $paginationKey !== '' && str_contains($layoutHtml, 'data-pcat-pagination-slider');
    $paginationUrlForPage = $paginationSeedKey !== ''
      ? static fn(int $page): string => post_cat__pagination_url($paginationKey, $page, [$paginationSeedKey => $paginationSeed])
      : null;
    $html = $layoutHtml . post_cat__pagination_html($paginationKey, $paginationPage, $paginationPages, $paginationUrlForPage);
    if ($paginationKey !== '') {
      $paginationStatus = function_exists('__')
        ? sprintf(__('Page %d of %d'), $paginationPage, $paginationPages)
        : sprintf('Page %d of %d', $paginationPage, $paginationPages);
      return '<div class="pcat-pagination-root" data-pcat-pagination-root="'
        . htmlspecialchars($paginationKey, ENT_QUOTES, 'UTF-8') . '"'
        . ($sliderPagination ? ' data-pcat-pagination-mode="slider"' : '')
        . ' role="region" aria-label="' . htmlspecialchars($paginationStatus, ENT_QUOTES, 'UTF-8')
        . '" tabindex="-1">' . $html . '</div>';
    }
    return $html;
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
