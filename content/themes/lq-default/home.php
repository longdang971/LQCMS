<?php
/**
 * Danh sách bài mới nhất: trang chủ mặc định, hoặc trang được chọn làm "Trang danh sách bài viết".
 * Trang 1 của trang chủ có thêm lưới bento (bài nổi bật, số liệu, chuyên mục…).
 */
defined('LQ_ROOT') || exit;
get_header();

$shown = [];
?>
<div class="wrap">
<?php if (is_front_page() && !is_paged()): ?>
  <?php
  // Bài nổi bật: field "featured" do theme khai báo; chưa đánh dấu bài nào thì lấy bài mới nhất.
  $hero = get_posts(['meta' => ['key' => 'featured', 'value' => true], 'per_page' => 1])[0] ?? (lq_main_query()->posts[0] ?? null);
  $side = $hero ? get_posts(['per_page' => 2, 'exclude' => [$hero['id']]]) : [];
  $shown = array_merge($hero ? [(int) $hero['id']] : [], array_map('intval', array_column($side, 'id')));
  $stats = lqd_site_stats();
  $months = lqd_monthly_counts();
  $peak = max(1, ...array_column($months, 'count'));
  ?>
  <section class="intro">
    <h1 class="intro-title"><?php bloginfo('name'); ?></h1>
    <p class="intro-sub"><?php bloginfo('description'); ?></p>
  </section>

  <?php if ($hero): ?>
  <section class="bento" aria-label="Nổi bật">
    <?php setup_postdata($hero); get_template_part('parts/card-hero'); ?>

    <div class="bento-side">
    <?php foreach ($side as $p): setup_postdata($p); ?>
      <article<?php post_class('tile tile-side'); ?>>
        <a class="tile-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?= lqd_thumb() ?></a>
        <div class="tile-body">
          <div class="card-top"><?= lqd_primary_category() ?><span class="muted"><?= e(lqd_time_ago()) ?></span></div>
          <h3 class="card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
        </div>
      </article>
    <?php endforeach; lq_reset_postdata(); ?>
    </div>

    <div class="tile tile-stats">
      <span class="eyebrow">Website</span>
      <div class="stats">
        <div><strong><?= $stats['posts'] ?></strong><span>bài viết</span></div>
        <div><strong><?= $stats['categories'] ?></strong><span>chuyên mục</span></div>
        <div><strong><?= $stats['authors'] ?></strong><span>tác giả</span></div>
        <div><strong><?= $stats['pages'] ?></strong><span>trang</span></div>
      </div>
    </div>

    <div class="tile tile-chart">
      <div class="tile-head"><span class="eyebrow">Bài đăng 12 tháng</span><span class="muted small">bấm cột để xem lưu trữ</span></div>
      <div class="bars">
        <?php foreach ($months as $m): ?>
          <a class="bar<?= $m['count'] ? '' : ' is-empty' ?>" href="<?= e(home_url(sprintf('/%04d/%02d/', $m['year'], $m['month']))) ?>" style="--h:<?= round($m['count'] / $peak * 100) ?>%" title="Tháng <?= (int) $m['month'] ?>/<?= $m['year'] ?>: <?= $m['count'] ?> bài">
            <span class="bar-fill"></span><span class="bar-label"><?= e($m['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="tile tile-cats">
      <span class="eyebrow">Chuyên mục</span>
      <ul class="cat-tiles">
        <?php foreach (get_terms('category', ['orderby' => 'count']) as $cat): ?>
          <li><a href="<?= e($cat['url']) ?>" style="--c:<?= identity_color((int) $cat['id']) ?>"><span><?= e($cat['name']) ?></span><strong><?= $cat['count'] ?></strong></a></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <?php if ($actors = get_terms('actor', ['orderby' => 'count', 'number' => 6])): ?>
    <div class="tile tile-actors">
      <span class="eyebrow">Diễn viên</span>
      <ul class="people">
        <?php foreach ($actors as $a): ?>
          <li><a href="<?= e($a['url']) ?>"><?= avatar($a['name'], '', 'actor-' . $a['id']) ?><span><?= e($a['name']) ?></span><small><?= $a['count'] ?> bài</small></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <div class="section-head">
    <h2>Mới nhất</h2>
    <?php if ($tags = get_terms('tag', ['orderby' => 'count', 'number' => 6])): ?>
      <div class="tag-cloud"><?php foreach ($tags as $t): ?><a class="tag" href="<?= e($t['url']) ?>">#<?= e($t['name']) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php get_template_part('parts/archive-header', null, [
      'eyebrow' => 'Blog',
      'title' => is_front_page() ? 'Bài viết mới' : get_the_title(get_queried_object()),
  ]); ?>
<?php endif; ?>

  <div class="layout">
    <div class="layout-main">
      <?php if (have_posts()): ?>
        <div class="grid-cards" data-posts-grid>
          <?php while (have_posts()): the_post(); ?>
            <?php if (in_array(get_the_ID(), $shown, true)) continue; ?>
            <?php get_template_part('parts/card'); ?>
          <?php endwhile; ?>
        </div>
        <?php get_template_part('parts/load-more'); ?>
      <?php else: ?>
        <div class="empty"><p>Chưa có bài viết nào. <a href="<?= e(admin_url('posts/new')) ?>">Viết bài đầu tiên</a>.</p></div>
      <?php endif; ?>
    </div>
    <?php get_sidebar(); ?>
  </div>
</div>
<?php get_footer(); ?>
