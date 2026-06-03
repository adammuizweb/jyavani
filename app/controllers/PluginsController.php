<?php
declare(strict_types=1);

class PluginsController
{
    public static function index(PDO $pdo): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 18;
        $catSlug = trim((string)($_GET['cat'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));

        $where = ["p.status = 'approved'", "p.is_deleted = 0"];
        $params = [];

        if ($catSlug !== '') {
            $where[] = "pc.slug = :cat";
            $params[':cat'] = $catSlug;
        }
        if ($q !== '') {
            $where[] = "(p.title LIKE :q OR p.excerpt LIKE :q OR p.description LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        $cntStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM plugins p
             LEFT JOIN plugin_categories pc ON pc.id = p.category_id
             WHERE {$whereSql}"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.slug, p.title, p.excerpt, p.icon, p.avg_rating,
                    p.total_downloads, p.total_ratings, p.php_required, p.tested_up_to,
                    pc.name AS category_name, pc.slug AS category_slug,
                    m.username AS author_username, m.display_name AS author_name
             FROM plugins p
             LEFT JOIN plugin_categories pc ON pc.id = p.category_id
             LEFT JOIN members m ON m.id = p.member_id
             WHERE {$whereSql}
             ORDER BY p.total_downloads DESC, p.avg_rating DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $plugins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Categories for filter
        $categories = $pdo->query(
            "SELECT slug, name, (SELECT COUNT(*) FROM plugins WHERE category_id = pc.id AND status = 'approved' AND is_deleted = 0) AS plugin_count
             FROM plugin_categories pc WHERE is_visible = 1 ORDER BY sort_order"
        )->fetchAll(PDO::FETCH_ASSOC);

        $page_title = 'Plugin Marketplace';
        $context_for_layout = 'plugins';

        ob_start(); ?>
        <section class="page-plugins" style="max-width:1100px;margin:2rem auto;padding:0 1rem">
            <h1 style="font-size:1.8rem;margin:0 0 .25rem">Plugin Marketplace</h1>
            <p style="color:#6b7280;margin:0 0 1.5rem;font-size:.95rem">
                Temukan dan unduh plugin untuk Jyavani CMS
            </p>

            <!-- Filters -->
            <form method="get" style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap">
                <input type="search" name="q" placeholder="Cari plugin..." value="<?= htmlspecialchars($q) ?>"
                       style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;flex:1;min-width:200px;font-size:.9rem">
                <select name="cat" style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= htmlspecialchars($c['slug']) ?>" <?= $catSlug === $c['slug'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?> (<?= (int)$c['plugin_count'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" style="padding:.5rem 1rem;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.9rem">Cari</button>
            </form>

            <?php if (empty($plugins)): ?>
            <div style="text-align:center;padding:3rem 1rem;color:#6b7280">
                <p style="font-size:1.1rem">Belum ada plugin yang tersedia.</p>
                <p><a href="/plugins/submit/" style="color:#2563eb">Submit plugin pertama</a></p>
            </div>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem">
                <?php foreach ($plugins as $p): ?>
                <div style="border:1px solid #e5e7eb;border-radius:10px;padding:1.25rem;background:#fff;transition:box-shadow .15s"
                     onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,.08)'"
                     onmouseout="this.style.boxShadow='none'">
                    <div style="display:flex;align-items:start;gap:.75rem">
                        <?php if ($p['icon']): ?>
                        <img src="<?= htmlspecialchars($p['icon']) ?>" alt="" style="width:48px;height:48px;border-radius:8px;object-fit:cover">
                        <?php endif; ?>
                        <div style="flex:1;min-width:0">
                            <h3 style="margin:0 0 .2rem;font-size:1rem">
                                <a href="/plugins/<?= rawurlencode($p['slug']) ?>/" style="color:#111;text-decoration:none"><?= htmlspecialchars($p['title']) ?></a>
                            </h3>
                            <?php if ($p['author_name']): ?>
                            <p style="margin:0 0 .25rem;font-size:.8rem;color:#6b7280">
                                oleh <a href="/member/<?= rawurlencode($p['author_username']) ?>/" style="color:#2563eb;text-decoration:none"><?= htmlspecialchars($p['author_name']) ?></a>
                            </p>
                            <?php endif; ?>
                            <?php if ($p['category_name']): ?>
                            <span style="display:inline-block;font-size:.72rem;background:#f3f4f6;color:#6b7280;padding:.1rem .5rem;border-radius:999px"><?= htmlspecialchars($p['category_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($p['excerpt']): ?>
                    <p style="margin:.5rem 0 0;font-size:.85rem;color:#4b5563;line-height:1.4"><?= htmlspecialchars($p['excerpt']) ?></p>
                    <?php endif; ?>
                    <div style="display:flex;gap:.75rem;margin-top:.6rem;font-size:.78rem;color:#6b7280">
                        <span>⬇ <?= number_format((int)$p['total_downloads']) ?></span>
                        <span>★ <?= number_format((float)$p['avg_rating'], 1) ?> (<?= (int)$p['total_ratings'] ?>)</span>
                        <?php if ($p['php_required']): ?><span>PHP <?= htmlspecialchars($p['php_required']) ?>+</span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <nav style="display:flex;justify-content:center;gap:.35rem;margin-top:2rem">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="/plugins/?page=<?= $i ?><?= $catSlug ? '&cat=' . rawurlencode($catSlug) : '' ?><?= $q ? '&q=' . rawurlencode($q) : '' ?>"
                   style="padding:.3rem .65rem;border:1px solid #d1d5db;border-radius:4px;text-decoration:none;color:<?= $i === $page ? '#fff' : '#374151' ?>;background:<?= $i === $page ? '#2563eb' : '#fff' ?>;font-size:.85rem">
                   <?= $i ?>
                </a>
                <?php endfor; ?>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
        $content_html = (string)ob_get_clean();
        require __DIR__ . '/../layout.php';
        exit;
    }

    public static function detail(PDO $pdo, string $slug): void
    {
        $stmt = $pdo->prepare(
            "SELECT p.*, pc.name AS category_name, pc.slug AS category_slug,
                    m.username AS author_username, m.display_name AS author_name, m.avatar AS author_avatar, m.bio AS author_bio
             FROM plugins p
             LEFT JOIN plugin_categories pc ON pc.id = p.category_id
             LEFT JOIN members m ON m.id = p.member_id
             WHERE p.slug = :slug AND p.status = 'approved' AND p.is_deleted = 0"
        );
        $stmt->execute([':slug' => $slug]);
        $plugin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plugin) {
            http_response_code(404);
            $context_for_layout = '404';
            require __DIR__ . '/../layout.php';
            exit;
        }

        // Get versions
        $versions = $pdo->prepare(
            "SELECT * FROM plugin_versions WHERE plugin_id = :id ORDER BY created_at DESC"
        );
        $versions->execute([':id' => $plugin['id']]);
        $versions = $versions->fetchAll(PDO::FETCH_ASSOC);

        // Get reviews
        $reviews = $pdo->prepare(
            "SELECT r.*, m.username, m.display_name, m.avatar
             FROM plugin_reviews r
             LEFT JOIN members m ON m.id = r.member_id
             WHERE r.plugin_id = :id AND r.status = 'approved'
             ORDER BY r.created_at DESC LIMIT 20"
        );
        $reviews->execute([':id' => $plugin['id']]);
        $reviews = $reviews->fetchAll(PDO::FETCH_ASSOC);

        $page_title = $plugin['title'] . ' — Plugin Marketplace';
        $context_for_layout = 'plugin-detail';

        ob_start(); ?>
        <section class="page-plugin-detail" style="max-width:800px;margin:2rem auto;padding:0 1rem">
            <p style="margin:0 0 1rem;font-size:.85rem">
                <a href="/plugins/" style="color:#2563eb;text-decoration:none">← Kembali ke Marketplace</a>
            </p>

            <div style="display:flex;gap:1.25rem;align-items:start;flex-wrap:wrap">
                <?php if ($plugin['banner']): ?>
                <img src="<?= htmlspecialchars($plugin['banner']) ?>" alt="" style="width:100%;max-height:240px;object-fit:cover;border-radius:10px">
                <?php endif; ?>

                <div style="flex:1;min-width:280px">
                    <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.5rem">
                        <?php if ($plugin['icon']): ?>
                        <img src="<?= htmlspecialchars($plugin['icon']) ?>" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover">
                        <?php endif; ?>
                        <div>
                            <h1 style="font-size:1.6rem;margin:0"><?= htmlspecialchars($plugin['title']) ?></h1>
                            <p style="margin:.25rem 0 0;font-size:.9rem;color:#6b7280">
                                oleh <a href="/member/<?= rawurlencode($plugin['author_username']) ?>/" style="color:#2563eb;text-decoration:none"><?= htmlspecialchars($plugin['author_name'] ?: $plugin['author_username']) ?></a>
                                <?php if ($plugin['category_name']): ?>
                                di <span style="color:#6b7280"><?= htmlspecialchars($plugin['category_name']) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div style="display:flex;gap:1rem;margin:1rem 0;font-size:.85rem;color:#6b7280;flex-wrap:wrap">
                        <span>⬙ <?= number_format((int)$plugin['total_downloads']) ?> download</span>
                        <span>★ <?= number_format((float)$plugin['avg_rating'], 1) ?> (<?= (int)$plugin['total_ratings'] ?> ulasan)</span>
                        <span>PHP <?= htmlspecialchars($plugin['php_required'] ?: '8.1') ?>+</span>
                        <?php if ($plugin['tested_up_to']): ?><span>Tested: <?= htmlspecialchars($plugin['tested_up_to']) ?></span><?php endif; ?>
                        <span>📅 <?= date('d M Y', strtotime($plugin['updated_at'] ?: $plugin['created_at'])) ?></span>
                    </div>

                    <!-- Description -->
                    <?php if ($plugin['description']): ?>
                    <div style="line-height:1.7;font-size:.92rem;color:#374151;margin:1rem 0">
                        <?= nl2br(htmlspecialchars($plugin['description'])) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Links -->
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:1rem 0">
                        <?php if ($plugin['homepage']): ?>
                        <a href="<?= htmlspecialchars($plugin['homepage']) ?>" target="_blank" rel="noopener" style="padding:.4rem .75rem;border:1px solid #d1d5db;border-radius:6px;text-decoration:none;font-size:.85rem;color:#374151">🌐 Homepage</a>
                        <?php endif; ?>
                        <?php if ($plugin['docs_url']): ?>
                        <a href="<?= htmlspecialchars($plugin['docs_url']) ?>" target="_blank" rel="noopener" style="padding:.4rem .75rem;border:1px solid #d1d5db;border-radius:6px;text-decoration:none;font-size:.85rem;color:#374151">📄 Dokumen</a>
                        <?php endif; ?>
                        <?php if ($plugin['github_url']): ?>
                        <a href="<?= htmlspecialchars($plugin['github_url']) ?>" target="_blank" rel="noopener" style="padding:.4rem .75rem;border:1px solid #d1d5db;border-radius:6px;text-decoration:none;font-size:.85rem;color:#374151">💻 GitHub</a>
                        <?php endif; ?>
                    </div>

                    <!-- Download -->
                    <?php $currentVersion = $versions[0] ?? null; ?>
                    <?php if ($currentVersion): ?>
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;margin:1rem 0">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
                            <div>
                                <strong style="font-size:1rem">v<?= htmlspecialchars($currentVersion['version']) ?></strong>
                                <span style="color:#6b7280;font-size:.85rem;margin-left:.5rem">
                                    <?= number_format((int)$currentVersion['zip_size']) ?> bytes
                                </span>
                            </div>
                            <a href="<?= htmlspecialchars($currentVersion['zip_file']) ?>" class="btn-download"
                               style="display:inline-block;padding:.5rem 1.25rem;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:.9rem;font-weight:500"
                               onclick="return confirm('Download plugin &quot;<?= htmlspecialchars($plugin['title']) ?>&quot; v<?= htmlspecialchars($currentVersion['version']) ?>?')">
                               ⬇ Download
                            </a>
                        </div>
                        <?php if ($currentVersion['changelog']): ?>
                        <details style="margin-top:.75rem;font-size:.85rem">
                            <summary style="cursor:pointer;color:#6b7280">Changelog</summary>
                            <pre style="background:#f3f4f6;padding:.75rem;border-radius:6px;margin-top:.5rem;font-size:.82rem;white-space:pre-wrap"><?= htmlspecialchars($currentVersion['changelog']) ?></pre>
                        </details>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Older versions -->
                    <?php if (count($versions) > 1): ?>
                    <details style="margin:1rem 0;font-size:.85rem">
                        <summary style="cursor:pointer;color:#6b7280">Riwayat Versi (<?= count($versions) ?>)</summary>
                        <ul style="margin:.5rem 0;padding-left:1.25rem">
                            <?php foreach ($versions as $v): ?>
                            <li style="margin-bottom:.35rem">
                                <a href="<?= htmlspecialchars($v['zip_file']) ?>" style="color:#2563eb">v<?= htmlspecialchars($v['version']) ?></a>
                                <span style="color:#6b7280;font-size:.8rem">— <?= date('d M Y', strtotime($v['created_at'])) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reviews -->
            <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid #e5e7eb">
                <h2 style="font-size:1.2rem;margin:0 0 1rem">Ulasan (<?= (int)$plugin['total_ratings'] ?>)</h2>
                <?php if (empty($reviews)): ?>
                <p style="color:#6b7280">Belum ada ulasan.</p>
                <?php else: ?>
                <div style="display:flex;flex-direction:column;gap:.75rem">
                    <?php foreach ($reviews as $r): ?>
                    <div style="padding:.75rem;border:1px solid #e5e7eb;border-radius:8px">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem">
                            <strong style="font-size:.9rem">
                                <?= htmlspecialchars($r['display_name'] ?: $r['username']) ?>
                                <span style="color:#f59e0b;margin-left:.35rem"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
                            </strong>
                            <span style="font-size:.78rem;color:#6b7280"><?= date('d M Y', strtotime($r['created_at'])) ?></span>
                        </div>
                        <?php if ($r['title']): ?><strong style="font-size:.88rem"><?= htmlspecialchars($r['title']) ?></strong><?php endif; ?>
                        <?php if ($r['content']): ?><p style="margin:.25rem 0 0;font-size:.85rem;color:#4b5563"><?= nl2br(htmlspecialchars($r['content'])) ?></p><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
        $content_html = (string)ob_get_clean();
        require __DIR__ . '/../layout.php';
        exit;
    }

    public static function submit(PDO $pdo): void
    {
        $page_title = 'Submit Plugin';
        $context_for_layout = 'plugins-submit';

        ob_start(); ?>
        <section class="page-submit-plugin" style="max-width:640px;margin:2rem auto;padding:0 1rem">
            <h1 style="font-size:1.6rem;margin:0 0 .25rem">Submit Plugin</h1>
            <p style="color:#6b7280;margin:0 0 1.5rem;font-size:.9rem">
                Bagikan plugin buatanmu ke komunitas Jyavani CMS.
            </p>

            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:2rem;text-align:center">
                <p style="font-size:1.1rem;color:#374151;margin:0 0 .5rem">Fitur submit plugin akan segera hadir.</p>
                <p style="color:#6b7280;font-size:.9rem">Saat ini kamu bisa mengirim plugin melalui halaman <a href="/member/register/" style="color:#2563eb">registrasi member</a>.</p>
            </div>

            <p style="margin-top:1rem;font-size:.85rem;color:#6b7280">
                <a href="/plugins/" style="color:#2563eb;text-decoration:none">← Kembali ke Marketplace</a>
            </p>
        </section>
        <?php
        $content_html = (string)ob_get_clean();
        require __DIR__ . '/../layout.php';
        exit;
    }
}
