<?php
// /adiwira/theme/adam/part/aside.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$base = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$requested = (string)($_GET['page'] ?? 'home');
$requested = trim($requested, "/ \t\n\r\0\x0B");

function adam_nav_active($requested, $prefix) {
    $requested = ltrim($requested, '/');
    $prefix = rtrim($prefix, '/');
    if ($requested === $prefix) return true;
    if (strpos($requested, $prefix . '/') === 0) return true;
    return mb_strpos($requested, $prefix) !== false;
}

// keep the existing nav_item helper if present in your version
function nav_item($base, $requested, $prefix, $icon, $label, $sublinks = []) {
    $isActive = adam_nav_active($requested, $prefix);
    $openClass = $isActive ? ' is-open' : '';
    $activeClass = $isActive ? ' adam-nav-link--active' : '';
    $html = '';
    $html .= '<li class="adam-nav-item' . $openClass . '" data-prefix="' . htmlspecialchars($prefix, ENT_QUOTES) . '">';
    $html .= '<a class="adam-nav-link' . $activeClass . '" href="' . htmlspecialchars($base . '/index.php?page=' . $prefix . '/index', ENT_QUOTES) . '">';
    $html .= '<span class="adam-nav-icon" aria-hidden="true">' . $icon . '</span>';
    $html .= '<span class="adam-nav-text">' . htmlspecialchars($label, ENT_QUOTES) . '</span>';
    $html .= '</a>';

    if (!empty($sublinks)) {
        $html .= '<div class="adam-nav-sub" aria-hidden="' . ($isActive ? 'false' : 'true') . '">';
        foreach ($sublinks as $sl) {
            $href = $sl[0]; $slabel = $sl[1]; $sicon = ($sl[2] ?? '');
            $isSubActive = false;
            if ($requested !== '') {
                $needle = 'page=' . $requested;
                if (mb_strpos($href, $needle) !== false) $isSubActive = true;
            }
            $sActiveClass = $isSubActive ? ' adam-nav-sublink--active' : '';
            $html .= '<a class="adam-nav-sublink' . $sActiveClass . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
            if ($sicon !== '') $html .= '<span class="adam-nav-sublink-icon" aria-hidden="true">' . $sicon . '</span> ';
            $html .= '<span class="adam-nav-sublink-text">' . htmlspecialchars($slabel, ENT_QUOTES) . '</span>';
            $html .= '</a>';
        }
        $html .= '</div>';
    }

    $html .= '</li>';
    return $html;
}

$userRole = $_SESSION['user_role'] ?? null;
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
      echo '<li class="adam-nav-item"><a class="adam-nav-link ' . (adam_nav_active($requested,'home') ? 'adam-nav-link--active' : '') . '" href="' . $base . '/index.php"><span class="adam-nav-icon">🏠</span><span class="adam-nav-text">Beranda</span></a></li>';
      
      echo '<li class="adam-nav-item">
        <a class="adam-nav-link" href="https://v1.jyavani.com">
          <span class="adam-nav-icon">🌐</span>
          <span class="adam-nav-text">View Web</span>
        </a>
      </li>';
      
      echo '<li class="adam-nav-heading">Konten</li>';

      echo nav_item($base, $requested, 'admin/posts', '✏️', 'Posts', [
        [$base . '/index.php?page=admin/posts/index','Daftar','📋'],
        [$base . '/index.php?page=admin/posts/artikel','Tambah','➕']
      ]);

      echo nav_item($base, $requested, 'admin/categories', '🏷️', 'Categories', [
        [$base . '/index.php?page=admin/categories/index','Daftar','📋'],
        [$base . '/index.php?page=admin/categories/add','Tambah','➕']
      ]);

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

      if (in_array($userRole, ['admin','editor'])) {
        echo nav_item($base, $requested, 'admin/themes', '🎨', 'Themes (Dev)', [
          [$base . '/index.php?page=admin/themes/index','Daftar','📋'],
          [$base . '/index.php?page=admin/themes/add','Tambah (Dev)','➕'],
          [$base . '/index.php?page=admin/themes/assign','Assign (Dev)','🔗']
        ]);
      
      }

      // SISTEM heading + Pengaturan (minimal, exact: Profile, Users)
      echo '<li class="adam-nav-heading">Sistem</li>';

      // Determine whether Pengaturan parent should be shown as open/active
      $isPengaturanActive = adam_nav_active($requested, 'admin/profile') || adam_nav_active($requested, 'admin/users') || adam_nav_active($requested, 'admin/pengaturan');

      // Render Pengaturan (manual to ensure activation when user visits profile/users)
      echo '<li class="adam-nav-item' . ($isPengaturanActive ? ' is-open' : '') . '" data-prefix="admin/pengaturan">';
      echo '<a class="adam-nav-link' . ($isPengaturanActive ? ' adam-nav-link--active' : '') . '" href="' . $base . '/index.php?page=admin/pengaturan/index">';
      echo '<span class="adam-nav-icon">⚙️</span><span class="adam-nav-text">Pengaturan</span>';
      echo '</a>';

// submenu: Profile always visible
echo '<div class="adam-nav-sub" aria-hidden="' . ($isPengaturanActive ? 'false' : 'true') . '">';
echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/profile') ? ' adam-nav-sublink--active' : '') . '" href="' . $base . '/index.php?page=admin/profile/index">🧑 Profile</a>';

if ($userRole === 'admin') {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/users') ? ' adam-nav-sublink--active' : '') . '" href="' . $base . '/index.php?page=admin/users/index">👥 Users</a>';

    // ✅ Website settings (admin only)
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/pengaturan/site') ? ' adam-nav-sublink--active' : '') . '" href="' . $base . '/index.php?page=admin/pengaturan/site">🌐 Website</a>';
} else {
    echo '<div style="opacity:.6;padding:.4rem .6rem">👥 Users (admin)</div>';
}

echo '</div>'; // end sub


      echo '</li>'; // end Pengaturan

      // Logout
      echo '<li class="adam-nav-item"><a class="adam-nav-link" href="' . $base . '/logout.php"><span class="adam-nav-icon">🔓</span><span class="adam-nav-text">Logout</span></a></li>';
      ?>
    </ul>
  </nav>
</aside>
