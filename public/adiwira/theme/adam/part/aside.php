<?php
// /adiwira/theme/adam/part/aside.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('adam_icon')) {
    function adam_icon($name, $class = '') {
        $class = trim('adam-svg-icon ' . $class);
        $svg = '';

        switch ($name) {
            case 'home':
                $svg = '
                <path d="M3 10.75 12 3.75l9 7"></path>
                <path d="M5.5 9.75V20h13V9.75"></path>
                <path d="M9.5 20v-5.5h5V20"></path>';
                break;

            case 'globe':
                $svg = '
                <circle cx="12" cy="12" r="9"></circle>
                <path d="M3 12h18"></path>
                <path d="M12 3c2.7 2.8 4.2 5.8 4.2 9S14.7 18.2 12 21"></path>
                <path d="M12 3c-2.7 2.8-4.2 5.8-4.2 9S9.3 18.2 12 21"></path>';
                break;

            case 'pen':
                $svg = '
                <path d="M12 20h9"></path>
                <path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L8 18l-4 1 1-4 11.5-11.5Z"></path>';
                break;

            case 'list':
                $svg = '
                <path d="M9 6h11"></path>
                <path d="M9 12h11"></path>
                <path d="M9 18h11"></path>
                <circle cx="4.5" cy="6" r=".85"></circle>
                <circle cx="4.5" cy="12" r=".85"></circle>
                <circle cx="4.5" cy="18" r=".85"></circle>';
                break;

            case 'plus':
                $svg = '
                <path d="M12 5v14"></path>
                <path d="M5 12h14"></path>';
                break;

            case 'tag':
                $svg = '
                <path d="M20 10 12 18 4 10V4h6l10 6Z"></path>
                <circle cx="8.5" cy="8.5" r="1"></circle>';
                break;

            case 'file':
                $svg = '
                <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"></path>
                <path d="M14 3v5h5"></path>
                <path d="M9 13h6"></path>
                <path d="M9 17h6"></path>';
                break;

            case 'image':
                $svg = '
                <rect x="3.5" y="5" width="17" height="14" rx="2"></rect>
                <circle cx="8.5" cy="10" r="1.25"></circle>
                <path d="m6 17 4.5-4.5 3.5 3.5 2.5-2.5 2 3"></path>';
                break;

            case 'folder':
                $svg = '
                <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H9l2 2h7.5A2.5 2.5 0 0 1 21 9.5v8A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5z"></path>';
                break;

            case 'camera':
                $svg = '
                <path d="M4 8h3l2-2h6l2 2h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z"></path>
                <circle cx="12" cy="13" r="4"></circle>';
                break;

            case 'palette':
                $svg = '
                <path d="M12 3c-5 0-9 3.5-9 8 0 4.4 3.5 8 7.8 8H12a2 2 0 0 0 0-4h-.8a1.7 1.7 0 0 1 0-3.4H13c4.4 0 8-2.7 8-6.3C21 5.7 17 3 12 3Z"></path>
                <circle cx="7.5" cy="10" r=".8"></circle>
                <circle cx="10.5" cy="7.5" r=".8"></circle>
                <circle cx="14" cy="7.2" r=".8"></circle>
                <circle cx="16.8" cy="9.8" r=".8"></circle>';
                break;

            case 'settings':
                $svg = '
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.08V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-.4-1.08 1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.08-.4H2.9a2 2 0 1 1 0-4H3a1.7 1.7 0 0 0 1.08-.4 1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6A1.7 1.7 0 0 0 10.4 2.9V2.8a2 2 0 1 1 4 0V3a1.7 1.7 0 0 0 .4 1.08 1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.2.37.32.79.32 1.2 0 .43.11.84.32 1.2"></path>';
                break;

            case 'logout':
                $svg = '
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <path d="m16 17 5-5-5-5"></path>
                <path d="M21 12H9"></path>';
                break;

            case 'user':
                $svg = '
                <circle cx="12" cy="8" r="4"></circle>
                <path d="M4 21a8 8 0 0 1 16 0"></path>';
                break;

            case 'users':
                $svg = '
                <path d="M16 21v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"></path>
                <circle cx="10" cy="8" r="4"></circle>
                <path d="M20 21v-1a4 4 0 0 0-3-3.87"></path>
                <path d="M16 4.13a4 4 0 0 1 0 7.75"></path>';
                break;

            case 'trash':
                $svg = '
                <path d="M3 6h18"></path>
                <path d="M8 6V4h8v2"></path>
                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                <path d="M10 11v6"></path>
                <path d="M14 11v6"></path>';
                break;

            case 'link':
                $svg = '
                <path d="M10 13a5 5 0 0 0 7.07 0l2.12-2.12a5 5 0 1 0-7.07-7.07L10.8 5.12"></path>
                <path d="M14 11a5 5 0 0 0-7.07 0L4.8 13.12a5 5 0 1 0 7.07 7.07L13.2 18.8"></path>';
                break;

            default:
                $svg = '<circle cx="12" cy="12" r="8"></circle>';
        }

        return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
    }
}

if (!function_exists('adam_theme_icon')) {
    function adam_theme_icon($class = '') {
        $class = trim('adam-svg-icon adam-theme-icon-svg ' . $class);

        return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <g class="icon-sun">
                <circle cx="12" cy="12" r="4"></circle>
                <path d="M12 2.5v2.2"></path>
                <path d="M12 19.3v2.2"></path>
                <path d="m4.93 4.93 1.55 1.55"></path>
                <path d="m17.52 17.52 1.55 1.55"></path>
                <path d="M2.5 12h2.2"></path>
                <path d="M19.3 12h2.2"></path>
                <path d="m4.93 19.07 1.55-1.55"></path>
                <path d="m17.52 6.48 1.55-1.55"></path>
            </g>
            <g class="icon-moon">
                <path d="M20 14.5A8.5 8.5 0 1 1 9.5 4 7 7 0 0 0 20 14.5Z"></path>
            </g>
        </svg>';
    }
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$rootweb = $scheme . '://' . $host;

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$requested = (string)($_GET['page'] ?? 'home');
$requested = trim($requested, "/ \t\n\r\0\x0B");

if (!function_exists('adam_nav_active')) {
    function adam_nav_active($requested, $prefix) {
        $requested = ltrim((string)$requested, '/');
        $prefix = rtrim((string)$prefix, '/');

        if ($requested === $prefix) return true;
        if ($prefix !== '' && strpos($requested, $prefix . '/') === 0) return true;

        return $prefix !== '' && mb_strpos($requested, $prefix) !== false;
    }
}

if (!function_exists('nav_item')) {
    function nav_item($base, $requested, $prefix, $icon, $label, $sublinks = []) {
        $isActive = adam_nav_active($requested, $prefix);
        $openClass = $isActive ? ' is-open' : '';
        $activeClass = $isActive ? ' adam-nav-link--active' : '';

        $html  = '';
        $html .= '<li class="adam-nav-item' . $openClass . '" data-prefix="' . h($prefix) . '">';
        $html .= '<a class="adam-nav-link' . $activeClass . '" href="' . h($base . '/index.php?page=' . $prefix . '/index') . '">';
        $html .= '<span class="adam-nav-icon" aria-hidden="true">' . $icon . '</span>';
        $html .= '<span class="adam-nav-text">' . h($label) . '</span>';
        $html .= '</a>';

        if (!empty($sublinks)) {
            $html .= '<div class="adam-nav-sub" aria-hidden="' . ($isActive ? 'false' : 'true') . '">';
            foreach ($sublinks as $sl) {
                $href   = (string)($sl[0] ?? '#');
                $slabel = (string)($sl[1] ?? '');
                $sicon  = (string)($sl[2] ?? '');

                $isSubActive = false;
                $qs = parse_url($href, PHP_URL_QUERY);
                if (is_string($qs)) {
                    parse_str($qs, $qarr);
                    $hp = (string)($qarr['page'] ?? '');
                    if ($hp !== '' && trim($hp, "/ \t\n\r\0\x0B") === $requested) {
                        $isSubActive = true;
                    }
                }

                $sActiveClass = $isSubActive ? ' adam-nav-sublink--active' : '';
                $html .= '<a class="adam-nav-sublink' . $sActiveClass . '" href="' . h($href) . '">';
                if ($sicon !== '') $html .= '<span class="adam-nav-sublink-icon" aria-hidden="true">' . $sicon . '</span> ';
                $html .= '<span class="adam-nav-sublink-text">' . h($slabel) . '</span>';
                $html .= '</a>';
            }
            $html .= '</div>';
        }

        $html .= '</li>';
        return $html;
    }
}

$userRole = null;
if (isset($user) && is_array($user)) {
    $userRole = $user['role'] ?? $user['user_role'] ?? null;
}
if (!$userRole && isset($_SESSION) && is_array($_SESSION)) {
    $userRole = $_SESSION['user_role'] ?? null;
}
$userRole = is_string($userRole) ? strtolower(trim($userRole)) : null;
?>
<aside id="adam-aside" class="adam-aside" aria-hidden="false">
  <div class="adam-aside-top">
    <button id="adam-collapse" class="adam-collapse" aria-label="Toggle sidebar" title="Sembunyikan / Tampilkan sidebar">
      <span class="arrow">◀</span>
      <span class="label">Sembunyikan</span>
    </button>
  </div>

  <nav class="adam-nav" aria-label="Main navigation">
    <ul class="adam-nav-list">
      <?php
echo '<li class="adam-nav-item"><a class="adam-nav-link ' . (adam_nav_active($requested,'home') ? 'adam-nav-link--active' : '') . '" href="' . h($base . '/index.php') . '"><span class="adam-nav-icon">' . adam_icon('home') . '</span><span class="adam-nav-text">Beranda</span></a></li>';

echo '<li class="adam-nav-item">
  <a class="adam-nav-link" href="' . h($rootweb) . '">
    <span class="adam-nav-icon">' . adam_icon('globe') . '</span>
    <span class="adam-nav-text">View Web</span>
  </a>
</li>';

echo nav_item($base, $requested, 'admin/posts', adam_icon('pen'), 'Posts', [
  [$base . '/index.php?page=admin/posts/index','Daftar', adam_icon('list','adam-svg-icon--sm')],
  [$base . '/index.php?page=admin/posts/artikel','Tambah', adam_icon('plus','adam-svg-icon--sm')]
]);

if (in_array($userRole, ['admin','editor'], true)) {
  echo nav_item($base, $requested, 'admin/categories', adam_icon('tag'), 'Categories', [
    [$base . '/index.php?page=admin/categories/index','Daftar', adam_icon('list','adam-svg-icon--sm')],
    [$base . '/index.php?page=admin/categories/add','Tambah', adam_icon('plus','adam-svg-icon--sm')]
  ]);
}

echo nav_item($base, $requested, 'admin/pages', adam_icon('file'), 'Pages', [
  [$base . '/index.php?page=admin/pages/index','Daftar', adam_icon('list','adam-svg-icon--sm')],
  [$base . '/index.php?page=admin/pages/halaman','Tambah', adam_icon('plus','adam-svg-icon--sm')]
]);

echo nav_item($base, $requested, 'admin/media', adam_icon('image'), 'Media', [
  [$base . '/index.php?page=admin/media/index&tab=list','Daftar', adam_icon('list','adam-svg-icon--sm')],
  [$base . '/index.php?page=admin/media/index&tab=add','Tambah', adam_icon('plus','adam-svg-icon--sm')]
]);

echo nav_item($base, $requested, 'admin/file', adam_icon('folder'), 'File', [
  [$base . '/index.php?page=admin/file/index&tab=list','Daftar', adam_icon('list','adam-svg-icon--sm')],
  [$base . '/index.php?page=admin/file/index&tab=add','Tambah', adam_icon('plus','adam-svg-icon--sm')]
]);

echo nav_item($base, $requested, 'admin/photos', adam_icon('camera'), 'Albums', [
  [$base . '/index.php?page=admin/photos/index','Daftar', adam_icon('list','adam-svg-icon--sm')]
]);

$themeLinks = [
  [$base . '/index.php?page=admin/themes/index','Daftar', adam_icon('list','adam-svg-icon--sm')],
  [$base . '/index.php?page=admin/themes/add','Tambah', adam_icon('plus','adam-svg-icon--sm')]
];

if ($userRole === 'admin') {
  $themeLinks[] = [$base . '/index.php?page=admin/themes/assign','Assign (Dev)', adam_icon('link','adam-svg-icon--sm')];
}

echo nav_item($base, $requested, 'admin/themes', adam_icon('palette'), 'Themes', $themeLinks);

      echo '<li class="adam-nav-heading">Sistem</li>';

      $isPengaturanActive =
        adam_nav_active($requested, 'admin/settings') ||
        adam_nav_active($requested, 'admin/profile') ||
        adam_nav_active($requested, 'admin/users') ||
        adam_nav_active($requested, 'admin/bin');

echo '<li class="adam-nav-item' . ($isPengaturanActive ? ' is-open' : '') . '" data-prefix="admin/settings">';
echo '<a class="adam-nav-link' . ($isPengaturanActive ? ' adam-nav-link--active' : '') . '" href="' . h($base . '/index.php?page=admin/settings/index') . '">';
echo '<span class="adam-nav-icon">' . adam_icon('settings') . '</span><span class="adam-nav-text">Settings</span>';
echo '</a>';

echo '<div class="adam-nav-sub" aria-hidden="' . ($isPengaturanActive ? 'false' : 'true') . '">';

if ($userRole === 'admin') {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/settings/site') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/settings/site') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('globe','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">Website</span></a>';
}

echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/profile') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/profile/index') . '">';
echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('user','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">Profile</span></a>';

if ($userRole === 'admin') {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/users') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/users/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('users','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">Users</span></a>';

    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/bin') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/bin/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('trash','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">Bin</span></a>';
}

echo '</div>';
echo '</li>';

echo '<li class="adam-nav-item"><a class="adam-nav-link" href="' . h($base . '/logout.php') . '"><span class="adam-nav-icon">' . adam_icon('logout') . '</span><span class="adam-nav-text">Logout</span></a></li>';
      ?>
    </ul>
  </nav>

  <div class="adam-aside-bottom" aria-label="Appearance">
    <button type="button"
            id="btn-theme-toggle"
            class="adam-theme-toggle"
            aria-pressed="false"
            title="Toggle theme">
      <span class="adam-nav-icon" id="adamThemeIcon" aria-hidden="true"><?= adam_theme_icon(); ?></span>
      <span class="adam-nav-text" id="adamThemeLabel">Dark</span>
    </button>
  </div>
</aside>