<?php
defined('LQ_ROOT') || exit;
/** Trang cài đặt do plugin khai báo (lq_add_settings_page). Biến: $page, $values, $errors */
use LQ\Core\View;
?>
<div class="page-head">
  <div>
    <h1><?= e($page['title']) ?></h1>
    <?php if ($page['description'] !== ''): ?><p class="muted"><?= e($page['description']) ?></p><?php endif; ?>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="submit" form="plugin-settings-form">Lưu thay đổi</button>
  </div>
</div>

<form method="post" action="/admin/plugin/<?= e($page['slug']) ?>" class="settings" id="plugin-settings-form" data-dirty-guard>
  <?= csrf_field() ?>
  <?php foreach ($page['sections'] as $i => $sec): ?>
    <section class="settings-section<?= $i === 0 ? ' first' : '' ?>">
      <div class="settings-label">
        <?php if ($sec['title'] !== ''): ?><h3><?= e($sec['title']) ?></h3><?php endif; ?>
        <?php if ($sec['description'] !== ''): ?><p class="muted"><?= e($sec['description']) ?></p><?php endif; ?>
      </div>
      <div class="settings-fields">
        <?php foreach ($sec['keys'] as $key): ?>
          <?= View::capture('admin/posts/_field', ['f' => $page['fields'][$key], 'value' => $values[$key], 'errors' => $errors, 'prefix' => 'settings']) ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</form>
