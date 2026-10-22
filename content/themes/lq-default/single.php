<?php
/** Bài viết đơn. */
defined('LQ_ROOT') || exit;
get_header();
?>
<?php while (have_posts()): the_post(); ?>
<article<?php post_class('entry'); ?>>
  <header class="entry-head wrap-narrow">
    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="<?= e(home_url('/')) ?>">Trang chủ</a>
      <?php foreach (get_the_category() as $cat): ?><span aria-hidden="true">/</span><a href="<?= e($cat['url']) ?>"><?= e($cat['name']) ?></a><?php endforeach; ?>
    </nav>
    <h1 class="entry-title"><?php the_title(); ?></h1>
    <?php if (($sub = (string) get_field('subtitle', '')) !== ''): ?><p class="entry-sub"><?= e($sub) ?></p><?php endif; ?>
    <div class="entry-meta">
      <?= avatar(get_the_author(), '') ?>
      <div>
        <?php the_author_link(); ?>
        <span class="muted small">
          <time datetime="<?= e(get_the_date('c')) ?>"><?php the_date(); ?> lúc <?php the_time(); ?></time>
          · <?= get_reading_time() ?> phút đọc
          <?php if (get_the_modified_date('Y-m-d') !== get_the_date('Y-m-d')): ?>· sửa <?php the_modified_date(); ?><?php endif; ?>
        </span>
      </div>
      <?php edit_post_link('Sửa bài', '<span class="entry-edit">', '</span>'); ?>
    </div>
  </header>

  <?php if (has_post_thumbnail()): ?>
    <figure class="entry-cover wrap"><?php the_post_thumbnail(['loading' => 'eager', 'fetchpriority' => 'high']); ?></figure>
  <?php endif; ?>

  <div class="wrap-narrow">
    <div class="prose"><?php the_content(); ?></div>

    <footer class="entry-foot">
      <?php the_terms('category', '', '<div class="term-row"><span class="term-label">Chuyên mục</span>', '</div>'); ?>
      <?php the_tags('<div class="term-row"><span class="term-label">Thẻ</span>', '', '</div>'); ?>
      <?php the_terms('actor', '', '<div class="term-row"><span class="term-label">Diễn viên</span>', '</div>'); ?>

      <div class="author-box">
        <?= avatar(get_the_author(), 'lg') ?>
        <div>
          <span class="eyebrow">Tác giả</span>
          <h2><a href="<?= e(get_author_posts_url()) ?>"><?php the_author(); ?></a></h2>
          <p class="muted"><?= lq_query(['author' => get_post()['author_id'], 'per_page' => 1])->found ?> bài đã đăng</p>
        </div>
      </div>

      <nav class="post-nav" aria-label="Bài trước và sau">
        <?php if ($prev = get_previous_post()): ?>
          <a class="post-nav-item" href="<?= e(get_permalink($prev)) ?>" rel="prev"><span class="muted small">← Bài trước</span><strong><?= e(get_the_title($prev)) ?></strong></a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if ($next = get_next_post()): ?>
          <a class="post-nav-item is-next" href="<?= e(get_permalink($next)) ?>" rel="next"><span class="muted small">Bài sau →</span><strong><?= e(get_the_title($next)) ?></strong></a>
        <?php endif; ?>
      </nav>
    </footer>
  </div>
</article>

<?php
// Bài liên quan: chấm điểm theo thẻ/chuyên mục/diễn viên dùng chung, thiếu thì bù bài cùng chuyên mục.
$related = get_related_posts(null, 3);
?>
<?php if ($related): ?>
  <section class="wrap related">
    <div class="section-head"><h2>Bài liên quan</h2></div>
    <div class="grid-cards grid-3">
      <?php foreach ($related as $item): setup_postdata($item); ?>
        <?php get_template_part('parts/card'); ?>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; lq_reset_postdata(); ?>
<?php endwhile; ?>
<?php get_footer(); ?>
