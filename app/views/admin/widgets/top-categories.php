<?php
use LQ\Admin\Charts;
use LQ\Admin\DashboardStats as S;

$top = S::topCategories();
?>
<section class="tile">
  <div class="tile-title"><div><h3>Chuyên mục nổi bật</h3><p>Theo số bài viết</p></div></div>
  <?php if ($top): ?>
    <?= Charts::hbars(array_map(fn ($c) => ['id' => $c['id'], 'label' => $c['name'], 'value' => (int) $c['value'], 'url' => '/admin/posts?category=' . $c['id']], $top)) ?>
  <?php else: ?>
    <p class="muted small">Chưa có dữ liệu.</p>
  <?php endif; ?>
</section>
