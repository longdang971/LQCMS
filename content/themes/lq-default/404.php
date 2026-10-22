<?php
/** Không tìm thấy trang (HTTP 404). */
defined('LQ_ROOT') || exit;
get_header();
?>
<div class="wrap-narrow error-page">
  <p class="error-code" aria-hidden="true">404</p>
  <h1 class="archive-title">Không tìm thấy trang</h1>
  <p class="muted">Đường dẫn <code><?= e(request_path()) ?></code> không tồn tại hoặc đã bị xoá. Thử tìm bài bạn cần:</p>
  <?php get_search_form(); ?>
  <div class="section-head"><h2>Bài mới</h2></div>
  <?php $recent = lq_query(['per_page' => 4]); ?>
  <div class="result-list">
    <?php while ($recent->have_posts()): $recent->the_post(); get_template_part('parts/card-row'); endwhile; lq_reset_postdata(); ?>
  </div>
</div>
<?php get_footer(); ?>
