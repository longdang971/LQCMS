<?php defined('LQ_ROOT') || exit; ?>
<aside class="sidebar">
  <section class="side-box">
    <h2 class="side-title">Chuyên mục</h2>
    <ul class="term-list">
      <?php foreach (get_terms('category') as $cat): ?>
        <li<?= is_category((int) $cat['id']) ? ' class="current"' : '' ?>>
          <a href="<?= e($cat['url']) ?>"><span class="cat-dot" style="--c:<?= identity_color((int) $cat['id']) ?>"></span><?= e($cat['name']) ?></a>
          <span class="count"><?= $cat['count'] ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="side-box">
    <h2 class="side-title">Bài mới</h2>
    <?php $recent = lq_query(['per_page' => 4, 'exclude' => is_single() ? [get_queried_object_id()] : []]); ?>
    <?php $i = 0; while ($recent->have_posts()): $recent->the_post(); ?>
      <?php get_template_part('parts/card-row', null, ['index' => ++$i]); ?>
    <?php endwhile; lq_reset_postdata(); ?>
  </section>

  <?php if ($tags = get_terms('tag', ['orderby' => 'count', 'number' => 15])): ?>
    <section class="side-box">
      <h2 class="side-title">Thẻ</h2>
      <div class="tag-cloud">
        <?php foreach ($tags as $t): ?>
          <a class="tag<?= is_tag((int) $t['id']) ? ' current' : '' ?>" href="<?= e($t['url']) ?>">#<?= e($t['name']) ?> <span><?= $t['count'] ?></span></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</aside>
