<?php
/** Trang tác giả: /tac-gia/ten-dang-nhap/ */
defined('LQ_ROOT') || exit;
get_header();
$author = get_queried_object();
?>
<div class="wrap">
  <?php get_template_part('parts/archive-header', null, [
      'eyebrow' => 'Tác giả',
      'title' => $author['display_name'] ?: $author['username'],
      'media' => avatar($author['display_name'] ?: $author['username'], 'xl'),
  ]); ?>
  <div class="layout">
    <div class="layout-main"><?php get_template_part('parts/loop-grid', null, ['empty' => 'Tác giả này chưa đăng bài nào.']); ?></div>
    <?php get_sidebar(); ?>
  </div>
</div>
<?php get_footer(); ?>
