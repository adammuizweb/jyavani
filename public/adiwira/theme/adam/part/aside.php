<?php
// /adiwira/theme/adam/part/aside.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
      echo '<li class="adam-nav-item"><a class="adam-nav-link ' . (adam_nav_active($requested,'home') ? 'adam-nav-link--active' : '') . '" href="' . h($base . '/index.php') . '"><span class="adam-nav-icon">🏠</span><span class="adam-nav-text">Beranda</span></a></li>';

      echo '<li class="adam-nav-item">
        <a class="adam-nav-link" href="' . h($rootweb) . '">
          <span class="adam-nav-icon">🌐</span>
          <span class="adam-nav-text">View Web</span>
        </a>
      </li>';

      echo '<li class="adam-nav-heading">Konten</li>';

      echo nav_item($base, $requested, 'admin/posts', '✏️', 'Posts', [
        [$base . '/index.php?page=admin/posts/index','Daftar','📋'],
        [$base . '/index.php?page=admin/posts/artikel','Tambah','➕']
      ]);

      if (in_array($userRole, ['admin','editor'], true)) {
        echo nav_item($base, $requested, 'admin/categories', '🏷️', 'Categories', [
          [$base . '/index.php?page=admin/categories/index','Daftar','📋'],
          [$base . '/index.php?page=admin/categories/add','Tambah','➕']
        ]);
      }

      echo nav_item($base, $requested, 'admin/pages', '📄', 'Pages', [
        [$base . '/index.php?page=admin/pages/index','Daftar','📋'],
        [$base . '/index.php?page=admin/pages/halaman','Tambah','➕']
      ]);

      echo nav_item($base, $requested, 'admin/media', '🖼️', 'Media', [
        [$base . '/index.php?page=admin/media/index&tab=list','Daftar','📋'],
        [$base . '/index.php?page=admin/media/index&tab=add','Tambah','➕']
      ]);

      echo nav_item($base, $requested, 'admin/file', '📁', 'File', [
        [$base . '/index.php?page=admin/file/index&tab=list','Daftar','📋'],
        [$base . '/index.php?page=admin/file/index&tab=add','Tambah','➕']
      ]);

      echo nav_item($base, $requested, 'admin/photos', '📷', 'Albums', [
        [$base . '/index.php?page=admin/photos/index','Daftar','📋']
      ]);

      $themeLinks = [
        [$base . '/index.php?page=admin/themes/index','Daftar','📋'],
        [$base . '/index.php?page=admin/themes/add','Tambah','➕']
      ];

      if ($userRole === 'admin') {
        $themeLinks[] = [$base . '/index.php?page=admin/themes/assign','Assign (Dev)','🔗'];
      }

      echo nav_item($base, $requested, 'admin/themes', '🎨', 'Themes', $themeLinks);

      echo '<li class="adam-nav-heading">Sistem</li>';

      $isPengaturanActive =
        adam_nav_active($requested, 'admin/settings') ||
        adam_nav_active($requested, 'admin/profile') ||
        adam_nav_active($requested, 'admin/users') ||
        adam_nav_active($requested, 'admin/bin');

      echo '<li class="adam-nav-item' . ($isPengaturanActive ? ' is-open' : '') . '" data-prefix="admin/settings">';
      echo '<a class="adam-nav-link' . ($isPengaturanActive ? ' adam-nav-link--active' : '') . '" href="' . h($base . '/index.php?page=admin/settings/index') . '">';
      echo '<span class="adam-nav-icon">⚙️</span><span class="adam-nav-text">Settings</span>';
      echo '</a>';

      echo '<div class="adam-nav-sub" aria-hidden="' . ($isPengaturanActive ? 'false' : 'true') . '">';

      // Urutan sinkron:
      // Website (admin) -> Profile -> Users (admin) -> Bin (admin)
      if ($userRole === 'admin') {
          echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/settings/site') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/settings/site') . '">';
          echo '<span class="adam-nav-sublink-icon" aria-hidden="true">🌐</span><span class="adam-nav-sublink-text">Website</span></a>';
      }

      echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/profile') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/profile/index') . '">';
      echo '<span class="adam-nav-sublink-icon" aria-hidden="true">🧑</span><span class="adam-nav-sublink-text">Profile</span></a>';

      if ($userRole === 'admin') {
          echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/users') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/users/index') . '">';
          echo '<span class="adam-nav-sublink-icon" aria-hidden="true">👥</span><span class="adam-nav-sublink-text">Users</span></a>';

          echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/bin') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/index.php?page=admin/bin/index') . '">';
          echo '<span class="adam-nav-sublink-icon" aria-hidden="true">🗑️</span><span class="adam-nav-sublink-text">Bin</span></a>';
      }

      echo '</div>';
      echo '</li>';

      echo '<li class="adam-nav-item"><a class="adam-nav-link" href="' . h($base . '/logout.php') . '"><span class="adam-nav-icon">🔓</span><span class="adam-nav-text">Logout</span></a></li>';
      ?>
    </ul>
  </nav>

  <div class="adam-aside-bottom" aria-label="Appearance">
    <button type="button"
            id="btn-theme-toggle"
            class="adam-theme-toggle"
            aria-pressed="false"
            title="Toggle theme">
      <span class="adam-nav-icon" id="adamThemeIcon" aria-hidden="true">🌙</span>
      <span class="adam-nav-text" id="adamThemeLabel">Dark</span>
    </button>
  </div>
</aside>