<?php
// lokasi file widget_shortcodes.php
declare(strict_types=1);

if (defined('WIDGET_SHORTCODES_INCLUDED')) return;
define('WIDGET_SHORTCODES_INCLUDED', true);

/**
 * Shortcode:
 *   [post_cat_shortcode category="news" limit="12" include_children="1" layout="cards" class_prefix="inter-news"]
 *
 * layout:
 * - cards (default)
 * - list
 *
 * Auto layout via context:
 *   $ctx['post_cat_layout_map'] = ['news'=>'cards','agenda'=>'list',...]
 *
 * Optional routing:
 *   - per shortcode: post_path="/news/"
 *   - via context map: $ctx['post_cat_path_map'] = ['news'=>'/news/','agenda'=>'/agenda/']
 *   - global fallback: $ctx['post_path'] (default '/news/')
 */

function post_cat__parse_attrs(string $attrRaw): array {
  $attrs = [];
  if ($attrRaw !== '') {
    preg_match_all('/(\w+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))/', $attrRaw, $mm, PREG_SET_ORDER);
    foreach ($mm as $a) {
      $k = strtolower($a[1]);
      $v = $a[3] ?? $a[4] ?? $a[5] ?? '';
      $attrs[$k] = $v;
    }
  }
  return $attrs;
}

function post_cat__bool($v, bool $default = false): bool {
  if ($v === null) return $default;
  $s = strtolower(trim((string)$v));
  if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'on') return true;
  if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'off') return false;
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

  // sanitize: hanya a-z 0-9 _ -
  $layout = preg_replace('/[^a-z0-9_-]/', '', $layout);
  $layout = substr($layout, 0, 40);

  return $layout !== '' ? $layout : 'cards';
}

function post_cat__excerpt(string $html, int $maxLen): string {
  $txt = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
  if ($maxLen < 10) $maxLen = 10;

  if (function_exists('mb_strlen')) {
    if (mb_strlen($txt, 'UTF-8') > $maxLen) return mb_substr($txt, 0, $maxLen - 1, 'UTF-8') . '…';
    return $txt;
  }
  if (strlen($txt) > $maxLen) return substr($txt, 0, $maxLen - 1) . '…';
  return $txt;
}

function post_cat__join_url(string $baseUrl, string $path, string $slug): string {
  $baseUrl = rtrim($baseUrl, '/');
  $path = '/' . trim($path, '/');
  $path = rtrim($path, '/') . '/';
  return $baseUrl . $path . rawurlencode($slug);
}

/**
 * Cari template layout di theme: active/assigned -> fallback default.
 * Template path:
 *   views/theme/<folder>/partials/shortcodes/post_cat/<layout>.php
 */
function post_cat__find_layout_template(PDO $pdo, string $layout): ?string {
  // sekarang ini sanitize-only (tanpa whitelist)
  $layout = post_cat__safe_layout($layout);

  $rel = 'partials/shortcodes/post_cat/' . $layout . '.php';

  if (!defined('VIEWS_BASE')) return null;

  $folders = [];
  if (function_exists('get_relevant_theme_folders')) {
    $folders = get_relevant_theme_folders($pdo, null);
    $folders = array_reverse($folders); // active first
  } else {
    $folders = [defined('DEFAULT_THEME_FOLDER') ? DEFAULT_THEME_FOLDER : 'default'];
  }

  $baseReal = realpath(VIEWS_BASE) ?: null;

  foreach ($folders as $folder) {
    $candidate = function_exists('path_candidate')
      ? path_candidate(VIEWS_BASE, (string)$folder, $rel)
      : rtrim(VIEWS_BASE, "/\\") . DIRECTORY_SEPARATOR . trim((string)$folder, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

    $real = realpath($candidate);
    if ($real && $baseReal && strpos($real, $baseReal) === 0 && is_file($real)) return $real;
  }

  return null;
}

function post_cat__render_template(string $path, array $vars): string {
  if (!is_file($path)) return '';
  ob_start();
  (function($__path, $__vars){
    extract($__vars, EXTR_SKIP);
    include $__path;
  })($path, $vars);
  return (string)ob_get_clean();
}

function post_cat_shortcode_expand($html, $pdo, array $ctx = []) {
  if (!($pdo instanceof PDO)) return $html;
  if (!function_exists('cms_posts_by_category')) return $html;

  return preg_replace_callback('/\[post_cat_shortcode\b([^\]]*)\]/i', function($m) use ($pdo, $ctx) {
    $attrs = post_cat__parse_attrs(trim((string)($m[1] ?? '')));

    $catRaw = (string)($attrs['category'] ?? 'news');
    $catKey = post_cat__slug($catRaw);
    if ($catKey === '') $catKey = strtolower(trim($catRaw)) ?: 'news';

    // layout manual > mapping context > default
    // layout manual > mapping context > default
    $layoutMap = (isset($ctx['post_cat_layout_map']) && is_array($ctx['post_cat_layout_map'])) ? $ctx['post_cat_layout_map'] : [];
    $layoutRaw = (string)($attrs['layout'] ?? ($layoutMap[$catKey] ?? 'cards'));

    // sanitasi nama layout: hanya huruf, angka, underscore, dash dan max 40 char
    $layoutSan = preg_replace('/[^a-z0-9_-]/i', '', $layoutRaw);
    $layoutSan = substr($layoutSan, 0, 40);
    $layoutSan = strtolower($layoutSan);

    // cek apakah template benar-benar ada di tema aktif; fallback ke 'cards'
    $tplCandidate = post_cat__find_layout_template($pdo, $layoutSan);
    if ($tplCandidate === null) {
      // jika tidak ditemukan, fallback ke 'cards' (pastikan cards.php ada)
      $layout = 'cards';
    } else {
      $layout = $layoutSan;
    }

$limitVisible = (int)($attrs['limit'] ?? 3);
if ($limitVisible < 1) $limitVisible = 3;

// fetch/max untuk jumlah data yang diambil (khusus sliderpage / umum juga boleh)
$fetch = (int)($attrs['fetch'] ?? $attrs['max_show'] ?? $attrs['max'] ?? 0);
if ($fetch < 1) $fetch = 0;

// default fetch: kalau user cuma isi limit, kita anggap minimal ambil = limit
$limit = max($limitVisible, $fetch);
if ($limit < 1) $limit = 6;

    $posts = cms_posts_by_category($pdo, $catRaw, [
      'type' => $attrs['type'] ?? 'article',
      'status' => $attrs['status'] ?? 'published',
      'include_children' => (($attrs['include_children'] ?? '1') !== '0'),
      'limit' => $limit,
      'order_by' => $attrs['order_by'] ?? 'created_at',
      'order_dir' => $attrs['order_dir'] ?? 'DESC',
    ]);

    $classPrefix = trim((string)($attrs['class_prefix'] ?? '')); // optional extra class for overrides
    $wrap = post_cat__bool($attrs['wrap'] ?? '1', true);

    if (!$posts) {
      $cls = 'pcat__empty' . ($classPrefix !== '' ? ' ' . htmlspecialchars($classPrefix, ENT_QUOTES, 'UTF-8') : '');
      return '<div class="'.$cls.'">Belum ada konten.</div>';
    }

    // routing (per shortcode > map > global)
    $baseUrl = rtrim((string)($ctx['base_url'] ?? ''), '/'); // optional
    $pathMap = (isset($ctx['post_cat_path_map']) && is_array($ctx['post_cat_path_map'])) ? $ctx['post_cat_path_map'] : [];
    $postPath = (string)($attrs['post_path'] ?? ($pathMap[$catKey] ?? ($ctx['post_path'] ?? '/news/')));

    $kicker = trim((string)($attrs['kicker'] ?? ''));
    if ($kicker === '') $kicker = strtoupper(str_replace(['-','_'], ' ', $catKey));

    $excerptLen = (int)($attrs['excerpt'] ?? $attrs['excerpt_len'] ?? 90);
    if ($excerptLen < 20) $excerptLen = 90;

    $dateFormat = (string)($attrs['date_format'] ?? 'd M Y');

    // build view-model items (layout agnostic)
    $items = [];
    foreach ($posts as $p) {
      $titleRaw = (string)($p['title'] ?? '');
      $slug = (string)($p['slug'] ?? '');

      $url = $slug !== '' ? post_cat__join_url($baseUrl, $postPath, $slug) : '#';

      $thumb = trim((string)($p['thumbnail'] ?? ''));
      if ($thumb !== '' && $baseUrl !== '' && $thumb[0] === '/') $thumb = $baseUrl . $thumb;

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
        'title' => $titleRaw,
        'url' => $url,
        'thumb' => $thumb,
        'desc' => $desc,
        'date_iso' => $dateIso,
        'date_label' => $dateLabel,
        'raw' => $p,
      ];
    }

    $tpl = post_cat__find_layout_template($pdo, $layout);

    // vars buat template
    $vars = [
      'items' => $items,
      'attrs' => $attrs,
      'ctx' => $ctx,
      'layout' => $layout,
      'category' => $catRaw,
      'cat_key' => $catKey,
      'kicker' => $kicker,
      'class_prefix' => $classPrefix,
      'wrap' => $wrap,
      'esc' => function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); },
    ];

    if ($tpl) {
      return post_cat__render_template($tpl, $vars);
    }

    // fallback minimal kalau template hilang
    $cls = 'pcat__empty' . ($classPrefix !== '' ? ' ' . htmlspecialchars($classPrefix, ENT_QUOTES, 'UTF-8') : '');
    return '<div class="'.$cls.'">Layout template not found: '.htmlspecialchars($layout, ENT_QUOTES, 'UTF-8').'</div>';
  }, (string)$html);
}