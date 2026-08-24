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
        $map = [
            'home' => 'house',
            'logout' => 'log-out',
            'trash' => 'trash-2',
            'code' => 'code',
            'search' => 'search',
        ];
        $icon = $map[$name] ?? $name;
        return svg_ico($icon, 'adam-svg-icon ' . $class);
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

$base = ADMIN_BASE_PATH;
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
        if (!$isActive) {
            foreach ($sublinks as $sublink) {
                $query = parse_url((string)($sublink[0] ?? ''), PHP_URL_QUERY);
                if (!is_string($query)) continue;
                parse_str($query, $queryValues);
                if (trim((string)($queryValues['page'] ?? ''), "/ \t\n\r\0\x0B") === $requested) {
                    $isActive = true;
                    break;
                }
            }
        }
        $openClass = $isActive ? ' is-open' : '';
        $activeClass = $isActive ? ' adam-nav-link--active' : '';

        $html  = '';
        $html .= '<li class="adam-nav-item' . $openClass . '" data-prefix="' . h($prefix) . '">';
        $html .= '<a class="adam-nav-link' . $activeClass . '" href="' . h($base . '/?page=' . $prefix . '/index') . '">';
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
                        if (isset($qarr['tab'])) {
                            $currentTab = (string)($_GET['tab'] ?? '');
                            $isSubActive = ($qarr['tab'] === $currentTab);
                        }
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
$navActor = function_exists('authorization_actor') ? authorization_actor($pdo) : null;
?>
<aside id="adam-aside" class="adam-aside" aria-hidden="false">
  <button id="adam-collapse" class="adam-collapse" aria-label="Toggle sidebar" title="<?= __('Hide / Show sidebar') ?>">
    <svg class="collapse-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  </button>

  <nav class="adam-nav" aria-label="Main navigation">
    <ul class="adam-nav-list">
      <?php
echo '<li class="adam-nav-item"><a class="adam-nav-link ' . (adam_nav_active($requested,'home') ? 'adam-nav-link--active' : '') . '" href="' . h($base . '/?') . '"><span class="adam-nav-icon">' . adam_icon('home') . '</span><span class="adam-nav-text">' . __('Dashboard') . '</span></a></li>';

echo '<li class="adam-nav-item">
  <a class="adam-nav-link" href="' . h($rootweb) . '">
    <span class="adam-nav-icon">' . adam_icon('globe') . '</span>
    <span class="adam-nav-text">' . __('View Web') . '</span>
  </a>
</li>';

$postLinks = [];
if (function_exists('current_user_permission_scope')
    && current_user_permission_scope($pdo, 'core.posts.read') !== null) {
  $postLinks[] = [$base . '/?page=admin/posts/index', __('List'), adam_icon('list','adam-svg-icon--sm')];
}
if (function_exists('current_user_can') && current_user_can($pdo, 'core.posts.create')) {
  $postLinks[] = [$base . '/?page=admin/posts/add', __('Add'), adam_icon('plus','adam-svg-icon--sm')];
}
if (function_exists('current_user_permission_scope')
    && (current_user_permission_scope($pdo, 'core.posts.restore') !== null
      || current_user_permission_scope($pdo, 'core.posts.purge') !== null)) {
  $postLinks[] = [$base . '/?page=admin/bin/article/index', __('Trash'), adam_icon('trash-2','adam-svg-icon--sm')];
}
if ($postLinks !== []) {
  echo nav_item($base, $requested, 'admin/posts', adam_icon('pen'), __('Posts'), $postLinks);
}

$categoryLinks = [];
if (function_exists('current_user_permission_scope')
    && current_user_permission_scope($pdo, 'core.categories.read') !== null) {
  $categoryLinks[] = [$base . '/?page=admin/categories/index', __('List'), adam_icon('list','adam-svg-icon--sm')];
}
if (function_exists('current_user_can') && current_user_can($pdo, 'core.categories.create')) {
  $categoryLinks[] = [$base . '/?page=admin/categories/add', __('Add'), adam_icon('plus','adam-svg-icon--sm')];
}
if (function_exists('current_user_permission_scope')
    && (current_user_permission_scope($pdo, 'core.categories.restore') !== null
      || current_user_permission_scope($pdo, 'core.categories.purge') !== null)) {
  $categoryLinks[] = [$base . '/?page=admin/bin/category/index', __('Trash'), adam_icon('trash-2','adam-svg-icon--sm')];
}
if ($categoryLinks !== []) {
  echo nav_item($base, $requested, 'admin/categories', adam_icon('tag'), __('Categories'), $categoryLinks);
}

$pageLinks = [];
if (function_exists('current_user_permission_scope')
    && current_user_permission_scope($pdo, 'core.pages.read') !== null) {
  $pageLinks[] = [$base . '/?page=admin/pages/index', __('List'), adam_icon('list','adam-svg-icon--sm')];
}
if (function_exists('current_user_can') && current_user_can($pdo, 'core.pages.create')) {
  $pageLinks[] = [$base . '/?page=admin/pages/add', __('Add'), adam_icon('plus','adam-svg-icon--sm')];
}
if (function_exists('current_user_permission_scope')
    && (current_user_permission_scope($pdo, 'core.pages.restore') !== null
      || current_user_permission_scope($pdo, 'core.pages.purge') !== null)) {
  $pageLinks[] = [$base . '/?page=admin/bin/page/index', __('Trash'), adam_icon('trash-2','adam-svg-icon--sm')];
}
if ($pageLinks !== []) {
  echo nav_item($base, $requested, 'admin/pages', adam_icon('file'), __('Pages'), $pageLinks);
}

// ===== PLUGIN TOP-LEVEL NAV (parent=pages) — rendered right after Pages =====
if (function_exists('plugin_nav_items')) {
    foreach (plugin_nav_items() as $pn) {
        if (($pn['parent'] ?? '') !== 'pages') continue;
        $pnPage = (string)($pn['page'] ?? '');
        if ($pnPage === '') continue;
        $pnRoute = function_exists('plugin_resolve_route') ? plugin_resolve_route($pnPage) : null;
        if (!is_array($pnRoute) || ($pnRoute['plugin'] ?? '') !== ($pn['plugin'] ?? '')
            || !plugin_route_is_allowed($pdo, $pnRoute)) continue;
        $pnActive = adam_nav_active($requested, $pnPage);
        echo '<li class="adam-nav-item' . ($pnActive ? ' is-open' : '') . '" data-prefix="' . h($pnPage) . '">';
        echo '<a class="adam-nav-link' . ($pnActive ? ' adam-nav-link--active' : '') . '" href="' . h($base . '/?page=' . $pnPage) . '">';
        echo '<span class="adam-nav-icon" aria-hidden="true">' . adam_icon($pn['icon'] ?? 'code') . '</span>';
        echo '<span class="adam-nav-text">' . h($pn['label'] ?? __('Plugin')) . '</span></a>';
        echo '</li>';
    }
}

echo nav_item($base, $requested, 'admin/media', adam_icon('image'), __('Media'), [
  [$base . '/?page=admin/media/index&tab=list',__('List'), adam_icon('list','adam-svg-icon--sm')],
  [$base . '/?page=admin/media/index&tab=add',__('Add'), adam_icon('plus','adam-svg-icon--sm')]
]);

echo nav_item($base, $requested, 'admin/file', adam_icon('folder'), __('File'), [
  [$base . '/?page=admin/file/index&tab=list',__('List'), adam_icon('list','adam-svg-icon--sm')],
  [$base . '/?page=admin/file/index&tab=add',__('Add'), adam_icon('plus','adam-svg-icon--sm')]
]);

$themeLinks = [
  [$base . '/?page=admin/themes/index',__('List'), adam_icon('list','adam-svg-icon--sm')],
  [$base . '/?page=admin/themes/add',__('Add'), adam_icon('plus','adam-svg-icon--sm')]
];

$canManageInstalledThemes = $navActor !== null && $navActor['is_site_owner'] === true
  && function_exists('current_user_can')
  && current_user_can($pdo, 'core.themes.manage');
if ($canManageInstalledThemes) {
  $themeLinks[] = [$base . '/?page=admin/themes/customize',__('Customize'), adam_icon('settings','adam-svg-icon--sm')];
  $themeLinks[] = [$base . '/?page=admin/themes/assign',__('Assign (Dev)'), adam_icon('link','adam-svg-icon--sm')];
  $themeLinks[] = [$base . '/?page=admin/themes/browse',__('Browse Themes'), adam_icon('search','adam-svg-icon--sm')];
}

echo nav_item($base, $requested, 'admin/themes', adam_icon('palette'), __('Themes'), $themeLinks);

      echo '<li class="adam-nav-heading">' . __('System') . '</li>';

      $isPengaturanActive =
        adam_nav_active($requested, 'admin/settings') ||
        adam_nav_active($requested, 'admin/settings/auth') ||
        adam_nav_active($requested, 'admin/profile') ||
        adam_nav_active($requested, 'admin/users') ||
        adam_nav_active($requested, 'admin/bin') ||
        adam_nav_active($requested, 'admin/menus') ||
        adam_nav_active($requested, 'admin/shortcodes') ||
        adam_nav_active($requested, 'admin/sidebar') ||
        adam_nav_active($requested, 'admin/plugins') ||
        adam_nav_active($requested, 'admin/update');

echo '<li class="adam-nav-item' . ($isPengaturanActive ? ' is-open' : '') . '" data-prefix="admin/settings">';
echo '<a class="adam-nav-link' . ($isPengaturanActive ? ' adam-nav-link--active' : '') . '" href="' . h($base . '/?page=admin/settings/index') . '">';
echo '<span class="adam-nav-icon">' . adam_icon('settings') . '</span><span class="adam-nav-text">' . __('Settings') . '</span>';
echo '</a>';

echo '<div class="adam-nav-sub" aria-hidden="' . ($isPengaturanActive ? 'false' : 'true') . '">';

if (function_exists('current_user_can') && current_user_can($pdo, 'core.settings.manage')) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/settings/site') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/settings/site') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('globe','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Website') . '</span></a>';

}

if ($navActor !== null && $navActor['is_site_owner'] === true
    && function_exists('current_user_can') && current_user_can($pdo, 'core.settings.manage')) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/settings/email') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/settings/email') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('mail','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Email') . '</span></a>';

    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/settings/auth') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/settings/auth') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('lock','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Auth') . '</span></a>';
}

if ($userRole === 'admin') {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/shortcodes') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/shortcodes/index&tab=presets') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('braces','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Shortcodes') . '</span></a>';
}

if (function_exists('current_user_can') && current_user_can($pdo, 'core.sidebar.manage')) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/settings/sidebar') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/settings/sidebar') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('columns-2','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Sidebar') . '</span></a>';

    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/sidebar') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/sidebar/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('puzzle','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Sidebar Widgets') . '</span></a>';
}

if (function_exists('current_user_can') && current_user_can($pdo, 'core.menus.manage')) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/menus') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/menus/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('rows-3','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Menus') . '</span></a>';
}

if (function_exists('current_user_can') && current_user_can($pdo, 'core.profile.manage')) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/profile') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/profile/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('user','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Profile') . '</span></a>';
}

if (function_exists('current_user_can') && current_user_can($pdo, 'core.users.read')) {
    $usersNavActive = adam_nav_active($requested, 'admin/users') && !adam_nav_active($requested, 'admin/users/roles');
    echo '<a class="adam-nav-sublink' . ($usersNavActive ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/users/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('users','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Users') . '</span></a>';
}

if ($navActor !== null && $navActor['is_site_owner'] === true) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/users/roles') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/users/roles/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('shield-check','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Roles & Permissions') . '</span></a>';
}

if ($userRole === 'admin') {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/bin') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/bin/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('trash','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Bin') . '</span></a>';
}

if ($userRole !== 'admin' && function_exists('current_user_can')
    && (current_user_can($pdo, 'core.users.restore') || current_user_can($pdo, 'core.users.purge'))) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/bin/users') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/bin/users/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('trash','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('User Trash') . '</span></a>';
}

if ($navActor !== null && $navActor['is_site_owner'] === true
    && function_exists('current_user_can') && current_user_can($pdo, 'core.plugins.manage')) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/plugins/index') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/plugins/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('box','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Plugins') . '</span></a>';

}

if ($navActor !== null && $navActor['is_site_owner'] === true
    && function_exists('current_user_can') && current_user_can($pdo, 'core.updates.manage')) {
    echo '<a class="adam-nav-sublink' . (adam_nav_active($requested,'admin/update') ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=admin/update/index') . '">';
    echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon('refresh-cw','adam-svg-icon--sm') . '</span><span class="adam-nav-sublink-text">' . __('Update') . '</span></a>';
}

echo '</div>';
echo '</li>';

// ===== PLUGIN NAV ITEMS =====
if (function_exists('plugin_nav_items')) {
    $pluginNavs = plugin_nav_items();
    $pluginNavGroups = [];
    foreach ($pluginNavs as $n) {
        $parent = $n['parent'] ?? '';
        if (!isset($pluginNavGroups[$parent])) $pluginNavGroups[$parent] = [];
        $pluginNavGroups[$parent][] = $n;
    }

    // Items under Settings (parent=settings) — rendered as sublinks
    $settingsItems = $pluginNavGroups['settings'] ?? [];
    foreach ($settingsItems as $n) {
        $label = $n['label'] ?? __('Plugin');
        $icon = $n['icon'] ?? 'code';
        $page = $n['page'] ?? '';
        $route = function_exists('plugin_resolve_route') ? plugin_resolve_route((string)$page) : null;
        if (!is_array($route) || ($route['plugin'] ?? '') !== ($n['plugin'] ?? '')
            || !plugin_route_is_allowed($pdo, $route)) continue;

        $isActive = adam_nav_active($requested, $page);
        echo '<a class="adam-nav-sublink' . ($isActive ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=' . $page) . '">';
        echo '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon($icon, 'adam-svg-icon--sm') . '</span>';
        echo '<span class="adam-nav-sublink-text">' . h($label) . '</span></a>';
    }

    // Items under Tools (parent=tools) — rendered as a collapsible Tools menu
    $toolsItems = $pluginNavGroups['tools'] ?? [];
    if (!empty($toolsItems)) {
        $isToolsActive = false;
        $toolsSublinksHtml = '';
        foreach ($toolsItems as $n) {
            $label = $n['label'] ?? __('Plugin');
            $icon = $n['icon'] ?? 'code';
            $page = $n['page'] ?? '';
            $route = function_exists('plugin_resolve_route') ? plugin_resolve_route((string)$page) : null;
            if (!is_array($route) || ($route['plugin'] ?? '') !== ($n['plugin'] ?? '')
                || !plugin_route_is_allowed($pdo, $route)) continue;

            $isActive = adam_nav_active($requested, $page);
            if ($isActive) $isToolsActive = true;

            $toolsSublinksHtml .= '<a class="adam-nav-sublink' . ($isActive ? ' adam-nav-sublink--active' : '') . '" href="' . h($base . '/?page=' . $page) . '">';
            $toolsSublinksHtml .= '<span class="adam-nav-sublink-icon" aria-hidden="true">' . adam_icon($icon, 'adam-svg-icon--sm') . '</span>';
            $toolsSublinksHtml .= '<span class="adam-nav-sublink-text">' . h($label) . '</span></a>';
        }

        if ($toolsSublinksHtml !== '') {
            echo '<li class="adam-nav-item' . ($isToolsActive ? ' is-open' : '') . '" data-prefix="admin/tools">';
            echo '<a class="adam-nav-link' . ($isToolsActive ? ' adam-nav-link--active' : '') . '" href="#">';
            echo '<span class="adam-nav-icon">' . adam_icon('box') . '</span><span class="adam-nav-text">' . __('Tools') . '</span>';
            echo '</a>';
            echo '<div class="adam-nav-sub" aria-hidden="' . ($isToolsActive ? 'false' : 'true') . '">';
            echo $toolsSublinksHtml;
            echo '</div>';
            echo '</li>';
        }
    }
}
// ===== END PLUGIN NAV ITEMS =====

// Hook for plugins to add nav items dynamically
do_action('admin_menu');

echo '<li class="adam-nav-item"><a class="adam-nav-link" href="' . h($base . '/logout.php') . '"><span class="adam-nav-icon">' . adam_icon('logout') . '</span><span class="adam-nav-text">' . __('Logout') . '</span></a></li>';
      ?>
    </ul>
  </nav>

  <div class="adam-aside-bottom" aria-label="Appearance">
    <button type="button"
            id="btn-theme-toggle"
            class="adam-theme-toggle"
            aria-pressed="false"
            title="<?= __('Toggle theme') ?>">
      <span class="adam-nav-icon" id="adamThemeIcon" aria-hidden="true"><?= adam_theme_icon(); ?></span>
      <span class="adam-nav-text" id="adamThemeLabel"><?= __('Dark') ?></span>
    </button>
  </div>
</aside>
