<?php
/** Lưu trữ taxonomy tuỳ biến "Diễn viên" — ví dụ cho thứ bậc taxonomy-{tax}.php. */
defined('LQ_ROOT') || exit;
get_header();
$actor = get_queried_object();
?>
<div class="wrap">
  <?php get_template_part('parts/archive-header', null, [
      'eyebrow' => 'Diễn viên',
      'title' => $actor['name'],
      'media' => avatar($actor['name'], 'xl', 'actor-' . $actor['id']),
  ]); ?>
  <?php if ($others = get_terms('actor', ['exclude' => [(int) $actor['id']], 'orderby' => 'count', 'number' => 8])): ?>
    <div class="tag-cloud sub-cats"><span class="muted small">Diễn viên khác:</span>
      <?php foreach ($others as $a): ?><a class="tag" href="<?= e($a['url']) ?>"><?= e($a['name']) ?> <span><?= $a['count'] ?></span></a><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php get_template_part('parts/loop-grid', null, ['empty' => 'Chưa có bài nào về diễn viên này.']); ?>
</div>
<?php get_footer(); ?>
