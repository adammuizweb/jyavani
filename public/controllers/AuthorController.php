<?php
// controllers/AuthorController.php

class AuthorController
{
    /**
     * ============================
     *  PAGE: LIST OF AUTHORS
     *  fallback order (strict):
     *  1) assigned slot (authors_list)
     *  2) theme slot (index.author / list.author)
     *  3) default theme file (DEFAULT_THEME_FOLDER)
     *  4) inline controller fallback
     * ============================
     */
    public static function listAuthors(PDO $pdo)
    {
        $page_title = 'Penulis';
        $content_html = '';

        try {
            $sql = "SELECT id, username, name, email, img, role, bio, phone
                    FROM users
                    WHERE (is_deleted = 0 OR is_deleted IS NULL)
                      AND (is_locked = 0 OR is_locked IS NULL)
                    ORDER BY name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[AuthorController::listAuthors] " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        // Respect explicit cleared assignment
        $isCleared = false;
        if (function_exists('is_assignment_cleared')) {
            try {
                $isCleared = is_assignment_cleared($pdo, 'content', 'authors_list');
            } catch (Throwable $e) {
                $isCleared = false;
            }
        }

        // 1) assigned slot (authors_list)
        if (!$isCleared && function_exists('render_assigned_slot')) {
            try {
                ob_start();
                render_assigned_slot($pdo, 'content', 'authors_list', [
                    'authors' => $authors,
                    'site_context' => 'authors_list',
                    'page_title' => $page_title
                ]);
                $assigned_html = ob_get_clean();
                if (trim($assigned_html) !== '') {
                    $content_html = $assigned_html;
                }
            } catch (Throwable $e) {
                error_log("[AuthorController::listAuthors] render_assigned_slot error: " . $e->getMessage());
            }
        }

        // 2) theme engine slots (index.author then list.author)
        if (trim($content_html) === '' && !$isCleared && function_exists('render_slot')) {
            try {
                $slotHtml = render_slot($pdo, 'index.author', [
                    'authors' => $authors,
                    'site_context' => 'authors_list',
                    'page_title' => $page_title
                ]);
                if (trim((string)$slotHtml) !== '') {
                    $content_html = $slotHtml;
                } else {
                    $slotHtml2 = render_slot($pdo, 'list.author', [
                        'authors' => $authors,
                        'site_context' => 'authors_list',
                        'page_title' => $page_title
                    ]);
                    if (trim((string)$slotHtml2) !== '') {
                        $content_html = $slotHtml2;
                    }
                }
            } catch (Throwable $e) {
                error_log("[AuthorController::listAuthors] render_slot(index.author/list.author) error: " . $e->getMessage());
            }
        }

        // 3) Explicit: try DEFAULT_THEME_FOLDER (index.author then list.author)
        if (trim($content_html) === '') {
            try {
                $authorVars = [
                    'authors' => $authors,
                    'site_context' => 'authors_list',
                    'page_title' => $page_title
                ];

                $themeFile = slot_to_file('index.author');
                $resolved = [
                    'type' => 'theme_file',
                    'theme_folder' => DEFAULT_THEME_FOLDER,
                    'theme_file' => $themeFile,
                ];
                $path = resolve_theme_file_path($resolved);
                if ($path) {
                    $content_html = include_template_file($path, $authorVars);
                } else {
                    // fallback try list.author
                    $themeFile2 = slot_to_file('list.author');
                    $resolved2 = [
                        'type' => 'theme_file',
                        'theme_folder' => DEFAULT_THEME_FOLDER,
                        'theme_file' => $themeFile2,
                    ];
                    $path2 = resolve_theme_file_path($resolved2);
                    if ($path2) {
                        $content_html = include_template_file($path2, $authorVars);
                    }
                }
            } catch (Throwable $e) {
                error_log("[AuthorController::listAuthors] default-theme attempt error: " . $e->getMessage());
            }
        }

        // 4) inline fallback (controller)
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <div class="author-container">
                <h1 class="page-title">Daftar Penulis (CTRL)</h1>

                <?php if (empty($authors)): ?>
                    <p>Tidak ada penulis.</p>
                <?php else: ?>
                    <div class="authors-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem">
                        <?php foreach ($authors as $a): ?>
                            <div class="author-card" style="border:1px solid #eee;padding:1rem;border-radius:6px;">
                                <div style="display:flex;align-items:center">
                                    <?php if (!empty($a['img'])): ?>
                                        <img src="<?= htmlspecialchars($a['img'], ENT_QUOTES, 'UTF-8') ?>"
                                             alt="<?= htmlspecialchars($a['name'] ?? $a['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                             style="width:64px;height:64px;object-fit:cover;border-radius:50%;margin-right:.8rem">
                                    <?php else: ?>
                                        <div style="width:64px;height:64px;border-radius:50%;background:#f0f0f0;margin-right:.8rem;display:flex;align-items:center;justify-content:center;color:#888;">
                                            <?= htmlspecialchars(strtoupper(mb_substr($a['name'] ?? ($a['username'] ?? '?'), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>

                                    <div>
                                        <?php
                                        if (!empty($a['username'])) {
                                            $authorLink = '/author/' . rawurlencode($a['username']) . '/';
                                        } else {
                                            $authorLink = '/author/' . rawurlencode($a['id']) . '/';
                                        }
                                        ?>
                                        <h3 style="margin:0;font-size:1.05rem">
                                            <a href="<?= htmlspecialchars($authorLink, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($a['name'] ?? $a['email'] ?? $a['username'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </h3>
                                        <div style="color:#666;font-size:.9rem">
                                            <?= htmlspecialchars($a['role'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            $content_html = ob_get_clean();
        }

        // expose for layout
        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        $context_for_layout = 'authors'; // layout context — concise & distinct from slot names

        // START SIDEBAR

        $layout_full_width = false;   // paksa pakai container (jadi tidak full width)
        $enable_sidebar    = false;   // paksa sidebar aktif
        $sidebar_position  = 'right'; // 'left' atau 'right'

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

    /**
     * ============================
     *  PAGE: AUTHOR POSTS
     *  fallback order (strict):
     *  1) assigned slot (author_posts:<identifier>)
     *  2) assigned slot generic (author_posts)
     *  3) theme slot (list.author / list.post)
     *  4) default theme (DEFAULT_THEME_FOLDER)
     *  5) inline controller fallback
     * ============================
     */
    public static function showAuthorPosts(PDO $pdo, string $ident, int $pageNum = 1, string $q = '')
    {
        // -------------------------
        // Find user by: username / id / email / name slug
        // -------------------------
        $user = false;

        // Check username column exists
        try {
            $col = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'username'");
            $col->execute();
            $hasUsername = (bool)$col->fetch();
        } catch (Throwable $e) {
            $hasUsername = false;
        }

        try {
            if ($hasUsername) {
                if (ctype_digit($ident)) {
                    $stm = $pdo->prepare(
                        "SELECT * FROM users
                         WHERE (is_deleted = 0 OR is_deleted IS NULL)
                           AND (is_locked = 0 OR is_locked IS NULL)
                           AND (id = :id OR username = :u)
                         LIMIT 1"
                    );
                    $stm->execute([':id' => (int)$ident, ':u' => $ident]);
                } else {
                    $stm = $pdo->prepare(
                        "SELECT * FROM users
                         WHERE (is_deleted = 0 OR is_deleted IS NULL)
                           AND (is_locked = 0 OR is_locked IS NULL)
                           AND username = :u
                         LIMIT 1"
                    );
                    $stm->execute([':u' => $ident]);
                }
                $user = $stm->fetch(PDO::FETCH_ASSOC);
            }

            if (!$user && ctype_digit($ident)) {
                $stm = $pdo->prepare(
                    "SELECT * FROM users
                     WHERE (is_deleted = 0 OR is_deleted IS NULL)
                       AND (is_locked = 0 OR is_locked IS NULL)
                       AND id = :id
                     LIMIT 1"
                );
                $stm->execute([':id' => (int)$ident]);
                $user = $stm->fetch(PDO::FETCH_ASSOC);
            }

            if (!$user) {
                $stm = $pdo->prepare(
                    "SELECT * FROM users
                     WHERE (is_deleted = 0 OR is_deleted IS NULL)
                       AND (is_locked = 0 OR is_locked IS NULL)
                       AND email = :e
                     LIMIT 1"
                );
                $stm->execute([':e' => $ident]);
                $user = $stm->fetch(PDO::FETCH_ASSOC);
            }

            if (!$user) {
                $nameTry = str_replace('-', ' ', rawurldecode($ident));
                $stm = $pdo->prepare(
                    "SELECT * FROM users
                     WHERE (is_deleted = 0 OR is_deleted IS NULL)
                       AND (is_locked = 0 OR is_locked IS NULL)
                       AND name = :n
                     LIMIT 1"
                );
                $stm->execute([':n' => $nameTry]);
                $user = $stm->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            error_log("[AuthorController::showAuthorPosts] " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        if (!$user) {
            http_response_code(404);
            require __DIR__ . '/../frontend_404.php';
            exit;
        }

        // -------------------------
        // Pagination + filter
        // -------------------------
        $perPage = 10;
        $pageNum = max(1, (int)$pageNum);
        $offset = ($pageNum - 1) * $perPage;

        $where = [
            "p.type = 'article'",
            "p.is_deleted = 0",
            "p.status = 'published'",
            "p.created_by = :uid"
        ];

        $params = [':uid' => (int)$user['id']];

        if ($q !== '') {
            $where[] = "MATCH(p.title, p.content) AGAINST(:q IN NATURAL LANGUAGE MODE)";
            $params[':q'] = $q;
        }

        $whereSQL = implode(' AND ', $where);

        // Count posts
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE $whereSQL");
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log("[AuthorController::showAuthorPosts] count error: " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        $pages = max(1, (int)ceil($total / $perPage));

        // Fetch posts
        try {
            $sql = "SELECT p.id, p.title, p.slug, p.content, p.thumbnail, p.youtube, p.created_at
                    FROM posts p
                    WHERE $whereSQL
                    ORDER BY p.created_at DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);

            foreach ($params as $k => $v) {
                if ($k === ':uid') {
                    $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($k, $v);
                }
            }

            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("[AuthorController::showAuthorPosts] fetch error: " . $e->getMessage());
            http_response_code(500);
            echo "Server error";
            exit;
        }

        // attach display images if PostController helper available
        if (!class_exists('PostController')) {
            $maybe = __DIR__ . '/PostController.php';
            if (file_exists($maybe)) {
                require_once $maybe;
            }
        }
        if (class_exists('PostController') && method_exists('PostController', 'attach_display_images')) {
            try {
                PostController::attach_display_images($posts, $pdo);
            } catch (Throwable $e) {
                error_log("[AuthorController] attach_display_images error: " . $e->getMessage());
            }
        }

        // -------------------------
        // Render view with strict fallback chain
        // -------------------------
        $page_title = "Artikel oleh " . ($user['name'] ?? $user['email'] ?? $user['username'] ?? 'Penulis');
        $vars = [
            'author' => $user,
            'posts'  => $posts,
            'page'   => $pageNum,
            'total'  => $total,
            'pages'  => $pages,
            'site_context' => 'author_posts',
            'page_title' => $page_title,
        ];

        $content_html = '';

        // Respect explicit cleared assignment for author_posts
        $isCleared = false;
        if (function_exists('is_assignment_cleared')) {
            try {
                $isCleared = is_assignment_cleared($pdo, 'content', 'author_posts');
            } catch (Throwable $e) {
                $isCleared = false;
            }
        }

        // 1) assigned slot specific to author (author_posts:<identifier>)
        if (!$isCleared && function_exists('render_assigned_slot')) {
            try {
                $identifier = $user['username'] ?? (string)$user['id'];
                ob_start();
                render_assigned_slot($pdo, 'content', 'author_posts:' . $identifier, $vars);
                $assigned_specific = ob_get_clean();
                if (trim($assigned_specific) !== '') {
                    $content_html = $assigned_specific;
                }
            } catch (Throwable $e) {
                error_log("[AuthorController::showAuthorPosts] render_assigned_slot specific error: " . $e->getMessage());
            }
        }

        // 2) assigned generic slot author_posts
        if (trim($content_html) === '' && !$isCleared && function_exists('render_assigned_slot')) {
            try {
                ob_start();
                render_assigned_slot($pdo, 'content', 'author_posts', $vars);
                $assigned_generic = ob_get_clean();
                if (trim($assigned_generic) !== '') {
                    $content_html = $assigned_generic;
                }
            } catch (Throwable $e) {
                error_log("[AuthorController::showAuthorPosts] render_assigned_slot generic error: " . $e->getMessage());
            }
        }

        // 3) theme engine: prefer list.author then list.post
        if (trim($content_html) === '' && !$isCleared && function_exists('render_slot')) {
            try {
                $slotHtml = render_slot($pdo, 'list.author', $vars);
                if (trim((string)$slotHtml) !== '') {
                    $content_html = $slotHtml;
                } else {
                    $slotHtml2 = render_slot($pdo, 'list.post', $vars);
                    if (trim((string)$slotHtml2) !== '') $content_html = $slotHtml2;
                }
            } catch (Throwable $e) {
                error_log("[AuthorController::showAuthorPosts] render_slot(list.author/list.post) error: " . $e->getMessage());
            }
        }

        // 4) Explicit: try DEFAULT_THEME_FOLDER for list.author then list.post
        if (trim($content_html) === '') {
            try {
                $themeFile = slot_to_file('list.author');
                $resolved = [
                    'type' => 'theme_file',
                    'theme_folder' => DEFAULT_THEME_FOLDER,
                    'theme_file' => $themeFile,
                ];
                $path = resolve_theme_file_path($resolved);
                if ($path) {
                    $content_html = include_template_file($path, $vars);
                } else {
                    // fallback try list.post in default theme
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
                error_log("[AuthorController::showAuthorPosts] default-theme attempt error: " . $e->getMessage());
            }
        }

        // 5) inline fallback
        if (trim($content_html) === '') {
            ob_start();
            ?>
            <div class="author-posts-container">
                <header class="author-header-large" style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem">
                    <?php if (!empty($user['img'])): ?>
                        <img src="<?= htmlspecialchars($user['img'], ENT_QUOTES, 'UTF-8') ?>" class="author-photo-large" style="width:84px;height:84px;object-fit:cover;border-radius:50%">
                    <?php else: ?>
                        <div class="author-photo-fallback-large" style="width:84px;height:84px;border-radius:50%;background:#eee;display:flex;align-items:center;justify-content:center;font-size:28px;color:#666">
                            <?= htmlspecialchars(strtoupper(substr($user['name'] ?? ($user['username'] ?? '?'), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <h1 style="margin:0"><?= htmlspecialchars($user['name'] ?? $user['username'] ?? $user['email'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <?php if (!empty($user['bio'])): ?>
                            <p style="margin:.3rem 0 .4rem;color:#444;max-width:600px;">
                                <?= nl2br(htmlspecialchars($user['bio'], ENT_QUOTES, 'UTF-8')) ?>
                            </p>
                        <?php endif; ?>
                        ( CTRL )
                    </div>
                </header>

                <?php if (!empty($posts)): ?>
                    <div class="posts-list">
                        <?php foreach ($posts as $p): ?>
                            <article class="post-card" style="display:flex;gap:1rem;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #eee;align-items:flex-start">
<?php
$imgUrl = !empty($p['display_image']) ? $p['display_image'] : (!empty($p['thumbnail']) ? $p['thumbnail'] : null);
$postUrl = '/' . rawurlencode($p['slug']) . '/';
?>
<?php if ($imgUrl): ?>
  <a href="<?= $postUrl ?>" class="post-thumb" style="flex:0 0 180px;">
    <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>"
         alt="<?= htmlspecialchars($p['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
         style="width:180px;height:110px;object-fit:cover;border-radius:6px;display:block">
  </a>
<?php endif; ?>

                                <div style="flex:1">
                                    <h2 style="margin:0 0 .4rem 0;font-size:1.15rem">
                                        <a href="/<?= rawurlencode($p['slug']) ?>/" style="color:inherit;text-decoration:none">
                                            <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </h2>

                                    <div class="post-meta" style="color:#666;font-size:.9rem;margin-bottom:.6rem">
                                        <?= htmlspecialchars($p['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>

                                    <p style="margin:0 0 .6rem 0;color:#333">
                                        <?= htmlspecialchars(mb_strimwidth(strip_tags($p['content']), 0, 300, '…'), ENT_QUOTES, 'UTF-8') ?>
                                    </p>

                                    <p style="margin:0">
                                        <a href="/<?= rawurlencode($p['slug']) ?>/">Baca selengkapnya →</a>
                                    </p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>Tidak ada artikel.</p>
                <?php endif; ?>

                <nav aria-label="Pagination" style="margin-top:1rem">
                    <?php if ($pageNum > 1): ?>
                        <a href="?page=<?= max(1, $pageNum - 1) ?><?= $q ? '&q=' . urlencode($q) : '' ?>">&larr; Sebelumnya</a>
                    <?php endif; ?>
                    &nbsp; Halaman <?= $pageNum ?> dari <?= $pages ?> &nbsp;
                    <?php if ($pageNum < $pages): ?>
                        <a href="?page=<?= min($pages, $pageNum + 1) ?><?= $q ? '&q=' . urlencode($q) : '' ?>">Berikutnya &rarr;</a>
                    <?php endif; ?>
                </nav>
            </div>
            <?php
            $content_html = ob_get_clean();
        }

        // expose for layout
        $layout_pdo = $GLOBALS['pdo'] ?? $pdo;
        $pdo = $layout_pdo;

        $context_for_layout = 'posts'; // layout context for author posts

        // START SIDEBAR

        $layout_full_width = false;   // paksa pakai container (jadi tidak full width)
        $enable_sidebar    = true;    // paksa sidebar aktif
        $sidebar_position  = 'right'; // 'left' atau 'right'

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