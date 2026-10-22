<?php
/** Lưu trữ chung: chuyên mục, thẻ, theo ngày (taxonomy & tác giả có tệp riêng). */
defined('LQ_ROOT') || exit;
get_header();
$o = get_queried_object();
$eyebrow = match (true) {
    is_category() => 'Chuyên mục',
    is_tag() => 'Thẻ',
    is_date() => 'Lưu trữ',
    default => '',
};
?>
<div class="wrap">
  <?php get_template_part('parts/archive-header', null, [
      'eyebrow' => $eyebrow,
      'title' => is_category() || is_tag() ? (is_tag() ? '#' : '') . $o['name'] : get_the_archive_title(),
  ]); ?>

  <?php if (is_category() && ($subs = get_terms('category', ['parent' => (int) $o['id']]))): ?>
    <div class="tag-cloud sub-cats">
      <?php foreach ($subs as $s): ?><a class="tag" href="<?= e($s['url']) ?>"><?= e($s['name']) ?> <span><?= $s['count'] ?></span></a><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="layout">
    <div class="layout-main"><?php get_template_part('parts/loop-grid'); ?></div>
    <?php get_sidebar(); ?>
  </div>
</div>
<?php get_footer(); ?>
