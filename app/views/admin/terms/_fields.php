<?php
/** Các ô nhập dùng chung cho form thêm & sửa chuyên mục/thẻ. Biến: $tax, $term (có thể null), $parents, $errors */
$val = fn (string $k) => old($k, $term[$k] ?? '');
?>
<div class="field">
  <label for="name">Tên</label>
  <input id="name" name="name" class="input<?= invalid($errors, 'name') ?>" value="<?= e($val('name')) ?>" required data-slug-source>
  <?= field_error($errors, 'name') ?>
</div>
<div class="field">
  <label for="slug">Slug</label>
  <input id="slug" name="slug" class="input mono<?= invalid($errors, 'slug') ?>" value="<?= e($val('slug')) ?>" placeholder="tu-dong-tao-tu-ten" data-slug-target spellcheck="false">
  <p class="help">Phần xuất hiện trên URL, chỉ gồm chữ thường không dấu, số và gạch ngang.</p>
  <?= field_error($errors, 'slug') ?>
</div>
<?php if (\LQ\Content\Terms::info($tax)['hierarchical']): ?>
  <div class="field">
    <label for="parent_id"><?php $sg = \LQ\Content\Terms::info($tax)['singular']; ?><?= e(mb_strtoupper(mb_substr($sg, 0, 1)) . mb_substr($sg, 1)) ?> cha</label>
    <select id="parent_id" name="parent_id" class="input<?= invalid($errors, 'parent_id') ?>">
      <option value="0">— Không có —</option>
      <?php foreach ($parents as $p): ?>
        <option value="<?= (int) $p['id'] ?>"<?= selected($p['id'], $val('parent_id')) ?>><?= str_repeat('— ', $p['depth']) . e($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'parent_id') ?>
  </div>
<?php endif; ?>
<div class="field">
  <label for="description">Mô tả</label>
  <textarea id="description" name="description" class="input" rows="4"><?= e($val('description')) ?></textarea>
  <p class="help">Một số theme sẽ hiển thị mô tả này ở trang lưu trữ.</p>
</div>
