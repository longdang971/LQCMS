<?php
/** Đầu trang lưu trữ: tiêu đề, mô tả, số bài. $args['eyebrow'], $args['media'] tuỳ chọn. */
defined('LQ_ROOT') || exit;
$q = lq_main_query();
?>
<header class="archive-head">
  <?php if (!empty($args['media'])): ?><div class="archive-media"><?= $args['media'] ?></div><?php endif; ?>
  <div>
    <?php if (!empty($args['eyebrow'])): ?><span class="eyebrow"><?= e($args['eyebrow']) ?></span><?php endif; ?>
    <h1 class="archive-title"><?= e($args['title'] ?? get_the_archive_title()) ?></h1>
    <?php the_archive_description('<div class="archive-desc">', '</div>'); ?>
    <p class="archive-count"><strong><?= $q->found ?></strong> bài viết<?php if (is_paged()): ?> · trang <?= $q->page ?>/<?= $q->maxPages ?><?php endif; ?></p>
  </div>
</header>
