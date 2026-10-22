<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\Charts;
use LQ\Admin\DashboardStats as S;

$months = S::published()['months'];
?>
<section class="tile">
  <div class="tile-title">
    <div>
      <h3>Bài đăng theo tháng</h3>
      <p><?= number_format(array_sum(array_column($months, 'value')), 0, ',', '.') ?> bài trong 12 tháng gần nhất</p>
    </div>
  </div>
  <?= Charts::columns($months, 'bài', 'Số bài đăng theo tháng, 12 tháng gần nhất') ?>
</section>
