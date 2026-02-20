<?php
// controllers/PhotoController.php
declare(strict_types=1);

class PhotoController
{
    /** Prefix URL untuk seluruh halaman gallery (kategori) */
    private const PREFIX = '/gallery/';

    /**
     * Slot keys mengikuti arsitektur theme_helper.php (slot_to_file map):
     * - index.category  => main/index/category.php
     * - list.category   => main/list/category.php
     * - single.post     => main/single/post.php
     * - main.404        => main/404.php
     */
// slot keys khusus Gallery
// slot keys khusus Gallery
private const SLOT_INDEX_GALLERY  = 'index.gallery';
private const SLOT_LIST_GALLERY   = 'list.gallery';
private const SLOT_SINGLE_GALLERY = 'single.gallery';

// untuk showSingle() (dipanggil PostController)
private const SLOT_SINGLE_POST = 'single.post';

// 404
private const SLOT_404 = 'main.404';

    /** Urutan album/photo post: sort_order dulu (0 dianggap paling belakang) */
    private static function photoPostOrderBy(string $alias = 'p'): string
    {
        return " ORDER BY ($alias.sort_order = 0) ASC, $alias.sort_order ASC, $alias.created_at DESC, $alias.id DESC ";
    }

    /* =========================================================
     * Fallback 4 sistem default (sesuai PostController)
     * 1) render_assigned_slot (respect is_assignment_cleared)
     * 2) render_slot (theme engine: assignment->active theme->default fallback by engine)
     * 3) enforce DEFAULT_THEME_FOLDER file langsung (sebelum controller fallback)
     * 4) controller fallback (local view / inline)
     * ========================================================= */

    private static function isAssignmentCleared(PDO $pdo, string $contextKey): bool
    {
        if (!function_exists('is_assignment_cleared')) return false;
        try {
            return (bool)is_assignment_cleared($pdo, 'content', $contextKey);
        } catch (Throwable $e) {
            error_log("[PhotoController] is_assignment_cleared error: " . $e->getMessage());
            return false;
        }
    }

    private static function tryAssigned(PDO $pdo, string $assignKey, array $vars): string
    {
        if (!function_exists('render_assigned_slot')) return '';
        try {
            ob_start();
            render_assigned_slot($pdo, 'content', $assignKey, $vars);
            return (string)ob_get_clean();
        } catch (Throwable $e) {
            if (ob_get_level()) @ob_end_clean();
            error_log("[PhotoController] render_assigned_slot error ({$assignKey}): " . $e->getMessage());
            return '';
        }
    }

    private static function trySlot(PDO $pdo, string $slotKey, array $vars): string
    {
        if (!function_exists('render_slot')) return '';
        try {
            $out = render_slot($pdo, $slotKey, $vars);
            return is_string($out) ? $out : (string)$out;
        } catch (Throwable $e) {
            error_log("[PhotoController] render_slot error ({$slotKey}): " . $e->getMessage());
            return '';
        }
    }

    /**
     * Enforce DEFAULT_THEME_FOLDER sebelum fallback controller.
     * Ini sengaja "melewati" active theme dan langsung load file dari DEFAULT_THEME_FOLDER.
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
            error_log("[PhotoController] default-theme attempt error ({$slotKey}): " . $e->getMessage());
        }

        return '';
    }

    /* =========================================================
     * Helpers (image, media target, author, categories)
     * ========================================================= */

    private static function extract_first_image_src_from_html(?string $html): ?string
    {
        if (!$html) return null;
        if (!class_exists('DOMDocument')) return null;

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        if (!$loaded) return null;

        $imgs = $doc->getElementsByTagName('img');
        foreach ($imgs as $img) {
            $src = trim((string)$img->getAttribute('src'));
            if ($src !== '') return $src;
        }
        return null;
    }

    /** Photo display image priority: thumbnail (cover) > first image in content (legacy) > null */
    private static function resolve_post_display_image(array $post): ?string
    {
        if (!empty($post['thumbnail'])) return (string)$post['thumbnail'];

        if (!empty($post['content'])) {
            $img = self::extract_first_image_src_from_html((string)$post['content']);
            if ($img) return $img;
        }

        return null;
    }

    /** Attach target_url & target_attribute dari table media untuk display_image */
    private static function attachDisplayImageTarget(array &$postData, PDO $pdo): void
    {
        $postData['display_image_target_url'] = null;
        $postData['display_image_target_attribute'] = null;

        if (empty($postData['display_image'])) return;

        $displayUrl = (string)$postData['display_image'];

        try {
            // 1) exact match
            $stm = $pdo->prepare("SELECT target_url, target_attribute FROM media WHERE url = :url LIMIT 1");
            $stm->execute([':url' => $displayUrl]);
            $m = $stm->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$m) {
                // 2) fallback: match by path fragment (useful when DB stores relative path)
                $path = parse_url($displayUrl, PHP_URL_PATH) ?: $displayUrl;
                $likePath = '%' . ltrim((string)$path, '/');
                $stm2 = $pdo->prepare("SELECT target_url, target_attribute FROM media WHERE url LIKE :like_path LIMIT 1");
                $stm2->execute([':like_path' => $likePath]);
                $m = $stm2->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if ($m) {
                $postData['display_image_target_url'] = $m['target_url'] !== null ? $m['target_url'] : null;
                $postData['display_image_target_attribute'] = $m['target_attribute'] !== null ? $m['target_attribute'] : null;
            }
        } catch (Throwable $e) {
            error_log("[PhotoController::attachDisplayImageTarget] " . $e->getMessage());
            $postData['display_image_target_url'] = null;
            $postData['display_image_target_attribute'] = null;
        }
    }

    private static function augmentAuthor(array &$postData, PDO $pdo): void
    {
        $postData['author_id'] = null;
        $postData['author_name'] = null;
        $postData['author_username'] = null;
        $postData['author_email'] = null;
        $postData['author_img'] = null;
        $postData['author_label'] = 'Kontributor';

        if (empty($postData['created_by'])) return;

        try {
            $stm = $pdo->prepare("
                SELECT id, name, username, email, img, is_deleted
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $stm->execute([':id' => (int)$postData['created_by']]);
            $author = $stm->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($author) {
                $postData['author_id']       = $author['id'] ?? null;
                $postData['author_name']     = $author['name'] ?? null;
                $postData['author_username'] = $author['username'] ?? null;
                $postData['author_email']    = $author['email'] ?? null;
                $postData['author_img']      = $author['img'] ?? null;
                $postData['author_label']    = $author['name']
                                            ?? $author['username']
                                            ?? $author['email']
                                            ?? 'Kontributor';
            }
        } catch (Throwable $e) {
            error_log("[PhotoController::augmentAuthor] " . $e->getMessage());
        }
    }

    private static function augmentCategories(array &$postData, PDO $pdo): void
    {
        try {
            $catStmt = $pdo->prepare("
                SELECT
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS category_names,
                    GROUP_CONCAT(DISTINCT c.slug ORDER BY c.name SEPARATOR ', ') AS category_slugs
                FROM post_categories pc
                JOIN categories c
                  ON c.id = pc.category_id
                 AND c.is_deleted = 0
                WHERE pc.post_id = :pid
            ");
            $catStmt->execute([':pid' => (int)$postData['id']]);
            $cats = $catStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $postData['category_names'] = $cats['category_names'] ?? '';
            $postData['category_slugs'] = $cats['category_slugs'] ?? '';
        } catch (Throwable $e) {
            error_log("[PhotoController::augmentCategories] " . $e->getMessage());
            $postData['category_names'] = '';
            $postData['category_slugs'] = '';
        }
    }

    /** Ambil item gallery untuk 1 photo post (urut sort_order) */
    private static function fetchPhotoItems(PDO $pdo, int $postId): array
    {
        try {
            $it = $pdo->prepare("
                SELECT
                    pmi.media_id AS id,
                    m.url,
                    COALESCE(pmi.caption_override, '') AS caption,
                    COALESCE(pmi.alt_override, '') AS alt,
                    COALESCE(pmi.link_url_override, '') AS link_url,
                    COALESCE(pmi.link_target_override, '') AS link_target,
                    pmi.sort_order
                FROM post_media_items pmi
                JOIN media m ON m.id = pmi.media_id
                WHERE pmi.post_id = :pid
                ORDER BY pmi.sort_order ASC, pmi.media_id ASC
            ");
            $it->execute([':pid' => $postId]);
            return $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("[PhotoController::fetchPhotoItems] " . $e->getMessage());
            return [];
        }
    }

    /* =========================================================
     * Routing robustness helpers
     * ========================================================= */

    /** Router kadang kebalik urutan arg: (pdo, q, page) vs (pdo, page, q). Ini bikin aman. */
    private static function normalizePageAndQuery($arg2, $arg3): array
    {
        $page = 1;
        $q = '';

        if (is_numeric($arg2)) {
            $page = max(1, (int)$arg2);
            $q = (string)$arg3;
        } else {
            $q = (string)$arg2;
            $page = is_numeric($arg3) ? max(1, (int)$arg3) : 1;
        }

        $q = trim($q);
        return [$page, $q];
    }

    private static function buildPath(array $slugs): string
    {
        $slugs = array_values(array_filter(array_map('trim', $slugs), fn($s) => $s !== ''));
        if (!$slugs) return self::PREFIX;
        $enc = array_map('rawurlencode', $slugs);
        return rtrim(self::PREFIX, '/') . '/' . implode('/', $enc) . '/';
    }

    /** Breadcrumb chain dari root -> parent, tanpa current category */
    private static function getBreadcrumbs(PDO $pdo, array $category): array
    {
        $breadcrumbs = [];
        $cur = $category;

        while (!empty($cur['parent_id'])) {
            try {
                $pStmt = $pdo->prepare("SELECT id, name, slug, parent_id FROM categories WHERE id = :id AND is_deleted = 0 LIMIT 1");
                $pStmt->execute([':id' => (int)$cur['parent_id']]);
                $parent = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $parent = null;
            }
            if (!$parent) break;

            array_unshift($breadcrumbs, $parent);
            $cur = $parent;
        }

        return $breadcrumbs;
    }

    /** Tambahkan field 'url' untuk tiap breadcrumb dan juga current category path */
    private static function addUrlsToCrumbs(array $breadcrumbs, array $currentCategory): array
    {
        $chain = [];
        $slugs = [];

        foreach ($breadcrumbs as $b) {
            $slugs[] = (string)$b['slug'];
            $b['url'] = self::buildPath($slugs);
            $chain[] = $b;
        }

        $slugs2 = array_merge($slugs, [(string)$currentCategory['slug']]);
        $currentCategory['url'] = self::buildPath($slugs2);

        return [$chain, $currentCategory, $slugs2];
    }

    /** Resolve chain berdasarkan path slugs dan validasi parent-child sesuai urutan path */
    private static function resolveCategoryByPath(PDO $pdo, array $slugs): array
    {
        $slugs = array_values(array_filter(array_map('trim', $slugs), fn($s) => $s !== ''));

        if (!$slugs) {
            return [null, [], []];
        }

        $chain = [];
        $expectedParentId = 0;

        foreach ($slugs as $i => $slug) {
            try {
                $stmt = $pdo->prepare("SELECT id, name, slug, parent_id FROM categories WHERE slug = :slug AND is_deleted = 0 LIMIT 1");
                $stmt->execute([':slug' => $slug]);
                $cat = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $cat = null;
            }

            if (!$cat) {
                return [null, [], []];
            }

            $pid = (int)($cat['parent_id'] ?? 0);

            if ($i === 0) {
                if (!($pid === 0 || $cat['parent_id'] === null)) {
                    return [null, [], []];
                }
            } else {
                if ($pid !== $expectedParentId) {
                    return [null, [], []];
                }
            }

            $chain[] = $cat;
            $expectedParentId = (int)$cat['id'];
        }

        $current = $chain[count($chain) - 1];
        $breadcrumbs = array_slice($chain, 0, -1);

        return [$current, $breadcrumbs, $chain];
    }

    private static function render404(PDO $pdo, string $title = 'Halaman tidak ditemukan'): void
    {
        http_response_code(404);

        $page_title = $title;
        $context_for_layout = '404';

        $vars = [
            'page_title'   => $page_title,
            'site_context' => '404',
            'context'      => '404',
        ];

        $content_html = '';

        // (opsional) assignment khusus 404 jika kamu mau pakai (tidak wajib ada di DB)
        if (!self::isAssignmentCleared($pdo, 'main_404')) {
            $a = self::tryAssigned($pdo, 'main_404', $vars);
            if (trim($a) !== '') $content_html = $a;
        }

        if (trim($content_html) === '') {
            $out = self::trySlot($pdo, self::SLOT_404, $vars);
            if (trim($out) !== '') $content_html = $out;
        }

        if (trim($content_html) === '') {
            $out = self::tryDefaultThemeFile(self::SLOT_404, $vars);
            if (trim($out) !== '') $content_html = $out;
        }

        if (trim($content_html) === '') {
            $content_html = '<div class="container"><h1>404</h1><p>Halaman tidak ditemukan.</p></div>';
        }

        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        require __DIR__ . '/../layout.php';
        exit;
    }

    /* =========================================================
     * Public: /gallery/ (index)
     * ========================================================= */

    /**
     * INDEX gallery: /gallery/
     * Arg fleksibel: bisa dipanggil index($pdo, $page, $q) atau index($pdo, $q, $page)
     */
    public static function index(PDO $pdo, $arg2 = 1, $arg3 = '')
    {
        [$page, $q] = self::normalizePageAndQuery($arg2, $arg3);
        $isLoggedIn = !empty($_SESSION['user_id']);

        $page_title = 'Gallery';
        $context_for_layout = self::SLOT_INDEX_GALLERY;

        // Root categories (parent null/0)
        try {
            $catSql = "
                SELECT c.id, c.name, c.slug, c.parent_id, COUNT(DISTINCT pc.post_id) AS cnt
                FROM categories c
                LEFT JOIN post_categories pc ON pc.category_id = c.id
                LEFT JOIN posts p ON p.id = pc.post_id
                    AND p.type = 'photo'
                    AND p.is_deleted = 0
                    " . ($isLoggedIn ? "" : " AND p.status = 'published' ") . "
                WHERE c.is_deleted = 0 AND (c.parent_id IS NULL OR c.parent_id = 0)
                GROUP BY c.id
                ORDER BY cnt DESC, c.name ASC
            ";
            $catStmt = $pdo->prepare($catSql);
            $catStmt->execute();
            $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("[PhotoController::index] categories error: " . $e->getMessage());
            $categories = [];
        }

        foreach ($categories as &$c) {
            $c['url'] = self::buildPath([(string)$c['slug']]);
        }
        unset($c);

        // optional: recent photo posts (untuk index)
        try {
            $where = "p.type='photo' AND p.is_deleted=0";
            $params = [];
            if (!$isLoggedIn) $where .= " AND p.status='published' ";

            if ($q !== '') {
                $where .= " AND (p.title LIKE :q OR p.slug LIKE :q)";
                $params[':q'] = '%' . $q . '%';
            }

            $recentSql = "
                SELECT
                    p.id, p.title, p.slug, p.thumbnail, p.content, p.created_at, p.sort_order,
                    (SELECT COUNT(*) FROM post_media_items x WHERE x.post_id = p.id) AS media_count
                FROM posts p
                WHERE {$where}
                " . self::photoPostOrderBy('p') . "
                LIMIT 30
            ";
            $st = $pdo->prepare($recentSql);
            $st->execute($params);
            $recent = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("[PhotoController::index] recent error: " . $e->getMessage());
            $recent = [];
        }

        if (!empty($recent)) {
            foreach ($recent as &$r) {
                $r['display_image'] = self::resolve_post_display_image($r);
                try { self::attachDisplayImageTarget($r, $pdo); } catch (Throwable $e) {}
            }
            unset($r);
        }

        $vars = [
            'page_title'   => $page_title,
            'categories'   => $categories,
            'recent'       => $recent,
            'q'            => $q,
            'gallery_home' => ['label' => 'Gallery', 'url' => self::PREFIX],
            'site_context' => 'gallery_index',
            'context'      => 'gallery_index',
        ];

        $content_html = '';

        // 1) assignment
        if (!self::isAssignmentCleared($pdo, 'gallery_index')) {
            $assigned = self::tryAssigned($pdo, 'gallery_index', $vars);
            if (trim($assigned) !== '') $content_html = $assigned;
        }

// 2) theme engine slot (index.gallery)
if (trim($content_html) === '') {
    $slotOut = self::trySlot($pdo, self::SLOT_INDEX_GALLERY, $vars);
    if (trim($slotOut) !== '') $content_html = $slotOut;
}

// 3) enforce DEFAULT_THEME_FOLDER untuk index.gallery
if (trim($content_html) === '') {
    $defOut = self::tryDefaultThemeFile(self::SLOT_INDEX_GALLERY, $vars);
    if (trim($defOut) !== '') $content_html = $defOut;
}

        // 4) controller fallback (local view -> inline)
        if (trim($content_html) === '') {
            $local_view = __DIR__ . '/../views/photo/photo-index.php';
            if (is_file($local_view)) {
                ob_start();
                extract($vars, EXTR_SKIP);
                include $local_view;
                $content_html = (string)ob_get_clean();
            } else {
                ob_start();
                ?>
                <div class="container">
                    <h1>Gallery (DEFAULT)</h1>
                    <?php if (!empty($categories)): ?>
                        <ul>
                            <?php foreach ($categories as $c): ?>
                                <li>
                                    <a href="<?= htmlspecialchars((string)($c['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)($c['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>Belum ada kategori gallery.</p>
                    <?php endif; ?>
                </div>
                <?php
                $content_html = (string)ob_get_clean();
            }
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $canonical_url = $scheme . '://' . $host . self::PREFIX;

        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        require __DIR__ . '/../layout.php';
        exit;
    }

    /* =========================================================
     * Public: path category /gallery/{...}/
     * ========================================================= */

    /**
     * PENTING: Ini dipanggil router untuk /gallery/news/photo/
     * Bisa menerima:
     *  - array slugs: ['news','photo']
     *  - string path: 'news/photo'
     */
    public static function showCategoryPath(PDO $pdo, $pathOrSlugs, $arg3 = 1, $arg4 = '')
    {
        [$page, $q] = self::normalizePageAndQuery($arg3, $arg4);

        $slugs = [];
        if (is_array($pathOrSlugs)) {
            $slugs = $pathOrSlugs;
        } else {
            $path = trim((string)$pathOrSlugs, '/');
            $slugs = ($path === '') ? [] : explode('/', $path);
        }

        $slugs = array_values(array_filter(array_map('trim', $slugs), fn($s) => $s !== ''));

        if (empty($slugs)) {
            self::index($pdo, $page, $q);
            return;
        }

        [$category, $breadcrumbs] = self::resolveCategoryByPath($pdo, $slugs);
        if (!$category) {
            self::render404($pdo, 'Kategori tidak ditemukan');
        }

        self::renderCategory($pdo, $category, $breadcrumbs, (int)$page, (string)$q);
    }

    /** Fallback jika router masih memanggil showCategory($pdo,'slug',...) */
    public static function showCategory(PDO $pdo, string $slug, $arg3 = 1, $arg4 = '')
    {
        [$page, $q] = self::normalizePageAndQuery($arg3, $arg4);

        $slug = trim($slug);
        if ($slug === '') {
            header('Location: ' . self::PREFIX);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM categories WHERE slug = :slug AND is_deleted = 0 LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log("[PhotoController::showCategory] " . $e->getMessage());
            $category = null;
        }

        if (!$category) self::render404($pdo, 'Kategori tidak ditemukan');

        $breadcrumbs = self::getBreadcrumbs($pdo, $category);
        self::renderCategory($pdo, $category, $breadcrumbs, (int)$page, (string)$q);
    }

    private static function renderCategory(PDO $pdo, array $category, array $breadcrumbs, int $page, string $q): void
    {
        $isLoggedIn = !empty($_SESSION['user_id']);

        // Inject url ke breadcrumbs + current category url + chainSlugs
        [$breadcrumbsWithUrl, $categoryWithUrl, $chainSlugs] = self::addUrlsToCrumbs($breadcrumbs, $category);

        // Children categories
        try {
            $childStmt = $pdo->prepare("
                SELECT c.id, c.name, c.slug, c.parent_id,
                       (SELECT COUNT(DISTINCT pc.post_id)
                        FROM post_categories pc
                        JOIN posts p ON p.id = pc.post_id
                        WHERE pc.category_id = c.id
                          AND p.type = 'photo'
                          AND p.is_deleted = 0
                          " . ($isLoggedIn ? "" : " AND p.status = 'published' ") . "
                       ) AS cnt
                FROM categories c
                WHERE c.parent_id = :cat_id AND c.is_deleted = 0
                ORDER BY c.name ASC
            ");
            $childStmt->execute([':cat_id' => (int)$categoryWithUrl['id']]);
            $children = $childStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("[PhotoController::children] " . $e->getMessage());
            $children = [];
        }

        foreach ($children as &$ch) {
            $ch['url'] = self::buildPath(array_merge($chainSlugs, [(string)$ch['slug']]));
        }
        unset($ch);

        // ===== Jika punya children -> tampilkan halaman kategori (index.category) =====
        if (!empty($children)) {
            $page_title = ($categoryWithUrl['name'] ?? 'Gallery') . ' — Gallery';
            $context_for_layout = self::SLOT_INDEX_GALLERY;

            $vars = [
                'page_title'    => $page_title,
                'gallery_home'  => ['label' => 'Gallery', 'url' => self::PREFIX],
                'category'      => $categoryWithUrl,
                'children'      => $children,
                'breadcrumbs'   => $breadcrumbsWithUrl,
                'category_path' => self::buildPath($chainSlugs),
                'q'             => $q,
                'site_context'  => 'gallery_categories',
                'context'       => 'gallery_categories',
            ];

            $content_html = '';

            // 1) assignment (specific -> generic)
            if (!self::isAssignmentCleared($pdo, 'gallery_categories')) {
                $k1 = 'gallery_categories:' . (string)($categoryWithUrl['slug'] ?? '');
                $a1 = self::tryAssigned($pdo, $k1, $vars);
                if (trim($a1) !== '') {
                    $content_html = $a1;
                } else {
                    $a2 = self::tryAssigned($pdo, 'gallery_categories', $vars);
                    if (trim($a2) !== '') $content_html = $a2;
                }
            }

$context_for_layout = self::SLOT_LIST_GALLERY;

// 2) theme engine slot (list.gallery)
if (trim($content_html) === '') {
    $slotOut = self::trySlot($pdo, self::SLOT_LIST_GALLERY, $vars);
    if (trim($slotOut) !== '') $content_html = $slotOut;
}

// 3) enforce DEFAULT_THEME_FOLDER
if (trim($content_html) === '') {
    $defOut = self::tryDefaultThemeFile(self::SLOT_LIST_GALLERY, $vars);
    if (trim($defOut) !== '') $content_html = $defOut;
}
            // 4) controller fallback
            if (trim($content_html) === '') {
                $local_view = __DIR__ . '/../views/photo/photo-categories.php';
                if (is_file($local_view)) {
                    ob_start();
                    extract($vars, EXTR_SKIP);
                    include $local_view;
                    $content_html = (string)ob_get_clean();
                } else {
                    ob_start();
                    ?>
                    <div class="container">
                        <h1><?= htmlspecialchars((string)($categoryWithUrl['name'] ?? 'Gallery'), ENT_QUOTES, 'UTF-8') ?></h1>
                        <ul>
                            <?php foreach ($children as $ch): ?>
                                <li>
                                    <a href="<?= htmlspecialchars((string)($ch['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)($ch['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php
                    $content_html = (string)ob_get_clean();
                }
            }

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
            $canonical_url = $scheme . '://' . $host . self::buildPath($chainSlugs);

            $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
            $pdo = $layout_pdo;

            require __DIR__ . '/../layout.php';
            exit;
        }

        // ===== Leaf: 1 POST (ALBUM) per halaman, tampilkan max 30 item foto =====
        $perPage = 1;
        $page = max(1, (int)$page);
        $offset = ($page - 1) * $perPage;

        // Count total album
        try {
            $countSql = "
              SELECT COUNT(DISTINCT p.id)
              FROM posts p
              JOIN post_categories pc ON pc.post_id = p.id
              WHERE pc.category_id = :cat_id
                AND p.is_deleted = 0
                AND p.type = 'photo'
            ";
            $params = [':cat_id' => (int)$categoryWithUrl['id']];
            if (!$isLoggedIn) $countSql .= " AND p.status = 'published' ";
            if ($q !== '') {
                $countSql .= " AND (p.title LIKE :q OR p.slug LIKE :q)";
                $params[':q'] = '%' . $q . '%';
            }

            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("[PhotoController::count albums] " . $e->getMessage());
            $total = 0;
        }

        $totalPages = (int)ceil($total / max(1, $perPage));
        if ($totalPages > 0 && $page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        // Fetch 1 album
        $album = null;
        try {
            $sql = "
              SELECT DISTINCT
                p.id, p.title, p.slug, p.thumbnail, p.content, p.created_at, p.sort_order, p.created_by
              FROM posts p
              JOIN post_categories pc ON pc.post_id = p.id
              WHERE pc.category_id = :cat_id
                AND p.is_deleted = 0
                AND p.type = 'photo'
            ";
            if (!$isLoggedIn) $sql .= " AND p.status = 'published' ";
            if ($q !== '') $sql .= " AND (p.title LIKE :q OR p.slug LIKE :q) ";
            $sql .= self::photoPostOrderBy('p') . " LIMIT 1 OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':cat_id', (int)$categoryWithUrl['id'], PDO::PARAM_INT);
            if ($q !== '') $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            $album = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log("[PhotoController::fetch album] " . $e->getMessage());
            $album = null;
        }

        // Items max 30
        $items = [];
        if ($album && !empty($album['id'])) {
            // enrich album
            $album['display_image'] = self::resolve_post_display_image($album);
            try { self::attachDisplayImageTarget($album, $pdo); } catch (Throwable $e) {}

            $items = self::fetchPhotoItems($pdo, (int)$album['id']);
            if (count($items) > 30) $items = array_slice($items, 0, 30);

            if (empty($items)) {
                $fallback = self::resolve_post_display_image($album);
                if ($fallback) {
                    $items = [[
                        'id' => 0,
                        'url' => $fallback,
                        'caption' => '',
                        'alt' => (string)($album['title'] ?? 'Photo'),
                        'link_url' => '',
                        'link_target' => '',
                        'sort_order' => 0
                    ]];
                }
            }
        }

        $base = self::buildPath($chainSlugs);
        $page_title = ($categoryWithUrl['name'] ?? 'Gallery') . ' — Gallery';
        $context_for_layout = self::SLOT_SINGLE_GALLERY;

        // superset vars (biar template kategori standar juga bisa jalan)
        $vars = [
            'page_title'    => $page_title,
            'gallery_home'  => ['label' => 'Gallery', 'url' => self::PREFIX],
            'category'      => $categoryWithUrl,
            'breadcrumbs'   => $breadcrumbsWithUrl,
            'category_path' => $base,

            // gallery leaf payload
            'album'         => $album,
            'items'         => $items,

            // optional compat: some themes expect posts list
            'posts'         => $album ? [$album] : [],
            'post'          => $album, // in case single/post template used

            'page'          => $page,
            'perPage'       => $perPage,
            'total'         => $total,
            'totalPages'    => max(1, $totalPages),
            'q'             => $q,
            'base'          => $base,

            'site_context'  => 'gallery_list',
            'context'       => 'gallery_list',
        ];

        $content_html = '';

        // 1) assignment (specific -> generic)
        if (!self::isAssignmentCleared($pdo, 'gallery_list')) {
            $k1 = 'gallery_list:' . (string)($categoryWithUrl['slug'] ?? '');
            $a1 = self::tryAssigned($pdo, $k1, $vars);
            if (trim($a1) !== '') {
                $content_html = $a1;
            } else {
                $a2 = self::tryAssigned($pdo, 'gallery_list', $vars);
                if (trim($a2) !== '') $content_html = $a2;
            }
        }

$context_for_layout = self::SLOT_SINGLE_GALLERY;

// 2) theme engine slot (single.gallery)
if (trim($content_html) === '') {
    $slotOut = self::trySlot($pdo, self::SLOT_SINGLE_GALLERY, $vars);
    if (trim($slotOut) !== '') $content_html = $slotOut;
}

// 3) enforce DEFAULT_THEME_FOLDER
if (trim($content_html) === '') {
    $defOut = self::tryDefaultThemeFile(self::SLOT_SINGLE_GALLERY, $vars);
    if (trim($defOut) !== '') $content_html = $defOut;
}

        // 4) controller fallback
        if (trim($content_html) === '') {
            $local_view = __DIR__ . '/../views/photo/photo-list.php';
            if (is_file($local_view)) {
                ob_start();
                extract($vars, EXTR_SKIP);
                include $local_view;
                $content_html = (string)ob_get_clean();
            } else {
                ob_start();
                ?>
                <div class="container">
                    <h1><?= htmlspecialchars((string)($categoryWithUrl['name'] ?? 'Gallery'), ENT_QUOTES, 'UTF-8') ?></h1>

                    <?php if (!empty($album)): ?>
                        <h2 style="margin-top:12px"><?= htmlspecialchars((string)($album['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php endif; ?>

                    <?php if (!empty($items)): ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:12px">
                            <?php foreach ($items as $it): ?>
                                <figure style="margin:0;border:1px solid #eee;border-radius:12px;overflow:hidden;background:#fff">
                                    <img src="<?= htmlspecialchars((string)($it['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars((string)($it['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                         style="width:100%;height:220px;object-fit:cover;display:block">
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>Tidak ada foto.</p>
                    <?php endif; ?>

                    <?php
                    $tp = max(1, (int)($vars['totalPages'] ?? 1));
                    $pg = max(1, (int)($vars['page'] ?? 1));
                    ?>
                    <nav aria-label="Pagination" style="margin-top:1rem;">
                        <?php if ($pg > 1): ?>
                            <a href="<?= htmlspecialchars($base . '?page=' . ($pg - 1), ENT_QUOTES, 'UTF-8') ?>">&larr; Sebelumnya</a>
                        <?php endif; ?>
                        &nbsp; Halaman <?= $pg ?> dari <?= $tp ?> &nbsp;
                        <?php if ($pg < $tp): ?>
                            <a href="<?= htmlspecialchars($base . '?page=' . ($pg + 1), ENT_QUOTES, 'UTF-8') ?>">Berikutnya &rarr;</a>
                        <?php endif; ?>
                    </nav>
                </div>
                <?php
                $content_html = (string)ob_get_clean();
            }
        }

        // canonical ikut page
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $canonicalPath = ($page <= 1) ? $base : (rtrim($base, '/') . '/page/' . $page . '/');
        $canonical_url = $scheme . '://' . $host . $canonicalPath;

        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        require __DIR__ . '/../layout.php';
        exit;
    }

    /* =========================================================
     * Public: detail post photo (dipanggil dari PostController)
     * ========================================================= */

    public static function showSingle(array $photoData)
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO is not available in $GLOBALS');
        }

        $slug = (string)($photoData['slug'] ?? '');
        $page_title = (string)($photoData['title'] ?? 'Photo');
        $context_for_layout = self::SLOT_404;

        self::augmentAuthor($photoData, $pdo);
        self::augmentCategories($photoData, $pdo);

        try {
            $photoData['display_image'] = self::resolve_post_display_image($photoData);
            self::attachDisplayImageTarget($photoData, $pdo);
        } catch (Throwable $e) {
            $photoData['display_image_target_url'] = null;
            $photoData['display_image_target_attribute'] = null;
        }

        $pid = (int)($photoData['id'] ?? 0);
        $photoData['items'] = $pid > 0 ? self::fetchPhotoItems($pdo, $pid) : [];

        $vars = [
            'page_title'   => $page_title,
            'post'         => $photoData,
            'items'        => $photoData['items'] ?? [],
            'site_context' => 'photos_single',
            'context'      => 'photos_single',
        ];

        $content_html = '';

        // 1) assignment (specific -> generic)
        if (!self::isAssignmentCleared($pdo, 'photos_single')) {
            $k1 = 'photos_single:' . $slug;
            $a1 = self::tryAssigned($pdo, $k1, $vars);
            if (trim($a1) !== '') {
                $content_html = $a1;
            } else {
                $a2 = self::tryAssigned($pdo, 'photos_single', $vars);
                if (trim($a2) !== '') $content_html = $a2;
            }
        }

        // 2) theme engine slot (single.post)
        if (trim($content_html) === '') {
            $slotOut = self::trySlot($pdo, self::SLOT_SINGLE_POST, $vars);
            if (trim($slotOut) !== '') $content_html = $slotOut;
        }

        // 3) enforce DEFAULT_THEME_FOLDER
        if (trim($content_html) === '') {
            $defOut = self::tryDefaultThemeFile(self::SLOT_SINGLE_POST, $vars);
            if (trim($defOut) !== '') $content_html = $defOut;
        }

        // 4) controller fallback
        if (trim($content_html) === '') {
            $local_view = __DIR__ . '/../views/photo/photo-single.php';
            if (is_file($local_view)) {
                ob_start();
                extract($vars, EXTR_SKIP);
                include $local_view;
                $content_html = (string)ob_get_clean();
            } else {
                ob_start();
                ?>
                <div class="container">
                    <h1><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></h1>
                    <?php if (!empty($vars['items'])): ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:12px">
                            <?php foreach ($vars['items'] as $it): ?>
                                <figure style="margin:0;border:1px solid #eee;border-radius:12px;overflow:hidden;background:#fff">
                                    <img src="<?= htmlspecialchars((string)($it['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars((string)($it['alt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                         style="width:100%;height:220px;object-fit:cover;display:block">
                                </figure>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p>Belum ada foto di post ini.</p>
                    <?php endif; ?>
                </div>
                <?php
                $content_html = (string)ob_get_clean();
            }
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $canonical_url = $scheme . '://' . $host . '/' . rawurlencode($slug) . '/';

        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        require __DIR__ . '/../layout.php';
        exit;
    }
}
