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
        $paths = [
            PUBLIC_PATH . '/views/category/' . $filename,
            PUBLIC_PATH . '/views/' . $filename,
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

    /**
     * Ambil slug path dari REQUEST_URI (bagian setelah /category/)
     * Contoh: /category/news/photo/?page=2 -> "news/photo"
     */
    private static function slugFromRequestUri(string $prefix = 'category'): string
    {
        $path = '/';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        }
        $path = trim((string)$path);

        $needle = '/' . trim($prefix, '/');
        $pos = strpos($path, $needle);
        if ($pos === false) return '';

        $after = substr($path, $pos + strlen($needle));
        $after = trim((string)$after, " \t\n\r\0\x0B/");

        // decode setiap segmen path
        $after = preg_replace_callback('~[^/]+~', function ($m) {
            return rawurldecode($m[0]);
        }, (string)$after);

        return (string)$after;
    }

    private static function resolveCategoryBySlug(PDO $pdo, string $slug)
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = :slug AND is_deleted = 0 LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
        } catch (Throwable $e) {
            error_log("[CategoryController::resolveCategoryBySlug] DB error: " . $e->getMessage());
            return false;
        }
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
                    $sql = "SELECT * FROM categories
                            WHERE slug = :slug
                              AND is_deleted = 0
                              AND (parent_id IS NULL OR parent_id = 0)
                            LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':slug' => $seg]);
                } else {
                    $sql = "SELECT * FROM categories
                            WHERE slug = :slug
                              AND parent_id = :parent_id
                              AND is_deleted = 0
                            LIMIT 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':slug' => $seg, ':parent_id' => (int)$parentId]);
                }

                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) return false;

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
            $pid = ($r['parent_id'] === null) ? null : (int)$r['parent_id'];
            $children[$pid][] = (int)$r['id'];
        }

        $result = [];
        $queue = [$rootId];
        while (!empty($queue)) {
            $cur = array_pop($queue);
            if (in_array($cur, $result, true)) continue;
            $result[] = $cur;
            if (!empty($children[$cur])) {
                foreach ($children[$cur] as $c) $queue[] = $c;
            }
        }

        return $result;
    }

    private static function trySlot(PDO $pdo, string $slotKey, array $vars): string
    {
        if (!function_exists('render_slot')) return '';
        try {
            $out = render_slot($pdo, $slotKey, $vars);
            return is_string($out) ? $out : (string)$out;
        } catch (Throwable $e) {
            error_log("[CategoryController] render_slot error ({$slotKey}): " . $e->getMessage());
            return '';
        }
    }

    /**
     * Paksa load file dari DEFAULT_THEME_FOLDER untuk slotKey tertentu.
     */
    private static function tryDefaultThemeFile(string $slotKey, array $vars): string
    {
        if (
            !defined('DEFAULT_THEME_FOLDER') ||
            !function_exists('slot_to_file') ||
            !function_exists('resolve_theme_file_path') ||
            !function_exists('include_template_file')
        ) {
            return '';
        }

        try {
            $themeFile = slot_to_file($slotKey);
            $resolved = [
                'type'         => 'theme_file',
                'theme_folder' => DEFAULT_THEME_FOLDER,
                'theme_file'   => $themeFile,
            ];
            $path = resolve_theme_file_path($resolved);
            if ($path) return (string)include_template_file($path, $vars);
        } catch (Throwable $e) {
            error_log("[CategoryController] default-theme attempt error ({$slotKey}): " . $e->getMessage());
        }

        return '';
    }

    public static function showCategory(PDO $pdo, string $slug = '', int $page = 1, string $q = '')
    {
        $slug = trim((string)$slug, " \t\n\r\0\x0B/");
        $q = trim((string)$q);

        $catPrefix = function_exists('get_category_path') ? get_category_path($pdo) : 'category';
        $hasPrefix = $catPrefix !== '';
        $catBase = $hasPrefix ? '/' . $catPrefix . '/' : '/';

        // Paksa slug dari REQUEST_URI agar /category/news/photo/ selalu akurat
        $reqPath = '/';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        }
        $reqPathTrim = rtrim((string)$reqPath, '/');
        $isCategoryIndex = $hasPrefix && ($reqPathTrim === '/' . $catPrefix);

        if ($hasPrefix && !$isCategoryIndex) {
            $uriSlug = self::slugFromRequestUri($catPrefix);
            // Strip pagination segments (/page/N/) so slug remains clean
            $uriSlug = preg_replace('#/page/\d+#', '', $uriSlug);
            if ($uriSlug !== '') $slug = $uriSlug;
        } elseif (!$hasPrefix && $slug === '') {
            // Root-level categories: slug must come from the parameter (already resolved)
        }

        // Helper: attach display images
        $attachDisplayImages = function (&$posts) {
            if (class_exists('PostController') && method_exists('PostController', 'attach_display_images')) {
                try { PostController::attach_display_images($posts); } catch (Throwable $e) {}
            }
        };

        // Root-level categories with empty prefix have no index page
        if (!$hasPrefix && $slug === '') {
            http_response_code(404);
            $page_title = '404';
            $context_for_layout = '404';
            $content_html = '';
            require __DIR__ . '/../layout.php';
            exit;
        }

        // =========================
        // INDEX /category (parents)
        // =========================
        if ($slug === '' || $isCategoryIndex) {
            try {
                $stmt = $pdo->query("
                    SELECT id, name, slug, description
                    FROM categories
                    WHERE is_deleted = 0
                      AND (parent_id IS NULL OR parent_id = 0)
                    ORDER BY name ASC
                ");
                $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                error_log("[CategoryController::showCategory] DB error (list parents): " . $e->getMessage());
                http_response_code(500);
                echo "Server error";
                exit;
            }

            $page_title = 'Kategori';
            $vars = [
                'categories'   => $cats,
                'site_context' => 'categories_parents_list',
                'page_title'   => $page_title,
                'q'            => $q,
                'cat_base'     => $catBase,
            ];

            $content_html = '';

            // 1) engine slot
            $content_html = self::trySlot($pdo, 'index.category', $vars);

            // 2) enforce default theme
            if (trim($content_html) === '') {
                $content_html = self::tryDefaultThemeFile('index.category', $vars);
            }

            // 3) local view (opsional)
            if (trim($content_html) === '') {
                $local = self::render_local_view('index.php', $vars);
                if (trim($local) === '') $local = self::render_local_view('parents_index.php', $vars);
                if (trim($local) !== '') $content_html = $local;
            }

            // 4) inline fallback
            if (trim($content_html) === '') {
                ob_start(); ?>
                <div class="container">
                  <h1>Kategori</h1>
                  <?php if (empty($cats)): ?>
                    <p>Tidak ada kategori level atas.</p>
                  <?php else: ?>
                    <ul>
                      <?php foreach ($cats as $c): ?>
                        <li><a href="<?= htmlspecialchars($catBase . rawurlencode($c['slug']) . '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
                <?php
                $content_html = ob_get_clean();
            }

            $context_for_layout = 'index.category';

            // layout override (aman dari notice)
            $layout_full_width = false;
            $enable_sidebar    = false;
            $sidebar_position  = 'right';

            if (isset($themeData) && !empty($themeData['meta'])) {
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

            require __DIR__ . '/../layout.php';
            exit;
        }

        // =========================
        // RESOLVE CATEGORY
        // =========================
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
            $last = (string)end($parts);
            $category = self::resolveCategoryBySlug($pdo, $last);
        }

        if (!$category) {
            http_response_code(404);
            $page_title = 'Kategori tidak ditemukan';
            $content_html = '<h1>404 — Kategori tidak ditemukan</h1>';
            require __DIR__ . '/../layout.php';
            exit;
        }

        $page       = max(1, (int)$page);
        $offset     = ($page - 1) * 10;
        $isLoggedIn = !empty($_SESSION['user_id'] ?? null);

        $categoryId = (int)$category['id'];
        $catIds     = self::getDescendantCategoryIds($pdo, $categoryId);

        // build IN clause
        $inPlaceholders = [];
        $params = [];
        foreach ($catIds as $i => $cid) {
            $ph = ":cid" . $i;
            $inPlaceholders[] = $ph;
            $params[$ph] = (int)$cid;
        }
        $inSQL = implode(',', $inPlaceholders);

        // COUNT
        try {
            $countSql = "
              SELECT COUNT(DISTINCT p.id)
              FROM posts p
              JOIN post_categories pc ON pc.post_id = p.id
              WHERE pc.category_id IN ($inSQL)
                AND p.is_deleted = 0
                AND p.type = 'article'
            ";
            if (!$isLoggedIn) $countSql .= " AND p.status = 'published' ";

            $countParams = $params;
            if ($q !== '') {
                $countSql .= " AND (p.title LIKE :q OR p.slug LIKE :q OR p.content LIKE :q) ";
                $countParams[':q'] = '%' . $q . '%';
            }

            $stmt = $pdo->prepare($countSql);
            foreach ($countParams as $k => $v) {
                if (strpos($k, ':cid') === 0) $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
                else $stmt->bindValue($k, (string)$v, PDO::PARAM_STR);
            }
            $stmt->execute();
            $total = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("[CategoryController::showCategory] DB error (count): " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        $perPage = 10;
        $totalPages = (int)ceil($total / max(1, $perPage));
        if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        // FETCH
        try {
            $selectCols = "p.id,p.title,p.slug,p.content,p.thumbnail,p.youtube,p.meta,p.created_at";

            $sql = "
              SELECT DISTINCT $selectCols
              FROM posts p
              JOIN post_categories pc ON pc.post_id = p.id
              WHERE pc.category_id IN ($inSQL)
                AND p.is_deleted = 0
                AND p.type = 'article'
            ";
            if (!$isLoggedIn) $sql .= " AND p.status = 'published' ";

            $fetchParams = $params;
            if ($q !== '') {
                $sql .= " AND (p.title LIKE :q OR p.slug LIKE :q OR p.content LIKE :q) ";
                $fetchParams[':q'] = '%' . $q . '%';
            }

            $sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($fetchParams as $k => $v) {
                if (strpos($k, ':cid') === 0) $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
                else $stmt->bindValue($k, (string)$v, PDO::PARAM_STR);
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

        $attachDisplayImages($posts);

        $vars = [
            'category'      => $category,
            'posts'         => $posts,
            'page'          => $page,
            'perPage'       => $perPage,
            'total'         => $total,
            'totalPages'    => $totalPages,
            'category_path' => implode('/', $parts),
            'site_context'  => 'posts_list',
            'q'             => $q,
        ];

        // =========================
        // RENDER
        // =========================
        $content_html = '';

        // 1) engine slot list.category
        $content_html = self::trySlot($pdo, 'list.category', $vars);

        // 2) fallback engine list.post
        if (trim($content_html) === '') {
            $content_html = self::trySlot($pdo, 'list.post', $vars);
        }

        // 3) enforce default theme list.category / list.post
        if (trim($content_html) === '') {
            $content_html = self::tryDefaultThemeFile('list.category', $vars);
        }
        if (trim($content_html) === '') {
            $content_html = self::tryDefaultThemeFile('list.post', $vars);
        }

        // 4) local view (opsional)
        if (trim($content_html) === '') {
            $local = self::render_local_view('posts_list.php', $vars);
            if (trim($local) !== '') $content_html = $local;
        }

        // 5) inline fallback
        if (trim($content_html) === '') {
            ob_start(); ?>
            <div class="container">
              <h1><?= htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8') ?></h1>

              <?php if (!empty($q)): ?>
                <p style="color:#666">Pencarian: <strong><?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?></strong></p>
              <?php endif; ?>

              <?php if (empty($posts)): ?>
                <p>Tidak ada artikel dalam kategori ini.</p>
              <?php else: ?>
                <?php foreach ($posts as $p): ?>
                  <article style="margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid #eee">
                    <h2><a href="<?= htmlspecialchars(function_exists('get_post_permalink') ? get_post_permalink($p) : '/' . rawurlencode($p['slug']) . '/', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?></a></h2>
                    <div style="color:#666;font-size:13px"><?= htmlspecialchars((string)$p['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                  </article>
                <?php endforeach; ?>

                <nav aria-label="Pagination" style="margin-top:1rem;">
                  <?php $base = $catBase . implode('/', array_map('rawurlencode', $parts)) . '/'; ?>
                  <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($base . '?page=' . ($page - 1) . ($q !== '' ? '&q=' . rawurlencode($q) : ''), ENT_QUOTES, 'UTF-8') ?>">&larr; Sebelumnya</a>
                  <?php endif; ?>
                  &nbsp; Halaman <?= (int)$page ?> dari <?= (int)$totalPages ?> &nbsp;
                  <?php if ($page < $totalPages): ?>
                    <a href="<?= htmlspecialchars($base . '?page=' . ($page + 1) . ($q !== '' ? '&q=' . rawurlencode($q) : ''), ENT_QUOTES, 'UTF-8') ?>">Berikutnya &rarr;</a>
                  <?php endif; ?>
                </nav>
              <?php endif; ?>
            </div>
            <?php
            $content_html = ob_get_clean();
        }

        $page_title = htmlspecialchars($category['name'] . ' — Kategori', ENT_QUOTES, 'UTF-8');
        $context_for_layout = 'list.category';
        require __DIR__ . '/../layout.php';
        exit;
    }

    public static function listCategories(PDO $pdo)
    {
        // Keep compatibility; simply call showCategory with empty slug
        self::showCategory($pdo, '', 1, '');
    }
}