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
                height: 100%; /* PENTING: Mengambil 100% tinggi dari parent .adam-panel-body */
                /* Hapus padding dari .adam-panel-body jika ada konflik, dan tambahkan di sini jika perlu */
            ">
                <h3 style="margin-top:0; margin-bottom: 12px; padding: 0 12px;">Live Theme Preview (ID: <?= $theme_id ?>)</h3>
<iframe 
  id="theme-live-preview"
  src="<?= ADMIN_BASE_PATH ?>/live.php?id=<?= $theme_id ?>" 
  sandbox="allow-same-origin allow-scripts allow-forms"
  style="width:100%; flex-grow:1; border:1px solid #ddd; margin: 0 12px;"
  frameborder="0"
></iframe>
            </div>

<?php else: ?>

    <?php if (strpos($requested, 'admin/posts') === 0): ?>
        <h3>Posts</h3>
        <p>
            <?=_e('Posts are used to publish dynamic articles such as news, activities, agendas, announcements, and other informative content.')?>
            <?=_e('All posts are sorted by date, can be categorized, and can be published or saved as drafts.')?>
        </p>

    <?php elseif (strpos($requested, 'admin/pages') === 0): ?>
        <h3>Pages</h3>
        <p>
            <?=_e('Pages are used to create static pages such as Profile, Vision & Mission, About Us, and Contact Page.')?>
            <?=_e('Unlike posts, pages are not date-based and are typically used for permanent or rarely changed content.')?>
        </p>

    <?php elseif (strpos($requested, 'admin/categories') === 0): ?>
        <h3>Categories</h3>
        <p>
            <?=_e('Categories are used to create labels or topic groups that can be used to organize and filter articles or programs.')?>
            <?=_e('Categories help visitors find relevant content according to their interests.')?>
        </p>

    <?php elseif (strpos($requested, 'admin/program') === 0): ?>
        <h3>Program</h3>
        <p>
            <?=_e('Programs are used to publish learning materials, curriculum, topics, or training modules.')?>
            <?=_e('Suitable for schools, training institutions, and educational applications that need structured content.')?>
        </p>

    <?php elseif (strpos($requested, 'admin/themes') === 0): ?>
        <h3>Themes</h3>
        <p>
            <?=_e('Themes are used to create or edit theme partials using HTML, CSS, and JavaScript.')?>
            <?=_e('This menu is intended for users who understand frontend basics to design the website appearance as needed.')?>
        </p>

    <?php elseif ($requested === 'home'): ?>
        <h3><?=_e('Information')?></h3>
        <p><?=_e('Welcome to the control panel. Select a menu on the side to start managing content.')?></p>

    <?php endif; ?>

    <section class="panel-info">
        <p><?=_e('This panel displays contextual information according to the menu currently being opened.')?></p>
    </section>

<?php endif; ?>


    <?php do_action('admin_details'); ?>
    </div>
</aside>