<?php
defined('LQ_ROOT') || exit;
use LQ\Core\View;

$route = '/admin/' . $info['route'];
?>
<div class="page-head">
  <div>
    <h1>Sửa <?= e($info['singular']) ?></h1>
  </div>
</div>

<section class="card narrow">
  <form method="post" action="<?= e($route) ?>/save" class="stack" data-dirty-guard>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $term['id'] ?>">
    <?= View::capture('admin/terms/_fields', compact('tax', 'term', 'parents', 'errors')) ?>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
      <a class="btn btn-ghost" href="<?= e($route) ?>">Huỷ</a>
    </div>
  </form>
</section>
