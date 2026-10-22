<?php
/** Kết quả tìm kiếm: /?s=tu-khoa (tìm cả bài viết và trang). */
defined('LQ_ROOT') || exit;
get_header();
$q = lq_main_query();
?>
<div class="wrap-narrow search-page">
  <span class="eyebrow">Tìm kiếm</span>
  <h1 class="archive-title">“<?php the_search_query(); ?>”</h1>
  <?php get_search_form(); ?>
  <p class="archive-count"><strong><?= $q->found ?></strong> kết quả<?php if (is_paged()): ?> · trang <?= $q->page ?>/<?= $q->maxPages ?><?php endif; ?></p>

  <?php if (have_posts()): ?>
    <div class="result-list">
      <?php while (have_posts()): the_post(); ?>
        <article<?php post_class('result'); ?>>
          <a class="row-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?= lqd_thumb(null, 'thumb-sm') ?></a>
          <div>
            <div class="card-top">
              <?= get_post()['type'] === 'page' ? '<span class="chip">Trang</span>' : lqd_primary_category() ?>
              <span class="muted small"><?php the_date(); ?></span>
            </div>
            <h2 class="row-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
            <p class="muted"><?php the_excerpt(24); ?></p>
          </div>
        </article>
      <?php endwhile; ?>
    </div>
    <?php the_posts_pagination(); ?>
  <?php else: ?>
    <div class="empty"><p>Không có kết quả nào. Thử từ khoá ngắn hơn, hoặc xem các chuyên mục bên dưới.</p>
      <div class="tag-cloud"><?php foreach (get_terms('category') as $c): ?><a class="tag" href="<?= e($c['url']) ?>"><?= e($c['name']) ?></a><?php endforeach; ?></div>
    </div>
  <?php endif; ?>
</div>
<?php get_footer(); ?>
