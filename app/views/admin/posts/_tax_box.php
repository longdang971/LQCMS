<?php
/** Khung chọn term của một taxonomy tuỳ biến. Biến: $t (thông tin taxonomy + selected + tree) */
$old = old('tax_input', null);
$oldVal = is_array($old) && array_key_exists($t['key'], $old) ? $old[$t['key']] : null;
?>
<section class="card">
  <div class="card-head">
    <h3><?= e($t['label']) ?></h3>
    <?php if (can($t['cap'])): ?><a class="link small" href="/admin/<?= e($t['route']) ?>" target="_blank">Quản lý</a><?php endif; ?>
  </div>
  <?php if ($t['hierarchical']): ?>
    <?php $checked = is_array($oldVal) ? array_map('intval', $oldVal) : array_map('intval', array_column($t['selected'], 'id')); ?>
    <input type="hidden" name="tax_input[<?= e($t['key']) ?>][]" value="">
    <?php if (!$t['tree']): ?>
      <p class="help">Chưa có <?= e($t['singular']) ?> nào. <?php if (can($t['cap'])): ?><a class="link" href="/admin/<?= e($t['route']) ?>" target="_blank">Thêm mới</a><?php endif; ?></p>
    <?php else: ?>
      <div class="checklist">
        <?php foreach ($t['tree'] as $c): ?>
          <label class="check" style="--depth: <?= (int) $c['depth'] ?>">
            <input type="checkbox" name="tax_input[<?= e($t['key']) ?>][]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $checked, true) ? ' checked' : '' ?>>
            <span><?= e($c['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <?php $value = is_string($oldVal) ? $oldVal : implode(', ', array_column($t['selected'], 'name')); ?>
    <div class="tag-input" data-tag-input data-tax="<?= e($t['key']) ?>">
      <div class="tag-chips" data-tag-chips></div>
      <input class="tag-entry" type="text" placeholder="<?= e($t['placeholder'] ?: 'Thêm ' . $t['singular'] . '…') ?>" data-tag-entry autocomplete="off" aria-label="Thêm <?= e($t['singular']) ?>">
      <div class="tag-suggest" data-tag-suggest hidden></div>
      <input type="hidden" name="tax_input[<?= e($t['key']) ?>]" value="<?= e($value) ?>" data-tag-value>
    </div>
    <p class="help" style="margin-top: 6px"><?= $t['description'] !== '' ? e($t['description']) : 'Nhấn Enter hoặc dấu phẩy để thêm.' ?></p>
  <?php endif; ?>
</section>
