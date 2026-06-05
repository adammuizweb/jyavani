<?php
// Sidebar default theme
// Memakai widget_helper.php (widget / render_widget)

// =============================
// KILL SWITCH (praktis)
// Kalau mau MATIKAN SIDEBAR tanpa ubah-ubah banyak,
// cukup UNCOMMENT baris return di bawah.
// =============================
// return;

// Optional: matikan via query param ?nosidebar=1 (tanpa edit kode)
if (isset($_GET['nosidebar']) && $_GET['nosidebar'] === '1') {
    return;
}

// Safety: kalau helper widget belum keload, jangan fatal
if (!function_exists('widget')) {
    return;
}
?>
<div class="sidebar-wrap">
  <?php
  // Kalau mau “matikan widget” satu blok, tinggal comment blok ini pakai /* ... */
  echo widget('search_form', ['placeholder' => 'Cari artikel...']);
  echo widget('recent_posts', ['title' => 'Artikel Terbaru', 'limit' => 6]);
  echo widget('categories_list', ['title' => 'Kategori', 'limit' => 30, 'only_parents' => true]);
/*  echo widget('pages_list', ['title' => 'Halaman', 'limit' => 20]); */
  echo widget('author_posts', ['title' => 'Artikel Saya', 'limit' => 8]);
  ?>
</div>
