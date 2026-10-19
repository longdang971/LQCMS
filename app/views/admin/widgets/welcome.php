<?php
$u = current_user();
$links = array_filter([
    can('edit_posts') ? ['/admin/posts/new', 'pen-line', 'Viết bài mới'] : null,
    can('edit_posts') ? ['/admin/posts', 'file-text', 'Bài viết'] : null,
    can('manage_themes') ? ['/admin/themes', 'palette', 'Giao diện'] : null,
    can('manage_options') ? ['/admin/settings', 'settings', 'Cài đặt'] : null,
    ['/admin/profile', 'user-round', 'Hồ sơ'],
]);
?>
<section class="tile tile-welcome">
  <div>
    <h3>Xin chào <?= e($u['display_name']) ?></h3>
    <p class="muted">Chúc bạn một ngày làm việc hiệu quả với <?= e(option('site_title')) ?>.</p>
  </div>
  <div class="welcome-links">
    <?php foreach ($links as [$url, $ic, $label]): ?>
      <a href="<?= e($url) ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </div>
</section>
