<?php
/** Mẫu trang "Toàn chiều rộng": không cột bên, tiêu đề lớn, nội dung trải rộng (landing page). */
defined('LQ_ROOT') || exit;
get_header();
?>
<?php while (have_posts()): the_post(); ?>
<article<?php post_class('entry entry-wide'); ?>>
  <header class="wide-hero wrap">
    <h1 class="wide-title"><?php the_title(); ?></h1>
    <?php edit_post_link('Sửa trang', '<p class="entry-edit">', '</p>'); ?>
  </header>
  <?php if (has_post_thumbnail()): ?><figure class="entry-cover wrap"><?php the_post_thumbnail(['loading' => 'eager']); ?></figure><?php endif; ?>
  <div class="wrap"><div class="prose prose-wide"><?php the_content(); ?></div></div>
</article>
<?php endwhile; ?>
<?php get_footer(); ?>
