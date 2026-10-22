<?php
defined('LQ_ROOT') || exit;
/** Vẽ một field tuỳ biến. Biến: $f (định nghĩa), $value, $errors */
$name = 'meta[' . $f['key'] . ']';
$id = 'meta-' . $f['key'];
$err = $errors['meta.' . $f['key']] ?? null;
$inv = $err ? ' is-invalid' : '';
$attrs = function () use ($f) {
    $a = '';
    foreach (['min', 'max', 'step'] as $k) {
        if ($f[$k] !== null && in_array($f['type'], ['number', 'date'], true)) {
            $a .= " $k=\"" . e($f[$k]) . '"';
        }
    }
    if ($f['type'] === 'text' && $f['max'] !== null) {
        $a .= ' maxlength="' . (int) $f['max'] . '"';
    }
    return $a . ($f['placeholder'] !== '' ? ' placeholder="' . e($f['placeholder']) . '"' : '') . ($f['ai_role'] ? ' data-ai-role="' . e($f['ai_role']) . '"' : '');
};
$inputType = ['text' => 'text', 'number' => 'number', 'url' => 'url', 'email' => 'email', 'date' => 'date', 'color' => 'color'][$f['type']] ?? 'text';

/** Một ô nhập đơn (dùng cho cả field thường và từng dòng của field lặp). */
$single = function (string $inName, mixed $v, string $inId = '') use ($f, $inv, $attrs, $inputType): string {
    if ($f['type'] === 'image') {
        $v = (string) $v;
        return '<div class="image-field" data-image-field>'
            . '<img src="' . e($v) . '" alt="" data-image-preview' . ($v === '' ? ' hidden' : '') . '>'
            . '<input type="hidden" name="' . e($inName) . '" value="' . e($v) . '" data-image-value>'
            . '<button type="button" class="btn btn-secondary btn-sm" data-image-pick>' . icon('images') . ' <span data-image-label>' . ($v === '' ? 'Chọn ảnh' : 'Đổi ảnh') . '</span></button>'
            . '<button type="button" class="btn btn-ghost btn-sm" data-image-clear' . ($v === '' ? ' hidden' : '') . '>Gỡ</button>'
            . '</div>';
    }
    if ($f['type'] === 'color') {
        $v = (string) ($v ?: '#000000');
        return '<div class="color-field"><input type="color" name="' . e($inName) . '" value="' . e($v) . '"' . ($inId ? ' id="' . e($inId) . '"' : '') . '><code data-color-code>' . e($v) . '</code></div>';
    }
    return '<input class="input' . ($f['type'] === 'url' ? ' mono' : '') . $inv . '" type="' . $inputType . '" name="' . e($inName) . '" value="' . e(is_scalar($v) ? (string) $v : '') . '"'
        . ($inId ? ' id="' . e($inId) . '"' : '') . $attrs() . '>';
};
?>
<div class="field meta-field<?= $f['width'] === 'half' ? ' is-half' : '' ?>" data-field="<?= e($f['key']) ?>">
  <?php if ($f['type'] !== 'checkbox'): ?>
    <label for="<?= e($id) ?>"><?= e($f['label']) ?><?= $f['required'] ? ' <span class="req">*</span>' : '' ?></label>
  <?php endif; ?>

  <?php if ($f['repeat']): ?>
    <?php $items = array_values(array_filter((array) $value, fn ($x) => $x !== '' && $x !== null)) ?: ['']; ?>
    <div class="repeat" data-repeat data-max="<?= (int) $f['max_items'] ?>">
      <div class="repeat-rows" data-repeat-rows>
        <?php foreach ($items as $i => $item): ?>
          <div class="repeat-row" data-repeat-row>
            <span class="repeat-handle" title="Kéo để sắp xếp"><?= icon('grip-vertical') ?></span>
            <?= $single($name . '[]', $item, $i === 0 ? $id : '') ?>
            <button type="button" class="icon-btn sm" data-repeat-remove title="Xoá dòng"><?= icon('x') ?></button>
          </div>
        <?php endforeach; ?>
      </div>
      <template data-repeat-template>
        <div class="repeat-row" data-repeat-row>
          <span class="repeat-handle" title="Kéo để sắp xếp"><?= icon('grip-vertical') ?></span>
          <?= $single($name . '[]', '') ?>
          <button type="button" class="icon-btn sm" data-repeat-remove title="Xoá dòng"><?= icon('x') ?></button>
        </div>
      </template>
      <button type="button" class="btn btn-ghost btn-sm repeat-add" data-repeat-add><?= icon('plus') ?> <?= e($f['add_label']) ?></button>
    </div>

  <?php elseif ($f['type'] === 'textarea'): ?>
    <textarea class="input<?= $inv ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="4"<?= $f['ai_role'] ? ' data-ai-role="' . e($f['ai_role']) . '"' : '' ?><?= $f['placeholder'] !== '' ? ' placeholder="' . e($f['placeholder']) . '"' : '' ?><?= $f['max'] ? ' maxlength="' . (int) $f['max'] . '"' : '' ?>><?= e((string) $value) ?></textarea>

  <?php elseif ($f['type'] === 'select'): ?>
    <select class="input<?= $inv ?>" id="<?= e($id) ?>" name="<?= e($name) ?>">
      <option value=""><?= e($f['placeholder'] ?: '— Chọn —') ?></option>
      <?php foreach ($f['options'] as $ov => $ol): ?>
        <option value="<?= e($ov) ?>"<?= selected($ov, $value) ?>><?= e($ol) ?></option>
      <?php endforeach; ?>
    </select>

  <?php elseif ($f['type'] === 'radio'): ?>
    <div class="radio-group field">
      <?php foreach ($f['options'] as $ov => $ol): ?>
        <label class="check"><input type="radio" name="<?= e($name) ?>" value="<?= e($ov) ?>"<?= checked($ov, $value) ?>><span><?= e($ol) ?></span></label>
      <?php endforeach; ?>
    </div>

  <?php elseif ($f['type'] === 'checkbox'): ?>
    <label class="switch-row boxed">
      <span><strong><?= e($f['label']) ?></strong><?php if ($f['help'] !== ''): ?><small class="muted"><?= e($f['help']) ?></small><?php endif; ?></span>
      <span class="switch"><input type="checkbox" name="<?= e($name) ?>" value="1"<?= $value ? ' checked' : '' ?>><span class="switch-ui"></span></span>
    </label>

  <?php else: ?>
    <?= $single($name, $value, $id) ?>
  <?php endif; ?>

  <?php if ($f['help'] !== '' && $f['type'] !== 'checkbox'): ?><p class="help"><?= e($f['help']) ?></p><?php endif; ?>
  <?php if ($err): ?><p class="field-error"><?= e($err) ?></p><?php endif; ?>
</div>
