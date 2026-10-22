<?php
/**
 * Template dự phòng cuối cùng của thứ bậc template: dùng khi theme không có tệp cụ thể hơn.
 * Thứ bậc (tệp đầu tiên tồn tại sẽ được dùng):
 *   bài viết      single-{slug}.php → single.php → singular.php → index.php
 *   trang         mẫu trang đã chọn → page-{slug}.php → page-{id}.php → page.php → singular.php → index.php
 *   chuyên mục    category-{slug}.php → category-{id}.php → category.php → archive.php → index.php
 *   thẻ           tag-{slug}.php → tag-{id}.php → tag.php → archive.php → index.php
 *   taxonomy      taxonomy-{tax}-{slug}.php → taxonomy-{tax}.php → taxonomy.php → archive.php → index.php
 *   tác giả       author-{username}.php → author-{id}.php → author.php → archive.php → index.php
 *   theo ngày     date.php → archive.php → index.php
 *   trang chủ     front-page.php → home.php → index.php
 *   tìm kiếm      search.php → index.php          404   404.php → index.php
 */
defined('LQ_ROOT') || exit;
get_header();
?>
<div class="wrap">
  <?php if (is_singular()): ?>
    <?php while (have_posts()): the_post(); ?>
      <article class="entry"><h1 class="entry-title"><?php the_title(); ?></h1><div class="prose"><?php the_content(); ?></div></article>
    <?php endwhile; ?>
  <?php else: ?>
    <header class="archive-head"><div><h1 class="archive-title"><?= e(is_404() ? 'Không tìm thấy trang' : get_the_archive_title()) ?></h1></div></header>
    <?php get_template_part('parts/loop-grid'); ?>
  <?php endif; ?>
</div>
<?php get_footer(); ?>
