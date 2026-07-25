-- Jyavani CMS Demo Content
-- Generated: 2026-07-25 16:15:36 
-- Source DB: jyavani_local
--
-- This file is imported by the installer when the user chooses "Install demo content".
-- It inserts sample categories, posts, media, and a main menu so the site is usable immediately.
--
-- Table order: categories -> posts -> media -> post_categories -> menus -> menu_items

SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

REPLACE INTO `categories` (`id`, `name`, `slug`, `description`, `parent_id`, `meta`, `created_by`, `created_at`, `updated_at`, `is_deleted`, `deleted_at`) VALUES
(1, 'Panduan', 'panduan', 'Artikel panduan langkah demi langkah menggunakan Jyavani CMS', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL),
(2, 'Keamanan', 'keamanan', 'Artikel tentang keamanan, privasi, proteksi data, dan akses kontrol', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL),
(3, 'Pengembangan', 'pengembangan', 'Artikel tentang theme, plugin, widget, shortcodes, dan pengembangan fitur CMS', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL),
(4, 'Sistem', 'sistem', 'Artikel tentang administrasi sistem, maintenance, update, dan manajemen user', NULL, NULL, 1, '2026-07-25 09:07:14', '2026-07-25 09:07:14', 0, NULL);

INSERT INTO `posts` (`id`, `title`, `slug`, `content`, `type`, `meta`, `youtube`, `thumbnail`, `status`, `created_by`, `created_at`, `updated_at`, `sort_order`, `deleted_at`, `is_deleted`) VALUES
(272, 'Content Management — Mengelola Artikel dan Halaman', 'content-management-mengelola-artikel-dan-halaman', '<p>Jyavani CMS dirancang untuk memberikan kontrol penuh atas konten website tanpa kerumitan yang tidak perlu. Dalam artikel ini, kita akan membahas secara mendalam bagaimana mengelola artikel dan halaman — dua pilar utama dari setiap sistem manajemen konten.</p>

<h2>Memahami Perbedaan Article dan Page</h2>

<p>Sebelum mulai membuat konten, penting untuk memahami perbedaan mendasar antara <strong>Article</strong> dan <strong>Page</strong> di Jyavani CMS. Keduanya disimpan di tabel yang sama (<code>posts</code>), namun memiliki perilaku dan tujuan yang berbeda.</p>

<p><strong>Article</strong> adalah konten blog yang bersifat temporal — ditampilkan secara kronologis, bisa dikategorikan, dan biasanya memiliki tanggal publikasi yang relevan. Contoh: tutorial, berita, tips, atau artikel opini.</p>

<p><strong>Page</strong> adalah konten statis yang tidak bergantung pada waktu — halaman "Tentang Kami", "Kontak", "Kebijakan Privasi", atau halaman arahan lainnya. Page tidak memiliki kategori dan tidak muncul di halaman daftar artikel.</p>

<h2>Dashboard Posts — Melihat Semua Artikel</h2>

<p>Setelah login ke dashboard admin, buka menu <strong>Posts</strong> untuk melihat daftar semua artikel. Di sini kamu akan menemukan beberapa fitur pengelolaan:</p>

<img src="/static/img/2026/07/screenshots/posts-list.jpg" alt="Dashboard Posts List" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<p><strong>Tabel Artikel</strong> — Setiap artikel ditampilkan dalam format baris dengan informasi: judul, status (draft/published/private), tanggal update, dan kategori. Kamu bisa mencari artikel berdasarkan judul atau konten menggunakan fitur pencarian di bagian atas.</p>

<p><strong>Filter dan Sorting</strong> — Gunakan dropdown filter untuk menampilkan artikel berdasarkan status tertentu. Sorting bisa dilakukan berdasarkan tanggal atau judul untuk mempermudah pencarian.</p>

<p><strong>Aksi Massal</strong> — Pilih beberapa artikel sekaligus menggunakan checkbox, lalu lakukan aksi massal seperti publish, unpublish, atau delete secara bersamaan.</p>

<h2>Membuat Artikel Baru</h2>

<p>Mari kita mulai dengan membuat artikel baru. Akses dashboard admin dan buka menu <strong>Posts → Tambah Artikel</strong>. Kamu akan melihat form editor dengan beberapa bagian utama.</p>

<img src="/static/img/2026/07/screenshots/add-post.jpg" alt="Form Tambah Artikel" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<h3>Field Utama</h3>

<p><strong>Judul</strong> — Judul artikel yang akan tampil di frontend dan halaman arsip. Buat judul yang deskriptif dan mengandung kata kunci utama.</p>

<p><strong>Slug</strong> — URL-friendly version dari judul. Slug digenerate otomatis dari judul, tapi bisa diubah manual. Pastikan slug unik dan menggunakan format kecil dengan tanda hubung sebagai pemisah.</p>

<p><strong>Konten</strong> — Area editor menggunakan Quill, editor WYSIWYG yang powerful. Kamu bisa menulis konten dengan formatting lengkap: heading, bold, italic, list, code block, blockquote, dan link. Editor juga mendukung HTML langsung untuk kontrol yang lebih presisi.</p>

<p><strong>Kategori</strong> — Assign artikel ke satu atau lebih kategori. Kategori membantu pengunjung menemukan konten yang relevan dan membantu SEO dengan struktur URL yang bersih.</p>

<p><strong>Status</strong> — Tiga opsi tersedia:</p>
<ul>
<li><code>draft</code> — Artikel tersimpan tapi belum terlihat di frontend</li>
<li><code>published</code> — Artikel tampil untuk semua pengunjung</li>
<li><code>private</code> — Artikel hanya bisa diakses oleh user yang login</li>
</ul>

<h3>Thumbnail</h3>

<p>Thumbnail adalah gambar utama yang muncul di daftar artikel dan media sosial. Di Jyavani, thumbnail bisa diatur melalui:</p>
<ul>
<li><strong>Gallery button</strong> — untuk author/editor/admin, pilih dari Media Library</li>
<li><strong>Insert via URL</strong> — untuk editor/admin, masukkan URL gambar langsung</li>
</ul>

<p>Ukuran ideal thumbnail adalah <strong>1200×675 piksel</strong> (rasio 16:9). Gambar akan otomatis di-crop dan diresize oleh tema untuk tampilan terbaik.</p>

<h3>Meta Tags</h3>

<p>Jika fitur meta tags diaktifkan di settings, kamu bisa mengatur deskripsi custom untuk SEO. Jika tidak diisi, sistem akan otomatis generate excerpt dari konten artikel (maksimal 160 karakter).</p>

<h2>Menggunakan Quill Editor</h2>

<p>Quill editor di Jyavani CMS memiliki beberapa fitur unggulan:</p>

<p><strong>Toolbar Lengkap</strong> — Format teks (bold, italic, underline, strikorthrough), heading levels, list (ordered/unordered), blockquote, code block, link, dan gambar.</p>

<p><strong>Code Block</strong> — Untuk artikel teknis, kamu bisa menyisipkan code block dengan syntax highlighting. Pilih format "Code Block" dari toolbar, lalu paste kode kamu. CMS akan otomatis detect bahasa pemrograman dan memberikan highlighting yang sesuai.</p>

<p><strong>Dark Mode Support</strong> — Editor mendukung light dan dark mode. Warna teks dan background akan menyesuaikan dengan tema yang aktif, termasuk syntax highlighting di code block.</p>

<p><strong>HTML Mode</strong> — Untuk pengguna yang lebih advanced, kamu bisa beralih ke source code view untuk mengedit HTML langsung. Ini berguna untuk menambahkan embed video atau custom styling.</p>

<h2>Mengelola Halaman (Pages)</h2>

<p>Halaman dikelola dari menu <strong>Pages → Tambah Halaman</strong>. Interface-nya mirip dengan artikel, tapi tanpa field kategori dan thumbnail.</p>

<img src="/static/img/2026/07/screenshots/pages-list.jpg" alt="Dashboard Pages List" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<p>Halaman cocok untuk konten yang bersifat statis dan tidak berubah terlalu sering. Beberapa contoh penggunaan:</p>
<ul>
<li>Halaman "Tentang Kami" — profil singkat tentang website atau organisasi</li>
<li>Halaman "Kontak" — informasi kontak atau form sederhana</li>
<li>Halaman "Syarat dan Ketentuan" — legal page yang wajib ada</li>
<li>Halaman arahan khusus — landing page untuk kampanye tertentu</li>
</ul>

<p>URL halaman mengikuti pola <code>/halaman/{slug}/</code>, berbeda dengan artikel yang menggunakan <code>/{slug}/</code> langsung di root.</p>

<h2>Mengelola Kategori</h2>

<p>Kategori membantu mengorganisir konten dan memudahkan navigasi pengunjung. Akses menu <strong>Categories</strong> untuk mengelola kategori.</p>

<img src="/static/img/2026/07/screenshots/categories.jpg" alt="Dashboard Categories" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<p><strong>Struktur Hierarki</strong> — Kategori mendukung struktur parent/child. Misalnya, kategori "Tutorial" bisa memiliki sub-kategori "PHP", "MySQL", "CSS", dan lainnya.</p>

<p><strong>Many-to-Many Relationship</strong> — Satu artikel bisa masuk ke beberapa kategori sekaligus. Ini fleksibel karena konten seringkali relevan dengan beberapa topik.</p>

<p><strong>URL Kategori</strong> — Setiap kategori memiliki URL unik: <code>/category/{slug}/</code>. Ini membantu SEO karena search engine bisa mengindeks halaman kategori secara terpisah.</p>

<h2>Status dan Workflow Publikasi</h2>

<p>Jyavani CMS mendukung workflow publikasi yang fleksibel:</p>

<p><strong>Draft → Published</strong> — Mulai dari draft untuk konten yang masih dalam proses, lalu ubah ke published saat siap tayang.</p>

<p><strong>Published → Private</strong> — Jika konten perlu dibatasi aksesnya, ubah ke private. Hanya user yang login yang bisa melihatnya.</p>

<p><strong>Soft Delete</strong> — Artikel yang dihapus tidak langsung hilang dari database. CMS menggunakan sistem soft delete (flag <code>is_deleted</code>), sehingga konten bisa dipulihkan kapan saja dari Trash.</p>

<h2>Mengelola Konten yang Sudah Ada</h2>

<p>Dashboard posts list menampilkan semua artikel dengan informasi lengkap: judul, status, kategori, tanggal update, dan jumlah komentar (jika ada). Beberapa fitur pengelolaan:</p>

<p><strong>Search</strong> — Cari artikel berdasarkan judul atau konten. Fitur full-text search di database membuat pencarian cepat dan akurat.</p>

<p><strong>Filter by Status</strong> — Tampilkan hanya draft, published, atau private articles.</p>

<p><strong>Sorting</strong> — Urutkan berdasarkan tanggal, judul, atau status.</p>

<p><strong>Bulk Actions</strong> — Pilih beberapa artikel sekaligus untuk publish, unpublish, atau delete secara massal.</p>

<h2>Best Practices</h2>

<p>Berikut beberapa tips untuk pengelolaan konten yang efektif:</p>

<p><strong>1. Konsisten dengan Slug</strong> — Gunakan slug yang deskriptif dan SEO-friendly. Hindari slug yang terlalu panjang atau mengandung karakter khusus.</p>

<p><strong>2. Selalu Sertakan Thumbnail</strong> — Artikel dengan thumbnail memiliki click-through rate yang lebih tinggi. Siapkan gambar sebelum publish.</p>

<p><strong>3. Manfaatkan Kategori</strong> — Jangan biarkan artikel tanpa kategori. Struktur yang baik membantu navigasi dan SEO.</p>

<p><strong>4. Review Sebelum Publish</strong> — Gunakan preview untuk memastikan tampilan konten sesuai keinginan sebelum dipublikasikan.</p>

<p><strong>5. Backup Rutin</strong> — Meskipun CMS memiliki soft delete, backup database secara berkala tetap penting untuk keamanan data.</p>

<h2>Kesimpulan</h2>

<p>Content Management di Jyavani CMS dirancang untuk kemudahan penggunaan tanpa mengorbankan fleksibilitas. Dengan editor Quill yang powerful, sistem kategori yang fleksibel, dan workflow publikasi yang terstruktur, kamu bisa mengelola konten website dengan efisien. Selanjutnya, kita akan membahas Media Library untuk mengelola gambar dan file.</p>', 'article', NULL, NULL, '/static/img/2026/07/content-management-71b8788f.jpg', 'published', 1, '2026-07-24 09:58:30', '2026-07-24 10:09:11', 0, NULL, 0),
(273, 'Kategori dan Organisasi Konten', 'kategori-dan-organisasi-konten', '<p>Organisasi konten yang baik adalah fondasi dari website yang mudah dinavigasi dan ramah SEO. Dalam artikel ini, kita akan membahas bagaimana sistem kategori di Jyavani CMS membantu kamu mengatur artikel agar mudah ditemukan oleh pengunjung maupun mesin pencari.</p>

<h2>Mengapa Kategori Penting?</h2>

<p>Bayangkan sebuah perpustakaan tanpa sistem pengelompokan — semua buku diletakkan secara acak di rak yang sama. Kamu mungkin masih bisa menemukan buku yang dicari, tapi akan membutuhkan waktu yang jauh lebih lama. Hal yang sama berlaku untuk website tanpa kategori yang terstruktur.</p>

<p>Kategori memberikan beberapa manfaat utama:</p>
<ul>
<li><strong>Navigasi yang intuitif</strong> — Pengunjung bisa langsung menuju topik yang diminati</li>
<li><strong>SEO yang lebih baik</strong> — Struktur URL yang bersih membantu search engine mengindeks konten</li>
<li><strong>Manajemen konten</strong> — Kamu bisa melihat distribusi artikel per topik</li>
<li><strong>Pengalaman pengguna</strong> — Pengunjung menemukan konten terkait dengan lebih mudah</li>
</ul>

<h2>Dashboard Kategori</h2>

<p>Akses halaman kategori dari sidebar admin: <strong>Categories</strong>. Di sini kamu akan melihat daftar semua kategori yang sudah dibuat.</p>

<img src="/static/img/2026/07/screenshots/categories-list.jpg" alt="Dashboard Categories List" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<p><strong>Tabel Kategori</strong> — Setiap kategori ditampilkan dengan informasi: nama, slug, jumlah artikel, dan status. Kamu bisa mengedit atau menghapus kategori langsung dari tabel ini.</p>

<p><strong>Jumlah Artikel</strong> — Kolom ini menunjukkan berapa banyak artikel yang tergabung dalam kategori tersebut. Informasi ini membantu kamu melihat kategori mana yang paling aktif dan mana yang perlu lebih banyak konten.</p>

<h2>Membuat Kategori Baru</h2>

<p>Klik tombol <strong>Tambah Kategori</strong> untuk membuat kategori baru. Kamu akan melihat form sederhana dengan field berikut:</p>

<img src="/static/img/2026/07/screenshots/add-category.jpg" alt="Form Tambah Kategori" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<h3>Field yang Tersedia</h3>

<p><strong>Nama</strong> — Nama kategori yang akan tampil di frontend. Gunakan nama yang deskriptif dan mudah dipahami. Contoh: "Tutorial PHP", "Tips SEO", "Review Produk".</p>

<p><strong>Slug</strong> — URL-friendly version dari nama kategori. Slug digenerate otomatis dari nama, tapi bisa diubah manual. Slug akan digunakan dalam URL: <code>/category/{slug}/</code>.</p>

<p><strong>Deskripsi</strong> — Deskripsi singkat tentang kategori ini. Deskripsi membantu pengunjung memahami jenis konten yang masuk dalam kategori ini, dan juga berguna untuk SEO.</p>

<p><strong>Parent Kategori</strong> — Jika kategori ini merupakan sub-kategori dari kategori lain, pilih parent kategori di sini. Ini menciptakan struktur hierarki yang terorganisir.</p>

<h2>Struktur Hierarki</h2>

<p>Jyavani CMS mendukung struktur kategori hierarki — kategori bisa memiliki sub-kategori, dan sub-kategori bisa memiliki sub-sub-kategori, dan seterusnya. Ini sangat berguna untuk website dengan banyak topik.</p>

<p><strong>Contoh Struktur:</strong></p>
<ul>
<li><strong>Tutorial</strong>
  <ul>
  <li>PHP</li>
  <li>MySQL</li>
  <li>CSS</li>
  <li>JavaScript</li>
  </ul>
</li>
<li><strong>Cyber Security</strong>
  <ul>
  <li>Network Security</li>
  <li>Web Security</li>
  <li>Malware Analysis</li>
  </ul>
</li>
<li><strong>Blog</strong>
  <ul>
  <li>Personal</li>
  <li>Teknologi</li>
  <li>Review</li>
  </ul>
</li>
</ul>

<p>Struktur hierarki membantu pengunjung navigasi dari topik umum ke topik spesifik. Di frontend, ini biasanya ditampilkan sebagai breadcrumb atau dropdown menu.</p>

<h2>Relasi Many-to-Many</h2>

<p>Salah satu fitur powerful di Jyavani CMS adalah relasi many-to-many antara artikel dan kategori. Artinya, satu artikel bisa masuk ke beberapa kategori sekaligus.</p>

<p><strong>Contoh Penggunaan:</strong></p>
<ul>
<li>Artikel "Tutorial PHP untuk Pemula" bisa masuk ke kategori "Tutorial" DAN "PHP"</li>
<li>Artikel "Review Plugin Security" bisa masuk ke "Review" DAN "Cyber Security"</li>
<li>Artikel "Tips SEO untuk Blog" bisa masuk ke "Blog" DAN "Tips"</li>
</ul>

<p>Fleksibilitas ini sangat berguna karena konten seringkali relevan dengan beberapa topik. Kamu tidak perlu memilih hanya satu kategori — cukup centang semua kategori yang relevan saat membuat atau mengedit artikel.</p>

<h2>URL Kategori di Frontend</h2>

<p>Setiap kategori memiliki URL unik yang bisa diakses oleh pengunjung. Pola URL-nya adalah: <code>/category/{slug}/</code>.</p>

<img src="/static/img/2026/07/screenshots/category-frontend.jpg" alt="Halaman Kategori di Frontend" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<p>Halaman kategori menampilkan semua artikel yang tergabung dalam kategori tersebut, diurutkan dari yang terbaru. Pengunjung bisa melihat judul, excerpt, tanggal, dan thumbnail setiap artikel.</p>

<p><strong>Manfaat SEO:</strong></p>
<ul>
<li>URL bersih dan deskriptif — search engine mudah mengindeks</li>
<li>Halaman kategori menjadi landing page untuk topik tertentu</li>
<li>Internal linking antar artikel dalam kategori yang sama</li>
<li>Breadcrumb navigation membantu search engine memahami struktur site</li>
</ul>

<h2>Best Practices untuk Kategori</h2>

<p>Berikut beberapa tips untuk mengelola kategori dengan efektif:</p>

<p><strong>1. Jangan Terlalu Banyak</strong> — Buat kategori yang cukup untuk mengorganisir konten, tapi jangan berlebihan. Terlalu banyak kategori justru membingungkan pengunjung. Untuk website blog, 5-10 kategori utama sudah lebih dari cukup.</p>

<p><strong>2. Nama yang Konsisten</strong> — Gunakan naming convention yang konsisten. Jika kamu memilih "Tutorial" sebagai prefix, gunakan "Tutorial PHP", "Tutorial CSS", bukan "PHP Tutorial" atau "Belajar PHP".</p>

<p><strong>3. Deskripsi yang Informatif</strong> — Tulis deskripsi yang jelas untuk setiap kategori. Deskripsi membantu pengunjung memahami jenis konten yang ada, dan juga muncul di search results.</p>

<p><strong>4. Gunakan Hierarki dengan Bijak</strong> — Hierarki membantu organisasi, tapi terlalu banyak level bisa membingungkan. Maksimal 2-3 level sudah cukup untuk kebanyakan website.</p>

<p><strong>5. Review dan Bersihkan Secara Berkala</strong> — Periksa kategori yang sudah tidak relevan atau tidak memiliki artikel. Hapus atau gabungkan kategori yang sudah usang untuk menjaga kebersihan struktur konten.</p>

<h2>Kesimpulan</h2>

<p>Sistem kategori di Jyavani CMS dirancang untuk fleksibilitas dan kemudahan penggunaan. Dengan struktur hierarki, relasi many-to-many, dan URL yang SEO-friendly, kamu bisa mengorganisir konten dengan efektif. Kategori yang baik tidak hanya membantu pengunjung menemukan konten, tapi juga meningkatkan visibilitas website di mesin pencari.</p>', 'article', NULL, NULL, '/static/img/2026/07/kategori-organisasi-0ef63d42.jpg', 'published', 1, '2026-07-24 10:31:05', '2026-07-24 10:45:07', 0, NULL, 0),
(274, 'Media Library — Mengelola Gambar dan File', 'media-library-mengelola-gambar-dan-file', '<p>Media Library adalah jantung dari pengelolaan gambar dan file di Jyavani CMS. Setiap gambar yang diupload, setiap file yang disimpan, semuanya tercatat di sini. Dalam artikel ini, kita akan membahas bagaimana cara mengupload, mengelola, dan menggunakan media di website kamu.</p>

<h2>Apa itu Media Library?</h2>

<p>Media Library di Jyavani CMS terdiri dari dua komponen: <strong>tabel database</strong> yang mencatat metadata setiap file, dan <strong>folder storage</strong> di server tempat file fisik disimpan. Kombinasi ini memungkinkan CMS untuk melacak semua media, menampilkannya di galeri, dan mengelola akses dengan aman.</p>

<p><strong>Metadata yang Tercatat:</strong></p>
<ul>
<li>URL publik file</li>
<li>Nama file dan ekstensi</li>
<li>Tipe MIME (image/png, application/pdf, dll)</li>
<li>Ukuran file dalam bytes</li>
<li>Dimensi gambar (lebar × tinggi)</li>
<li>Title dan alt text untuk SEO</li>
<li>Visibility (public/private)</li>
<li>Storage disk (public/private)</li>
<li>Siapa yang upload dan kapan</li>
</ul>

<h2>Dashboard Media Library</h2>

<p>Akses Media Library dari sidebar admin: <strong>Media</strong>. Kamu akan melihat dua tab utama — Gallery untuk melihat semua media, dan Upload untuk menambah media baru.</p>

<img src="/static/img/2026/07/screenshots/media-list.jpg" alt="Media Library Gallery" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<p><strong>Gallery View</strong> — Semua media ditampilkan dalam format grid atau list. Setiap item menampilkan thumbnail (untuk gambar), nama file, ukuran, tanggal upload, dan status visibility. Kamu bisa mencari media berdasarkan nama file atau title.</p>

<p><strong>Filter</strong> — Gunakan filter untuk menampilkan hanya tipe media tertentu: gambar, dokumen, video, atau semua. Ini sangat membantu ketika jumlah media sudah mulai banyak.</p>

<p><strong>Aksi</strong> — Klik pada media untuk melihat detail, mengedit metadata (title, alt text, caption), atau menghapus. URL media juga tersedia untuk disalin dan digunakan di artikel.</p>

<h2>Upload Media Baru</h2>

<p>Untuk menambah media baru, buka tab <strong>Upload</strong> di Media Library atau gunakan tombol upload di form artikel/halaman.</p>

<img src="/static/img/2026/07/screenshots/media-upload.jpg" alt="Form Upload Media" style="width:100%;border-radius:8px;margin:1rem 0;box-shadow:0 4px 12px rgba(0,0,0,.1)">

<h3>Proses Upload</h3>

<p><strong>1. Pilih File</strong> — Klik area upload atau drag & drop file. CMS mendukung berbagai tipe file: gambar (PNG, JPG, GIF, WebP, SVG), dokumen (PDF, DOC, XLS), video (MP4, WebM), dan audio (MP3, WAV).</p>

<p><strong>2. Otomatis Processing</strong> — Saat file diupload, CMS akan:</p>
<ul>
<li>Generate nama file unik: <code>{deskripsi}-{8hex}.{ext}</code></li>
<li>Simpan file ke folder <code>public/static/img/{YYYY}/{MM}/</code></li>
<li>INSERT metadata ke tabel <code>media</code></li>
<li>Generate thumbnail otomatis untuk gambar</li>
</ul>

<p><strong>3. URL Tersedia</strong> — Setelah upload selesai, URL file langsung tersedia untuk disalin. URL mengikuti pola: <code>/static/img/{YYYY}/{MM}/{filename}</code>.</p>

<h3>Via Form Artikel</h3>

<p>Saat menulis artikel, kamu bisa mengupload gambar langsung dari Quill editor. Klik tombol <strong>Image</strong> di toolbar, lalu pilih <strong>Upload</strong> untuk mengambil file dari komputer. Gambar akan otomatis diupload ke Media Library dan disisipkan ke konten.</p>

<h2>Visibility dan Akses</h2>

<p>Media Library mendukung dua tingkat visibilitas:</p>

<p><strong>Public</strong> — File bisa diakses oleh siapa saja yang mengetahui URL. Cocok untuk gambar artikel, thumbnail, dan konten publik lainnya.</p>

<p><strong>Private</strong> — File hanya bisa diakses oleh user yang login. Cocok untuk dokumen sensitif, lampiran internal, atau konten yang hanya untuk member.</p>

<p><strong>Storage Disk</strong> — CMS juga mendukung dua tipe storage: public disk (file disimpan di folder yang bisa diakses langsung) dan private disk (file disimpan di luar web root, diakses via PHP stream).</p>

<h2>Menggunakan Media di Artikel</h2>

<p>Ada beberapa cara menggunakan media yang sudah diupload:</p>

<h3>Via URL Langsung</h3>

<p>Copy URL dari Media Library, lalu gunakan di HTML artikel:</p>
<pre><code>&lt;img src="/static/img/2026/07/media-library-thumb-gen.jpg" alt="Deskripsi gambar"&gt;</code></pre>

<h3>Via Shortcode</h3>

<p>Untuk file yang membutuhkan akses terproteksi, gunakan shortcode:</p>
<pre><code>[private_pdf id="123"]</code></pre>
<p>Shortcode ini akan generate link download dengan token akses yang aman.</p>

<h3>Via Media Picker</h3>

<p>Di form artikel, klik tombol Gallery untuk membuka Media Picker. Kamu bisa mencari dan memilih media yang sudah ada, lalu CMS akan otomatis menyisipkan HTML yang benar ke konten.</p>

<h2>Mengelola Metadata</h2>

<p>Metadata yang baik membantu SEO dan aksesibilitas:</p>

<p><strong>Title</strong> — Judul deskriptif untuk gambar. Muncul di tooltip dan bisa digunakan oleh screen reader.</p>

<p><strong>Alt Text</strong> — Teks alternatif yang ditampilkan jika gambar tidak bisa dimuat. Penting untuk SEO dan aksesibilitas. Selalu isi alt text yang deskriptif.</p>

<p><strong>Caption</strong> — Keterangan singkat tentang gambar. Bisa ditampilkan di frontend di bawah gambar.</p>

<p><strong>Credit</strong> — Informasi kredit atau sumber gambar. Berguna untuk gambar yang bukan hasil karya sendiri.</p>

<h2>Best Practices</h2>

<p><strong>1. Konsisten dengan Naming</strong> — Gunakan nama file yang deskriptif dan konsisten. Hindari nama seperti <code>IMG_20260724.png</code>. Lebih baik: <code>tutorial-php-cover-2026.png</code>.</p>

<p><strong>2. Selalu Isi Alt Text</strong> — Alt text membantu search engine memahami konten gambar dan membantu pengguna dengan gangguan penglihatan. Ini bukan optional — ini best practice yang wajib dilakukan.</p>

<p><strong>3. Optimasi Ukuran</strong> — Upload gambar dengan ukuran yang sesuai. Untuk thumbnail, 1200×675 sudah lebih dari cukup. Jangan upload gambar mentah dari kamera (bisa berukuran 5MB+) tanpa optimasi.</p>

<p><strong>4. Gunakan Format yang Tepat</strong> — PNG untuk gambar dengan transparansi atau teks. JPG untuk foto. WebP untuk ukuran lebih kecil dengan kualitas setara. SVG untuk ikon dan grafik vektor.</p>

<p><strong>5. Backup Secara Berkala</strong> — Meskipun file tersimpan di server, backup folder <code>public/static/img/</code> secara berkala tetap penting untuk keamanan data.</p>

<h2>Kesimpulan</h2>

<p>Media Library di Jyavani CMS menyediakan cara yang efisien untuk mengelola semua aset digital website kamu. Dengan metadata lengkap, sistem visibilitas yang fleksibel, dan integrasi langsung dengan editor, kamu bisa fokus membuat konten tanpa khawatir kehilangan jejak file yang sudah diupload.</p>', 'article', NULL, NULL, '/static/img/2026/07/media-library-thumb-gen.jpg', 'published', 1, '2026-07-24 10:48:04', '2026-07-25 09:12:49', 0, NULL, 0),
(275, 'Menu Manager — Navigasi Website', 'menu-manager-navigasi-website', '<p>Menu navigasi adalah salah satu komponen terpenting dalam sebuah website. Menu yang baik membantu pengunjung menemukan konten dengan cepat dan memberikan struktur yang jelas pada website. Jyavani CMS menyediakan <strong>Menu Manager</strong> yang powerful dan fleksibel untuk mengelola navigasi website Anda.</p>

<h2>Apa itu Menu Manager?</h2>
<p>Menu Manager adalah fitur bawaan Jyavani CMS yang memungkinkan Anda membuat, mengedit, dan mengelola menu navigasi tanpa perlu menyentuh kode. Sistem ini mendukung multiple menus, nested items (dropdown), drag &amp; drop ordering, dan integrasi langsung dengan Theme Customizer.</p>
<p>Setiap menu memiliki <strong>name</strong> (nama tampil), <strong>slug</strong> (identifier unik), dan koleksi <strong>menu items</strong> yang bisa diatur hierarkinya. Menu bisa di-assign ke berbagai posisi di theme melalui Theme Customizer.</p>

<h2>Membuat Menu Baru</h2>
<p>Untuk membuat menu baru, navigasi ke <strong>Dashboard → Menu Manager</strong>. Di panel sebelah kanan, Anda akan menemukan form untuk membuat menu baru.</p>
<p>Masukkan nama menu (misalnya "Primary", "Footer", atau "Social Links") dan slug yang unik. Slug digunakan oleh theme untuk mereferensikan menu tertentu. Setelah membuat menu, Anda bisa langsung menambahkan item ke dalamnya.</p>

<img src="/static/img/2026/07/menu-manager-screenshot.jpg" alt="Menu Manager interface showing menu list and creation form" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<h2>Menambahkan Item Menu</h2>
<p>Menu items adalah komponen individual dalam sebuah menu. Setiap item memiliki label (teks yang ditampilkan), URL tujuan, dan opsi tambahan seperti:</p>
<ul>
  <li><strong>Type</strong> — custom (URL bebas), page (referensi ke halaman CMS), atau post (referensi ke artikel)</li>
  <li><strong>Parent</strong> — untuk membuat hierarki dropdown, assign item ke parent item lain</li>
  <li><strong>Target</strong> — buka di tab yang sama atau tab baru (target="_blank")</li>
  <li><strong>Hidden</strong> — sembunyikan item dari tampilan publik tanpa menghapusnya</li>
</ul>

<img src="/static/img/2026/07/menu-manager-items-screenshot.jpg" alt="Menu items list showing hierarchical structure with drag handles" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<h3>Membuat Dropdown Menu</h3>
<p>Untuk membuat dropdown, cukup assign <strong>parent_id</strong> pada item yang ingin dijadikan anak. Di Menu Manager, Anda bisa menyeret item ke bawah item lain untuk membuat hierarki. Indentasi visual menunjukkan level kedalaman menu.</p>
<p>Contoh struktur menu navigasi:</p>
<pre>Home
About
  ├── Our Team
  └── History
Blog
  ├── Technology
  └── Lifestyle
Contact</pre>

<h2>Menghapus dan Mengedit Menu</h2>
<p>Menu bisa dihapus melalui tombol <strong>Delete</strong> di panel kanan. Saat menghapus menu, semua item di dalamnya juga akan terhapus. Pastikan Anda yakin sebelum menghapus menu yang sudah ada.</p>
<p>Untuk mengedit nama atau slug menu, gunakan form edit yang tersedia. Perubahan slug akan mempengaruhi cara theme merender menu tersebut.</p>

<h2>Assign Menu ke Theme</h2>
<p>Setelah membuat menu, langkah selanjutnya adalah menghubungkannya dengan theme. Buka <strong>Theme Customizer</strong> dari Dashboard. Di bagian <strong>Navigation Widget</strong>, Anda akan menemukan dropdown untuk memilih menu yang ingin ditampilkan di posisi tertentu.</p>

<img src="/static/img/2026/07/theme-customizer-menu-screenshot.jpg" alt="Theme Customizer showing menu assignment dropdown for navigation widget" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Theme Customizer memungkinkan Anda assign menu yang berbeda untuk posisi yang berbeda. Misalnya, menu "Primary" untuk navigasi utama di header, dan menu "Footer" untuk link di bagian bawah halaman.</p>

<h2>Best Practices Menu Navigasi</h2>
<p>Berikut beberapa tips untuk membuat menu navigasi yang efektif:</p>
<ul>
  <li><strong>Jangan terlalu banyak item</strong> — idealnya 5-7 item utama. Terlalu banyak pilihan membuat pengunjung bingung.</li>
  <li><strong>Gunakan label yang jelas</strong> — "Blog" lebih baik dari "Articles" atau "Posts". Gunakan bahasa yang dipahami pengunjung Anda.</li>
  <li><strong>Struktur hierarki datar</strong> — hindari dropdown lebih dari 2 level. Struktur terlalu dalam sulit dinavigasi, terutama di mobile.</li>
  <li><strong>Consistent naming</strong> — gunakan konsistensi kapitalisasi. Jika satu item Title Case, semua harus Title Case.</li>
  <li><strong>Test di mobile</strong> — pastikan menu berfungsi baik di layar kecil. Menu dropdown harus bisa diakses dengan tap.</li>
</ul>

<h2>Multi-Menu Strategy</h2>
<p>Jyavani CMS memungkinkan Anda membuat banyak menu sekaligus. Strategi yang umum digunakan:</p>
<ul>
  <li><strong>Primary Menu</strong> — navigasi utama di header, berisi halaman utama website</li>
  <li><strong>Footer Menu</strong> — link tambahan di footer seperti Terms, Privacy Policy, Sitemap</li>
  <li><strong>Social Menu</strong> — link ke profil media sosial</li>
  <li><strong>Sidebar Menu</strong> — navigasi tambahan untuk blog atau dokumentasi</li>
</ul>

<h2>Menu Rendering di Frontend</h2>
<p>Menu yang dibuat di Dashboard akan otomatis dirender oleh theme di frontend. Jyavani CMS menggunakan fungsi <code>menu_render()</code> untuk menghasilkan HTML menu. Fungsi ini mendukung:</p>
<ul>
  <li>Output HTML dengan class CSS yang sesuai (untuk styling dengan Bootstrap, Tailwind, atau custom CSS)</li>
  <li>Active state — item halaman yang sedang aktif akan mendapat class <code>active</code></li>
  <li>Multi-level dropdown — sub-menu dirender sebagai nested <code>&lt;ul&gt;</code></li>
  <li>Target blank — item dengan target="_blank" akan dibuka di tab baru</li>
</ul>

<h2>Kesimpulan</h2>
<p>Menu Manager di Jyavani CMS memberikan fleksibilitas penuh dalam mengatur navigasi website. Dengan interface drag &amp; drop, dukungan multi-level, dan integrasi langsung dengan Theme Customizer, Anda bisa membuat struktur navigasi profesional tanpa kode sedikitpun. Manfaatkan fitur ini untuk memberikan pengalaman navigasi terbaik bagi pengunjung website Anda.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/menu-manager-thumb.jpg', 'published', 1, '2026-07-24 11:05:59', '2026-07-24 11:05:59', 0, NULL, 0),
(276, 'Theme System — Mengubah Tampilan Website', 'theme-system-mengubah-tampilan-website', '<p>Tampilan website adalah kesan pertama yang dilihat pengunjung. Dengan <strong>Theme System</strong> di Jyavani CMS, Anda bisa mengubah tampilan website sepenuhnya tanpa menyentuh kode. Mulai dari mengganti theme, mengkustomisasi warna dan layout, hingga mengatur mode gelap — semuanya bisa dilakukan dari dashboard.</p>

<h2>Apa itu Theme System?</h2>
<p>Theme System di Jyavani CMS menggunakan pendekatan <strong>slot-based rendering</strong>. Setiap theme mendefinisikan slot-slot (posisi) di mana konten bisa ditampilkan — header, footer, sidebar, dan area konten utama. Theme juga menyediakan <strong>Theme Zones</strong> yang memungkinkan Anda mengatur layout visual menggunakan drag &amp; drop.</p>
<p>Jyavani CMS hadir dengan beberapa theme bawaan, termasuk <strong>default</strong> dan <strong>adam</strong>. Anda bisa mengaktifkan theme yang berbeda kapan saja, dan semua pengaturan akan otomatis diterapkan.</p>

<h2>Theme Manager</h2>
<p>Untuk mengelola theme, buka <strong>Dashboard → Themes</strong>. Di halaman ini Anda akan melihat daftar semua theme yang terinstall beserta statusnya.</p>

<img src="/static/img/2026/07/themes-list-screenshot.jpg" alt="Theme Manager showing list of available themes with activate/deactivate options" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Setiap theme card menampilkan nama theme, deskripsi, dan tombol aksi:</p>
<ul>
  <li><strong>Activate</strong> — mengaktifkan theme untuk frontend website</li>
  <li><strong>Customize</strong> — membuka Theme Customizer untuk mengatur pengaturan visual</li>
  <li><strong>Delete</strong> — menghapus theme (hanya untuk theme yang tidak sedang aktif)</li>
</ul>

<h2>Theme Customizer</h2>
<p>Theme Customizer adalah interface visual untuk mengkustomisasi theme tanpa kode. Buka dari halaman Themes dengan klik tombol <strong>Customize</strong>.</p>

<img src="/static/img/2026/07/theme-customizer-screenshot.jpg" alt="Theme Customizer interface with live preview and configuration panels" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Theme Customizer terdiri dari beberapa bagian:</p>

<h3>Navigation Widget</h3>
<p>Mengatur menu navigasi yang ditampilkan di header. Pilih menu dari dropdown yang sudah dibuat di Menu Manager. Anda bisa assign menu yang berbeda untuk posisi yang berbeda.</p>

<h3>Social Widget</h3>
<p>Mengatur ikon media sosial di footer atau header. Masukkan URL profil media sosial Anda, dan ikon akan otomatis muncul dengan link ke profil Anda.</p>

<h3>Image Widget</h3>
<p>Mengatur logo atau gambar yang ditampilkan di header. Upload gambar melalui Media Library, lalu pilih di sini.</p>

<h3>HTML Widget</h3>
<p>Widget khusus untuk menambahkan kode HTML custom. Berguna untuk menambahkan tracking code, custom banner, atau elemen HTML lainnya.</p>

<h2>Theme Zones — Visual Layout Editor</h2>
<p>Theme Zones adalah fitur paling powerful di Theme System. Ini memungkinkan Anda mengatur layout halaman secara visual dengan drag &amp; drop.</p>
<p>Setiap theme memiliki zone-zone seperti <strong>header</strong>, <strong>content</strong>, dan <strong>footer</strong>. Di dalam setiap zone, Anda bisa menambahkan gadgets (komponen) seperti:</p>

<ul>
  <li><strong>tz_nav_menu</strong> — navigasi menu</li>
  <li><strong>tz_image</strong> — gambar atau logo</li>
  <li><strong>tz_html</strong> — konten HTML custom</li>
  <li><strong>tz_social</strong> — ikon media sosial</li>
  <li><strong>tz_text</strong> — teks atau judul</li>
</ul>

<p>Gadget bisa dipindahkan, diatur ukurannya, dan dikonfigurasi langsung dari interface visual. Setiap perubahan bisa di-preview sebelum disimpan.</p>

<h2>Color Mode — Light dan Dark Theme</h2>
<p>Jyavani CMS mendukung <strong>dark mode</strong> untuk kenyamanan membaca di kondisi cahaya rendah. Theme bisa dikonfigurasi untuk mendukung:</p>
<ul>
  <li><strong>Light only</strong> — hanya mode terang</li>
  <li><strong>Dark only</strong> — hanya mode gelap</li>
  <li><strong>Both</strong> — mendukung切换 antara light dan dark mode</li>
</ul>
<p>Pengunjung bisa beralih mode menggunakan toggle di interface. Preferensi disimpan di browser sehingga下次 kunjungan akan menggunakan mode yang sama.</p>

<h2>Install Theme dari Theme Store</h2>
<p>Selain theme bawaan, Anda bisa menginstall theme dari Theme Store. Theme Store adalah marketplace online di mana pengembang theme membagikan karya mereka.</p>
<p>Untuk menginstall theme baru:</p>
<ol>
  <li>Buka <strong>Theme Store</strong> dari halaman Themes</li>
  <li>Jelajahi theme yang tersedia</li>
  <li>Klik <strong>Install</strong> pada theme yang diinginkan</li>
  <li>Theme akan otomatis terdownload dan terinstall</li>
  <li>Klik <strong>Activate</strong> untuk mengaktifkan theme baru</li>
</ol>

<h2>Membuat Theme Custom</h2>
<p>Jika theme bawaan dan Theme Store tidak memenuhi kebutuhan, Anda bisa membuat theme sendiri. Struktur theme Jyavani CMS:</p>
<pre>themes/
  my-theme/
    theme.json        ← konfigurasi theme
    index.php         ← template utama
    header.php        ← header partial
    footer.php        ← footer partial
    sidebar.php       ← sidebar partial
    static/
      css/
        style.css     ← styles
      js/
        script.js     ← JavaScript
      img/
        ← gambar theme</pre>
<p>File <code>theme.json</code> mendefinisikan metadata theme, slot rendering, dan default configuration. Lihat theme bawaan sebagai referensi untuk membuat theme Anda sendiri.</p>

<h2>Best Practices</h2>
<ul>
  <li><strong>Backup sebelum ubah theme</strong> — jika Anda punya custom CSS di theme lama, pastikan sudah dibackup</li>
  <li><strong>Test di mobile</strong> — pastikan theme baru responsif di berbagai ukuran layar</li>
  <li><strong>Gunakan Theme Customizer</strong> — hindari edit kode langsung kecuali benar-benar perlu</li>
  <li><strong>Konsisten dengan brand</strong> — pilih warna dan tipografi yang sesuai dengan identitas brand Anda</li>
</ul>

<h2>Kesimpulan</h2>
<p>Theme System di Jyavani CMS memberikan kontrol penuh atas tampilan website. Dari sekadar mengganti warna hingga mengatur layout dengan Theme Zones, semuanya bisa dilakukan tanpa kode. Manfaatkan Theme Customizer untuk kustomisasi cepat, atau jelajahi Theme Store untuk menemukan inspiration baru.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/theme-system-thumb.jpg', 'published', 1, '2026-07-24 17:36:22', '2026-07-24 17:36:22', 0, NULL, 0),
(277, 'Plugin System — Memperluas Fitur CMS', 'plugin-system-memperluas-fitur-cms', '<p>Plugin System adalah jantung dari ekstensibilitas Jyavani CMS. Dengan plugin, Anda bisa menambahkan fitur baru tanpa mengubah kode inti CMS. Mulai dari formulir kontak, galeri gambar, hingga integrasi API pihak ketiga — semuanya bisa ditambahkan melalui plugin.</p>

<h2>Apa itu Plugin System?</h2>
<p>Plugin System di Jyavani CMS dirancang sederhana namun powerful. Setiap plugin terdiri dari dua komponen utama: <strong>plugin.json</strong> (metadata dan konfigurasi) dan <strong>plugin.php</strong> (logika plugin). Plugin berinteraksi dengan CMS melalui <strong>hooks</strong> — actions dan filters yang memungkinkan Anda memodifikasi perilaku CMS.</p>

<h2>Plugin Manager</h2>
<p>Untuk mengelola plugin, buka <strong>Dashboard → Plugins</strong>. Plugin Manager menampilkan semua plugin yang terinstall beserta statusnya.</p>

<img src="/static/img/2026/07/plugin-manager-screenshot.jpg" alt="Plugin Manager showing installed plugins with enable/disable toggles" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Setiap plugin card menampilkan:</p>
<ul>
  <li><strong>Nama dan deskripsi</strong> — identitas plugin</li>
  <li><strong>Versi</strong> — versi plugin yang terinstall</li>
  <li><strong>Status</strong> — aktif atau nonaktif</li>
  <li><strong>Tombol aksi</strong> — Enable, Disable, Delete, dan Settings</li>
</ul>

<p>Plugin bisa diaktifkan atau dinonaktifkan kapan saja tanpa menghapus file. Saat dinonaktifkan, fitur plugin akan hilang dari frontend tapi pengaturannya tetap tersimpan.</p>

<h2>Install Plugin dari ZIP</h2>
<p>Selain dari Plugin Store, Anda bisa menginstall plugin dari file ZIP. Berguna saat menginstall plugin custom atau plugin dari sumber eksternal.</p>

<img src="/static/img/2026/07/plugin-upload-screenshot.jpg" alt="Plugin upload form for installing plugins from ZIP file" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Langkah install plugin dari ZIP:</p>
<ol>
  <li>Buka halaman Plugins</li>
  <li>Klik tab <strong>Upload</strong></li>
  <li>Pilih file ZIP plugin</li>
  <li>Klik <strong>Install</strong></li>
  <li>Plugin akan otomatis terextract dan terdaftar</li>
  <li>Aktifkan plugin setelah install</li>
</ol>

<h2>Plugin Store</h2>
<p>Plugin Store adalah marketplace online di mana pengembang plugin membagikan karya mereka. Anda bisa menjelajahi plugin berdasarkan kategori, popularitas, atau rating.</p>

<img src="/static/img/2026/07/plugin-store-screenshot.jpg" alt="Plugin Store showing available plugins for download" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Untuk menginstall plugin dari Plugin Store:</p>
<ol>
  <li>Browse plugin yang tersedia</li>
  <li>Klik <strong>Install</strong> pada plugin yang diinginkan</li>
  <li>Plugin akan otomatis terdownload dan terinstall</li>
  <li>Aktifkan plugin dari Plugin Manager</li>
</ol>

<h2>Plugin Hooks — Actions dan Filters</h2>
<p>Plugin berinteraksi dengan CMS melalui dua jenis hooks:</p>

<h3>Actions</h3>
<p>Actions dipanggil saat peristiwa tertentu terjadi. Contoh:</p>
<pre>&lt;?php
// Dipanggil saat artikel dipublikasikan
function my_plugin_on_publish($post) {
    // Kirim notifikasi email
    mail(\'admin@example.com\', \'New Post\', $post[\'title\']);
}
add_action(\'post_published\', \'my_plugin_on_publish\');
?&gt;</pre>

<h3>Filters</h3>
<p>Filters memodifikasi data sebelum ditampilkan. Contoh:</p>
<pre>&lt;?php
// Tambahkan copyright notice di akhir konten
function my_plugin_add_copyright($content) {
    return $content . \'&lt;p&gt;© 2026 My Website&lt;/p&gt;\';
}
add_filter(\'the_content\', \'my_plugin_add_copyright\');
?&gt;</pre>

<h2>Struktur Plugin</h2>
<p>Setiap plugin memiliki struktur direktori sebagai berikut:</p>
<pre>plugins/
  my-plugin/
    plugin.json    ← metadata (nama, versi, deskripsi, dependencies)
    plugin.php     ← logika utama plugin
    assets/
      css/
        style.css  ← CSS plugin
      js/
        script.js  ← JavaScript plugin
    templates/
      ← template views jika diperlukan</pre>

<h3>File plugin.json</h3>
<pre>{
  "name": "My Plugin",
  "slug": "my-plugin",
  "version": "1.0.0",
  "description": "Deskripsi plugin",
  "author": "Nama Author",
  "hooks": {
    "actions": ["post_published", "comment_added"],
    "filters": ["the_content", "the_title"]
  },
  "assets": {
    "admin_css": ["assets/css/style.css"],
    "admin_js": ["assets/js/script.js"]
  }
}</pre>

<h2>Contoh: Terminal Plugin</h2>
<p>Jyavani CMS memiliki plugin bawaan bernama <strong>Terminal Plugin</strong> yang memberikan akses command line langsung dari dashboard. Plugin ini menunjukkan bagaimana plugin bisa menambahkan halaman admin baru dengan interface custom.</p>
<p>Terminal Plugin menambahkan route admin baru, mengirim output command ke browser, dan menyediakan interface terminal yang aman untuk menjalankan perintah sistem.</p>

<h2>Plugin Assets</h2>
<p>Plugin bisa menyertakan aset CSS dan JavaScript. Aset admin hanya dimuat di dashboard, sedangkan aset frontend dimuat di halaman publik. Pastikan aset plugin tidak konflik dengan CSS/JS theme atau plugin lain.</p>

<h2>Best Practices</h2>
<ul>
  <li><strong>Minimal dependencies</strong> — hindari dependensi plugin lain jika memungkinkan</li>
  <li><strong>Sandbox hooks</strong> — gunakan namespace unik untuk menghindari konflik nama fungsi</li>
  <li><strong>Nonaktifkan saat tidak dipakai</strong> — plugin aktif memakan resources</li>
  <li><strong>Backup sebelum update</strong> — terutama untuk plugin yang sudah dikustomisasi</li>
  <li><strong>Gunakan API CMS</strong> — hindari akses database langsung; gunakan helper functions yang disediakan</li>
</ul>

<h2>Kesimpulan</h2>
<p>Plugin System membuat Jyavani CMS sangat fleksibel dan extensible. Dengan arsitektur hooks yang sederhana, Anda bisa menambahkan fitur apapun tanpa mengubah kode inti. Manfaatkan Plugin Manager untuk install dan manage plugin, atau buat plugin custom untuk kebutuhan spesifik Anda.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/plugin-system-thumb.jpg', 'published', 1, '2026-07-24 18:43:04', '2026-07-24 18:43:04', 0, NULL, 0),
(278, 'Pengaturan Situs — Settings Dashboard', 'pengaturan-situs-settings-dashboard', '<p>Setiap website memiliki identitas dan kebutuhan unik. Jyavani CMS menyediakan <strong>Settings Dashboard</strong> yang komprehensif untuk mengkonfigurasi berbagai aspek website, mulai dari identitas situs, URL path, bahasa, sidebar, hingga pengaturan registrasi pengguna.</p>

<h2>Site Settings — Identitas Website</h2>
<p>Halaman pertama yang biasanya dikunjungi setelah install adalah Site Settings. Di sini Anda mengatur identitas dasar website:</p>

<img src="/static/img/2026/07/site-settings-screenshot.jpg" alt="Site Settings page showing website title, description, URL and favicon configuration" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<ul>
  <li><strong>Site Title</strong> — nama website yang muncul di title bar browser dan header</li>
  <li><strong>Tagline / Description</strong> — deskripsi singkat website untuk SEO</li>
  <li><strong>Site URL</strong> — URL utama website, penting untuk canonical URL dan sitemap</li>
  <li><strong>Favicon</strong> — ikon kecil yang muncul di tab browser</li>
  <li><strong>Default Language</strong> — bahasa default untuk konten dan admin UI</li>
</ul>

<h2>Admin dan Auth Paths</h2>
<p>Keamanan website dimulai dari URL. Jyavani CMS memungkinkan Anda mengubah path default untuk area admin dan autentikasi:</p>
<ul>
  <li><strong>Admin Path</strong> — default <code>/adiwira/</code>, bisa diubah ke path custom</li>
  <li><strong>Login Path</strong> — path halaman login</li>
  <li><strong>Register Path</strong> — path halaman registrasi (jika diaktifkan)</li>
</ul>
<p>Mengubah admin path dari default adalah praktik keamanan sederhana yang mengurangi exposure terhadap serangan brute force pada URL umum.</p>

<h2>Language Settings</h2>
<p>Jyavani CMS mendukung multi-bahasa dengan pemisahan yang jelas:</p>
<ul>
  <li><strong>Admin UI Language</strong> — bahasa untuk dashboard admin</li>
  <li><strong>Content Default Language</strong> — bahasa default untuk artikel dan halaman baru</li>
</ul>
<p>Pemisahan ini memungkinkan admin menggunakan bahasa Indonesia sementara konten website defaultnya bahasa Inggris, atau sebaliknya sesuai kebutuhan.</p>

<h2>Sidebar Settings</h2>
<p>Sidebar di Jyavani CMS tidak hanya statis — bisa dikonfigurasi secara penuh. Anda bisa mengatur:</p>

<img src="/static/img/2026/07/sidebar-settings-screenshot.jpg" alt="Sidebar settings page showing enable toggle, position options and controller overrides" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<ul>
  <li><strong>Enable/Disable Sidebar</strong> — aktifkan atau nonaktifkan sidebar global</li>
  <li><strong>Position</strong> — letakkan sidebar di kiri atau kanan</li>
  <li><strong>Controller Overrides</strong> — atur sidebar yang berbeda untuk halaman tertentu</li>
  <li><strong>Gadgets</strong> — tambahkan widget HTML, recent posts, categories, atau custom content</li>
</ul>

<h2>Registration Settings</h2>
<p>Jika website Anda memerlukan multi-user, Settings Dashboard menyediakan pengaturan registrasi:</p>
<ul>
  <li><strong>Enable Registration</strong> — izinkan pengunjung mendaftar sendiri</li>
  <li><strong>Require Approval</strong> — admin harus menyetujui user baru sebelum aktif</li>
  <li><strong>reCAPTCHA</strong> — aktifkan verifikasi untuk mencegah bot</li>
  <li><strong>Default Role</strong> — role yang diberikan ke user baru (author, editor, dll)</li>
</ul>

<h2>Permalink Settings</h2>
<p>Struktur URL yang baik penting untuk SEO dan keterbacaan. Jyavani CMS menyediakan builder permalink yang fleksibel:</p>

<img src="/static/img/2026/07/permalink-settings-screenshot.jpg" alt="Permalink builder settings showing URL pattern configuration" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Anda bisa memilih format URL untuk artikel dan halaman:</p>
<ul>
  <li><code>/{slug}/</code> — URL pendek dan bersih</li>
  <li><code>/{year}/{month}/{slug}/</code> — format berbasis tanggal</li>
  <li><code>/{category}/{slug}/</code> — berbasis kategori</li>
  <li><strong>Custom</strong> — buat pola URL sendiri dengan variabel yang tersedia</li>
</ul>

<h2>Social Media Links</h2>
<p>Settings Dashboard juga mengelola link media sosial website. Link ini bisa digunakan oleh theme untuk menampilkan ikon sosial di footer atau header. Tambahkan URL untuk Facebook, Twitter/X, Instagram, LinkedIn, YouTube, dan platform lainnya.</p>

<h2>Best Practices</h2>
<ul>
  <li><strong>Isi Site URL dengan benar</strong> — pastikan cocok dengan domain aktual, termasuk protokol HTTPS</li>
  <li><strong>Gunakan favicon</strong> — favicon professional meningkatkan brand recognition</li>
  <li><strong>Ubah admin path</strong> — praktik keamanan dasar yang efektif</li>
  <li><strong>Aktifkan approval</strong> — untuk registrasi publik, selalu aktifkan approval admin</li>
  <li><strong>Pilih permalink sekali</strong> — mengubah struktur URL setelah konten banyak bisa merusak SEO</li>
</ul>

<h2>Kesimpulan</h2>
<p>Settings Dashboard di Jyavani CMS memberikan kontrol penuh atas konfigurasi website. Luangkan waktu untuk meninjau setiap bagian pengaturan saat pertama kali install, karena konfigurasi awal yang tepat akan mempermudah pengelolaan website ke depannya.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/settings-dashboard-thumb.jpg', 'published', 1, '2026-07-24 19:00:47', '2026-07-24 19:00:47', 0, NULL, 0),
(279, 'User Management — Mengelola Pengguna', 'user-management-mengelola-pengguna', '<p>Website yang dikelola oleh banyak orang memerlukan sistem manajemen pengguna yang jelas. Jyavani CMS menyediakan <strong>User Management</strong> dengan role-based access control, memungkinkan admin menentukan siapa yang bisa mengakses fitur tertentu di dashboard.</p>

<h2>Peran dan Hak Akses (Roles)</h2>
<p>Jyavani CMS memiliki beberapa role bawaan yang masing-masing memiliki hak akses berbeda:</p>
<ul>
  <li><strong>Admin</strong> — akses penuh ke seluruh dashboard, pengaturan, user management, dan konfigurasi</li>
  <li><strong>Editor</strong> — bisa mengelola semua konten artikel dan halaman, termasuk mengedit/menghapus konten milik user lain</li>
  <li><strong>Author</strong> — hanya bisa membuat dan mengedit konten miliknya sendiri</li>
</ul>
<p>Role-based permissions memastikan bahwa setiap pengguna hanya bisa melakukan tugas sesuai dengan tanggung jawabnya.</p>

<h2>Users List</h2>
<p>Halaman <strong>Dashboard → Users</strong> menampilkan daftar semua pengguna terdaftar di CMS.</p>

<img src="/static/img/2026/07/users-list-screenshot.jpg" alt="Users list page showing registered users with roles and action buttons" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Di halaman ini Anda bisa:</p>
<ul>
  <li>Mencari pengguna berdasarkan nama atau email</li>
  <li>Melihat role dan status setiap pengguna</li>
  <li>Mengedit informasi pengguna</li>
  <li>Menonaktifkan atau menghapus akun</li>
</ul>

<h2>Membuat User Baru</h2>
<p>Untuk menambahkan pengguna baru, buka halaman <strong>Add User</strong>. Isi informasi yang diperlukan:</p>

<img src="/static/img/2026/07/add-user-screenshot.jpg" alt="Add user form with fields for name, email, password, and role selection" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<ul>
  <li><strong>Full Name</strong> — nama lengkap pengguna</li>
  <li><strong>Email</strong> — alamat email untuk login dan notifikasi</li>
  <li><strong>Username</strong> — identifier unik untuk login</li>
  <li><strong>Password</strong> — password awal yang bisa diubah pengguna nanti</li>
  <li><strong>Role</strong> — peran yang menentukan hak akses</li>
  <li><strong>Status</strong> — aktif atau nonaktif</li>
</ul>

<h2>User Profile</h2>
<p>Setiap pengguna bisa mengatur profilnya sendiri melalui halaman <strong>Profile</strong>. Informasi yang bisa dikelola antara lain:</p>

<img src="/static/img/2026/07/user-profile-screenshot.jpg" alt="User profile page showing avatar, bio, and account information" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<ul>
  <li><strong>Avatar</strong> — foto profil pengguna</li>
  <li><strong>Bio</strong> — deskripsi singkat tentang pengguna</li>
  <li><strong>Phone</strong> — nomor telepon opsional</li>
  <li><strong>Password</strong> — ubah password</li>
  <li><strong>Language preference</strong> — bahasa untuk dashboard</li>
</ul>

<h2>Password Management</h2>
<p>Admin bisa mereset password pengguna lain jika diperlukan. Pengguna juga bisa mengubah passwordnya sendiri dari halaman profil. Selalu gunakan password yang kuat dengan kombinasi huruf, angka, dan karakter khusus.</p>

<h2>Menonaktifkan dan Menghapus User</h2>
<p>Jika seorang pengguna tidak lagi aktif, admin bisa menonaktifkan akunnya tanpa menghapus konten yang pernah dibuat. Nonaktif berarti pengguna tidak bisa login tapi kontennya tetap ada.</p>
<p>Menghapus user biasanya tidak di-rekomendasikan karena bisa memengaruhi konten yang sudah dipublikasikan. Jika harus dihapus, pastikan konten milik user tersebut sudah dialihkan ke user lain.</p>

<h2>Keamanan Akun</h2>
<ul>
  <li><strong>Brute force protection</strong> — pembatasan percobaan login yang gagal</li>
  <li><strong>Session management</strong> — admin bisa melihat dan mengakhiri sesi aktif</li>
  <li><strong>reCAPTCHA</strong> — verifikasi saat login dan registrasi</li>
  <li><strong>Password hashing</strong> — password disimpan dengan aman menggunakan algoritma modern</li>
</ul>

<h2>Best Practices</h2>
<ul>
  <li><strong>Principle of least privilege</strong> — berikan role sesuai kebutuhan, jangan semua jadi admin</li>
  <li><strong>Review users secara berkala</strong> — hapus atau nonaktifkan akun yang tidak aktif</li>
  <li><strong>Gunakan email valid</strong> — penting untuk reset password dan notifikasi</li>
  <li><strong>Aktifkan approval registrasi</strong> — untuk website publik, aktifkan approval admin</li>
  <li><strong>Pantau log aktivitas</strong> — perhatikan login dan perubahan penting</li>
</ul>

<h2>Kesimpulan</h2>
<p>User Management di Jyavani CMS menyediakan kontrol yang cukup untuk website dengan banyak kontributor. Dengan sistem role yang jelas, Anda bisa membagi tugas secara efektif sambil menjaga keamanan dan integritas konten website.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/user-management-thumb.jpg', 'published', 1, '2026-07-24 19:07:46', '2026-07-24 19:07:46', 0, NULL, 0),
(280, 'SEO dan Meta Tags', 'seo-dan-meta-tags', '<p>Search Engine Optimization (SEO) adalah faktor penting agar website mudah ditemukan di mesin pencari. Jyavani CMS menyediakan berbagai fitur SEO bawaan yang membantu meningkatkan visibilitas konten Anda.</p>

<h2>Meta Description</h2>
<p>Setiap artikel dan halaman di Jyavani CMS memiliki kolom <strong>Meta Description</strong>. Deskripsi ini muncul di hasil pencarian Google di bawah judul halaman.</p>

<img src="/static/img/2026/07/meta-tags-screenshot.jpg" alt="Meta tags field in post editor showing SEO title and description inputs" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Jika Anda tidak mengisi meta description, Jyavani CMS akan otomatis mengambil excerpt dari konten. Namun, sebaiknya tulis deskripsi manual yang:</p>
<ul>
  <li>Panjangnya 120-160 karakter</li>
  <li>Mengandung kata kunci utama</li>
  <li>Menggambarkan isi konten dengan menarik</li>
  <li>Memiliki call-to-action ringan</li>
</ul>

<h2>Open Graph Tags</h2>
<p>Open Graph (OG) adalah protokol yang memungkinkan konten website ditampilkan dengan baik saat dibagikan di media sosial seperti Facebook, LinkedIn, dan WhatsApp.</p>
<p>Jyavani CMS secara otomatis menghasilkan OG tags berikut untuk setiap halaman:</p>
<ul>
  <li><code>og:title</code> — judul konten</li>
  <li><code>og:description</code> — meta description</li>
  <li><code>og:image</code> — thumbnail artikel atau gambar default website</li>
  <li><code>og:url</code> — URL kanonikal halaman</li>
  <li><code>og:type</code> — tipe konten, biasanya "article"</li>
</ul>

<img src="/static/img/2026/07/og-tags-screenshot.jpg" alt="Browser view-source showing Open Graph and meta tags in HTML head" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<h2>Twitter Card Tags</h2>
<p>Selain Open Graph, Jyavani CMS juga menghasilkan Twitter Card tags untuk tampilan optimal saat konten dibagikan di Twitter/X. Format yang digunakan biasanya summary_large_image untuk artikel dengan thumbnail.</p>

<h2>Canonical URL</h2>
<p>Canonical URL mencegah masalah duplikat konten. Setiap halaman memiliki tag <code>&lt;link rel="canonical"&gt;</code> yang menunjuk ke URL aslinya. Ini sangat penting jika konten sama bisa diakses melalui beberapa URL.</p>

<h2>Robots Meta</h2>
<p>Pengaturan robots meta mengontrol bagaimana mesin pencari mengindeks halaman:</p>
<ul>
  <li><code>index, follow</code> — indeks dan ikuti link (default untuk konten publik)</li>
  <li><code>noindex, follow</code> — jangan indeks tapi ikuti link (untuk halaman arsip tertentu)</li>
  <li><code>noindex, nofollow</code> — jangan indeks dan jangan ikuti link</li>
</ul>

<h2>Custom Meta Tags</h2>
<p>Untuk kebutuhan khusus, Anda bisa menambahkan custom meta tags per artikel atau per halaman. Berguna untuk verifikasi Google Search Console, meta keywords, atau skema khusus lainnya.</p>

<h2>Sitemap Otomatis</h2>
<p>Jyavani CMS secara otomatis menghasilkan <strong>sitemap.xml</strong> yang berisi semua URL publik website. Sitemap ini membantu mesin pencari menemukan dan mengindeks konten Anda lebih cepat.</p>

<img src="/static/img/2026/07/sitemap-screenshot.jpg" alt="sitemap.xml showing list of URLs with last modified dates" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Anda bisa mengirimkan URL sitemap ke Google Search Console dan Bing Webmaster Tools. URL sitemap biasanya berada di <code>/sitemap.xml</code>.</p>

<h2>JSON-LD Structured Data</h2>
<p>Jyavani CMS menyertakan structured data JSON-LD untuk artikel. Structured data membantu mesin pencari memahami konteks halaman dan bisa menghasilkan rich snippets di hasil pencarian.</p>

<h2>URL yang SEO-Friendly</h2>
<p>Slug artikel di Jyavani CMS otomatis dibuat dari judul. Anda bisa mengedit slug agar lebih pendek dan mengandung kata kunci. Hindari slug yang terlalu panjang atau berisi karakter khusus.</p>
<p>Contoh slug yang baik: <code>cara-menggunakan-jyavani-cms</code></p>
<p>Contoh slug yang kurang baik: <code>artikel-ke-5-tentang-cara-penggunaan-jyavani-cms-yang-lengkap</code></p>

<h2>Best Practices SEO di Jyavani CMS</h2>
<ul>
  <li><strong>Isi judul dengan kata kunci</strong> — judul tetap natural dan menarik</li>
  <li><strong>Gunakan meta description</strong> — jangan biarkan CMS mengisi otomatis terus-menerus</li>
  <li><strong>Pastikan thumbnail berkualitas</strong> — OG image akan muncul saat dibagikan</li>
  <li><strong>Gunakan heading dengan hierarki benar</strong> — h1 untuk judul, h2 untuk sub judul</li>
  <li><strong>Daftarkan sitemap</strong> — submit ke Google Search Console</li>
  <li><strong>Pantau performa</strong> — gunakan Google Search Console untuk melihat indexing dan klik</li>
</ul>

<h2>Kesimpulan</h2>
<p>SEO di Jyavani CMS dirancang untuk bekerja otomatis tanpa konfigurasi rumit, namun tetap memberikan kontrol manual untuk pengguna yang ingin mengoptimalkan lebih dalam. Manfaatkan meta tags, Open Graph, sitemap, dan struktur URL yang baik untuk meningkatkan visibilitas website Anda.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/seo-meta-tags-thumb.jpg', 'published', 1, '2026-07-24 19:16:07', '2026-07-24 19:16:07', 0, NULL, 0),
(281, 'Widget dan Shortcodes', 'widget-dan-shortcodes', '<p>Website modern tidak hanya terdiri dari teks statis. Terkadang Anda perlu menampilkan konten dinamis seperti daftar artikel terbaru, file PDF yang dilindungi, atau video tanpa harus menulis kode secara manual. Jyavani CMS menyediakan <strong>Widget</strong> dan <strong>Shortcodes</strong> untuk kebutuhan ini.</p>

<h2>Apa itu Widget?</h2>
<p>Widget adalah blok konten modular yang bisa ditempatkan di area tertentu website, seperti sidebar atau footer. Widget bersifat reusable — satu widget bisa digunakan di beberapa posisi atau halaman.</p>
<p>Contoh widget yang tersedia:</p>
<ul>
  <li><strong>HTML Widget</strong> — untuk kode HTML custom</li>
  <li><strong>Recent Posts</strong> — menampilkan artikel terbaru</li>
  <li><strong>Categories</strong> — daftar kategori</li>
  <li><strong>Social Icons</strong> — ikon media sosial</li>
  <li><strong>Image Widget</strong> — menampilkan gambar</li>
</ul>

<h2>Widget Settings</h2>
<p>Untuk mengatur widget di sidebar, buka <strong>Dashboard → Sidebar</strong>. Di sini Anda bisa menambahkan, mengedit, menghapus, dan mengurutkan widget.</p>

<img src="/static/img/2026/07/widget-settings-screenshot.jpg" alt="Widget settings page showing sidebar gadgets configuration" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Setiap widget memiliki konfigurasi sendiri. Misalnya widget Recent Posts bisa diatur jumlah artikel yang ditampilkan, widget Categories bisa dipilih hierarki atau flat, dan widget Image memerlukan URL gambar dari Media Library.</p>

<h2>Apa itu Shortcodes?</h2>
<p>Shortcodes adalah tag sederhana yang bisa disisipkan ke dalam konten artikel atau halaman untuk menampilkan konten dinamis. Format shortcodes di Jyavani CMS menggunakan tanda kurung siku, misalnya <code>[post_cat_shortcode]</code> atau <code>[[widget:nama_widget]]</code>.</p>

<h2>Shortcode Builder</h2>
<p>Jyavani CMS menyediakan <strong>Shortcode Builder</strong> — visual editor untuk membuat shortcode tanpa perlu menghafal sintaks.</p>

<img src="/static/img/2026/07/shortcode-builder-screenshot.jpg" alt="Shortcode builder interface showing component toolbar and live preview" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Fitur Shortcode Builder:</p>
<ul>
  <li><strong>Component toolbar</strong> — pilih komponen yang ingin ditambahkan</li>
  <li><strong>Split-view editor</strong> — kode di sebelah kiri, live preview di sebelah kanan</li>
  <li><strong>Variable inspector</strong> — lihat variabel yang tersedia</li>
  <li><strong>Presets</strong> — simpan shortcode yang sering digunakan</li>
</ul>

<h2>Built-in Shortcodes</h2>
<p>Jyavani CMS sudah menyediakan beberapa shortcodes siap pakai:</p>

<ul>
  <li><code>[post_cat_shortcode]</code> — menampilkan daftar artikel dari kategori tertentu</li>
  <li><code>[private_pdf]</code> — menampilkan link file PDF dengan akses terbatas</li>
  <li><code>[video]</code> — embed video dengan player responsif</li>
  <li><code>[[widget:nama_widget]]</code> — render widget tertentu di dalam konten</li>
</ul>

<h2>Widget Shortcodes</h2>
<p>Selain shortcodes bawaan, Anda juga bisa menampilkan widget di dalam konten menggunakan format <code>[[widget:nama_widget]]</code>. Ini berguna saat Anda ingin menampilkan widget di tengah artikel, bukan hanya di sidebar.</p>

<h2>Layout Editor</h2>
<p>Layout Editor terintegrasi dengan Theme Customizer dan Theme Zones. Anda bisa mengatur posisi widget, shortcode, dan komponen lainnya secara visual.</p>

<img src="/static/img/2026/07/layout-editor-screenshot.jpg" alt="Layout editor showing theme zones with draggable gadgets and configuration panels" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Dengan Layout Editor Anda bisa:</p>
<ul>
  <li>Menambah gadget ke zone tertentu</li>
  <li>Mengurutkan gadget dengan drag &amp; drop</li>
  <li>Mengkonfigurasi setiap gadget</li>
  <li>Melihat preview layout secara langsung</li>
</ul>

<h2>Membuat Shortcode Preset</h2>
<p>Jika Anda sering menggunakan shortcode dengan konfigurasi sama, simpan sebagai preset. Preset bisa dipanggil kembali dengan nama yang Anda tentukan, menghemat waktu saat menulis konten.</p>

<h2>Best Practices</h2>
<ul>
  <li><strong>Gunakan widget untuk konten global</strong> — sidebar, footer, atau elemen yang muncul di banyak halaman</li>
  <li><strong>Gunakan shortcodes untuk konten spesifik</strong> — elemen yang hanya muncul di satu artikel/halaman</li>
  <li><strong>Hindari terlalu banyak widget</strong> — sidebar yang terlalu ramai mengganggu fokus pembaca</li>
  <li><strong>Test shortcode sebelum publish</strong> — pastikan output sesuai harapan</li>
  <li><strong>Beri nama preset yang jelas</strong> — memudahkan penggunaan ulang</li>
</ul>

<h2>Kesimpulan</h2>
<p>Widget dan Shortcodes memberikan fleksibilitas besar dalam menyusun konten website. Kombinasi keduanya memungkinkan Anda membuat halaman yang kaya dan dinamis tanpa perlu mengubah kode theme atau plugin.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/widget-shortcodes-thumb.jpg', 'published', 1, '2026-07-24 23:33:31', '2026-07-24 23:33:31', 0, NULL, 0),
(282, 'Keamanan CMS — Best Practices', 'keamanan-cms-best-practices', '<p>Keamanan adalah aspek penting yang sering diabaikan saat mengelola website. Jyavani CMS hadir dengan berbagai fitur keamanan bawaan yang membantu melindungi website, konten, dan data pengguna dari ancaman umum.</p>

<h2>Hidden Admin — Custom Admin Path</h2>
<p>Salah satu keunggulan keamanan Jyavani CMS adalah kemampuan untuk menyembunyikan path admin. Secara default, dashboard admin berada di <code>/adiwira/</code>, tetapi Anda bisa mengubahnya ke path apapun melalui pengaturan autentikasi.</p>

<img src="/static/img/2026/07/auth-settings-screenshot.jpg" alt="Auth settings page showing custom admin path, login path, and register path configuration" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Dengan <strong>hidden admin path</strong>, alamat dashboard admin Anda tidak lagi mudah ditebak. Ini secara signifikan mengurangi serangan brute force dan scanning otomatis yang menargetkan URL admin default seperti <code>/wp-admin</code> atau <code>/admin</code>.</p>
<p>Anda juga bisa mengubah:</p>
<ul>
  <li><strong>Login Path</strong> — URL halaman login</li>
  <li><strong>Register Path</strong> — URL halaman registrasi</li>
  <li><strong>Admin Path</strong> — URL dashboard admin</li>
</ul>

<h2>Brute Force Protection</h2>
<p>Jyavani CMS membatasi jumlah percobaan login yang gagal. Jika seseorang mencoba menebak password berulang kali, sistem akan memblokir sementara percobaan dari IP tersebut. Fitur ini mengurangi risiko serangan brute force secara efektif.</p>

<h2>CSRF Protection</h2>
<p>Setiap form penting di Jyavani CMS dilindungi dengan <strong>CSRF token</strong> berbasis HMAC. Token ini memastikan bahwa setiap permintaan modifikasi data berasal dari halaman yang sah, bukan dari situs pihak ketiga yang mencoba mengeksploitasi sesi pengguna.</p>

<h2>Session Management</h2>
<p>Sesi login di Jyavani CMS dikelola dengan aman menggunakan cookie HTTP-only dan secure flag saat website berjalan di HTTPS. Admin bisa melihat sesi aktif dan mengakhiri sesi yang mencurigakan jika diperlukan.</p>

<h2>Login Page</h2>
<p>Halaman login Jyavani CMS dirancang sederhana namun aman. Tidak ada informasi yang mengindikasikan versi CMS atau platform yang digunakan, sehingga mengurangi informasi yang bisa dieksploitasi oleh attacker.</p>

<img src="/static/img/2026/07/login-page-screenshot.jpg" alt="Login page showing username and password form without revealing CMS platform information" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<h2>User Roles dan Permissions</h2>
<p>Sistem role-based access control memastikan setiap pengguna hanya bisa mengakses fitur sesuai tanggung jawabnya. Admin memiliki akses penuh, Editor bisa mengelola konten, dan Author hanya bisa mengedit konten miliknya sendiri.</p>

<img src="/static/img/2026/07/user-roles-screenshot.jpg" alt="Users list showing different roles and permission levels" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<h2>Private Files dengan Token</h2>
<p>Jyavani CMS mendukung file private yang hanya bisa diakses melalui URL dengan token khusus. File private tidak bisa diakses langsung melalui URL publik, sehingga cocok untuk dokumen berbayar, konten eksklusif, atau materi internal.</p>

<h2>HTTPS dan SSL</h2>
<p>Penggunaan HTTPS sangat direkomendasikan untuk setiap website. Jyavani CMS bekerja optimal di HTTPS dan otomatis memaksa cookie secure flag saat protokol HTTPS aktif. Pastikan Anda memasang sertifikat SSL yang valid.</p>

<h2>Konfigurasi .env</h2>
<p>File <code>.env</code> menyimpan konfigurasi sensitif seperti database credentials. Pastikan file ini:</p>
<ul>
  <li>Tidak bisa diakses dari browser</li>
  <li>Memiliki permission yang benar (biasanya 640 atau 600)</li>
  <li>Tidak di-push ke repository publik</li>
  <li>Memiliki APP_KEY yang unik dan kuat</li>
</ul>

<h2>Keamanan Database</h2>
<ul>
  <li>Gunakan password database yang kuat dan unik</li>
  <li>Batasi akses database hanya dari host yang diperlukan</li>
  <li>Lakukan backup database secara berkala</li>
  <li>Pastikan user database hanya memiliki hak akses minimum yang dibutuhkan</li>
</ul>

<h2>Best Practices Keamanan</h2>
<ul>
  <li><strong>Gunakan password kuat</strong> — minimal 12 karakter dengan kombinasi huruf, angka, dan simbol</li>
  <li><strong>Ubah admin path default</strong> — ini adalah langkah pertama dan paling mudah</li>
  <li><strong>Aktifkan reCAPTCHA</strong> — untuk login dan registrasi publik</li>
  <li><strong>Update CMS secara berkala</strong> — pantau release notes dan security patches</li>
  <li><strong>Batasi user admin</strong> — jangan semua pengguna memiliki role admin</li>
  <li><strong>Monitor log</strong> — perhatikan aktivitas login dan perubahan penting</li>
  <li><strong>Backup rutin</strong> — database dan file website</li>
</ul>

<h2>Kesimpulan</h2>
<p>Keamanan website adalah tanggung jawab bersama antara CMS dan penggunanya. Jyavani CMS menyediakan fondasi keamanan yang kuat dengan hidden admin path, brute force protection, CSRF protection, dan sistem role-based permissions. Dengan mengikuti best practices, Anda bisa menjaga website tetap aman dari ancaman umum.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/keamanan-cms-thumb.jpg', 'published', 1, '2026-07-24 23:45:59', '2026-07-24 23:45:59', 0, NULL, 0),
(283, 'Tentang Jyavani', 'tentang-jyavani', '<p>Jyavani CMS adalah <strong>Content Management System</strong> native PHP yang dirancang untuk pengguna yang menginginkan kontrol penuh atas website mereka tanpa bergantung pada framework besar atau Composer.</p>

<h2>Visi</h2>
<p>Menyediakan CMS yang sederhana, ringan, aman, dan mudah dikustomisasi untuk berbagai kebutuhan website — mulai dari blog pribadi hingga website organisasi.</p>

<h2>Fitur Utama</h2>
<ul>
  <li>Manajemen artikel dan halaman</li>
  <li>Sistem kategori hierarki</li>
  <li>Media Library untuk gambar dan file</li>
  <li>Theme System dengan Customizer dan Zones</li>
  <li>Plugin System dengan hooks</li>
  <li>Menu Manager dengan drag &amp; drop</li>
  <li>User Management dengan roles</li>
  <li>SEO dan meta tags otomatis</li>
  <li>Hidden admin path untuk keamanan</li>
</ul>

<h2>Kenapa Native PHP?</h2>
<p>Jyavani CMS dibangun dengan native PHP sehingga tidak memerlukan composer install, tidak ada vendor folder yang besar, dan performa tetap optimal. Cocok untuk shared hosting maupun VPS.</p>

<h2>Kontribusi</h2>
<p>Jyavani CMS dikembangkan secara terbuka. Anda bisa berkontribusi melalui GitHub, mengembangkan plugin, atau membuat theme sesuai kebutuhan Anda. Kunjungi komunitas kami di <a href="https://jyavani.com/">jyavani.com</a>.</p>
', 'page', NULL, NULL, NULL, 'published', 1, '2026-07-24 23:50:53', '2026-07-25 00:00:28', 0, NULL, 0),
(284, 'Kontak', 'kontak', '<p>Hubungi kami jika Anda memiliki pertanyaan, saran, atau ingin berkontribusi dalam pengembangan Jyavani CMS.</p>

<h2>Informasi Kontak</h2>
<ul>
  <li><strong>Email:</strong> <a href="mailto:hello@jyavani.com">hello@jyavani.com</a></li>
  <li><strong>Website:</strong> <a href="https://jyavani.com/">https://jyavani.com/</a></li>
  <li><strong>GitHub:</strong> <a href="https://github.com/adammuizweb/jyavani">https://github.com/adammuizweb/jyavani</a></li>
</ul>

<h2>Media Sosial</h2>
<ul>
  <li>Twitter/X: <a href="https://twitter.com/jyavanicms">@jyavanicms</a></li>
  <li>Facebook: <a href="https://facebook.com/jyavanicms">Jyavani CMS</a></li>
  <li>Instagram: <a href="https://instagram.com/jyavanicms">@jyavanicms</a></li>
</ul>

<h2>Alamat</h2>
<p>Jyavani CMS HQ<br>
Jl. Teknologi No. 123<br>
Kota Digital, Indonesia</p>

<h2>Formulir Kontak</h2>
<p>Silakan kirim pesan Anda melalui email ke <a href="mailto:hello@jyavani.com">hello@jyavani.com</a>. Kami akan berusaha merespons secepatnya.</p>
', 'page', NULL, NULL, NULL, 'published', 1, '2026-07-24 23:50:53', '2026-07-25 00:00:28', 0, NULL, 0),
(285, 'Privacy Policy', 'privacy-policy', '<p>Jyavani CMS menghargai privasi pengunjung dan pengguna. Kebijakan privasi ini menjelaskan bagaimana data dikumpulkan, digunakan, dan dilindungi di website yang menggunakan Jyavani CMS.</p>

<h2>Data yang Dikumpulkan</h2>
<p>Ketika Anda menggunakan website ini, kami mungkin mengumpulkan:</p>
<ul>
  <li>Informasi akun seperti nama, email, dan username</li>
  <li>Log aktivitas seperti IP address dan waktu akses</li>
  <li>Cookie untuk menjaga sesi login dan preferensi</li>
</ul>

<h2>Penggunaan Data</h2>
<p>Data yang dikumpulkan digunakan untuk:</p>
<ul>
  <li>Autentikasi dan otorisasi pengguna</li>
  <li>Meningkatkan keamanan website</li>
  <li>Menyediakan pengalaman pengguna yang lebih baik</li>
  <li>Menganalisis traffic website secara anonim</li>
</ul>

<h2>Perlindungan Data</h2>
<p>Kami menerapkan berbagai langkah keamanan untuk melindungi data Anda, termasuk:</p>
<ul>
  <li>Enkripsi password dengan algoritma modern</li>
  <li>Perlindungan CSRF pada setiap form</li>
  <li>Session management yang aman</li>
  <li>Backup data secara berkala</li>
</ul>

<h2>Cookie</h2>
<p>Website ini menggunakan cookie untuk menyimpan sesi login dan preferensi tampilan. Anda bisa mengatur browser untuk menolak cookie, namun beberapa fitur mungkin tidak berfungsi optimal.</p>

<h2>Perubahan Kebijakan</h2>
<p>Kebijakan privasi ini dapat diperbarui sewaktu-waktu. Perubahan akan diumumkan di halaman ini.</p>

<h2>Kontak</h2>
<p>Jika Anda memiliki pertanyaan tentang kebijakan privasi ini, silakan hubungi kami melalui email <a href="mailto:hello@jyavani.com">hello@jyavani.com</a> atau kunjungi <a href="https://jyavani.com/">jyavani.com</a>.</p>
', 'page', NULL, NULL, NULL, 'published', 1, '2026-07-24 23:50:53', '2026-07-25 00:00:28', 0, NULL, 0),
(286, 'Sistem Soft Delete — Recycle Bin di Jyavani CMS', 'sistem-soft-delete-recycle-bin', '<p>Kesalahan dalam mengelola konten website bisa terjadi kapan saja. Seorang editor mungkin tidak sengaja menghapus artikel penting, atau konten lama perlu dipulihkan kembali setelah evaluasi. Untuk melindungi data dari penghapusan permanen yang tidak disengaja, Jyavani CMS menyediakan sistem <strong>soft delete</strong> yang canggih melalui fitur Recycle Bin.</p>

<h2>Apa itu Soft Delete?</h2>
<p>Soft delete adalah metode penghapusan di mana konten tidak benar-benar dihapus dari database. Sebaliknya, CMS menandai konten tersebut dengan status <strong>trashed</strong> sehingga tidak lagi muncul di frontend website dan tidak lagi terlihat di daftar konten aktif. Namun, konten tetap tersimpan secara aman di database beserta metadatanya seperti author, tanggal pembuatan, kategori, dan tag.</p>

<p>Keuntungan utama dari sistem soft delete meliputi:</p>
<ul>
  <li><strong>Pemulihan konten yang mudah</strong> — artikel yang terhapus karena kesalahan bisa dikembalikan dalam hitungan detik</li>
  <li><strong>Mencegah kehilangan data permanen</strong> — ada lapisan keamanan tambahan sebelum konten benar-benar hilang</li>
  <li><strong>Opsi review sebelum hapus permanen</strong> — admin bisa meninjau ulang konten sebelum memutuskan untuk menghapusnya selamanya</li>
  <li><strong>Mendukung audit trail</strong> — informasi siapa yang menghapus dan kapan masih tersimpan</li>
  <li><strong>Perlindungan terhadap kesalahan human error</strong> — kesalahan klik tidak langsung berakibat fatal</li>
</ul>

<h2>Mengenal Recycle Bin</h2>
<p>Untuk melihat semua konten yang telah dihapus sementara, buka <strong>Dashboard → Recycle Bin</strong>. Halaman ini menampilkan daftar artikel, halaman, atau media yang berada dalam status trashed beserta informasi penting seperti tanggal penghapusan, penghapus, dan alasan penghapusan jika tersedia.</p>

<img src="/static/img/2026/07/bin-screenshot.jpg" alt="Recycle Bin page showing deleted posts with restore and permanent delete options" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Antarmuka Recycle Bin memudahkan Anda untuk mengidentifikasi konten yang perlu dipulihkan. Anda bisa melakukan pencarian, filter berdasarkan tipe konten, atau mengurutkan berdasarkan tanggal penghapusan. Setiap item memiliki dua tombol aksi utama:</p>

<ul>
  <li><strong>Restore</strong> — mengembalikan konten ke status aktif, baik itu published, draft, atau private sesuai keadaan sebelum dihapus</li>
  <li><strong>Delete Permanently</strong> — menghapus konten secara permanen dari database dan tidak bisa dibatalkan</li>
</ul>

<h2>Cara Menghapus Konten ke Recycle Bin</h2>
<p>Saat Anda menghapus artikel atau halaman dari halaman daftar konten, Jyavani CMS tidak langsung menghapusnya secara permanen. Sebagai gantinya, konten tersebut dipindahkan ke Recycle Bin. Proses ini dilakukan secara otomatis dan transparan, sehingga pengguna tidak perlu memikirkan perbedaan antara soft delete dan hard delete pada saat pertama kali menghapus.</p>

<p>Untuk menghapus konten:</p>
<ol>
  <li>Buka halaman <strong>Posts</strong> atau <strong>Pages</strong> dari dashboard</li>
  <li>Cari konten yang ingin dihapus</li>
  <li>Klik tombol <strong>Delete</strong> atau pilih aksi massal delete untuk beberapa konten sekaligus</li>
  <li>Konfirmasi penghapusan pada dialog yang muncul</li>
  <li>Konten akan dipindahkan ke Recycle Bin dan tidak lagi muncul di website</li>
</ol>

<h2>Mengembalikan Konten dari Recycle Bin</h2>
<p>Proses restore sangat sederhana dan tidak memerlukan keahlian teknis. Untuk mengembalikan konten:</p>

<ol>
  <li>Navigasi ke menu <strong>Dashboard → Recycle Bin</strong></li>
  <li>Temukan konten yang ingin dipulihkan menggunakan fitur pencarian atau filter</li>
  <li>Klik tombol <strong>Restore</strong> pada baris konten yang dipilih</li>
  <li>Konten akan kembali ke status semula dan muncul lagi di daftar Posts atau Pages</li>
  <li>Jika sebelumnya konten berstatus published, maka konten akan langsung kembali muncul di frontend website</li>
</ol>

<p>Restore tidak hanya mengembalikan konten utama, tetapi juga mempertahankan relasi yang terkait seperti kategori, tag, thumbnail, dan komentar jika ada. Ini memastikan konten yang dipulihkan benar-benar identik dengan keadaan sebelum dihapus.</p>

<h2>Penghapusan Permanen</h2>
<p>Setelah konten berada di Recycle Bin selama periode waktu tertentu, atau jika Anda yakin konten tersebut memang tidak lagi dibutuhkan, Anda bisa menghapusnya secara permanen. Penghapusan permanen akan benar-benar menghapus data dari database dan tidak dapat dipulihkan kembali.</p>

<p>Sebelum menghapus permanen, pertimbangkan hal-hal berikut:</p>
<ul>
  <li>Apakah konten tersebut sudah memiliki backup? Jika ya, hapus permanen lebih aman dilakukan.</li>
  <li>Apakah konten tersebut memiliki backlink dari website lain? Jika ya, pertimbangkan redirect URL.</li>
  <li>Apakah konten tersebut perlu diarsipkan untuk kebutuhan legal atau audit? Jika ya, simpan backup terlebih dahulu.</li>
</ul>

<h2>Soft Delete untuk Media dan File</h2>
<p>Selain artikel dan halaman, soft delete juga berlaku untuk media seperti gambar dan dokumen. Ketika sebuah file dihapus dari Media Library, file tersebut tidak langsung dihapus dari storage. Sebaliknya, file dipindahkan ke status trashed dan tetap bisa dipulihkan dari Recycle Bin. Hal ini mencegah kehilangan aset visual penting akibat kesalahan klik.</p>

<p>Namun, perlu diperhatikan bahwa file yang dihapus permanen dari Media Library akan menghapus file fisik dari server. Oleh karena itu, pastikan Anda sudah membackup file-file penting sebelum melakukan penghapusan permanen.</p>

<h2>Pengaturan dan Otomatisasi Recycle Bin</h2>
<p>Beberapa konfigurasi yang bisa diatur terkait Recycle Bin meliputi:</p>

<ul>
  <li><strong>Auto-clean interval</strong> — konten yang sudah berada di Recycle Bin lebih dari jangka waktu tertentu bisa dihapus otomatis untuk menghemat storage</li>
  <li><strong>Role-based access</strong> — hanya admin atau editor tertentu yang bisa melakukan penghapusan permanen</li>
  <li><strong>Batch restore/delete</strong> — fitur aksi massal untuk memulihkan atau menghapus banyak konten sekaligus</li>
</ul>

<h2>Best Practices Soft Delete</h2>
<ul>
  <li><strong>Jangan langsung hapus permanen</strong> — biarkan konten di Recycle Bin minimal beberapa hari hingga dipastikan tidak diperlukan lagi</li>
  <li><strong>Backup rutin</strong> — meskipun ada soft delete, backup tetap menjadi garis pertahanan terakhir yang penting</li>
  <li><strong>Periksa Recycle Bin secara berkala</strong> — bersihkan konten yang memang sudah tidak diperlukan untuk menjaga database tetap rapi</li>
  <li><strong>Batasi akses penghapusan permanen</strong> — hanya berikan izin hapus permanen kepada admin senior untuk mencegah kehilangan data</li>
  <li><strong>Edukasikan tim konten</strong> — pastikan semua editor memahami bahwa konten yang dihapus pertama kali masih bisa dipulihkan</li>
</ul>

<h2>Kesimpulan</h2>
<p>Sistem soft delete di Jyavani CMS memberikan lapisan perlindungan penting terhadap kesalahan manusiawi. Dengan Recycle Bin yang mudah diakses, proses restore yang cepat, dan penghapusan permanen yang terpisah, Anda memiliki kendali penuh atas siklus hidup konten website. Manfaatkan fitur ini untuk menjaga integritas data dan memberikan rasa aman kepada tim konten Anda.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/sistem-soft-delete-thumb.jpg', 'published', 1, '2026-07-25 00:03:17', '2026-07-25 08:20:11', 0, NULL, 0),
(287, 'Sistem Reinstall dan Update', 'sistem-reinstall-dan-update', '<p>Pemeliharaan website adalah bagian penting dari operasional jangka panjang. Setiap CMS memerlukan mekanisme yang andal untuk memperbarui fitur, memperbaiki bug, dan menangani situasi darurat seperti korupsi konfigurasi atau kebutuhan untuk mengembalikan sistem ke kondisi default. Jyavani CMS menyediakan halaman <strong>Update &amp; Maintenance</strong> yang dirancang khusus untuk memudahkan proses update, reinstall, dan reset sistem.</p>

<h2>Mengenal Halaman Update</h2>
<p>Halaman Update di Jyavani CMS adalah pusat kendali untuk semua kebutuhan pemeliharaan sistem. Buka melalui <strong>Dashboard → Update</strong> untuk melihat informasi penting mengenai status versi CMS yang sedang berjalan.</p>

<img src="/static/img/2026/07/update-page-screenshot.jpg" alt="Update and maintenance page showing current version, update options, and reset buttons" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Beberapa informasi yang ditampilkan di halaman ini meliputi:</p>

<ul>
  <li><strong>Versi saat ini</strong> — nomor versi Jyavani CMS yang terinstall di server Anda</li>
  <li><strong>Update tersedia</strong> — notifikasi jika ada versi baru yang lebih tinggi di repository</li>
  <li><strong>Changelog</strong> — daftar perubahan, penambahan fitur, perbaikan bug, dan perubahan breaking change</li>
  <li><strong>Tombol Check for Updates</strong> — memeriksa versi terbaru secara manual</li>
  <li><strong>Tombol Update Now</strong> — memulai proses download dan install versi terbaru</li>
  <li><strong>Opsi Reset</strong> — soft reset dan hard reset konfigurasi</li>
</ul>

<h2>Proses Auto-Update</h2>
<p>Jyavani CMS menyediakan fitur auto-update yang memungkinkan Anda memperbarui sistem ke versi terbaru tanpa harus melakukannya secara manual melalui FTP atau Git. Proses auto-update bekerja dengan mengunduh paket versi terbaru, mengekstraknya, dan mengganti file-file core yang sudah ada.</p>

<p>Langkah-langkah melakukan auto-update:</p>

<ol>
  <li><strong>Backup database dan file terlebih dahulu</strong> — ini adalah langkah paling penting sebelum setiap update</li>
  <li>Buka halaman <strong>Dashboard → Update</strong></li>
  <li>Klik tombol <strong>Check for Updates</strong> untuk memverifikasi ketersediaan versi baru</li>
  <li>Baca <strong>changelog</strong> untuk memahami perubahan yang akan terjadi</li>
  <li>Klik tombol <strong>Update Now</strong> untuk memulai proses update</li>
  <li>Tunggu proses download dan ekstraksi selesai, biasanya memerlukan waktu beberapa detik hingga menit tergantung koneksi internet dan ukuran paket</li>
  <li>Setelah selesai, verifikasi website berjalan normal dengan membuka beberapa halaman utama</li>
  <li>Periksa kembali pengaturan theme, plugin, dan menu untuk memastikan tidak ada yang berubah tidak terduga</li>
</ol>

<h2>Backup Sebelum Update</h2>
<p>Backup adalah aspek krusial yang tidak boleh dilewatkan. Sebelum melakukan update, pastikan Anda sudah melakukan backup pada komponen berikut:</p>

<ul>
  <li><strong>Database</strong> — export seluruh tabel database ke file SQL melalui phpMyAdmin, command line mysqldump, atau fitur backup yang tersedia di server</li>
  <li><strong>File upload dan media</strong> — folder public/static/img yang berisi gambar, thumbnail, dan file publik lainnya</li>
  <li><strong>Private files</strong> — jika ada file private yang disimpan di luar public, pastikan juga dibackup</li>
  <li><strong>File konfigurasi</strong> — file .env dan file custom di plugins atau themes jika Anda sudah melakukan kustomisasi</li>
  <li><strong>Theme dan plugin custom</strong> — backup folder themes/ dan plugins/ jika Anda mengembangkan theme atau plugin sendiri</li>
</ul>

<p>Dengan backup yang lengkap, Anda bisa dengan cepat mengembalikan website ke kondisi sebelum update jika terjadi masalah kompatibilitas atau error setelah update.</p>

<h2>Reinstall Komponen CMS</h2>
<p>Terkadang, theme atau plugin mengalami masalah yang tidak bisa diperbaiki dengan sekadar menonaktifkan. Dalam situasi tersebut, Anda mungkin perlu melakukan reinstall pada komponen tertentu. Jyavani CMS memudahkan proses ini melalui opsi reinstall yang tersedia di halaman Update.</p>

<p>Reinstall komponen biasanya diperlukan ketika:</p>

<ul>
  <li>File komponen terhapus atau rusak secara tidak sengaja</li>
  <li>Konfigurasi komponen menjadi tidak konsisten</li>
  <li>Komponen hasil modifikasi manual ingin dikembalikan ke versi asli</li>
  <li>Muncul error yang tidak bisa dijelaskan setelah eksperimen</li>
</ul>

<p>Proses reinstall akan mengunduh ulang file komponen dari sumbernya dan menimpa file yang ada. Pastikan Anda sudah membackup modifikasi kustom sebelum melakukan reinstall jika tidak ingin kehilangan kustomisasi.</p>

<h2>Soft Reset dan Hard Reset</h2>
<p>Kadang kala konfigurasi website menjadi sangat berantakan sehingga lebih mudah untuk mengembalikan CMS ke kondisi default daripada memperbaiki satu per satu. Jyavani CMS menyediakan dua opsi reset untuk situasi berbeda.</p>

<h3>Soft Reset</h3>
<p>Soft reset mengembalikan pengaturan tertentu ke default tanpa menghapus konten Anda. Fitur ini cocok digunakan ketika konfigurasi theme, sidebar, atau menu menjadi kacau namun konten artikel dan halaman tetap ingin dipertahankan. Komponen yang dikembalikan ke default meliputi:</p>

<ul>
  <li>Konfigurasi theme dan theme zones</li>
  <li>Pengaturan sidebar dan widget</li>
  <li>Struktur menu navigasi</li>
  <li>Beberapa pengaturan tampilan yang tidak memengaruhi data konten</li>
</ul>

<h3>Hard Reset</h3>
<p>Hard reset adalah opsi yang lebih drastis. Fitur ini mengembalikan CMS ke kondisi pabrik dengan mereset theme, auth paths, plugin, slots, sidebar, dan menu ke default. Gunakan hard reset hanya dalam situasi darurat karena fitur ini akan menghapus kustomisasi yang sudah Anda lakukan. Sebelum hard reset, lakukan backup penuh terlebih dahulu.</p>

<h2>Update Manual Melalui Git atau FTP</h2>
<p>Jika auto-update tidak berfungsi karena keterbatasan server, koneksi internet, atau preferensi Anda, Anda masih bisa melakukan update manual. Terdapat beberapa cara untuk melakukan update manual:</p>

<h3>Update via Git</h3>
<pre>cd /var/www/jyavani.lan
git pull origin main</pre>

<p>Jika Anda menggunakan Git untuk mengelola deployment, pastikan Anda sudah melakukan commit atau stash terhadap kustomisasi lokal sebelum melakukan pull. Setelah pull, jalankan migrasi database jika ada file migrasi yang disertakan.</p>

<h3>Update via ZIP</h3>
<ol>
  <li>Download paket versi terbaru dari repository resmi Jyavani CMS</li>
  <li>Backup file dan database yang ada</li>
  <li>Ekstrak file ZIP ke folder sementara</li>
  <li>Ganti file core dengan file baru, kecuali file .env, folder uploads, plugins, dan themes kustom</li>
  <li>Pastikan permission file dan folder tetap benar</li>
  <li>Jalankan migrasi database jika diperlukan</li>
  <li>Verifikasi website berjalan normal</li>
</ol>

<h2>Troubleshooting Update</h2>
<p>Jika proses update mengalami kegagalan, berikut beberapa langkah yang bisa dilakukan:</p>

<ul>
  <li><strong>Periksa permission folder</strong> — pastikan CMS memiliki hak tulis ke direktori yang diperlukan untuk ekstraksi file</li>
  <li><strong>Pastikan koneksi internet stabil</strong> — koneksi yang terputus saat download bisa menyebabkan file korup</li>
  <li><strong>Cek error log server</strong> — log error sering memberikan petunjuk spesifik mengenai penyebab kegagalan</li>
  <li><strong>Lakukan update manual</strong> — jika auto-update gagal berulang kali, gunakan metode manual</li>
  <li><strong>Hubungi komunitas atau dukungan</strong> — jika masalah berlanjut, laporkan dengan menyertakan log error dan versi CMS</li>
</ul>

<h2>Best Practices Update dan Maintenance</h2>
<ul>
  <li><strong>Selalu backup sebelum update</strong> — ini adalah aturan emas yang tidak boleh dilanggar</li>
  <li><strong>Baca changelog dengan cermat</strong> — pahami perubahan, terutama breaking changes sebelum update</li>
  <li><strong>Update di staging terlebih dahulu</strong> — untuk website produksi yang penting, uji update di environment staging sebelum menerapkan ke produksi</li>
  <li><strong>Jangan skip versi major</strong> — ikuti urutan update yang disarankan untuk menghindari masalah kompatibilitas</li>
  <li><strong>Verifikasi plugin dan theme</strong> — pastikan plugin dan theme yang digunakan kompatibel dengan versi baru CMS</li>
  <li><strong>Jadwalkan update secara rutin</strong> — jangan menunda security update meskipun website tampak berjalan normal</li>
</ul>

<h2>Kesimpulan</h2>
<p>Sistem update dan reinstall di Jyavani CMS dirancang untuk memudahkan pemeliharaan website jangka panjang. Dengan auto-update, opsi reset yang terstruktur, dan dukungan update manual, Anda memiliki fleksibilitas untuk menjaga CMS tetap aman, stabil, dan up-to-date. Ingatlah untuk selalu melakukan backup sebelum melakukan perubahan besar agar data dan kustomisasi Anda tetap aman.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/sistem-reinstall-update-thumb.jpg', 'published', 1, '2026-07-25 00:06:50', '2026-07-25 08:20:11', 0, NULL, 0),
(288, 'Memulai dengan Jyavani CMS', 'memulai-dengan-jyavani', '<p>Selamat datang di <strong>Jyavani CMS</strong>! Panduan ini dirancang khusus untuk membantu pengguna baru memahami fondasi CMS ini, mulai dari struktur file, cara login ke dashboard, hingga membuat dan mempublikasikan konten pertama Anda. Jyavani CMS dibangun dengan pendekatan native PHP sehingga ringan, cepat, dan tidak memerlukan dependency manager seperti Composer.</p>

<h2>Apa itu Jyavani CMS?</h2>
<p>Jyavani CMS adalah <strong>Content Management System</strong> yang dikembangkan menggunakan native PHP. Berbeda dengan CMS populer lainnya yang bergantung pada framework besar, Jyavani CMS dirancang agar sederhana, modular, dan mudah dipahami bahkan oleh pengguna yang baru memulai pengembangan website. Anda tidak perlu memahami konsep dependency injection, ORM, atau build tools modern untuk mulai menggunakannya.</p>

<p>Keunggulan utama Jyavani CMS antara lain:</p>

<ul>
  <li><strong>Native PHP tanpa Composer</strong> — tidak ada vendor folder yang besar atau kompleksitas dependency</li>
  <li><strong>Struktur file yang intuitif</strong> — setiap fitur memiliki lokasi yang jelas dan mudah ditemukan</li>
  <li><strong>Dashboard dengan sidebar navigasi yang lengkap</strong> — semua fitur CMS dapat diakses dari satu tempat</li>
  <li><strong>Theme dan plugin system yang modular</strong> — kustomisasi tampilan dan fitur tanpa mengubah core</li>
  <li><strong>Hidden admin path untuk keamanan</strong> — URL dashboard dapat diubah dari default untuk meningkatkan keamanan</li>
  <li><strong>SEO dan meta tags otomatis</strong> — konten Anda siap diindeks mesin pencari sejak awal</li>
</ul>

<h2>Struktur File dan Folder</h2>
<p>Setelah proses install selesai, Anda akan melihat struktur direktori utama seperti berikut. Memahami struktur ini akan sangat membantu saat Anda mulai mengelola website:</p>

<pre>jyavani/
  cfg/            ← konfigurasi, helper functions, dan file .env
  public/         ← entry point website (index.php) dan static assets
  dashboard/      ← seluruh file antarmuka admin dashboard
  pondasi/        ← file installer untuk setup awal
  plugins/        ← plugin yang terinstall di website
  themes/         ← theme aktif dan theme yang tersedia
  schema/         ← file SQL untuk install dan demo content</pre>

<p>Struktur ini sengaja dibuat sederhana agar pengguna dapat dengan cepat menemukan file yang ingin diedit, baik untuk kustomisasi theme, pembuatan plugin, maupun debugging konfigurasi.</p>

<h2>Login ke Dashboard</h2>
<p>Setelah install selesai, Anda akan mendapatkan URL dashboard admin. Secara default, URL admin berada di <code>/adiwira/</code>, tetapi sangat disarankan untuk mengubahnya saat install melalui pengaturan auth path. URL login dapat diakses sesuai dengan login path yang ditentukan saat install.</p>

<p>Untuk login, masukkan username dan password yang Anda buat saat install pertama kali. Jika berhasil, Anda akan diarahkan ke dashboard utama yang menampilkan ringkasan website dan menu navigasi lengkap.</p>

<h2>Tour Dashboard Jyavani CMS</h2>
<p>Setelah login, Anda akan melihat dashboard utama Jyavani CMS. Antarmuka dashboard terdiri dari beberapa area utama yang masing-masing memiliki fungsi tersendiri:</p>

<img src="/static/img/2026/07/dashboard-screenshot.jpg" alt="Jyavani CMS dashboard main page showing overview cards, sidebar navigation, and recent activity" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<ul>
  <li><strong>Sidebar Navigasi</strong> — menu utama ke semua fitur seperti Posts, Pages, Media, Categories, Themes, Plugins, Users, Settings, dan Update. Menu ini dirancang dengan hierarki yang jelas sehingga mudah dijelajahi.</li>
  <li><strong>Top Header</strong> — menampilkan informasi user yang sedang login, tautan ke profile, notifikasi, dan quick actions.</li>
  <li><strong>Main Content Area</strong> — area kerja utama tempat konten dan form ditampilkan. Area ini akan berubah sesuai menu yang dipilih.</li>
  <li><strong>Overview Cards</strong> — ringkasan visual mengenai jumlah artikel, halaman, pengguna, media, dan statistik singkat lainnya.</li>
  <li><strong>Recent Activity</strong> — menampilkan konten terbaru yang baru saja dibuat atau diperbarui.</li>
</ul>

<p>Luangkan waktu beberapa menit untuk mengklik setiap menu dan melihat apa yang tersedia. Ini akan membantu Anda memahami kapabilitas CMS secara keseluruhan sebelum mulai membuat konten.</p>

<h2>Membuat Artikel Pertama</h2>
<p>Salah satu langkah pertama yang paling menyenangkan adalah membuat artikel pertama. Untuk melakukannya, navigasi ke <strong>Posts → Add New</strong> dari sidebar. Anda akan melihat form editor yang terdiri dari beberapa komponen penting:</p>

<img src="/static/img/2026/07/add-post-screenshot.jpg" alt="Add new post form with title, slug, Quill editor, category selection, and publish status" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<ul>
  <li><strong>Title</strong> — judul artikel yang akan ditampilkan di halaman artikel dan daftar konten. Gunakan judul yang jelas dan mengandung kata kunci utama.</li>
  <li><strong>Slug</strong> — URL friendly untuk artikel. CMS akan menggenerate slug otomatis dari judul, tetapi Anda bisa mengubahnya manual agar lebih pendek dan optimal untuk SEO.</li>
  <li><strong>Content Editor</strong> — editor WYSIWYG berbasis Quill yang memungkinkan Anda menulis, memformat teks, menambahkan heading, list, gambar, dan video dengan mudah.</li>
  <li><strong>Categories</strong> — pilih satu atau lebih kategori untuk mengorganisir artikel Anda. Kategori membantu pengunjung menemukan konten terkait.</li>
  <li><strong>Status</strong> — pilih antara Draft, Published, atau Private. Draft digunakan untuk menyimpan artikel yang belum selesai, Published untuk mempublikasikan, dan Private untuk artikel yang hanya bisa diakses user tertentu.</li>
  <li><strong>Thumbnail</strong> — gambar utama artikel yang akan digunakan sebagai featured image di daftar artikel dan meta tag Open Graph.</li>
  <li><strong>Meta Tags</strong> — deskripsi dan meta tambahan untuk keperluan SEO dan media sosial.</li>
</ul>

<h2>Draft, Preview, dan Publish</h2>
<p>Anda tidak perlu langsung mempublikasikan artikel. CMS memungkinkan Anda menyimpan artikel sebagai <strong>Draft</strong> terlebih dahulu. Fitur ini sangat berguna saat Anda masih mengembangkan ide atau menunggu persetujuan.</p>

<p>Setelah artikel selesai, Anda bisa mengubah status menjadi <strong>Published</strong>. Artikel akan langsung muncul di frontend website dan bisa diakses melalui URL berdasarkan slug yang telah ditentukan. Jika ingin melihat tampilan artikel sebelum benar-benar dipublikasikan, Anda bisa membuka URL preview yang tersedia.</p>

<h2>Preview Artikel di Frontend</h2>
<p>Setelah artikel dipublikasikan, Anda bisa membukanya di bagian depan website dengan URL seperti <code>https://jyavani.com/{slug}/</code>. Tampilan frontend bergantung pada theme yang aktif. Secara default, Jyavani CMS menyediakan theme yang bersih dan responsif, sehingga artikel Anda akan terlihat baik di desktop maupun perangkat mobile.</p>

<h2>Langkah Selanjutnya</h2>
<p>Setelah membuat artikel pertama, ada banyak fitur menarik yang bisa Anda jelajahi lebih dalam:</p>

<ul>
  <li><strong>Manajemen Kategori</strong> — buat kategori untuk mengelompokkan artikel berdasarkan topik.</li>
  <li><strong>Media Library</strong> — upload gambar, dokumen, dan file lain untuk digunakan di artikel.</li>
  <li><strong>Menu Manager</strong> — atur navigasi website agar pengunjung mudah menemukan halaman penting.</li>
  <li><strong>Theme Customizer</strong> — ubah warna, logo, layout, dan tampilan website sesuai brand Anda.</li>
  <li><strong>Settings Dashboard</strong> — konfigurasi judul website, URL, bahasa, sidebar, dan pengaturan autentikasi.</li>
  <li><strong>Plugin System</strong> — install atau buat plugin untuk menambahkan fitur tanpa mengubah core CMS.</li>
  <li><strong>User Management</strong> — tambahkan editor atau author jika website dikelola oleh banyak orang.</li>
</ul>

<h2>Tips untuk Pengguna Baru</h2>
<ul>
  <li><strong>Jangan takut bereksperimen dengan draft</strong> — gunakan status Draft untuk mencoba format dan konten tanpa memengaruhi website publik.</li>
  <li><strong>Pelajari penggunaan slug dan SEO</strong> — URL yang bersih dan deskriptif akan membantu artikel ditemukan di mesin pencari.</li>
  <li><strong>Gunakan kategori sejak awal</strong> — struktur kategori yang baik akan memudahkan organisasi konten jika website terus bertumbuh.</li>
  <li><strong>Rutin backup</strong> — lakukan backup database dan file upload secara berkala untuk menjaga keamanan data.</li>
  <li><strong>Baca dokumentasi dan artikel tutorial</strong> — Jyavani CMS menyediakan serangkaian artikel tutorial yang membahas setiap fitur secara detail.</li>
  <li><strong>Ubah admin path dari default</strong> — langkah keamanan sederhana yang sebaiknya dilakukan sejak awal.</li>
</ul>

<h2>Kesimpulan</h2>
<p>Memulai dengan Jyavani CMS sangat mudah. Setelah login, Anda langsung bisa membuat artikel, mengatur kategori, dan menjelajahi fitur-fitur utama. Dengan struktur file yang sederhana dan dashboard yang intuitif, Jyavani CMS cocok untuk pemula yang ingin memiliki website profesional tanpa harus mempelajari framework yang kompleks. Selamat mengelola konten dan membangun website impian Anda!</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/memulai-jyavani-thumb.jpg', 'published', 1, '2026-07-25 00:09:41', '2026-07-25 08:20:11', 0, NULL, 0),
(289, 'Private Files dan Media — Aset Digital dengan Akses Terbatas', 'private-files-dan-media', '<p>Tidak semua konten website boleh dilihat oleh publik. Ada banyak situasi di mana Anda perlu membatasi akses file — misalnya dokumen internal perusahaan, materi kursus berbayar, file hasil download untuk member, atau gambar pribadi yang hanya boleh diakses oleh pengguna tertentu. Jyavani CMS menyediakan fitur <strong>Private Files</strong> dan <strong>Private Media</strong> yang memungkinkan Anda mengupload aset digital dengan akses terbatas.</p>

<h2>Perbedaan Private Files dan Private Media</h2>
<p>Jyavani CMS memisahkan pengelolaan file dan media untuk memberikan fleksibilitas yang lebih besar. Berikut perbedaan utama keduanya:</p>

<ul>
  <li><strong>Private Files</strong> — dikelola melalui menu <strong>Files</strong> di dashboard. Cocok untuk dokumen, PDF, arsip, spreadsheet, atau file yang tidak perlu ditampilkan langsung di konten artikel. File disimpan di private disk dan diakses melalui URL dengan token atau kondisi akses tertentu.</li>
  <li><strong>Private Media</strong> — dikelola melalui <strong>Media Library</strong>. Cocok untuk gambar, video, atau audio yang mungkin ingin Anda sisipkan di artikel namun hanya boleh dilihat oleh pengguna tertentu, seperti member berlangganan atau user yang sudah login.</li>
</ul>

<p>Keduanya mendukung pengaturan visibility <strong>private</strong>, sehingga file tidak bisa diakses hanya dengan mengetahui URL publiknya. Sistem akan memeriksa apakah pengguna memiliki hak akses sebelum mengirimkan file ke browser.</p>

<h2>Private Files — Kelola Dokumen Terproteksi</h2>
<p>Untuk mengelola file private, buka <strong>Dashboard → Files</strong>. Di halaman ini Anda bisa melihat daftar file yang sudah diupload beserta status, tanggal upload, dan ukuran file. Anda juga bisa beralih antara tab list dan tab upload untuk menambahkan file baru.</p>

<img src="/static/img/2026/07/files-list-screenshot.jpg" alt="Private Files list page showing uploaded files with visibility status" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Ketika mengupload file, pastikan Anda memilih:</p>

<ul>
  <li><strong>Visibility: Private</strong> — agar file tidak bisa diakses publik</li>
  <li><strong>Title dan Description</strong> — untuk memudahkan identifikasi file di masa depan</li>
  <li><strong>Access Scope</strong> — siapa saja yang boleh mengakses file, misalnya <code>logged_in</code>, <code>author</code>, <code>admin</code>, atau role tertentu</li>
</ul>

<h2>Private Media — Gambar dan Aset Visual Terbatas</h2>
<p>Media Library di Jyavani CMS tidak hanya untuk file publik. Setiap media yang diupload juga bisa diatur visibility-nya menjadi private. Fitur ini berguna untuk website membership, kursus online, galeri pribadi, atau konten premium yang hanya boleh diakses oleh pengguna berlangganan.</p>

<img src="/static/img/2026/07/media-list-screenshot.jpg" alt="Media Library list page showing visibility column for private and public media" style="width:100%;border-radius:8px;margin:16px 0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">

<p>Kolom visibility di Media Library memudahkan Anda untuk membedakan mana aset yang publik dan mana yang private hanya dengan sekali lihat. Anda juga bisa mengubah visibility media kapan saja tanpa harus mengupload ulang file.</p>

<h2>Cara Mengupload Private Files dan Media</h2>
<p>Proses upload private file atau media hampir sama dengan upload publik. Perbedaan utama ada pada pengaturan visibility:</p>

<ol>
  <li>Buka menu <strong>Files</strong> atau <strong>Media Library</strong> dari sidebar dashboard</li>
  <li>Pilih tab <strong>Upload</strong> atau klik tombol upload</li>
  <li>Pilih file dari komputer atau drag &amp; drop ke area upload</li>
  <li>Isi metadata seperti title, alt text, dan description</li>
  <li>Atur <strong>Visibility menjadi Private</strong></li>
  <li>Pilih <strong>Access Scope</strong> yang sesuai</li>
  <li>Simpan atau upload</li>
</ol>

<h2>Access Scope dan Hak Akses</h2>
<p>Jyavani CMS memungkinkan Anda mengontrol siapa yang boleh mengakses file private dengan beberapa tingkat akses. Beberapa contoh scope yang umum digunakan:</p>

<ul>
  <li><code>public</code> — file bisa diakses siapa saja, tanpa login</li>
  <li><code>logged_in</code> — hanya user yang sudah login, tanpa memandang role</li>
  <li><code>author</code> — hanya author dari konten terkait atau user yang memiliki relasi tertentu</li>
  <li><code>admin</code> — hanya admin yang bisa mengakses</li>
  <li><code>role:editor</code> — hanya user dengan role editor ke atas</li>
</ul>

<p>Pemilihan access scope yang tepat sangat penting untuk menjaga keamanan konten. Jika file hanya untuk internal, gunakan scope admin atau role yang spesifik. Jika untuk konten premium, gunakan logged_in atau role member.</p>

<h2>Menggunakan Private Files di Artikel atau Halaman</h2>
<p>Untuk menyisipkan private file atau media ke dalam artikel, Anda bisa menggunakan shortcode yang disediakan CMS. Shortcode akan menghasilkan link download yang aman dengan token, atau embed media yang hanya bisa di-render jika pengguna memiliki akses.</p>

<pre>&lt;code&gt;[private_pdf id="123"]&lt;/code&gt; — tampilkan link download PDF private
&lt;code&gt;[private_image id="456"]&lt;/code&gt; — tampilkan gambar private dengan proteksi</pre>

<p>Shortcode ini akan mengecek status login dan role pengunjung sebelum menampilkan file. Jika pengunjung tidak memiliki akses, maka shortcode tidak akan menampilkan apapun atau menampilkan pesan bahwa konten memerlukan akses khusus.</p>

<h2>Keamanan Private Storage</h2>
<p>File private di Jyavani CMS disimpan di lokasi yang berbeda dari file publik. Beberapa aspek keamanannya meliputi:</p>

<ul>
  <li><strong>Private disk</strong> — file private disimpan di luar web root atau di folder yang dilindungi, sehingga tidak bisa diakses langsung melalui URL publik</li>
  <li><strong>Tokenized access</strong> — jika URL file perlu dibagikan, CMS bisa menghasilkan URL dengan token yang kadaluwarsa</li>
  <li><strong>Session validation</strong> — setiap akses file private diperiksa melawan session dan role pengguna</li>
  <li><strong>No directory listing</strong> — folder private tidak memiliki index listing, sehingga penyerang tidak bisa melihat daftar file dengan mudah</li>
</ul>

<h2>Situasi Penggunaan Private Files dan Media</h2>
<p>Fitur private files dan media sangat cocok untuk berbagai skenario:</p>

<ul>
  <li><strong>Website membership</strong> — e-book, video tutorial, atau materi kursus hanya untuk member berbayar</li>
  <li><strong>Portal karyawan</strong> — dokumen internal, SOP, atau laporan yang hanya untuk karyawan</li>
  <li><strong>Galeri pribadi atau klien</strong> — foto atau video yang hanya boleh dilihat klien atau keluarga tertentu</li>
  <li><strong>Konten premium</strong> — file bonus, cheat sheet, atau template yang hanya untuk subscriber</li>
  <li><strong>Arsip rahasia</strong> — dokumen legal, kontrak, atau data sensitif yang memerlukan kontrol akses ketat</li>
</ul>

<h2>Best Practices Private Files dan Media</h2>
<ul>
  <li><strong>Selalu set visibility private untuk konten sensitif</strong> — jangan andalkan URL yang tidak dipublikasikan sebagai satu-satunya proteksi</li>
  <li><strong>Pilih access scope minimal yang diperlukan</strong> — gunakan scope paling ketat yang masih memenuhi kebutuhan akses</li>
  <li><strong>Gunakan HTTPS</strong> — pastikan website berjalan di HTTPS agar file tidak diintercept saat transit</li>
  <li><strong>Review daftar file private secara berkala</strong> — hapus file yang sudah tidak diperlukan untuk mengurangi exposure</li>
  <li><strong>Backup private files</strong> — private disk juga perlu dibackup karena berisi data penting</li>
  <li><strong>Hindari membagikan URL private langsung</strong> — gunakan fitur token atau shortcode untuk akses yang lebih terkontrol</li>
</ul>

<h2>Kesimpulan</h2>
<p>Jyavani CMS memberikan kontrol penuh atas aset digital Anda melalui fitur Private Files dan Private Media. Dengan pengaturan visibility private dan access scope yang fleksibel, Anda bisa membatasi akses file sesuai kebutuhan. Fitur ini membuat Jyavani CMS tidak hanya cocok untuk blog publik, tetapi juga untuk website internal, membership, atau organisasi yang menangani data sensitif.</p>

<hr>
<p><em>Artikel ini adalah bagian dari dokumentasi Jyavani CMS.</em></p>
', 'article', NULL, NULL, '/static/img/2026/07/private-files-thumb.jpg', 'published', 1, '2026-07-25 09:01:03', '2026-07-25 09:01:03', 0, NULL, 0);

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
(78, '/static/img/2026/07/menu-manager-screenshot.jpg', 'menu-manager-screenshot.png', 'image/png', 'png', 143620, 1280, 1190, 'Menu Manager Screenshot', 'Menu Manager list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 11:05:59', NULL, NULL, NULL),
(79, '/static/img/2026/07/menu-manager-items-screenshot.jpg', 'menu-manager-items-screenshot.png', 'image/png', 'png', 59866, 1280, 1190, 'Menu Items Screenshot', 'Menu items list screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 11:05:59', NULL, NULL, NULL),
(80, '/static/img/2026/07/theme-customizer-menu-screenshot.jpg', 'theme-customizer-menu-screenshot.png', 'image/png', 'png', 242101, 1280, 1763, 'Theme Customizer Menu Screenshot', 'Theme Customizer menu assignment screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 11:05:59', NULL, NULL, NULL),
(81, '/static/img/2026/07/theme-system-thumb.jpg', 'theme-system-thumb.png', 'image/png', 'png', 358203, 1024, 559, 'Theme System Thumbnail', 'Thumbnail for Theme System article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 17:36:22', NULL, NULL, NULL),
(82, '/static/img/2026/07/themes-list-screenshot.jpg', 'themes-list-screenshot.png', 'image/png', 'png', 102667, 1280, 1190, 'Themes List Screenshot', 'Theme Manager list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 17:36:22', '2026-07-24 17:36:57', NULL, NULL),
(84, '/static/img/2026/07/theme-customizer-screenshot.jpg', 'theme-customizer-screenshot.png', 'image/png', 'png', 101093, 1280, 800, 'Theme Customizer Screenshot', 'Theme Customizer interface screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 17:36:22', '2026-07-24 17:36:57', NULL, NULL),
(85, '/static/img/2026/07/plugin-system-thumb.jpg', 'plugin-system-thumb.png', 'image/png', 'png', 1652112, 1402, 1122, 'Plugin System Thumbnail', 'Thumbnail for Plugin System article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 18:43:04', NULL, NULL, NULL),
(86, '/static/img/2026/07/plugin-manager-screenshot.jpg', 'plugin-manager-screenshot.png', 'image/png', 'png', 116736, 1280, 1190, 'Plugin Manager Screenshot', 'Plugin Manager list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 18:43:04', '2026-07-24 18:43:15', NULL, NULL),
(88, '/static/img/2026/07/plugin-store-screenshot.jpg', 'plugin-store-screenshot.png', 'image/png', 'png', 120832, 1280, 1190, 'Plugin Store Screenshot', 'Plugin Store browse page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 18:43:04', '2026-07-24 18:43:15', NULL, NULL),
(89, '/static/img/2026/07/settings-dashboard-thumb.jpg', 'settings-dashboard-thumb.png', 'image/png', 'png', 211017, 1024, 559, 'Settings Dashboard Thumbnail', 'Thumbnail for Settings Dashboard article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:00:47', NULL, NULL, NULL),
(90, '/static/img/2026/07/site-settings-screenshot.jpg', 'site-settings-screenshot.png', 'image/png', 'png', 222775, 1280, 1763, 'Site Settings Screenshot', 'Site Settings page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:00:47', '2026-07-24 19:00:56', NULL, NULL),
(92, '/static/img/2026/07/sidebar-settings-screenshot.jpg', 'sidebar-settings-screenshot.png', 'image/png', 'png', 95725, 1280, 1190, 'Sidebar Settings Screenshot', 'Sidebar settings page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:00:47', '2026-07-24 19:00:56', NULL, NULL),
(93, '/static/img/2026/07/user-management-thumb.jpg', 'user-management-thumb.png', 'image/png', 'png', 386066, 1024, 559, 'User Management Thumbnail', 'Thumbnail for User Management article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:07:46', NULL, NULL, NULL),
(94, '/static/img/2026/07/users-list-screenshot.jpg', 'users-list-screenshot.png', 'image/png', 'png', 178536, 1280, 1763, 'Users List Screenshot', 'Users list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:07:46', '2026-07-24 19:07:57', NULL, NULL),
(96, '/static/img/2026/07/add-user-screenshot.jpg', 'add-user-screenshot.png', 'image/png', 'png', 128229, 1280, 1190, 'Add User Screenshot', 'Add user form screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:07:46', '2026-07-24 19:07:57', NULL, NULL),
(97, '/static/img/2026/07/seo-meta-tags-thumb.jpg', 'seo-meta-tags-thumb.png', 'image/png', 'png', 427001, 1024, 559, 'SEO Meta Tags Thumbnail', 'Thumbnail for SEO article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:16:07', NULL, NULL, NULL),
(98, '/static/img/2026/07/meta-tags-screenshot.jpg', 'meta-tags-screenshot.png', 'image/png', 'png', 521216, 1280, 1763, 'Meta Tags Screenshot', 'Meta tags field in post editor', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:16:07', '2026-07-24 19:16:16', NULL, NULL),
(100, '/static/img/2026/07/sitemap-screenshot.jpg', 'sitemap-screenshot.png', 'image/png', 'png', 156672, 1280, 800, 'Sitemap Screenshot', 'sitemap.xml preview', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 19:16:07', '2026-07-24 19:16:16', NULL, NULL),
(101, '/static/img/2026/07/widget-shortcodes-thumb.jpg', 'widget-shortcodes-thumb.png', 'image/png', 'png', 370305, 1024, 559, 'Widget dan Shortcodes Thumbnail', 'Thumbnail for Widget dan Shortcodes article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:33:31', NULL, NULL, NULL),
(102, '/static/img/2026/07/shortcode-builder-screenshot.jpg', 'shortcode-builder-screenshot.png', 'image/png', 'png', 239616, 1280, 1763, 'Shortcode Builder Screenshot', 'Shortcode builder interface', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:33:31', '2026-07-24 23:33:39', NULL, NULL),
(104, '/static/img/2026/07/widget-settings-screenshot.jpg', 'widget-settings-screenshot.png', 'image/png', 'png', 241664, 1280, 1763, 'Widget Settings Screenshot', 'Widget settings page', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:33:31', '2026-07-24 23:33:39', NULL, NULL),
(105, '/static/img/2026/07/keamanan-cms-thumb.jpg', 'keamanan-cms-thumb.png', 'image/png', 'png', 560233, 1024, 559, 'Keamanan CMS Thumbnail', 'Thumbnail for Keamanan CMS article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:45:59', NULL, NULL, NULL),
(106, '/static/img/2026/07/auth-settings-screenshot.jpg', 'auth-settings-screenshot.png', 'image/png', 'png', 192775, 1280, 1190, 'Auth Settings Screenshot', 'Auth settings page with custom admin path', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:45:59', '2026-07-24 23:46:08', NULL, NULL),
(108, '/static/img/2026/07/login-page-screenshot.jpg', 'login-page-screenshot.png', 'image/png', 'png', 178536, 1280, 1763, 'Login Page Screenshot', 'Custom login page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-24 23:45:59', '2026-07-24 23:46:08', NULL, NULL),
(109, '/static/img/2026/07/sistem-soft-delete-thumb.jpg', 'sistem-soft-delete-thumb.png', 'image/png', 'png', 208635, 1024, 559, 'Sistem Soft Delete Thumbnail', 'Thumbnail for Sistem Soft Delete article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:03:17', NULL, NULL, NULL),
(110, '/static/img/2026/07/bin-screenshot.jpg', 'bin-screenshot.png', 'image/png', 'png', 162008, 1905, 1190, 'Recycle Bin Screenshot', 'Thumbnail for Sistem Soft Delete article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:03:17', '2026-07-25 08:01:27', NULL, NULL),
(111, '/static/img/2026/07/sistem-reinstall-update-thumb.jpg', 'sistem-reinstall-update-thumb.png', 'image/png', 'png', 0, 1024, 559, 'Sistem Reinstall Update Thumbnail', 'Thumbnail for Sistem Reinstall dan Update article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:06:50', NULL, NULL, NULL),
(112, '/static/img/2026/07/update-page-screenshot.jpg', 'update-page-screenshot.png', 'image/png', 'png', 240434, 1905, 1190, 'Update Page Screenshot', 'Update and maintenance page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:06:50', '2026-07-25 08:01:27', NULL, NULL),
(113, '/static/img/2026/07/memulai-jyavani-thumb.jpg', 'memulai-jyavani-thumb.png', 'image/png', 'png', 0, 1024, 559, 'Memulai dengan Jyavani Thumbnail', 'Thumbnail for Memulai dengan Jyavani article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:09:41', NULL, NULL, NULL),
(114, '/static/img/2026/07/dashboard-screenshot.jpg', 'dashboard-screenshot.png', 'image/png', 'png', 150802, 1911, 923, 'Dashboard Screenshot', 'Jyavani CMS dashboard screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:09:41', '2026-07-25 08:01:27', NULL, NULL),
(115, '/static/img/2026/07/add-post-screenshot.jpg', 'add-post-screenshot.png', 'image/png', 'png', 151108, 1905, 1414, 'Add Post Screenshot', 'Add new post form screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 00:09:41', '2026-07-25 08:01:27', NULL, NULL),
(117, '/static/img/2026/07/private-files-thumb.jpg', 'private-files-thumb.png', 'image/png', 'png', 0, 1536, 1024, 'Private Files Thumbnail', 'Thumbnail for Private Files and Media article', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:01:03', NULL, NULL, NULL),
(118, '/static/img/2026/07/files-list-screenshot.jpg', 'files-list-screenshot.png', 'image/png', 'png', 0, 1905, 1190, 'Private Files List Screenshot', 'Private Files list page screenshot', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:01:03', NULL, NULL, NULL),
(119, '/static/img/2026/07/media-list-screenshot.jpg', 'media-list-screenshot.png', 'image/png', 'png', 0, 1905, 1190, 'Media List Screenshot', 'Media Library list page with visibility column', NULL, NULL, 'public', 'public', NULL, 'public', 1, 1, '2026-07-25 09:01:03', NULL, NULL, NULL),
(120, '/static/img/2026/07/media-library-thumb-gen.jpg', 'media-library-thumb-gen.png', 'image/png', 'png', 369059, 1024, 559, 'Media Library Thumbnail', 'Thumbnail for Media Library article', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/media-library-thumb-gen.jpg', 'public', 1, 1, '2026-07-25 09:22:03', '2026-07-25 09:22:03', NULL, NULL),
(121, '/static/img/2026/07/user-profile-screenshot.jpg', 'user-profile-screenshot.png', 'image/png', 'png', 128229, 1274, 1190, 'User Profile Screenshot', 'User Profile Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/user-profile-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(122, '/static/img/2026/07/og-tags-screenshot.jpg', 'og-tags-screenshot.png', 'image/png', 'png', 152524, 1280, 720, 'OG Tags Screenshot', 'OG Tags Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/og-tags-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(123, '/static/img/2026/07/permalink-settings-screenshot.jpg', 'permalink-settings-screenshot.png', 'image/png', 'png', 95725, 1274, 1190, 'Permalink Settings Screenshot', 'Permalink Settings Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/permalink-settings-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(124, '/static/img/2026/07/plugin-upload-screenshot.jpg', 'plugin-upload-screenshot.png', 'image/png', 'png', 118398, 1274, 1190, 'Plugin Upload Screenshot', 'Plugin Upload Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/plugin-upload-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(125, '/static/img/2026/07/layout-editor-screenshot.jpg', 'layout-editor-screenshot.png', 'image/png', 'png', 241444, 1274, 1749, 'Layout Editor Screenshot', 'Layout Editor Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/layout-editor-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL),
(126, '/static/img/2026/07/user-roles-screenshot.jpg', 'user-roles-screenshot.png', 'image/png', 'png', 178536, 1274, 1190, 'User Roles Screenshot', 'User Roles Screenshot', NULL, NULL, 'public', 'public', 'public/static/img/2026/07/user-roles-screenshot.jpg', 'public', 1, 1, '2026-07-25 09:29:42', '2026-07-25 09:29:42', NULL, NULL);

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
(289, 2, 1, '2026-07-25 09:07:48');

-- Demo shortcode preset: random demo posts by author 1
INSERT INTO `posts` (`id`, `title`, `slug`, `content`, `type`, `meta`, `youtube`, `thumbnail`, `status`, `created_by`, `created_at`, `updated_at`, `sort_order`, `deleted_at`, `is_deleted`) VALUES
(300, 'Demo Random Posts Preset', 'demo_random_posts', '', 'sc_preset', '{"source":"posts","type":"article","category":"","author":"1","order_by":"RAND()","order_dir":"DESC","limit":"4","layout":"mini","kicker":"Pilihan","excerpt_len":"0","wrap":"1"}', NULL, NULL, 'published', 1, '2026-07-25 09:07:48', '2026-07-25 09:07:48', 0, NULL, 0);

-- Demo sidebar widget using the preset above
INSERT IGNORE INTO `sidebar_zone_items` (`zone_id`, `type`, `title`, `config`, `ordering`, `active`) VALUES
(3, 'shortcode_preset', 'Pilihan Demo', '{"preset_slug":"demo_random_posts"}', 2, 1);

SET FOREIGN_KEY_CHECKS=1;
