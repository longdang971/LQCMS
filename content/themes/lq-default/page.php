<?php
/** Trang tĩnh (mẫu mặc định). */
defined('LQ_ROOT') || exit;
get_header();
?>
<?php while (have_posts()): the_post(); ?>
<article<?php post_class('entry'); ?>>
  <header class="entry-head wrap-narrow">
    <?php if ($ancestors = get_post_ancestors()): ?>
      <nav class="crumbs" aria-label="Breadcrumb">
        <a href="<?= e(home_url('/')) ?>">Trang chủ</a>
        <?php foreach ($ancestors as $a): ?><span aria-hidden="true">/</span><a href="<?= e(get_permalink($a)) ?>"><?= e(get_the_title($a)) ?></a><?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <h1 class="entry-title"><?php the_title(); ?></h1>
    <?php edit_post_link('Sửa trang', '<p class="entry-edit">', '</p>'); ?>
  </header>
  <?php if (has_post_thumbnail()): ?><figure class="entry-cover wrap"><?php the_post_thumbnail(['loading' => 'eager']); ?></figure><?php endif; ?>
  <div class="wrap-narrow">
    <div class="prose"><?php the_content(); ?></div>

    <?php if ($children = get_pages(['parent' => get_the_ID()])): ?>
      <section class="child-pages">
        <h2 class="side-title">Trong mục này</h2>
        <div class="child-grid">
          <?php foreach ($children as $c): ?>
            <a class="child-card" href="<?= e($c['url']) ?>"><strong><?= e($c['title']) ?></strong><span class="muted small"><?= e(get_the_excerpt($c, 14)) ?></span></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</article>
<?php endwhile; ?>
<?php get_footer(); ?>
