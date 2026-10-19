<?php
use LQ\Admin\DashboardStats as S;
?>
<section class="tile">
  <div class="tile-title"><div><h3>Hệ thống</h3><p>Môi trường đang chạy</p></div><span class="badge status-published">Hoạt động</span></div>
  <dl class="kv">
    <?php foreach (S::system() as $k => $val): ?>
      <dt><?= e($k) ?></dt><dd><?= e($val) ?></dd>
    <?php endforeach; ?>
  </dl>
</section>
