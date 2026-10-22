<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\Charts;
use LQ\Admin\DashboardStats as S;

$weeks = S::edits();
?>
<a class="tile" href="/admin/posts">
  <div class="tile-head"><span>Lượt biên tập</span><?= icon('activity') ?></div>
  <div class="tile-value" data-count="<?= array_sum($weeks) ?>"><?= number_format(array_sum($weeks), 0, ',', '.') ?></div>
  <div class="tile-foot">
    <span class="muted">12 tuần gần nhất</span>
    <?= Charts::sparkline($weeks, 'Số bài được tạo hoặc sửa mỗi tuần') ?>
  </div>
</a>
