<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\Charts;
use LQ\Admin\DashboardStats as S;

$c = S::counts();
$upcoming = S::upcoming();
?>
<section class="tile">
  <div class="tile-title">
    <div>
      <h3>Trạng thái nội dung</h3>
      <p><?= number_format(array_sum($c) - $c['trash'], 0, ',', '.') ?> bài (không tính thùng rác)</p>
    </div>
  </div>
  <?= Charts::stack([
      ['key' => 'published', 'label' => 'Đã đăng', 'value' => $c['published']],
      ['key' => 'scheduled', 'label' => 'Lên lịch', 'value' => $c['scheduled']],
      ['key' => 'pending', 'label' => 'Chờ duyệt', 'value' => $c['pending']],
      ['key' => 'draft', 'label' => 'Bản nháp', 'value' => $c['draft']],
  ]) ?>
  <?php if ($upcoming): ?>
    <div class="subhead">Sắp đăng</div>
    <ul class="mini-list">
      <?php foreach ($upcoming as $p): ?>
        <li><a href="/admin/posts/<?= (int) $p['id'] ?>/edit"><?= e($p['title'] ?: '(Không tiêu đề)') ?></a><span><?= e(format_date($p['published_at'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php elseif ($c['trash']): ?>
    <p class="tile-note"><?= icon('trash-2') ?> <a href="/admin/posts?status=trash"><?= $c['trash'] ?> bài trong thùng rác</a></p>
  <?php endif; ?>
</section>
