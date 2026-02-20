<?php
// controllers/CategoryController.php

if (!class_exists('PostController')) {
    $postControllerPath = __DIR__ . '/PostController.php';
    if (is_file($postControllerPath)) {
        require_once $postControllerPath;
    }
}

class CategoryController
{
    private static function render_local_view(string $filename, array $vars = []): string
    {
        // try category-specific and generic app views
        $paths = [
            __DIR__ . '/../views/category/' . $filename,
            __DIR__ . '/../views/' . $filename,
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                ob_start();
                extract($vars, EXTR_SKIP);
                include $path;
                return ob_get_clean();
            }
        }

        return '';
    }

    private static function resolveCategoryByPath(PDO $pdo, array $pathParts)
    {
        $parentId = null;
        $category = false;

        try {
            foreach ($pathParts as $seg) {
                $seg = trim((string)$seg);
                if ($seg === '') continue;

                if ($parentId === null) {
                    $sql = "SELECT * FROM categories WHERE slug = :slug AND (parent_id IS NULL) AND is_deleted = 0 LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':slug' => $seg]);
                } else {
                    $sql = "SELECT * FROM categories WHERE slug = :slug AND parent_id = :parent_id AND is_deleted = 0 LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':slug' => $seg, ':parent_id' => (int)$parentId]);
                }

                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return false;
                }

                $category = $row;
                $parentId = (int)$row['id'];
            }
        } catch (Throwable $e) {
            error_log("[CategoryController::resolveCategoryByPath] DB error: " . $e->getMessage());
            return false;
        }

        return $category;
    }

    private static function getDescendantCategoryIds(PDO $pdo, int $rootId): array
    {
        try {
            $stmt = $pdo->prepare("SELECT id, parent_id FROM categories WHERE is_deleted = 0");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[CategoryController::getDescendantCategoryIds] DB error: " . $e->getMessage());
            return [$rootId];
        }

        $children = [];
        foreach ($rows as $r) {
            $pid = $r['parent_id'] === null ? null : (int)$r['parent_id'];
            $children[$pid][] = (int)$r['id'];
        }

        $result = [];
        $queue = [$rootId];
        while (!empty($queue)) {
            $cur = array_pop($queue);
            if (in_array($cur, $result, true)) continue;
            $result[] = $cur;
            if (!empty($children[$cur])) {
                foreach ($children[$cur] as $c) {
                    $queue[] = $c;
                }
            }
        }

        return $result;
    }

    /**
     * Show posts by category path/slug.
     *
     * Router NOTE: $slug may contain slashes (e.g. "parent/child/grandchild").
     *
     * @param PDO $pdo
     * @param string $slug path or single slug; if empty => show parent categories list
     * @param int $page
     * @param string $q optional search query
     */
    public static function showCategory(PDO $pdo, string $slug = '', int $page = 1, string $q = '')
    {
        // normalize slug
        $slug = trim((string)$slug, " \t\n\r\0\x0B/");

        // Quick helper: attach display images via PostController if available
        $attachDisplayImages = function (&$posts) {
            if (class_exists('PostController') && method_exists('PostController', 'attach_display_images')) {
                try {
                    PostController::attach_display_images($posts);
                    return;
                } catch (Throwable $e) {
                    // ignore
                }
            }
            // fallback: no-op
        };

        // If no slug -> show parent categories
        $reqPath = '/';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        }
        $reqPath = rtrim($reqPath, '/');

        if ($slug === '' || $reqPath === '/category') {
            // SHOW PARENT CATEGORIES
            try {
                $stmt = $pdo->query("SELECT id, name, slug, description FROM categories WHERE is_deleted = 0 AND parent_id IS NULL ORDER BY name ASC");
                $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                error_log("[CategoryController::showCategory] DB error (list parents): " . $e->getMessage());
                http_response_code(500);
                echo "Server error";
                exit;
            }

            $page_title = 'Kategori';
            $content_html = '';

            // Respect cleared assignment (if admin inserted cleared marker)
            $isCleared = false;
            if (function_exists('is_assignment_cleared')) {
                try {
                    $isCleared = is_assignment_cleared($pdo, 'content', 'categories_parents_list');
                } catch (Throwable $e) {
                    $isCleared = false;
                }
            }

            // 1) explicit assigned slot (highest priority) - candidate 'categories_parents_list'
            if (!$isCleared && function_exists('render_assigned_slot')) {
                try {
                    ob_start();
                    render_assigned_slot($pdo, 'content', 'categories_parents_list', ['categories' => $cats, 'site_context' => 'categories_parents_list']);
                    $assigned_html = ob_get_clean();
                    if (trim($assigned_html) !== '') {
                        $content_html = $assigned_html;
                    }
                } catch (Throwable $e) {
                    error_log("[CategoryController::showCategory] render_assigned_slot error: " . $e->getMessage());
                }
            }

            // 2) theme engine slot 'index.category'
            if (trim($content_html) === '' && !$isCleared && function_exists('render_slot')) {
                try {
                    $slotHtml = render_slot($pdo, 'index.category', ['categories' => $cats, 'site_context' => 'categories_parents_list']);
                    if (trim((string)$slotHtml) !== '') $content_html = $slotHtml;
                } catch (Throwable $e) {
                    error_log("[CategoryController::showCategory] render_slot(index.category) error: " . $e->getMessage());
                }
            }

// 3) Explicit: try DEFAULT_THEME_FOLDER (index.category)
if (trim($content_html) === '') {
    try {
        $parentVars = ['categories' => $cats, 'site_context' => 'categories_parents_list', 'page_title' => 'Kategori'];
        $themeFile = slot_to_file('index.category');
        $resolved = [
            'type' => 'theme_file',
            'theme_folder' => DEFAULT_THEME_FOLDER,
            'theme_file' => $themeFile,
        ];
        $path = resolve_theme_file_path($resolved);
        if ($path) {
            $content_html = include_template_file($path, $parentVars);
        }
    } catch (Throwable $e) {
        error_log("[CategoryController::showCategory] default-theme (parents) error: " . $e->getMessage());
    }
}

            // 4) inline fallback
            if (trim($content_html) === '') {
                ob_start();
                ?>
                <div class="container">
                  <h1>Kategori</h1>
                  <?php if (empty($cats)): ?>
                    <p>Tidak ada kategori level atas.</p>
                  <?php else: ?>
                    <ul>
                      <?php foreach ($cats as $c): ?>
                        <li><a href="/category/<?= rawurlencode($c['slug']) ?>/"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
                <?php
                $content_html = ob_get_clean();
            }

            // expose for layout
            $context_for_layout = 'index.category';
            
                    // ==============================
        // LAYOUT OVERRIDE (INI TEMPATNYA)
        // ==============================
        // Default layout policy di layout.php:
        // - homepage: full width + sidebar off
        // - selain homepage: container + sidebar on
        //
        // Kalau theme-page kamu mau beda, set di sini:

        $layout_full_width = false;   // paksa pakai container (jadi tidak full width)
        $enable_sidebar    = false;    // paksa sidebar aktif
        $sidebar_position  = 'right';  // 'left' atau 'right'

        // (Opsional) kalau mau bisa diatur dari meta JSON pada post theme:
        // meta contoh: {"layout_full_width":true,"enable_sidebar":false,"sidebar_position":"right"}
        if (!empty($themeData['meta'])) {
            $meta = json_decode((string)$themeData['meta'], true);
            if (is_array($meta)) {
                if (array_key_exists('layout_full_width', $meta)) $layout_full_width = (bool)$meta['layout_full_width'];
                if (array_key_exists('enable_sidebar', $meta))    $enable_sidebar    = (bool)$meta['enable_sidebar'];
                if (!empty($meta['sidebar_position'])) {
                    $pos = strtolower(trim((string)$meta['sidebar_position']));
                    if (in_array($pos, ['left','right'], true)) $sidebar_position = $pos;
                }
            }
        }
        
        // END SIDEBAR
            require __DIR__ . '/../layout.php';
            exit;
        }

        // Resolve hierarchical category
        $parts = array_values(array_filter(array_map('trim', explode('/', $slug)), fn($v) => $v !== ''));
        if (empty($parts)) {
            http_response_code(404);
            $page_title = 'Kategori tidak ditemukan';
            $content_html = '<h1>404 — Kategori tidak ditemukan</h1>';
            require __DIR__ . '/../layout.php';
            exit;
        }

        $category = self::resolveCategoryByPath($pdo, $parts);
        if (!$category) {
            http_response_code(404);
            $page_title = 'Kategori tidak ditemukan';
            $content_html = '<h1>404 — Kategori tidak ditemukan</h1>';
            require __DIR__ . '/../layout.php';
            exit;
        }

        // Pagination + fetch posts for category and descendants
        $perPage = 10;
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $isLoggedIn = !empty($_SESSION['user_id'] ?? null);

        $catIds = self::getDescendantCategoryIds($pdo, (int)$category['id']);

        // build IN clause
        $inPlaceholders = [];
        $params = [];
        foreach ($catIds as $i => $cid) {
            $ph = ":cid" . $i;
            $inPlaceholders[] = $ph;
            $params[$ph] = $cid;
        }
        $inSQL = implode(',', $inPlaceholders);

        // count
        try {
            if ($isLoggedIn) {
                $countSql = "SELECT COUNT(DISTINCT p.id) FROM posts p
                             JOIN post_categories pc ON pc.post_id = p.id
                             WHERE pc.category_id IN ($inSQL)
                               AND p.is_deleted = 0";
            } else {
                $countSql = "SELECT COUNT(DISTINCT p.id) FROM posts p
                             JOIN post_categories pc ON pc.post_id = p.id
                             WHERE pc.category_id IN ($inSQL)
                               AND p.is_deleted = 0
                               AND p.status = 'published'";
            }
            $stmt = $pdo->prepare($countSql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
            }
            $stmt->execute();
            $total = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("[CategoryController::showCategory] DB error (count): " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        $totalPages = (int)ceil($total / max(1, (int)$perPage));
        if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        // fetch posts
        try {
            $sql = "
              SELECT DISTINCT p.id,p.title,p.slug,p.content,p.thumbnail,p.youtube,p.created_at
              FROM posts p
              JOIN post_categories pc ON pc.post_id = p.id
              WHERE pc.category_id IN ($inSQL)
                AND p.is_deleted = 0
            ";
            if (!$isLoggedIn) {
                $sql .= " AND p.status = 'published' ";
            }
            $sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
            }
            $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[CategoryController::showCategory] DB error (fetch posts): " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        // attach display images if helper exists
        $attachDisplayImages($posts);

        // vars for templates
        $vars = [
            'category' => $category,
            'posts' => $posts,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'site_context' => 'posts_list',
            'category_path' => implode('/', $parts),
        ];

        $content_html = '';

        // Respect cleared assignment for posts list (per-category or generic)
        $isCleared = false;
        if (function_exists('is_assignment_cleared')) {
            try {
                $isCleared = is_assignment_cleared($pdo, 'content', 'posts_list');
            } catch (Throwable $e) {
                $isCleared = false;
            }
        }

        // 1) assigned per-category slot (highest)
        if (!$isCleared && function_exists('render_assigned_slot')) {
            try {
                ob_start();
                render_assigned_slot($pdo, 'content', 'posts_list:' . $category['slug'], $vars);
                $assigned_percat = ob_get_clean();
                if (trim($assigned_percat) !== '') {
                    $content_html = $assigned_percat;
                }
            } catch (Throwable $e) {
                error_log("[CategoryController::showCategory] render_assigned_slot percat error: " . $e->getMessage());
            }
        }

        // 2) assigned generic slot posts_list
        if (trim($content_html) === '' && !$isCleared && function_exists('render_assigned_slot')) {
            try {
                ob_start();
                render_assigned_slot($pdo, 'content', 'posts_list', $vars);
                $assigned_generic = ob_get_clean();
                if (trim($assigned_generic) !== '') {
                    $content_html = $assigned_generic;
                }
            } catch (Throwable $e) {
                error_log("[CategoryController::showCategory] render_assigned_slot generic error: " . $e->getMessage());
            }
        }

        // 3) theme engine slots: prefer 'list.category' then 'list.post'
        if (trim($content_html) === '' && !$isCleared && function_exists('render_slot')) {
            try {
                $slotHtml = render_slot($pdo, 'list.category', $vars);
                if (trim((string)$slotHtml) !== '') {
                    $content_html = $slotHtml;
                } else {
                    $slotHtml2 = render_slot($pdo, 'list.post', $vars);
                    if (trim((string)$slotHtml2) !== '') $content_html = $slotHtml2;
                }
            } catch (Throwable $e) {
                error_log("[CategoryController::showCategory] render_slot(list.category/list.post) error: " . $e->getMessage());
            }
        }

// 4) Explicit: try DEFAULT_THEME_FOLDER for list.category, fallback to list.post file
if (trim($content_html) === '') {
    try {
        $themeFile = slot_to_file('list.category');
        $resolved = [
            'type' => 'theme_file',
            'theme_folder' => DEFAULT_THEME_FOLDER,
            'theme_file' => $themeFile,
        ];
        $path = resolve_theme_file_path($resolved);
        if ($path) {
            $content_html = include_template_file($path, $vars);
        } else {
            // second chance: try list.post in default theme
            $themeFile2 = slot_to_file('list.post');
            $resolved2 = [
                'type' => 'theme_file',
                'theme_folder' => DEFAULT_THEME_FOLDER,
                'theme_file' => $themeFile2,
            ];
            $path2 = resolve_theme_file_path($resolved2);
            if ($path2) {
                $content_html = include_template_file($path2, $vars);
            }
        }
    } catch (Throwable $e) {
        error_log("[CategoryController::showCategory] default-theme attempt error: " . $e->getMessage());
    }
}

        // 6) inline fallback
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <div class="container">
              <h1><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></h1>
              <?php if (empty($posts)): ?>
                <p>Tidak ada artikel dalam kategori ini.</p>
              <?php else: ?>
                <?php foreach ($posts as $p): ?>
                  <article class="post-item" style="margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid #eee">
                    <h2><a href="/<?= rawurlencode($p['slug']) ?>/"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                    <div class="meta"><?= htmlspecialchars($p['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($p['thumbnail'])): ?>
                      <img src="<?= htmlspecialchars($p['thumbnail'], ENT_QUOTES, 'UTF-8') ?>" alt="" style="max-width:220px;display:block;margin-bottom:.6rem">
                    <?php endif; ?>
                    <p><?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 360, '…'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p><a href="/<?= rawurlencode($p['slug']) ?>/">Baca selengkapnya →</a></p>
                  </article>
                <?php endforeach; ?>

                <nav aria-label="Pagination" style="margin-top:1rem;">
                  <?php
                    $base = '/category/' . implode('/', array_map('rawurlencode', $parts)) . '/';
                  ?>
                  <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($base . '?page=' . ($page - 1), ENT_QUOTES, 'UTF-8') ?>">&larr; Sebelumnya</a>
                  <?php endif; ?>
                  &nbsp; Halaman <?= $page ?> dari <?= $totalPages ?> &nbsp;
                  <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($base . '?page=' . ($page + 1), ENT_QUOTES, 'UTF-8') ?>">Berikutnya &rarr;</a>
                  <?php endif; ?>
                </nav>
              <?php endif; ?>
            </div>
            <?php
            $content_html = ob_get_clean();
        }

        // expose to layout
        $page_title = htmlspecialchars($category['name'] . ' — Kategori', ENT_QUOTES, 'UTF-8');
        $context_for_layout = 'list.category';

        require __DIR__ . '/../layout.php';
        exit;
    }

    public static function listCategories(PDO $pdo)
    {
        try {
            $stmt = $pdo->query("SELECT id, name, slug, description, parent_id FROM categories WHERE is_deleted = 0 ORDER BY name ASC");
            $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[CategoryController::listCategories] DB error: " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        $page_title = 'Kategori';
        ob_start();
        ?>
        <div class="container">
          <h1>Kategori</h1>
          <?php if (empty($cats)): ?>
            <p>Tidak ada kategori.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($cats as $c): ?>
                <?php
                  if (empty($c['parent_id'])) {
                      $link = '/category/' . rawurlencode($c['slug']) . '/';
                  } else {
                      $link = '/category/' . rawurlencode($c['slug']) . '/';
                  }
                ?>
                <li><a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <?php
        $content_html = ob_get_clean();
        
                // ==============================
        // LAYOUT OVERRIDE (INI TEMPATNYA)
        // ==============================
        // Default layout policy di layout.php:
        // - homepage: full width + sidebar off
        // - selain homepage: container + sidebar on
        //
        // Kalau theme-page kamu mau beda, set di sini:

        $layout_full_width = false;   // paksa pakai container (jadi tidak full width)
        $enable_sidebar    = true;    // paksa sidebar aktif
        $sidebar_position  = 'right';  // 'left' atau 'right'

        // (Opsional) kalau mau bisa diatur dari meta JSON pada post theme:
        // meta contoh: {"layout_full_width":true,"enable_sidebar":false,"sidebar_position":"right"}
        if (!empty($themeData['meta'])) {
            $meta = json_decode((string)$themeData['meta'], true);
            if (is_array($meta)) {
                if (array_key_exists('layout_full_width', $meta)) $layout_full_width = (bool)$meta['layout_full_width'];
                if (array_key_exists('enable_sidebar', $meta))    $enable_sidebar    = (bool)$meta['enable_sidebar'];
                if (!empty($meta['sidebar_position'])) {
                    $pos = strtolower(trim((string)$meta['sidebar_position']));
                    if (in_array($pos, ['left','right'], true)) $sidebar_position = $pos;
                }
            }
        }
        
        // END SIDEBAR

        require __DIR__ . '/../layout.php';
        exit;
    }
}
