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
<style>
/* Minimal styling khusus default theme (boleh pindah ke assets/css) */
.sidebar-wrap{display:flex;flex-direction:column;gap:14px}
.w-box{
  background:#fff;border:1px solid #e7e7e7;border-radius:12px;
  padding:14px 14px;
}
.w-title{font-weight:700;margin-bottom:10px}
.w-empty{color:#666;font-size:.92rem}
.w-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px}
.w-list a{text-decoration:none;color:inherit}
.w-list a:hover{text-decoration:underline}
.w-search-form{display:flex;gap:8px}
.w-search-form input{
  flex:1;border:1px solid #ddd;border-radius:10px;padding:10px 12px;outline:none;
}
.w-search-form button{
  border:1px solid #ddd;background:#f5f5f5;border-radius:10px;padding:10px 14px;cursor:pointer;
}
</style>

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
