<?php
/** Thẻ bài trong lưới. Dùng trong vòng lặp: get_template_part('parts/card'). */
defined('LQ_ROOT') || exit;
?>
<article<?php post_class('card'); ?>>
  <a class="card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?= lqd_thumb() ?></a>
  <div class="card-body">
    <div class="card-top"><?= lqd_primary_category() ?><span class="muted"><?= e(lqd_time_ago()) ?></span></div>
    <h3 class="card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
    <p class="card-excerpt"><?php the_excerpt(); ?></p>
    <div class="card-foot">
      <?= avatar(get_the_author(), 'sm') ?>
      <span><?php the_author(); ?></span>
      <span class="dot" aria-hidden="true"></span>
      <span class="muted"><?= get_reading_time() ?> phút đọc</span>
    </div>
  </div>
</article>
