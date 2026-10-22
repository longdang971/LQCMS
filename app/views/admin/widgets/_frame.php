<?php
defined('LQ_ROOT') || exit;
/** Khung bọc mỗi widget: thanh công cụ (chỉ hiện khi Tuỳ chỉnh) + nội dung. */
use LQ\Admin\Widgets;
?>
<div class="widget span-<?= (int) $size ?>" data-widget="<?= e($w['id']) ?>" data-size="<?= (int) $size ?>" data-sizes="<?= e(implode(',', $w['sizes'])) ?>">
  <div class="widget-tools">
    <span class="widget-handle" data-widget-handle title="Kéo để di chuyển"><?= icon('grip-vertical') ?></span>
    <span class="widget-name"><?= e($w['title']) ?></span>
    <?php if ($w['source']['type'] !== 'core'): ?>
      <span class="widget-source" title="Widget của <?= $w['source']['type'] === 'plugin' ? 'plugin' : 'theme' ?> <?= e($w['source']['name']) ?>"><?= icon($w['source']['type'] === 'plugin' ? 'plug' : 'palette') ?><?= e($w['source']['name']) ?></span>
    <?php endif; ?>
    <?php if (count($w['sizes']) > 1): ?>
      <button type="button" class="widget-size" data-widget-size title="Đổi độ rộng"><?= icon('move-horizontal') ?><span><?= e(Widgets::SIZES[$size] ?? '') ?></span></button>
    <?php endif; ?>
    <button type="button" class="widget-remove" data-widget-remove title="Gỡ widget"><?= icon('x') ?></button>
  </div>
  <?= $inner ?>
</div>
