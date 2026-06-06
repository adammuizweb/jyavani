<?php
// /adiwira/theme/adam/part/footer.php
if (!defined('ADAM_THEME')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<?php do_action('admin_footer'); ?>
<footer id="adam-footer" class="adam-footer">
  <div class="adam-footer-inner">
    <small>&copy; <?= date('Y') ?> Jyavani CMS — Adam Muiz</small>
  </div>
</footer>

