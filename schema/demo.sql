-- Jyavani CMS Demo Content
-- Generated: 2026-08-19
-- Canonical source: schema/demo-content/manifest.json (version 1)
-- Regenerate: php tools/build-demo-content.php
--
-- Imported on request by Pondasi after the Core schema and initial Site Owner exist.
-- Tables written: categories, posts, media, post_categories, sidebar_zone_items.

SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

REPLACE INTO `categories` (`id`, `name`, `slug`, `description`, `parent_id`, `meta`, `created_by`, `created_at`, `updated_at`, `is_deleted`, `deleted_at`) VALUES
(1, 'Panduan', 'panduan', 'Artikel panduan langkah demi langkah menggunakan Jyavani CMS', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL),
(2, 'Keamanan', 'keamanan', 'Artikel tentang keamanan, privasi, proteksi data, dan akses kontrol', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL),
(3, 'Pengembangan', 'pengembangan', 'Artikel tentang theme, plugin, widget, shortcodes, dan pengembangan fitur CMS', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL),
(4, 'Sistem', 'sistem', 'Artikel tentang administrasi sistem, maintenance, update, dan manajemen user', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL);

INSERT INTO `posts` (`id`, `title`, `slug`, `content`, `type`, `meta`, `youtube`, `thumbnail`, `status`, `created_by`, `created_at`, `updated_at`, `sort_order`, `deleted_at`, `is_deleted`) VALUES
(272, 'Content Management: Artikel, Halaman, dan Publikasi', 'content-management-mengelola-artikel-dan-halaman', '<p>Jyavani Core 2.3.74 menyimpan artikel dan halaman di tabel konten yang sama, tetapi memberi keduanya alur dashboard dan permission terpisah. Artikel dapat memiliki beberapa kategori; halaman tidak memiliki relasi kategori.</p>
<h2>Membuat dan menerbitkan</h2>
<ol>
  <li>Buka <strong>Posts</strong> atau <strong>Pages</strong>, lalu pilih tambah baru.</li>
  <li>Isi judul, slug ASCII yang unik, dan konten pada editor Quill.</li>
  <li>Untuk artikel, pilih kategori dan thumbnail bila diperlukan.</li>
  <li>Pilih <strong>Draft</strong>, <strong>Published</strong>, atau <strong>Private</strong>, lalu simpan.</li>
</ol>
<img src="/static/img/2026/07/screenshots/add-post.jpg" alt="Form artikel Jyavani" style="width:100%;margin:1rem 0">
<p>Draft tidak tampil untuk publik. Published dapat dirutekan untuk publik. Private hanya tersedia bagi pengguna terautentikasi yang lolos pemeriksaan akses. Kemampuan membaca, membuat, memperbarui, menerbitkan, memindahkan ke Trash, dan menghapus permanen ditentukan oleh permission serta scope pemilik, bukan hanya label role lama.</p>
<h2>Slug dan rute</h2>
<p>Slug harus unik di antara artikel, halaman, dan theme content aktif. Core juga mendukung <code>content_routes</code>: satu rute kanonis per konten dan locale, dengan rute lama tetap menjadi alias redirect. Jika konten lama belum memiliki rute kanonis, resolver permalink/slug lama menjadi fallback.</p>
<h2>HTML dan kepemilikan</h2>
<p>Author bekerja pada resource sesuai scope yang diberikan. HTML yang tidak difilter memerlukan permission khusus; jangan menganggap semua kontributor boleh memasukkan markup arbitrer. Saat mengganti pemilik, status, atau tanggal, Core memeriksa permission terkait di dalam transaksi.</p>
<h2>Penghapusan</h2>
<p>Delete pertama pada artikel atau halaman menetapkan <code>is_deleted</code> dan <code>deleted_at</code>. Trash menyediakan restore dan purge sesuai permission. Restore dapat ditolak jika slug sudah dipakai konten aktif. Trash bukan backup: ekspor database dan aset tetap diperlukan.</p>
', 'article', NULL, NULL, '/static/img/2026/07/content-management-71b8788f.jpg', 'published', 1, '2026-07-24 09:58:30', '2026-08-19 00:00:00', 0, NULL, 0),
(273, 'Kategori dan Organisasi Konten', 'kategori-dan-organisasi-konten', '<p>Kategori mengelompokkan artikel melalui relasi many-to-many. Satu artikel dapat masuk ke beberapa kategori, sedangkan halaman tidak memakai kategori.</p>
<h2>Identitas dan hierarki</h2>
<p>Setiap kategori memiliki nama, slug unik, deskripsi, dan parent opsional. Parent membentuk jalur bertingkat. Hindari siklus dan gunakan hierarki pendek agar URL serta navigasi mudah dipahami.</p>
<img src="/static/img/2026/07/screenshots/categories-list.jpg" alt="Daftar kategori" style="width:100%;margin:1rem 0">
<h2>Akses publik</h2>
<p>Jalur kategori memakai nilai <code>category_path</code>, dengan default <code>category</code>. Kategori bertingkat dapat menghasilkan path bertingkat. Hasil publik hanya memuat kategori dan artikel aktif; tampilan akhir bergantung pada theme.</p>
<h2>Permission dan Trash</h2>
<p>Read, create, update, trash, restore, dan purge adalah permission terpisah. Scope dapat membatasi kategori berdasarkan pembuat. Menghapus kategori pertama kali adalah soft delete. Restore dapat gagal bila slug sudah digunakan kategori aktif, sedangkan purge menghapus record secara permanen dan relasinya mengikuti constraint database.</p>
<p>Gunakan nama stabil, deskripsi faktual, dan sedikit kategori yang benar-benar membantu pencarian. Jangan menjanjikan breadcrumb atau optimasi SEO tertentu karena itu bergantung pada theme atau plugin.</p>
', 'article', NULL, NULL, '/static/img/2026/07/kategori-organisasi-0ef63d42.jpg', 'published', 1, '2026-07-24 10:31:05', '2026-08-19 00:00:00', 0, NULL, 0),
(274, 'Media Library: Gambar Publik dan Private', 'media-library-mengelola-gambar-dan-file', '<p>Media Library Core mengelola gambar. Dokumen, PDF, audio, dan video umum dikelola melalui menu <strong>Files</strong>, bukan dianggap sebagai gambar Media Library.</p>
<h2>Metadata dan storage</h2>
<p>Record media menyimpan URL, nama file, MIME, ukuran, dimensi, title, alt, caption, credit, visibility, storage disk, storage path, access scope, dan pemilik. Upload publik berada di public disk. Gambar private berada di luar web root dan dilayani controller.</p>
<img src="/static/img/2026/07/media-list-screenshot.jpg" alt="Daftar media dengan informasi akses" style="width:100%;margin:1rem 0">
<h2>Aturan akses</h2>
<ul>
  <li><code>public</code>: hanya benar-benar publik bila visibility, disk, dan scope semuanya public.</li>
  <li><code>editorial</code>: ditujukan bagi tim konten yang memiliki akses private asset.</li>
  <li><code>admin</code>: membatasi aset ke administrator yang berwenang.</li>
</ul>
<p>URL gambar private diberikan oleh Media Picker dan tetap diperiksa saat diminta. Mengetahui URL tidak melewati sesi dan permission.</p>
<h2>Operasi aman</h2>
<p>Isi alt text sesuai fungsi gambar dan optimalkan ukuran sebelum upload. Permission media memisahkan read, upload, update, dan delete serta dapat memakai scope pemilik. Berbeda dari artikel, halaman, kategori, theme content, dan user, tabel media Core tidak memiliki kolom soft delete. Aksi delete dapat menghapus record dan file; verifikasi pemakaian dan backup terlebih dahulu.</p>
', 'article', NULL, NULL, '/static/img/2026/07/media-library-thumb-gen.jpg', 'published', 1, '2026-07-24 10:48:04', '2026-08-19 00:00:00', 0, NULL, 0),
(275, 'Menu Manager dan Navigasi', 'menu-manager-navigasi-website', '<p>Menu Manager menyimpan beberapa menu dan item bertingkat. Menu tidak otomatis muncul di semua theme; theme atau gadget <code>tz_nav_menu</code> harus memilih slug menu yang akan dirender.</p>
<img src="/static/img/2026/07/menu-manager-screenshot.jpg" alt="Menu Manager" style="width:100%;margin:1rem 0">
<h2>Tipe item</h2>
<p>Item dapat menunjuk URL custom atau record artikel, halaman, theme content, dan kategori. Item menyimpan label, parent, urutan, target, serta status hidden. Gunakan target internal agar perubahan rute dapat ditangani oleh Core; gunakan custom URL untuk tujuan eksternal atau jalur khusus.</p>
<h2>Hierarki dan urutan</h2>
<p>Parent item harus berasal dari menu yang sama. Urutan menentukan posisi saudara. Kemampuan dropdown, kedalaman visual, active state, dan perilaku mobile tetap menjadi tanggung jawab renderer/theme.</p>
<h2>Theme Zones</h2>
<p>Di Customize, tambahkan atau konfigurasi gadget navigasi pada posisi yang dideklarasikan theme. Nilai <code>menu</code> memilih slug, sedangkan <code>depth</code>, class, dan atribut daftar membantu theme membentuk output. Selalu uji link, fokus keyboard, dan tampilan layar sempit setelah perubahan.</p>
<p>Pengelolaan menu memerlukan <code>core.menus.manage</code>. Mengubah slug menu dapat memutus konfigurasi theme yang masih menunjuk slug lama.</p>
', 'article', NULL, NULL, '/static/img/2026/07/menu-manager-thumb.jpg', 'published', 1, '2026-07-24 11:05:59', '2026-08-19 00:00:00', 0, NULL, 0),
(276, 'Theme System, Customizer, dan Theme Zones', 'theme-system-mengubah-tampilan-website', '<p>Core mengelola satu system theme bernama <code>default</code>. Theme lain dipasang dan diperbarui melalui Theme Store atau paket yang sesuai. Theme aktif dipilih dari dashboard; file PHP theme harus diperlakukan sebagai kode tepercaya.</p>
<h2>Kontrak theme</h2>
<p><code>theme.json</code> mendeskripsikan identitas, partial, Customizer, layout zone, aset, dan metadata Store. Rendering memakai slot seperti header, footer, homepage, list, single post, dan single page. Resolver mencoba assignment, theme aktif, lalu fallback system theme.</p>
<img src="/static/img/2026/07/theme-customizer-screenshot.jpg" alt="Theme Customizer" style="width:100%;margin:1rem 0">
<h2>Customizer dan Theme Zones</h2>
<p>Customizer menyimpan nilai per folder theme dalam setting <code>theme_mods_{folder}</code>. Field yang didukung antara lain image, menu, sidebar zone, textarea, text, dan toggle. Theme harus membaca nilai dengan helper dan menyediakan default.</p>
<p>Theme Zones menyimpan gadget per <code>theme_folder</code>, zone, position, dan ordering. Layout di manifest hanya menyatakan kontrak posisi dan default yang dapat dimuat; data pengguna tetap di database. Template sebaiknya memeriksa apakah posisi berisi gadget, merendernya, lalu menyediakan fallback HTML bila kosong.</p>
<h2>Pengembangan</h2>
<p>Partial berada di folder seperti <code>main/</code>, <code>single/</code>, <code>list/</code>, dan <code>index/</code>. Plugin dapat menambah tipe gadget melalui filter registry dan renderer. Jangan mengedit system theme untuk perubahan yang harus bertahan lintas update; buat theme Store/custom dan uji desktop, mobile, serta kondisi tanpa gadget.</p>
', 'article', NULL, NULL, '/static/img/2026/07/theme-system-thumb.jpg', 'published', 1, '2026-07-24 17:36:22', '2026-08-19 00:00:00', 0, NULL, 0),
(277, 'Plugin System: Manifest, Lifecycle, dan Hooks', 'plugin-system-memperluas-fitur-cms', '<p>Plugin adalah kode tepercaya di bawah <code>plugins/{name}/</code>. <code>plugin.json</code> mendeklarasikan identitas, kebutuhan plugin, halaman dashboard, navigasi, aset, static copy, dan permission; file PHP menyediakan hook atau implementasi route.</p>
<h2>Instalasi dan aktivasi</h2>
<p>Uploader memvalidasi paket, mengekstrak ke folder plugin, menyalin aset yang dideklarasikan, dan dapat menjalankan konvensi tetap <code>install.sh</code> dengan batas waktu/output. <strong>Install</strong> dapat menaruh plugin dalam keadaan nonaktif; <strong>Install &amp; Activate</strong> juga memeriksa dependency dan mengaktifkannya. Jangan memasang paket yang sumbernya tidak dipercaya.</p>
<img src="/static/img/2026/07/plugin-manager-screenshot.jpg" alt="Plugin Manager" style="width:100%;margin:1rem 0">
<h2>Route dashboard</h2>
<p>Setiap page mendeklarasikan route relatif dan file yang tetap berada di folder plugin. Core menolak traversal, duplikasi internal, shadow route dashboard Core, dan collision antarplugin. Route dapat memakai guard Site Owner atau permission plugin; keduanya tidak boleh digabung pada route yang sama.</p>
<h2>Hooks dan dependency</h2>
<p>Gunakan <code>add_action</code>/<code>do_action</code> untuk kejadian dan <code>add_filter</code>/<code>apply_filters</code> untuk transformasi nilai. Namespace nama fungsi dan hook milik plugin. <code>requires.plugins</code> memakai constraint versi; dependency harus terpasang, aktif, kompatibel, dan dapat dimuat. Core menolak deaktivasi provider yang masih dibutuhkan plugin aktif.</p>
<p>Nonaktif menyimpan file dan state plugin tetapi menghentikan pemuatan aktif. Uninstall/delete dapat menghapus file dan menjalankan konsekuensi plugin; backup dan baca dokumentasinya lebih dahulu.</p>
', 'article', NULL, NULL, '/static/img/2026/07/plugin-system-thumb.jpg', 'published', 1, '2026-07-24 18:43:04', '2026-08-19 00:00:00', 0, NULL, 0),
(278, 'Pengaturan Situs dan Jalur Autentikasi', 'pengaturan-situs-settings-dashboard', '<p>Settings memisahkan identitas situs, bahasa, path autentikasi, sidebar, permalink, dan kebijakan registrasi. Perubahan memerlukan permission pengaturan; beberapa area sensitif juga dibatasi untuk Site Owner.</p>
<h2>Identitas dan bahasa</h2>
<p>Isi site title, description, URL, dan aset identitas sesuai deployment. <code>site_language</code> mengatur bahasa UI dashboard, sedangkan <code>content_default_language</code> menjadi locale dasar frontend. Core menyediakan seed UI en, id, dan de, tetapi translasi konten memerlukan plugin.</p>
<img src="/static/img/2026/07/site-settings-screenshot.jpg" alt="Pengaturan situs" style="width:100%;margin:1rem 0">
<h2>Path autentikasi</h2>
<p><code>admin_path</code>, <code>login_path</code>, dan <code>register_path</code> adalah path relatif yang dicocokkan router. File dashboard tetap berada di luar web root. Permintaan dashboard pada path yang salah mendapat frontend 404, tetapi path custom bukan pengganti password kuat, HTTPS, dan pembatasan akses.</p>
<h2>Registrasi dan permalink</h2>
<p>Registrasi default nonaktif. Bila diaktifkan, akun baru menerima system role Author; approval opsional membuat akun terkunci sampai disetujui. reCAPTCHA hanya bekerja bila fitur dan kredensial dikonfigurasi.</p>
<p>Struktur permalink lama tetap digunakan sebagai fallback untuk konten tanpa canonical route. Setelah URL publik berubah, uji canonical dan redirect. Hindari mengganti path sistem ke nilai yang bertabrakan dengan file publik, route konten, atau route plugin.</p>
', 'article', NULL, NULL, '/static/img/2026/07/settings-dashboard-thumb.jpg', 'published', 1, '2026-07-24 19:00:47', '2026-08-19 00:00:00', 0, NULL, 0),
(279, 'User Management, Site Owner, Role, dan Scope', 'user-management-mengelola-pengguna', '<p>Jyavani Core 2.3.74 memakai role dan permission dinamis. Kolom role lama tetap disinkronkan untuk kompatibilitas, tetapi keputusan akses baru harus memakai permission efektif dan scope.</p>
<h2>Site Owner</h2>
<p>Site Owner adalah atribut akun khusus, bukan role. Pondasi menandai akun awal sebagai Site Owner, memberi legacy role admin, dan memasang system role Administrator. Operasi paling sensitif seperti Roles &amp; Permissions dan update Core memerlukan Site Owner selain permission terkait.</p>
<img src="/static/img/2026/07/users-list-screenshot.jpg" alt="Daftar pengguna" style="width:100%;margin:1rem 0">
<h2>System role dan custom role</h2>
<ul>
  <li><strong>Author</strong>: grant Core terutama untuk konten sendiri.</li>
  <li><strong>Editor</strong>: mewarisi fondasi Author dan mendapat grant editorial tambahan.</li>
  <li><strong>Administrator</strong>: grant aktif Core secara luas, tetapi bukan otomatis Site Owner.</li>
</ul>
<p>Custom role dimulai tanpa grant. Permission berscope dapat memakai Own, Same or Lower, atau Any; tindakan non-resource memakai Global. Authority rank hanya memengaruhi Same or Lower dan tidak memberi permission dengan sendirinya.</p>
<h2>Siklus akun</h2>
<p>User dapat memiliki beberapa role aktif. Lock mencegah login tanpa menghapus akun. Delete user memakai Trash, dengan restore/purge terpisah; pertimbangkan kepemilikan konten sebelum purge. Perubahan role dan operasi otorisasi penting dicatat di audit log.</p>
<p>Registrasi publik, bila diaktifkan, selalu memasang Author dan dapat membuat akun locked saat approval diwajibkan. Tinjau akses dengan akun non-owner, bukan hanya dari tampilan Site Owner.</p>
', 'article', NULL, NULL, '/static/img/2026/07/user-management-thumb.jpg', 'published', 1, '2026-07-24 19:07:46', '2026-08-19 00:00:00', 0, NULL, 0),
(280, 'SEO Core: Metadata, Canonical URL, dan Sitemap', 'seo-dan-meta-tags', '<p>Core menyediakan fondasi metadata, canonical URL, dan sitemap. Hasil SEO tetap bergantung pada isi, konfigurasi domain, theme, crawl policy, dan mesin pencari; Core tidak menjamin peringkat atau rich result.</p>
<h2>Metadata dokumen</h2>
<p>Layout membaca deskripsi khusus dari <code>meta.meta_tags.description</code>. Jika kosong, Core membuat excerpt konten, lalu memakai deskripsi situs sebagai fallback. Layout juga mengeluarkan Open Graph dan Twitter Card untuk title, description, serta image bila tersedia. Nilai dapat disesuaikan plugin melalui filter.</p>
<img src="/static/img/2026/07/meta-tags-screenshot.jpg" alt="Field metadata konten" style="width:100%;margin:1rem 0">
<h2>Canonical URL</h2>
<p>Single content memakai permalink yang diselesaikan Core. Bila record <code>content_routes</code> memiliki canonical base-locale, URL itu diprioritaskan. Alias mengarah ke canonical dan mempertahankan query dengan aman. Tag <code>rel=canonical</code> dikeluarkan oleh layout.</p>
<h2>Sitemap</h2>
<p>Controller sitemap mengekspor konten published dan tidak terhapus. Konten yang dikelola melalui canonical routes hanya masuk pada URL kanonis yang memenuhi kontrak route. Sitemap locale dapat diperluas integrasi translasi. Kirim endpoint yang benar ke alat webmaster dan periksa responsnya setelah perubahan route.</p>
<h2>Praktik operasional</h2>
<p>Tulis title dan description yang akurat, gunakan thumbnail publik yang dapat diambil crawler, dan jangan menerbitkan duplikasi path. JSON-LD khusus, robots policy lanjutan, redirect lintas domain, dan translasi SEO bukan janji universal Core; implementasikan melalui theme/plugin bila dibutuhkan.</p>
', 'article', NULL, NULL, '/static/img/2026/07/seo-meta-tags-thumb.jpg', 'published', 1, '2026-07-24 19:16:07', '2026-08-19 00:00:00', 0, NULL, 0),
(281, 'Widget, Shortcode, dan Preset', 'widget-dan-shortcodes', '<p>Jyavani membedakan widget area, Theme Zone gadget, shortcode konten, dan shortcode preset. Masing-masing dirender pada tahap yang berbeda.</p>
<h2>Sidebar dan Theme Zones</h2>
<p>Sidebar zone berisi item seperti search, recent posts, HTML, atau preset. Theme Zones menempatkan gadget pada posisi yang dideklarasikan theme, termasuk navigation, image, rich text, pages, social, search, dan metadata post. Ketersediaan tampilan bergantung pada theme yang benar-benar merender zone tersebut.</p>
<img src="/static/img/2026/07/layout-editor-screenshot.jpg" alt="Editor Theme Zones" style="width:100%;margin:1rem 0">
<h2>Shortcode Core</h2>
<ul>
  <li><code>[post_cat_shortcode ...]</code> untuk koleksi post.</li>
  <li><code>[private_pdf id="123" mode="card"]</code> untuk PDF dari File Library.</li>
  <li><code>[video id="123"]</code> untuk record video yang didukung.</li>
  <li><code>[[widget:name key=value]]</code> untuk widget terdaftar.</li>
</ul>
<p>ID private file berasal dari menu Files, bukan Media Library. Pemeriksaan akses tetap berlaku ketika shortcode dirender atau stream diminta.</p>
<h2>Preset</h2>
<p>Preset adalah post bertipe <code>sc_preset</code> yang menyimpan konfigurasi query/layout dalam JSON. Preset demo memilih empat artikel acak milik owner awal dan digunakan sidebar. Karena query acak tidak deterministik saat runtime, jangan gunakan preset itu untuk urutan editorial yang harus stabil.</p>
<p>Plugin dapat mendaftarkan shortcode atau gadget tambahan. Validasi atribut, escape output, dan jangan memasukkan kode pihak ketiga yang tidak dipercaya ke HTML widget.</p>
', 'article', NULL, NULL, '/static/img/2026/07/widget-shortcodes-thumb.jpg', 'published', 1, '2026-07-24 23:33:31', '2026-08-19 00:00:00', 0, NULL, 0),
(282, 'Keamanan Jyavani Core: Batas Perlindungan dan Operasi Aman', 'keamanan-cms-best-practices', '<p>Core menyediakan kontrol akses dinamis, CSRF, session fingerprint, path guard, brute-force tracking, private storage, dan validasi paket. Fitur ini adalah lapisan, bukan jaminan keamanan deployment.</p>
<h2>Autentikasi dan dashboard</h2>
<p>PHP dashboard berada di luar web root dan hanya dicapai lewat router pada <code>admin_path</code>. Path yang salah dimasking sebagai frontend 404. Login memakai CSRF, password hash PHP, dan proteksi percobaan yang dapat dikonfigurasi. Path tersembunyi mengurangi noise pemindaian, tetapi tidak menggantikan password unik, rate limiting proxy, atau MFA dari integrasi tambahan.</p>
<img src="/static/img/2026/07/auth-settings-screenshot.jpg" alt="Pengaturan autentikasi" style="width:100%;margin:1rem 0">
<h2>Session dan HTTPS</h2>
<p>Cookie dapat memakai HttpOnly, SameSite, domain/path, dan Secure sesuai konfigurasi. Set <code>FORCE_HTTPS=1</code> hanya setelah proxy/web server meneruskan status HTTPS dengan benar. Lindungi <code>cfg/.env</code>, secret session, secret token file, dan kredensial database.</p>
<h2>Otorisasi</h2>
<p>Gunakan least privilege. Site Owner adalah batas tambahan bagi operasi kritis; admin biasa tidak identik dengan owner. Permission, scope, kepemilikan, status akun, dan guard route diperiksa server-side. Plugin ACL menambah syarat, bukan melewati guard Core.</p>
<h2>Aset dan operasi</h2>
<p>Private asset harus berada pada private disk dengan scope tepat. Token viewer PDF bersifat sementara dan bukan tautan publik. Update hanya dari endpoint/paket tepercaya, periksa checksum/manifest, dan buat backup teruji. Patch PHP, database, web server, theme, serta plugin secara rutin. Pantau error log dan audit authorization tanpa menyimpan secret ke log.</p>
', 'article', NULL, NULL, '/static/img/2026/07/keamanan-cms-thumb.jpg', 'published', 1, '2026-07-24 23:45:59', '2026-08-19 00:00:00', 0, NULL, 0),
(283, 'Tentang Jyavani', 'tentang-jyavani', '<p><strong>Halaman contoh.</strong> Ganti isi ini dengan identitas organisasi atau situs Anda sebelum produksi.</p>
<p>Jyavani CMS adalah CMS native PHP dengan manajemen artikel/halaman, kategori, media/file, theme, plugin, menu, role/permission, dan update. Fitur yang benar-benar tersedia pada situs bergantung pada versi Core, theme, plugin, serta konfigurasi deployment.</p>
<h2>Tentang situs ini</h2>
<p>Jelaskan pemilik/pengelola, tujuan publikasi, cakupan konten, dan cara pembaca memverifikasi informasi. Jangan memakai teks demo sebagai pernyataan resmi organisasi.</p>
', 'page', NULL, NULL, NULL, 'published', 1, '2026-07-24 23:50:53', '2026-08-19 00:00:00', 0, NULL, 0),
(284, 'Kontak', 'kontak', '<p><strong>Placeholder kontak.</strong> Jyavani Core tidak menyediakan alamat, akun sosial, SLA respons, atau layanan dukungan untuk situs Anda.</p>
<h2>Isi sebelum diterbitkan</h2>
<ul>
  <li>Nama pengelola atau organisasi.</li>
  <li>Alamat email/telepon yang benar-benar dipantau.</li>
  <li>Jam layanan dan perkiraan respons yang dapat dipenuhi.</li>
  <li>Alamat fisik hanya bila perlu dan aman dipublikasikan.</li>
</ul>
<p>Jika memakai form kontak dari plugin, dokumentasikan tujuan data, retensi, penerima, proteksi spam, dan consent. Uji pengiriman sendiri; Core tidak menjamin email delivery atau respons operasional.</p>
', 'page', NULL, NULL, NULL, 'published', 1, '2026-07-24 23:50:53', '2026-08-19 00:00:00', 0, NULL, 0),
(285, 'Privacy Policy', 'privacy-policy', '<p><strong>Template, bukan nasihat hukum.</strong> Sesuaikan kebijakan ini dengan data yang benar-benar diproses situs, yurisdiksi, theme, plugin, analytics, hosting, dan prosedur organisasi Anda.</p>
<h2>Inventaris data</h2>
<p>Dokumentasikan data akun, log keamanan, alamat IP, cookie sesi, unggahan, form, analytics, dan integrasi pihak ketiga yang benar-benar aktif. Core memakai data akun dan cookie sesi untuk autentikasi; plugin dapat menambah pemrosesan lain.</p>
<h2>Tujuan dan retensi</h2>
<p>Nyatakan dasar/tujuan pemrosesan, penerima data, lokasi penyimpanan, masa retensi, backup, serta proses penghapusan. Jangan menjanjikan anonimisasi, enkripsi, backup berkala, atau respons dalam jangka tertentu kecuali deployment Anda menerapkannya dan dapat membuktikannya.</p>
<h2>Hak dan kontak</h2>
<p>Jelaskan cara subjek data meminta akses, koreksi, ekspor, keberatan, atau penghapusan sesuai hukum yang berlaku. Tambahkan kontak privacy yang valid dan tanggal berlaku/revisi. Tinjau ulang setiap kali plugin, vendor, atau praktik operasional berubah.</p>
', 'page', NULL, NULL, NULL, 'published', 1, '2026-07-24 23:50:53', '2026-08-19 00:00:00', 0, NULL, 0),
(286, 'Recycle Bin dan Soft Delete Konten', 'sistem-soft-delete-recycle-bin', '<p>Recycle Bin Core berlaku untuk artikel, halaman, theme content, kategori, dan user yang memiliki <code>is_deleted</code>/<code>deleted_at</code>. Media dan File Library tidak memakai kontrak soft delete yang sama.</p>
<h2>Trash, restore, purge</h2>
<p>Delete awal menandai record agar tidak tampil pada daftar aktif atau frontend. Halaman Bin per tipe menampilkan item yang boleh dilihat pengguna berdasarkan permission restore/purge dan scope pemilik.</p>
<img src="/static/img/2026/07/bin-screenshot.jpg" alt="Recycle Bin artikel" style="width:100%;margin:1rem 0">
<p>Restore menghapus tanda delete. Untuk konten dan kategori, Core memeriksa collision slug dengan record aktif; selesaikan konflik sebelum mencoba lagi. Artikel/halaman non-draft juga dapat memerlukan permission publish saat dipulihkan. Purge menghapus record permanen dan relasi database terkait dapat ikut terhapus melalui foreign key.</p>
<h2>Apa yang tidak dijamin</h2>
<p>Core tidak menyimpan snapshot status sebelum trash di kolom terpisah; status post yang ada tetap pada record. Tidak ada janji auto-clean universal atau alasan penghapusan pada schema tersebut. Media/file delete memiliki alur sendiri dan dapat menghapus file fisik, jadi jangan mengandalkan Bin untuk aset.</p>
<h2>Prosedur aman</h2>
<ol>
  <li>Batasi restore dan purge dengan permission/scope minimum.</li>
  <li>Periksa slug, rute, kategori, dan tampilan setelah restore.</li>
  <li>Backup database serta storage secara terpisah.</li>
  <li>Uji restore backup; Trash hanya melindungi dari sebagian kesalahan operasional.</li>
</ol>
', 'article', NULL, NULL, '/static/img/2026/07/sistem-soft-delete-thumb.jpg', 'published', 1, '2026-07-25 00:03:17', '2026-08-19 00:00:00', 0, NULL, 0),
(287, 'Update, Reinstall, Backup, dan Hard Reset', 'sistem-reinstall-dan-update', '<p>CMS Update hanya tersedia bagi Site Owner yang juga memiliki <code>core.updates.manage</code>. Halaman dapat memeriksa endpoint remote, menerima ZIP manual berisi manifest, menerapkan update, atau reinstall Core.</p>
<img src="/static/img/2026/07/update-page-screenshot.jpg" alt="CMS Update" style="width:100%;margin:1rem 0">
<h2>Sebelum perubahan</h2>
<p>Backup database, <code>cfg/.env</code>, <code>cfg/var</code>, private files, upload bertanggal, plugin, dan theme pihak ketiga. Pastikan proses web dapat menulis target Core. Backup otomatis updater untuk file yang berubah membantu rollback file, tetapi bukan pengganti backup deployment lengkap.</p>
<h2>Update dan reinstall</h2>
<p>Remote check mengambil metadata versi, lalu paket diverifikasi terhadap manifest sebelum penerapan. Upload manual mengharuskan <code>cms-manifest.json</code> di root ZIP. Reinstall menimpa file Core dengan versi paket original sambil mempertahankan data yang dikecualikan oleh kebijakan updater.</p>
<h2>Hard reset</h2>
<p>Opsi hard reset hanya tersedia bersama reinstall. Opsi ini mengembalikan system theme, auth paths, status plugin, slot, sidebar, dan menu ke default. Konten, user, media, konfigurasi database, dan upload dinyatakan tidak dihapus oleh alur ini, tetapi backup tetap wajib. Tidak ada tombol soft reset terpisah.</p>
<h2>Verifikasi</h2>
<p>Setelah update, cocokkan versi, buka frontend/login/dashboard, uji tulis konten, cek plugin/theme, dan baca log. Jangan menimpa deployment dengan <code>git pull</code> tanpa memahami preserve policy. Jika update gagal, pertahankan bukti log dan pulihkan dari backup yang telah diuji.</p>
', 'article', NULL, NULL, '/static/img/2026/07/sistem-reinstall-update-thumb.jpg', 'published', 1, '2026-07-25 00:06:50', '2026-08-19 00:00:00', 0, NULL, 0),
(288, 'Memulai dengan Jyavani CMS 2.3.74', 'memulai-dengan-jyavani', '<p>Jyavani CMS 2.3.74 adalah CMS native PHP tanpa Composer runtime. Entry publik berada di <code>public/router.php</code>; konfigurasi, dashboard, aplikasi, plugin, schema, dan private files berada di luar web root deployment yang benar.</p>
<h2>Orientasi pertama</h2>
<p>Setelah Pondasi selesai, login melalui path yang dibuat installer, default <code>/login/</code>. Dashboard default berada di <code>/dashboard/</code>. Keduanya dapat diubah di Auth Settings; akses path lama akan tampil sebagai 404.</p>
<img src="/static/img/2026/07/dashboard-screenshot.jpg" alt="Dashboard Jyavani" style="width:100%;margin:1rem 0">
<h2>Checklist awal</h2>
<ol>
  <li>Hapus/nonaktifkan folder installer <code>public/pondasi</code>.</li>
  <li>Pastikan <code>PUBLIC_PATH</code> menunjuk web root absolut yang ada.</li>
  <li>Periksa site URL, title, bahasa dashboard, dan content default language.</li>
  <li>Uji login, logout, 404 dashboard, dan HTTPS/session cookie.</li>
  <li>Buat artikel draft, pilih kategori, preview bila diizinkan, lalu publish.</li>
  <li>Siapkan backup database, upload publik, dan private files.</li>
</ol>
<h2>Model akses</h2>
<p>Akun Pondasi adalah Site Owner dan Administrator. Jangan membagikan akun ini. Buat akun harian dengan system/custom role minimum. Registrasi publik default nonaktif; bila diaktifkan, akun baru menerima Author dan dapat menunggu approval.</p>
<p>Gunakan Theme/Plugin Store hanya untuk paket tepercaya. Perubahan URL baru sebaiknya memakai canonical content routes agar riwayat path tetap dapat redirect. Untuk instalasi detail, ikuti artikel Pondasi pada dokumentasi demo ini.</p>
', 'article', NULL, NULL, '/static/img/2026/07/memulai-jyavani-thumb.jpg', 'published', 1, '2026-07-25 00:09:41', '2026-08-19 00:00:00', 0, NULL, 0),
(289, 'Private Files dan Media: Storage, Scope, dan Shortcode', 'private-files-dan-media', '<p>Core memisahkan gambar di Media Library dan dokumen umum di File Library. Keduanya mendukung public/private storage serta access scope, tetapi URL dan shortcode-nya berbeda.</p>
<h2>Kontrak akses</h2>
<ul>
  <li><code>public</code>: aset hanya publik bila visibility, disk, dan scope semuanya public.</li>
  <li><code>editorial</code>: akses internal tim konten sesuai permission efektif.</li>
  <li><code>admin</code>: akses administrator yang berwenang.</li>
</ul>
<p>File private berada di <code>private_files/files</code>; gambar private berada di <code>private_files/media</code>. Controller membaca record, sesi, permission, storage identity, dan path sebelum stream. Jangan menaruh data sensitif pada public disk lalu hanya menyembunyikan URL.</p>
<img src="/static/img/2026/07/files-list-screenshot.jpg" alt="File Library" style="width:100%;margin:1rem 0">
<h2>Penyisipan</h2>
<p>Gunakan ID File Library untuk <code>[private_pdf id="123" mode="embed"]</code> atau mode card/link yang didukung. Viewer PDF membuat URL stream bertoken HMAC dengan masa terbatas; token itu bukan mekanisme berbagi publik. Gambar private dipilih melalui Media Picker dan memakai endpoint private media, bukan shortcode PDF.</p>
<h2>Batas fitur</h2>
<p>Scope Core ditujukan untuk public, editorial, dan admin. Core tidak menyediakan membership/subscriber download policy. Plugin dapat menambah workflow, tetapi harus tetap memanggil guard Core dan tidak memperlonggar akses.</p>
<p>Backup public upload dan kedua private storage. Aksi delete media/file bukan Recycle Bin universal dan dapat menghapus file fisik. Uji akses sebagai anonymous, author, editor, admin non-owner, dan Site Owner.</p>
', 'article', NULL, NULL, '/static/img/2026/07/private-files-thumb.jpg', 'published', 1, '2026-07-25 09:01:03', '2026-08-19 00:00:00', 0, NULL, 0),
(290, 'Roles & Permissions: Akses Dinamis dan Scope', 'roles-permissions-mengatur-akses-pengguna', '<p>Roles &amp; Permissions menggabungkan grant dari seluruh role aktif pengguna. Menu ini dibatasi untuk Site Owner; permission pada route tetap diperiksa server-side.</p>
<img src="/static/img/2026/08/roles-permissions-light.jpg" alt="Roles dan Permissions" style="width:100%;margin:1rem 0">
<h2>System role</h2>
<p>Author, Editor, dan Administrator disediakan Core. Slug, rank dasar, dan grant Core system role direkonsiliasi schema/migrasi agar kebijakan dasar tetap konsisten. Gunakan custom role untuk variasi tugas, bukan mengandalkan modifikasi grant system role yang dapat diselaraskan kembali.</p>
<h2>Permission dan scope</h2>
<p>Permission key menyatakan tindakan. <code>supports_scope</code> menyatakan apakah tindakan memerlukan konteks owner. Scope <strong>Own</strong> membatasi resource sendiri; <strong>Same or Lower</strong> membandingkan authority rank owner; <strong>Any</strong> mencakup semua resource; <strong>Global</strong> untuk tindakan tanpa owner. Rank tidak memberi akses tanpa permission.</p>
<h2>Custom role</h2>
<ol>
  <li>Buat slug stabil dan rank minimum.</li>
  <li>Aktifkan hanya permission yang dibutuhkan.</li>
  <li>Pilih scope terkecil untuk permission berscope.</li>
  <li>Tetapkan role ke user, lalu uji dengan akun tersebut.</li>
</ol>
<p>Permission yang belum dimigrasikan penuh dapat tampil tidak tersedia untuk custom role. Grant nonaktif dipertahankan agar tidak hilang diam-diam. Permission plugin juga dapat nondelegable sehingga hanya system role default yang boleh menerimanya.</p>
<p>Perubahan role dan permission memicu audit/hook setelah transaksi. Core melakukan recheck pada mutasi sensitif untuk mengurangi race ketika akses berubah bersamaan.</p>
', 'article', '{"meta_tags":{"description":"Panduan Roles & Permissions Jyavani Core: system role, custom role, permission, scope, authority rank, dan Site Owner."}}', NULL, '/static/img/2026/08/roles-permissions-thumbnail.jpg', 'published', 1, '2026-08-18 18:00:00', '2026-08-19 00:00:00', 0, NULL, 0),
(291, 'Instalasi Bersih Jyavani CMS: Pondasi, Site Owner, dan Verifikasi Pertama', 'instalasi-bersih-pondasi-site-owner-verifikasi', '<p>Pondasi adalah installer web sekali pakai untuk deployment baru. Ekstrak paket Jyavani sehingga document root menunjuk direktori publik yang berisi router, sementara <code>cfg</code>, <code>dashboard</code>, <code>app</code>, <code>plugins</code>, dan <code>private_files</code> tetap di luar web root.</p>
<h2>Prasyarat dan database</h2>
<ol>
  <li>Siapkan PHP dan MariaDB/MySQL sesuai versi paket serta extension yang dibutuhkan.</li>
  <li>Buka <code>/pondasi/</code>, isi host, port, nama database, user, dan password.</li>
  <li>Pondasi membuat/menjalankan schema default, seed translasi, dan menandai migrasi paket sebagai applied.</li>
  <li>Isi identitas situs dan akun owner awal; konten demo bersifat opsional.</li>
</ol>
<h2>Site Owner dan grant awal</h2>
<p>Akun awal ditulis sebagai legacy admin, <code>is_site_owner=1</code>, tidak locked, lalu menerima system role Administrator. Core mencatat event instalasi owner. System role Author, Editor, Administrator dan grant Core berasal dari schema; jangan membuat grant awal manual.</p>
<h2>Path dan masking</h2>
<p>Installer menetapkan default <code>/dashboard/</code>, <code>/login/</code>, dan <code>/register/</code>. Dashboard berada di luar web root; request yang tidak cocok dengan path konfigurasi diberi frontend 404. Registrasi tidak diaktifkan hanya karena path tersedia. Defaultnya disabled; bila administrator mengaktifkannya, pendaftar mendapat Author dan dapat locked bila approval aktif.</p>
<h2>File konfigurasi dan lock operasional</h2>
<p>Pondasi menulis <code>cfg/.env</code>, secret acak, session settings, dan <code>PUBLIC_PATH</code> absolut menuju web root yang terdeteksi. Bila penulisan gagal, installer menampilkan isi untuk pemasangan manual. Setelah sukses, hapus atau nonaktifkan folder <code>public/pondasi</code>; keberadaan <code>.env</code> hanya mengubah bootstrap normal, bukan pengganti penghapusan installer.</p>
<h2>Verifikasi pertama</h2>
<ul>
  <li>Pastikan frontend, login, dashboard, logout, dan 404 masking bekerja.</li>
  <li>Periksa Site Owner memiliki Administrator dan halaman Roles/Update dapat dibuka.</li>
  <li>Buat draft, upload aset uji, lalu hapus data uji.</li>
  <li>Verifikasi HTTPS, cookie Secure, email/site URL, dan backup.</li>
</ul>
<p>Owner deployment harus mengatur ownership/permission file agar proses web hanya dapat menulis lokasi yang diperlukan. Anggap mode file hasil ekstraksi sebagai kondisi paket yang harus diaudit, bukan kebijakan keamanan Core yang sengaja menjadikan semua file privat.</p>
', 'article', NULL, NULL, '/static/img/2026/07/memulai-jyavani-thumb.jpg', 'published', 1, '2026-08-19 00:00:00', '2026-08-19 00:00:00', 0, NULL, 0),
(292, 'Plugin Permissions: Default Roles dan Delegability', 'plugin-permissions-default-roles-dan-delegability', '<p>Kontrak permission plugin diperkenalkan pada 2.3.73 dan diperketat pada 2.3.74. Plugin mendeklarasikan permission miliknya di <code>plugin.json</code>; Core memvalidasi lalu menyinkronkannya ke authorization dinamis.</p>
<h2>Namespace dan route guard</h2>
<p>Key harus lowercase dan berbentuk <code>plugin.{nama-plugin}.{resource}.{action}</code>. Nama provider harus sama dengan nama plugin. Route dashboard dapat mendeklarasikan <code>permission</code>; permission route tidak boleh berscope karena route tidak membawa konteks resource. <code>roles</code> tetap menjadi compatibility policy dan filter pengetatan.</p>
<pre><code>{
  "name": "example",
  "permissions": [{
    "key": "plugin.example.settings.access",
    "label": "Access Example Settings",
    "supports_scope": false,
    "default_roles": ["admin"],
    "delegable": true
  }]
}</code></pre>
<h2>Default roles</h2>
<p><code>default_roles</code> hanya menerima Author, Editor, atau Administrator dan menyemai grant system role saat sinkronisasi. Bila permission menjaga route dan default tidak ditulis, Core menurunkannya dari compatibility <code>roles</code>. Bila keduanya ditulis, nilainya harus identik.</p>
<h2>Delegability</h2>
<p><code>delegable:true</code> mengizinkan grant custom role dipertahankan. <code>delegable:false</code> membatasi assignment ke system role default dan sinkronisasi menghapus grant stale pada custom role. Ini berguna untuk operasi plugin yang tidak aman untuk didelegasikan.</p>
<h2>Lifecycle dan fail closed</h2>
<p>Plugin aktif disinkronkan setelah load dan sebelum <code>admin_init</code>. Permission plugin disabled tetap dikenal tetapi <code>is_active=0</code>; uninstall menghapus permission dan grant. Perubahan label/delegability dapat diperbarui, tetapi collision semantic provider/resource/action/scope menyebabkan rollback. Route permission belum dianggap siap bila sinkronisasi gagal.</p>
<p>Collision key, ownership, route Core, route plugin lain, atau kontrak invalid ditolak. ACL plugin adalah syarat tambahan: route tetap harus lolos Core guard, Site Owner policy bila dipakai, compatibility role filter, dan pemeriksaan permission. Tidak ada deklarasi plugin yang boleh melewati guard Core.</p>
', 'article', NULL, NULL, '/static/img/2026/08/roles-permissions-thumbnail.jpg', 'published', 1, '2026-08-19 00:00:00', '2026-08-19 00:00:00', 0, NULL, 0),
(293, 'Canonical Content Routes dan Riwayat Redirect', 'canonical-content-routes-dan-riwayat-redirect', '<p><code>content_routes</code> adalah registry path publik untuk artikel, halaman, dan theme content. Registry memisahkan identitas post/slug lama dari URL publik yang dapat berubah tanpa kehilangan riwayat redirect.</p>
<h2>Canonical dan alias</h2>
<p>Untuk setiap pasangan post-locale hanya satu row memiliki <code>is_canonical=1</code> dan <code>canonical_slot=1</code>. Saat canonical diganti, canonical lama diturunkan menjadi alias. Resolver alias mengembalikan target canonical sehingga router dapat memberi redirect permanen dan mempertahankan query string dengan aman.</p>
<h2>Namespace global</h2>
<p>Path dinormalisasi lowercase ASCII tanpa slash awal/akhir. Segment dapat bertingkat, misalnya <code>docs/core/routing</code>. Unik berlaku per locale. Validator juga menolak collision dengan slug/rute konten, permalink lama, prefix Core, path auth/list/category, route plugin, tahun arsip, dan file/direktori publik. Karena URL default memakai locale kosong, semua tipe konten berbagi namespace global tersebut.</p>
<h2>Fallback</h2>
<p>Konten lama yang belum memiliki canonical route tetap dapat diselesaikan melalui permalink dan slug legacy. Fallback bukan alias tersimpan: setelah route dibuat, gunakan registry sebagai sumber URL kanonis. Menu theme content memilih canonical route; sitemap canonical juga bergantung pada registry.</p>
<h2>Locale dan plugin translasi</h2>
<p>Core menyimpan kolom locale dan dapat membentuk URL <code>/{locale}/{path}/</code>, tetapi Core tidak menyediakan editor translasi native. Plugin translasi bertanggung jawab membuat versi konten, menentukan locale, menyinkronkan canonical/alias, menangani redirect lintas locale, dan menambah sitemap locale. Plugin harus memakai helper route agar collision, transaksi, dan riwayat canonical tetap konsisten.</p>
<p>Sebelum mengubah path, cek link internal dan route plugin, simpan canonical baru secara atomik, lalu uji URL baru, alias lama, query string, 404, menu, canonical tag, dan sitemap.</p>
', 'article', NULL, NULL, '/static/img/2026/07/seo-meta-tags-thumb.jpg', 'published', 1, '2026-08-19 00:00:00', '2026-08-19 00:00:00', 0, NULL, 0),
(294, 'Bahasa Dashboard, Content Locale, dan Integrasi Translasi', 'bahasa-dashboard-content-locale-dan-translasi', '<p>Jyavani memisahkan bahasa antarmuka dashboard dari locale dasar konten. Pemisahan ini memungkinkan editor memakai UI Indonesia sementara situs dasar menggunakan bahasa lain.</p>
<h2>Dua setting</h2>
<ul>
  <li><code>site_language</code> mengisi locale dashboard melalui <code>admin_ui_locale()</code>.</li>
  <li><code>content_default_language</code> mengisi locale frontend melalui <code>default_locale()</code>/<code>content_default_locale()</code>.</li>
</ul>
<p>Jika content default kosong atau invalid, Core jatuh kembali ke site language; nilai invalid site language jatuh ke English. Seed UI menyediakan source English serta translasi Indonesia dan Jerman. English tidak memerlukan lookup database.</p>
<h2>Bootstrap dan HTML</h2>
<p>Bootstrap membaca kedua setting, mengaktifkan content locale untuk frontend, lalu dashboard mengaktifkan kembali site language. Atribut <code>lang</code> melewati filter sehingga integrasi dapat menyesuaikan nilai. Seed UI diimpor idempotent: string baru ditambahkan tanpa menimpa terjemahan yang sudah diedit.</p>
<h2>Apa yang bukan fitur Core</h2>
<p>Core tidak membuat salinan terjemahan artikel/halaman, workflow penerjemah, language switcher data, route terjemahan, hreflang, atau fallback konten otomatis. Core hanya menyediakan locale helper, UI seed, hook lifecycle konten, tabel route ber-locale, dan extension point.</p>
<h2>Tanggung jawab plugin translasi</h2>
<p>Plugin menentukan model translasi, locale yang didukung, akses editor, dan fallback. Gunakan hook <code>admin_post_after_*</code>/<code>admin_page_after_*</code> untuk sinkronisasi setelah mutasi. Buat canonical route dan alias untuk setiap locale tanpa collision; default locale biasanya tanpa prefix, locale lain memakai prefix.</p>
<p>Menu dan kategori hanya diterjemahkan bila plugin secara eksplisit mendukung record tersebut dan theme memakai hasil integrasinya. Jangan menganggap mengganti content default otomatis menerjemahkan slug, menu, kategori, metadata, atau isi lama. Uji UI en/id/de, URL default/terjemahan, redirect, canonical, sitemap, dan kondisi plugin disabled.</p>
', 'article', NULL, NULL, '/static/img/2026/07/settings-dashboard-thumb.jpg', 'published', 1, '2026-08-19 00:00:00', '2026-08-19 00:00:00', 0, NULL, 0);

INSERT INTO `media` (`id`, `url`, `filename`, `mime`, `ext`, `size`, `width`, `height`, `title`, `alt`, `caption`, `credit`, `visibility`, `storage_disk`, `storage_path`, `access_scope`, `is_downloadable`, `user_id`, `created_at`, `updated_at`, `target_url`, `target_attribute`) VALUES
(64, '/static/img/2026/07/content-management-71b8788f.jpg', 'content-management-71b8788f.png', 'image/png', 'png', 372716, 1200, 675, 'Content Management', 'Content Management System Dashboard', NULL, NULL, 'public', 'public', '2026/07/content-management-71b8788f.png', 'public', 1, 1, '2026-07-24 09:57:56', NULL, NULL, NULL),
(65, '/static/img/2026/07/screenshots/posts-list.jpg', 'posts-list.png', 'image/png', 'png', 95949, 1280, 720, 'posts-list', 'Screenshot posts-list', NULL, NULL, 'public', 'public', '2026/07/screenshots/posts-list.png', 'public', 1, 1, '2026-07-24 10:07:52', NULL, NULL, NULL),
(66, '/static/img/2026/07/screenshots/add-post.jpg', 'add-post.png', 'image/png', 'png', 81382, 1280, 720, 'add-post', 'Screenshot add-post', NULL, NULL, 'public', 'public', '2026/07/screenshots/add-post.png', 'public', 1, 1, '2026-07-24 10:07:52', NULL, NULL, NULL),
(67, '/static/img/2026/07/screenshots/pages-list.jpg', 'pages-list.png', 'image/png', 'png', 83428, 1280, 720, 'pages-list', 'Screenshot pages-list', NULL, NULL, 'public', 'public', '2026/07/screenshots/pages-list.png', 'public', 1, 1, '2026-07-24 10:07:52', NULL, NULL, NULL),
(68, '/static/img/2026/07/screenshots/categories.jpg', 'categories.png', 'image/png', 'png', 96334, 1280, 720, 'categories', 'Screenshot categories', NULL, NULL, 'public', 'public', '2026/07/screenshots/categories.png', 'public', 1, 1, '2026-07-24 10:07:52', NULL, NULL, NULL),
(70, '/static/img/2026/07/screenshots/categories-list.jpg', 'categories-list.png', 'image/png', 'png', 96334, 1280, 720, 'categories-list', 'Screenshot categories-list', NULL, NULL, 'public', 'public', '2026/07/screenshots/categories-list.png', 'public', 1, 1, '2026-07-24 10:26:10', NULL, NULL, NULL),
(71, '/static/img/2026/07/screenshots/add-category.jpg', 'add-category.png', 'image/png', 'png', 76007, 1280, 720, 'add-category', 'Screenshot add-category', NULL, NULL, 'public', 'public', '2026/07/screenshots/add-category.png', 'public', 1, 1, '2026-07-24 10:26:10', NULL, NULL, NULL),
(72, '/static/img/2026/07/screenshots/category-frontend.jpg', 'category-frontend.png', 'image/png', 'png', 60729, 1280, 720, 'category-frontend', 'Screenshot category-frontend', NULL, NULL, 'public', 'public', '2026/07/screenshots/category-frontend.png', 'public', 1, 1, '2026-07-24 10:26:10', NULL, NULL, NULL),
(73, '/static/img/2026/07/kategori-organisasi-0ef63d42.jpg', 'kategori-organisasi-0ef63d42.png', 'image/png', 'png', 471332, 1200, 675, 'Kategori dan Organisasi', 'Kategori dan Organisasi Konten', NULL, NULL, 'public', 'public', '2026/07/kategori-organisasi-0ef63d42.png', 'public', 1, 1, '2026-07-24 10:45:07', NULL, NULL, NULL),
(75, '/static/img/2026/07/screenshots/media-list.jpg', 'media-list.png', 'image/png', 'png', 176435, 1280, 720, 'media-list', 'Screenshot media-list', NULL, NULL, 'public', 'public', '2026/07/screenshots/media-list.png', 'public', 1, 1, '2026-07-24 10:47:06', NULL, NULL, NULL),
(76, '/static/img/2026/07/screenshots/media-upload.jpg', 'media-upload.png', 'image/png', 'png', 75882, 1280, 720, 'media-upload', 'Screenshot media-upload', NULL, NULL, 'public', 'public', '2026/07/screenshots/media-upload.png', 'public', 1, 1, '2026-07-24 10:47:06', NULL, NULL, NULL),
(77, '/static/img/2026/07/menu-manager-thumb.jpg', 'menu-manager-thumb.png', 'image/png', 'png', 306864, 1024, 559, 'Menu Manager Thumbnail', 'Thumbnail for Menu Manager article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 11:05:59', NULL, NULL, NULL),
(78, '/static/img/2026/07/menu-manager-screenshot.jpg', 'menu-manager-screenshot.jpg', 'image/jpeg', 'jpg', 91556, 1280, 720, 'Menu Manager Screenshot', 'Menu Manager list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 11:05:59', NULL, NULL, NULL),
(79, '/static/img/2026/07/menu-manager-items-screenshot.jpg', 'menu-manager-items-screenshot.jpg', 'image/jpeg', 'jpg', 94241, 1280, 720, 'Menu Items Screenshot', 'Menu items list screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 11:05:59', NULL, NULL, NULL),
(80, '/static/img/2026/07/theme-customizer-menu-screenshot.jpg', 'theme-customizer-menu-screenshot.jpg', 'image/jpeg', 'jpg', 89144, 1280, 720, 'Theme Customizer Menu Screenshot', 'Theme Customizer menu assignment screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 11:05:59', NULL, NULL, NULL),
(81, '/static/img/2026/07/theme-system-thumb.jpg', 'theme-system-thumb.png', 'image/png', 'png', 358203, 1024, 559, 'Theme System Thumbnail', 'Thumbnail for Theme System article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 17:36:22', NULL, NULL, NULL),
(82, '/static/img/2026/07/themes-list-screenshot.jpg', 'themes-list-screenshot.jpg', 'image/jpeg', 'jpg', 111777, 1280, 720, 'Themes List Screenshot', 'Theme Manager list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 17:36:22', '2026-07-24 17:36:57', NULL, NULL),
(84, '/static/img/2026/07/theme-customizer-screenshot.jpg', 'theme-customizer-screenshot.jpg', 'image/jpeg', 'jpg', 226310, 1280, 1763, 'Theme Customizer Screenshot', 'Theme Customizer interface screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 17:36:22', '2026-07-24 17:36:57', NULL, NULL),
(85, '/static/img/2026/07/plugin-system-thumb.jpg', 'plugin-system-thumb.png', 'image/png', 'png', 1652112, 1402, 1122, 'Plugin System Thumbnail', 'Thumbnail for Plugin System article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 18:43:04', NULL, NULL, NULL),
(86, '/static/img/2026/07/plugin-manager-screenshot.jpg', 'plugin-manager-screenshot.jpg', 'image/jpeg', 'jpg', 127366, 1280, 720, 'Plugin Manager Screenshot', 'Plugin Manager list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 18:43:04', '2026-07-24 18:43:15', NULL, NULL),
(88, '/static/img/2026/07/plugin-store-screenshot.jpg', 'plugin-store-screenshot.jpg', 'image/jpeg', 'jpg', 152884, 1280, 720, 'Plugin Store Screenshot', 'Plugin Store browse page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 18:43:04', '2026-07-24 18:43:15', NULL, NULL),
(89, '/static/img/2026/07/settings-dashboard-thumb.jpg', 'settings-dashboard-thumb.png', 'image/png', 'png', 211017, 1024, 559, 'Settings Dashboard Thumbnail', 'Thumbnail for Settings Dashboard article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:00:47', NULL, NULL, NULL),
(90, '/static/img/2026/07/site-settings-screenshot.jpg', 'site-settings-screenshot.jpg', 'image/jpeg', 'jpg', 141266, 1280, 1262, 'Site Settings Screenshot', 'Site Settings page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:00:47', '2026-07-24 19:00:56', NULL, NULL),
(92, '/static/img/2026/07/sidebar-settings-screenshot.jpg', 'sidebar-settings-screenshot.jpg', 'image/jpeg', 'jpg', 93027, 1280, 720, 'Sidebar Settings Screenshot', 'Sidebar settings page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:00:47', '2026-07-24 19:00:56', NULL, NULL),
(93, '/static/img/2026/07/user-management-thumb.jpg', 'user-management-thumb.jpg', 'image/jpeg', 'jpg', 86524, 1024, 559, 'User Management Thumbnail', 'Thumbnail for User Management article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:07:46', NULL, NULL, NULL),
(94, '/static/img/2026/07/users-list-screenshot.jpg', 'users-list-screenshot.jpg', 'image/jpeg', 'jpg', 115002, 1280, 720, 'Users List Screenshot', 'Users list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:07:46', '2026-07-24 19:07:57', NULL, NULL),
(96, '/static/img/2026/07/add-user-screenshot.jpg', 'add-user-screenshot.jpg', 'image/jpeg', 'jpg', 77822, 1280, 720, 'Add User Screenshot', 'Add user form screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:07:46', '2026-07-24 19:07:57', NULL, NULL),
(97, '/static/img/2026/07/seo-meta-tags-thumb.jpg', 'seo-meta-tags-thumb.png', 'image/png', 'png', 427001, 1024, 559, 'SEO Meta Tags Thumbnail', 'Thumbnail for SEO article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:16:07', NULL, NULL, NULL),
(98, '/static/img/2026/07/meta-tags-screenshot.jpg', 'meta-tags-screenshot.jpg', 'image/jpeg', 'jpg', 76904, 1280, 720, 'Meta Tags Screenshot', 'Meta tags field in post editor', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:16:07', '2026-07-24 19:16:16', NULL, NULL),
(100, '/static/img/2026/07/sitemap-screenshot.jpg', 'sitemap-screenshot.png', 'image/png', 'png', 156672, 1280, 800, 'Sitemap Screenshot', 'sitemap.xml preview', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:16:07', '2026-07-24 19:16:16', NULL, NULL),
(101, '/static/img/2026/07/widget-shortcodes-thumb.jpg', 'widget-shortcodes-thumb.png', 'image/png', 'png', 370305, 1024, 559, 'Widget dan Shortcodes Thumbnail', 'Thumbnail for Widget dan Shortcodes article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:33:31', NULL, NULL, NULL),
(102, '/static/img/2026/07/shortcode-builder-screenshot.jpg', 'shortcode-builder-screenshot.jpg', 'image/jpeg', 'jpg', 88566, 1280, 720, 'Shortcode Builder Screenshot', 'Shortcode builder interface', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:33:31', '2026-07-24 23:33:39', NULL, NULL),
(104, '/static/img/2026/07/widget-settings-screenshot.jpg', 'widget-settings-screenshot.jpg', 'image/jpeg', 'jpg', 87122, 1280, 720, 'Widget Settings Screenshot', 'Widget settings page', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:33:31', '2026-07-24 23:33:39', NULL, NULL),
(105, '/static/img/2026/07/keamanan-cms-thumb.jpg', 'keamanan-cms-thumb.jpg', 'image/jpeg', 'jpg', 70925, 1024, 559, 'Keamanan CMS Thumbnail', 'Thumbnail for Keamanan CMS article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:45:59', NULL, NULL, NULL),
(106, '/static/img/2026/07/auth-settings-screenshot.jpg', 'auth-settings-screenshot.jpg', 'image/jpeg', 'jpg', 78634, 1280, 720, 'Auth Settings Screenshot', 'Auth settings page with custom admin path', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:45:59', '2026-07-24 23:46:08', NULL, NULL),
(108, '/static/img/2026/07/login-page-screenshot.jpg', 'login-page-screenshot.jpg', 'image/jpeg', 'jpg', 50546, 1280, 720, 'Login Page Screenshot', 'Custom login page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:45:59', '2026-07-24 23:46:08', NULL, NULL),
(109, '/static/img/2026/07/sistem-soft-delete-thumb.jpg', 'sistem-soft-delete-thumb.png', 'image/png', 'png', 208635, 1024, 559, 'Sistem Soft Delete Thumbnail', 'Thumbnail for Sistem Soft Delete article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:03:17', NULL, NULL, NULL),
(110, '/static/img/2026/07/bin-screenshot.jpg', 'bin-screenshot.jpg', 'image/jpeg', 'jpg', 113956, 1280, 720, 'Recycle Bin Screenshot', 'Thumbnail for Sistem Soft Delete article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:03:17', '2026-07-25 08:01:27', NULL, NULL),
(111, '/static/img/2026/07/sistem-reinstall-update-thumb.jpg', 'sistem-reinstall-update-thumb.png', 'image/png', 'png', 0, 1024, 559, 'Sistem Reinstall Update Thumbnail', 'Thumbnail for Sistem Reinstall dan Update article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:06:50', NULL, NULL, NULL),
(112, '/static/img/2026/07/update-page-screenshot.jpg', 'update-page-screenshot.jpg', 'image/jpeg', 'jpg', 107156, 1280, 720, 'Update Page Screenshot', 'Update and maintenance page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:06:50', '2026-07-25 08:01:27', NULL, NULL),
(113, '/static/img/2026/07/memulai-jyavani-thumb.jpg', 'memulai-jyavani-thumb.png', 'image/png', 'png', 0, 1024, 559, 'Memulai dengan Jyavani Thumbnail', 'Thumbnail for Memulai dengan Jyavani article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:09:41', NULL, NULL, NULL),
(114, '/static/img/2026/07/dashboard-screenshot.jpg', 'dashboard-screenshot.png', 'image/png', 'png', 150802, 1911, 923, 'Dashboard Screenshot', 'Jyavani CMS dashboard screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:09:41', '2026-07-25 08:01:27', NULL, NULL),
(115, '/static/img/2026/07/add-post-screenshot.jpg', 'add-post-screenshot.jpg', 'image/jpeg', 'jpg', 82706, 1280, 720, 'Add Post Screenshot', 'Add new post form screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:09:41', '2026-07-25 08:01:27', NULL, NULL),
(117, '/static/img/2026/07/private-files-thumb.jpg', 'private-files-thumb.png', 'image/png', 'png', 0, 1536, 1024, 'Private Files Thumbnail', 'Thumbnail for Private Files and Media article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:01:03', NULL, NULL, NULL),
(118, '/static/img/2026/07/files-list-screenshot.jpg', 'files-list-screenshot.png', 'image/png', 'png', 0, 1905, 1190, 'Private Files List Screenshot', 'Private Files list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:01:03', NULL, NULL, NULL),
(119, '/static/img/2026/07/media-list-screenshot.jpg', 'media-list-screenshot.jpg', 'image/jpeg', 'jpg', 114224, 1280, 720, 'Media List Screenshot', 'Media Library list page with visibility column', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:01:03', NULL, NULL, NULL),
(120, '/static/img/2026/07/media-library-thumb-gen.jpg', 'media-library-thumb-gen.png', 'image/png', 'png', 369059, 1024, 559, 'Media Library Thumbnail', 'Thumbnail for Media Library article', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/media-library-thumb-gen.jpg', 'public', 1, 1, '2026-07-25 09:22:03', '2026-07-25 09:22:03', NULL, NULL),
(121, '/static/img/2026/07/user-profile-screenshot.jpg', 'user-profile-screenshot.jpg', 'image/jpeg', 'jpg', 77212, 1280, 720, 'User Profile Screenshot', 'User Profile Screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(122, '/static/img/2026/07/og-tags-screenshot.jpg', 'og-tags-screenshot.png', 'image/png', 'png', 152524, 1280, 720, 'OG Tags Screenshot', 'OG Tags Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/og-tags-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(123, '/static/img/2026/07/permalink-settings-screenshot.jpg', 'permalink-settings-screenshot.jpg', 'image/jpeg', 'jpg', 83047, 1280, 720, 'Permalink Settings Screenshot', 'Permalink Settings Screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(124, '/static/img/2026/07/plugin-upload-screenshot.jpg', 'plugin-upload-screenshot.jpg', 'image/jpeg', 'jpg', 55006, 1280, 720, 'Plugin Upload Screenshot', 'Plugin Upload Screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(125, '/static/img/2026/07/layout-editor-screenshot.jpg', 'layout-editor-screenshot.jpg', 'image/jpeg', 'jpg', 177915, 1280, 1000, 'Layout Editor Screenshot', 'Layout Editor Screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(126, '/static/img/2026/07/user-roles-screenshot.jpg', 'user-roles-screenshot.png', 'image/png', 'png', 178536, 1274, 1190, 'User Roles Screenshot', 'User Roles Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/user-roles-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(127, '/static/img/2026/08/roles-permissions-thumbnail.jpg', 'roles-permissions-thumbnail.jpg', 'image/jpeg', 'jpg', 62350, 1200, 675, 'Roles and Permissions', 'Ilustrasi pengaturan role dan permission pengguna di Jyavani CMS', NULL, 'AI-generated illustration', 'public', 'public', 'public/static/img/2026/08/roles-permissions-thumbnail.jpg', 'public', 1, 1, '2026-08-18 18:00:00', '2026-08-18 18:00:00', NULL, NULL),
(128, '/static/img/2026/08/roles-permissions-light.jpg', 'roles-permissions-light.jpg', 'image/jpeg', 'jpg', 157950, 1440, 1000, 'Roles and Permissions Light Theme', 'Tampilan light theme menu Roles dan Permissions di dashboard Jyavani CMS', NULL, 'Screenshot from authorized OpenCode test environment', 'public', 'public', 'public/static/img/2026/08/roles-permissions-light.jpg', 'public', 1, 1, '2026-08-18 18:00:00', '2026-08-18 18:00:00', NULL, NULL);

INSERT INTO `post_categories` (`post_id`, `category_id`, `assigned_by`, `assigned_at`) VALUES
(272, 1, 1, '2026-07-25 09:07:48'),
(273, 1, 1, '2026-07-25 09:07:48'),
(274, 1, 1, '2026-07-25 09:07:48'),
(274, 3, 1, '2026-07-25 09:07:48'),
(275, 1, 1, '2026-07-25 09:07:48'),
(276, 3, 1, '2026-07-25 09:07:48'),
(277, 3, 1, '2026-07-25 09:07:48'),
(278, 1, 1, '2026-07-25 09:07:48'),
(278, 4, 1, '2026-07-25 09:07:48'),
(279, 2, 1, '2026-07-25 09:07:48'),
(279, 4, 1, '2026-07-25 09:07:48'),
(280, 1, 1, '2026-07-25 09:07:48'),
(280, 3, 1, '2026-07-25 09:07:48'),
(281, 3, 1, '2026-07-25 09:07:48'),
(282, 2, 1, '2026-07-25 09:07:48'),
(282, 4, 1, '2026-07-25 09:07:48'),
(286, 2, 1, '2026-07-25 09:07:48'),
(286, 4, 1, '2026-07-25 09:07:48'),
(287, 4, 1, '2026-07-25 09:07:48'),
(288, 1, 1, '2026-07-25 09:07:48'),
(289, 2, 1, '2026-07-25 09:07:48'),
(290, 1, 1, '2026-08-18 18:00:00'),
(291, 1, 1, '2026-08-19 00:00:00'),
(291, 4, 1, '2026-08-19 00:00:00'),
(292, 2, 1, '2026-08-19 00:00:00'),
(292, 3, 1, '2026-08-19 00:00:00'),
(292, 4, 1, '2026-08-19 00:00:00'),
(293, 1, 1, '2026-08-19 00:00:00'),
(293, 3, 1, '2026-08-19 00:00:00'),
(294, 1, 1, '2026-08-19 00:00:00'),
(294, 3, 1, '2026-08-19 00:00:00');

-- Demo shortcode preset: random demo posts by the initial Site Owner.
INSERT INTO `posts` (`id`, `title`, `slug`, `content`, `type`, `meta`, `youtube`, `thumbnail`, `status`, `created_by`, `created_at`, `updated_at`, `sort_order`, `deleted_at`, `is_deleted`) VALUES
(300, 'Demo Random Posts Preset', 'demo_random_posts', '', 'sc_preset', '{"source":"posts","type":"article","category":"","author":"1","order_by":"RAND()","order_dir":"DESC","limit":"4","layout":"mini","kicker":"Pilihan","excerpt_len":"0","wrap":"1"}', NULL, NULL, 'published', 1, '2026-07-25 09:07:48', '2026-07-25 09:07:48', 0, NULL, 0);

-- Demo sidebar widget using the preset above.
INSERT IGNORE INTO `sidebar_zone_items` (`zone_id`, `type`, `title`, `config`, `ordering`, `active`) VALUES
(3, 'shortcode_preset', 'Pilihan Demo', '{"preset_slug":"demo_random_posts"}', 2, 1);

SET FOREIGN_KEY_CHECKS=1;
