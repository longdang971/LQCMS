<?php
/** Ô lớn trên trang chủ: ảnh tràn, chữ đè lên. */
defined('LQ_ROOT') || exit;
?>
<article<?php post_class('tile tile-hero'); ?>>
  <?= lqd_thumb(null, 'hero-img') ?>
  <div class="hero-shade" aria-hidden="true"></div>
  <div class="hero-body">
    <div class="card-top"><?= lqd_primary_category() ?><?php if (get_field('featured')): ?><span class="chip chip-accent">Nổi bật</span><?php endif; ?></div>
    <h2 class="hero-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
    <p class="hero-excerpt"><?php the_excerpt(); ?></p>
    <div class="card-foot"><?= avatar(get_the_author(), 'sm') ?><span><?php the_author(); ?></span><span class="dot" aria-hidden="true"></span><time datetime="<?= e(get_the_date('c')) ?>"><?php the_date(); ?></time></div>
  </div>
</article>
