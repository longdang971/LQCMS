<?php
/** Khung chứa các field tuỳ biến hoặc meta box tự vẽ. Biến: $box, $post, $errors */
use LQ\Content\PostFields;
use LQ\Core\View;

$oldMeta = old('meta', null);
?>
<section class="card meta-box" id="<?= e($box['id']) ?>"<?= !empty($box['template']) ? ' data-for-template="' . e($box['template']) . '"' : '' ?>>
  <div class="card-head">
    <div>
      <h3><?= e($box['title']) ?></h3>
      <?php if (!empty($box['description'])): ?><p><?= e($box['description']) ?></p><?php endif; ?>
    </div>
  </div>
  <?php if ($box['render']): ?>
    <?php try { ($box['render'])($post); } catch (Throwable $ex) { error_log('[LQCMS] meta box ' . $box['id'] . ': ' . $ex->getMessage()); echo '<p class="field-error">Khung này gặp lỗi khi hiển thị.</p>'; } ?>
  <?php else: ?>
    <div class="meta-fields">
      <?php foreach ($box['fields'] as $f): ?>
        <?= View::capture('admin/posts/_field', ['f' => $f, 'value' => PostFields::value($f, $post, is_array($oldMeta) ? $oldMeta : null), 'errors' => $errors]) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
