<?php
use LQ\Admin\DashboardStats as S;

$c = S::counts();
$total = $c['draft'] + $c['pending'] + $c['scheduled'];
?>
<a class="tile" href="/admin/posts?status=draft">
  <div class="tile-head"><span>Đang chờ xử lý</span><?= icon('hourglass') ?></div>
  <div class="tile-value" data-count="<?= $total ?>"><?= number_format($total, 0, ',', '.') ?></div>
  <div class="tile-foot tile-split">
    <span><b><?= $c['draft'] ?></b> nháp</span>
    <span><b><?= $c['pending'] ?></b> chờ duyệt</span>
    <span><b><?= $c['scheduled'] ?></b> lên lịch</span>
  </div>
</a>
