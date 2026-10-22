<?php
/** Dòng bài gọn (ảnh nhỏ + tiêu đề). $args['index'] để đánh số nếu cần. */
defined('LQ_ROOT') || exit;
?>
<article<?php post_class('row-card'); ?>>
  <?php if (isset($args['index'])): ?><span class="row-index"><?= sprintf('%02d', $args['index']) ?></span><?php endif; ?>
  <a class="row-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?= lqd_thumb(null, 'thumb-sm') ?></a>
  <div>
    <h3 class="row-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
    <span class="muted small"><?php if (get_post()['type'] === 'page'): ?>Trang · <?php endif; ?><?= e(lqd_time_ago()) ?></span>
  </div>
</article>
