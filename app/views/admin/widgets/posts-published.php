<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\Charts;
use LQ\Admin\DashboardStats as S;

$c = S::counts();
$p = S::published();
$delta = $p['last30'] - $p['prev30'];
?>
<a class="tile" href="/admin/posts?status=published">
  <div class="tile-head"><span>Bài đã đăng</span><?= icon('file-check-2') ?></div>
  <div class="tile-value" data-count="<?= $c['published'] ?>"><?= number_format($c['published'], 0, ',', '.') ?></div>
  <div class="tile-foot">
    <span class="delta <?= $delta > 0 ? 'up' : ($delta < 0 ? 'down' : '') ?>"><?= $delta > 0 ? '▲ ' : ($delta < 0 ? '▼ ' : '') ?><?= $p['last30'] ?> trong 30 ngày</span>
    <?= Charts::sparkline($p['weeks'], 'Bài đăng mỗi tuần, 12 tuần gần nhất') ?>
  </div>
</a>
