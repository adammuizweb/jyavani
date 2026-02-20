<?php
// /adiwira/theme/adam/part/details.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}

$requested = (string)($_GET['page'] ?? 'home');
$requested = trim($requested, "/ \t\n\r\0\x0B");
?>

<aside id="adam-panel" class="adam-panel" role="complementary">
    
    <div id="adam-panel-resizer" class="adam-panel-resizer"></div>

    <div class="adam-panel-body">
        <?php 
        // Ambil ID tema dari URL utama
        $theme_id = (int)($_GET['id'] ?? 0);
        $is_theme_editor = ($requested === 'admin/themes/edit' && $theme_id > 0);
        ?>

        <?php if ($is_theme_editor): ?>
            
            <div style="
                display: flex; 
                flex-direction: column; 
                height: 100%;
            ">
                <h3 style="margin-top:0; margin-bottom: 12px; padding: 0 12px;">
                    Theme Editor (ID: <?= $theme_id ?>)
                </h3>

                <div style="padding: 0 12px; flex-grow:1; border:1px solid #eee;">
                    <p style="margin:0; padding:12px;">
                        Live preview dinonaktifkan. Simpan perubahan lalu buka tampilan situs untuk melihat hasilnya.
                    </p>
                    <!-- Di sini bisa ditaruh kontrol editor: tombol simpan, tombol buka di tab baru, dll. -->
                </div>
            </div>

        <?php else: ?>

            <?php if (strpos($requested, 'admin/posts') === 0): ?>
                <h3>Posts</h3>
                <p>
                    Posts digunakan untuk mempublikasikan <strong>artikel dinamis</strong> seperti berita, kegiatan,
                    agenda, pengumuman, dan konten informatif lainnya.  
                    Semua tulisan diurutkan berdasarkan tanggal, dapat diberi kategori, serta dapat dipublikasikan
                    atau disimpan sebagai draft.
                </p>

            <?php elseif (strpos($requested, 'admin/pages') === 0): ?>
                <h3>Pages</h3>
                <p>
                    Pages berfungsi untuk membuat <strong>halaman statis</strong> seperti Profil, Visi Misi, Tentang Kami,
                    dan Halaman Kontak.  
                    Berbeda dengan posts, pages tidak berbasis tanggal dan biasanya dipakai untuk konten yang
                    permanen atau jarang berubah.
                </p>

            <?php elseif (strpos($requested, 'admin/categories') === 0): ?>
                <h3>Categories</h3>
                <p>
                    Category digunakan untuk membuat <strong>label atau kelompok topik</strong> yang nantinya dapat dipakai
                    untuk menyusun dan memfilter artikel atau program.  
                    Kategori membantu pengunjung menemukan konten yang relevan sesuai minat mereka.
                </p>

            <?php elseif (strpos($requested, 'admin/themes') === 0): ?>
                <h3>Themes</h3>
                <p>
                    Themes digunakan untuk membuat atau mengedit <strong>partial tema</strong> menggunakan HTML, CSS,
                    dan JavaScript.  
                    Menu ini ditujukan untuk user yang memahami dasar-dasar frontend agar dapat merancang tampilan
                    website sesuai kebutuhan.
                </p>

            <?php elseif ($requested === 'home'): ?>
                <h3>Informasi</h3>
                <p>Selamat datang di panel kontrol. Pilih menu di samping untuk mulai mengelola konten.</p>

            <?php endif; ?>

            <section class="panel-info">
                <p>Panel ini menampilkan informasi konteks sesuai menu yang sedang dibuka.</p>
            </section>

        <?php endif; ?>


    </div>
</aside>
