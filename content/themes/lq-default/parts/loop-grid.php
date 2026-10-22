<?php
/** Lưới bài của truy vấn chính + phân trang. Dùng chung cho mọi trang danh sách. */
defined('LQ_ROOT') || exit;
?>
<?php if (have_posts()): ?>
  <div class="grid-cards" data-posts-grid>
    <?php while (have_posts()): the_post(); ?>
      <?php get_template_part('parts/card'); ?>
    <?php endwhile; ?>
  </div>
  <?php get_template_part('parts/load-more', null, ['pagination' => ['prev_text' => '← Trang trước', 'next_text' => 'Trang sau →']]); ?>
<?php else: ?>
  <div class="empty">
    <p><?= e($args['empty'] ?? 'Chưa có bài viết nào ở đây.') ?></p>
    <a class="btn btn-ghost" href="<?= e(home_url('/')) ?>">Về trang chủ</a>
  </div>
<?php endif; ?>
