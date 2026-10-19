<?php
use LQ\Admin\DashboardStats as S;

$t = S::termCounts();
?>
<a class="tile" href="/admin/categories">
  <div class="tile-head"><span>Phân loại</span><?= icon('folder-tree') ?></div>
  <div class="tile-value" data-count="<?= $t['category'] ?>"><?= number_format($t['category'], 0, ',', '.') ?></div>
  <div class="tile-foot tile-split">
    <span><b><?= $t['category'] ?></b> chuyên mục</span>
    <span><b><?= $t['tag'] ?></b> thẻ</span>
  </div>
</a>
