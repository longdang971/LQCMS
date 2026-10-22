<?php
defined('LQ_ROOT') || exit;
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
  <div class="label-row">
    <label for="description">Mô tả</label>
    <?php if (\LQ\AI\AI::enabled()): ?>
      <button type="button" class="btn btn-ghost btn-xs" data-ai-term-desc data-tax="<?= e($tax) ?>" data-id="<?= (int) ($term['id'] ?? 0) ?>"><?= icon('sparkles') ?> Viết bằng AI</button>
    <?php endif; ?>
  </div>
  <textarea id="description" name="description" class="input" rows="4" data-term-desc><?= e($val('description')) ?></textarea>
  <p class="help">Dùng làm mô tả SEO (thẻ meta description) của trang lưu trữ, khoảng 150–160 ký tự là đẹp trên Google. Một số theme còn hiển thị mô tả này trên trang.</p>
</div>
