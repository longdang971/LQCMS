<?php
use LQ\Core\View;

$route = '/admin/' . $info['route'];
$term = null;
?>
<div class="page-head">
  <div>
    <h1><?= e($info['label']) ?></h1>
    <p><?= count($terms) ?> <?= e($info['singular']) ?><?= $q !== '' ? ' khớp với "' . e($q) . '"' : '' ?></p>
  </div>
</div>

<div class="split">
  <section class="card">
    <div class="card-head"><h3>Thêm <?= e($info['singular']) ?> mới</h3></div>
    <form method="post" action="<?= e($route) ?>/save" class="stack">
      <?= csrf_field() ?>
      <?= View::capture('admin/terms/_fields', compact('tax', 'term', 'parents', 'errors')) ?>
      <div><button class="btn btn-primary" type="submit">Thêm <?= e($info['singular']) ?></button></div>
    </form>
  </section>

  <section class="card card-flush">
    <form class="toolbar" method="get" action="<?= e($route) ?>">
      <div class="input-icon grow">
        <?= icon('search') ?>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm <?= e($info['singular']) ?>…">
      </div>
    </form>

    <?php if (!$terms): ?>
      <div class="empty">
        <?= icon($info['icon']) ?>
        <h3>Chưa có <?= e($info['singular']) ?> nào</h3>
        <p class="muted">Dùng form bên cạnh để thêm mới.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Tên</th>
              <th class="hide-sm">Slug</th>
              <th class="hide-md">Mô tả</th>
              <th class="num">Số bài</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($terms as $t): $id = (int) $t['id']; $isDefault = $tax === 'category' && $id === $defaultCategory; ?>
            <tr>
              <td>
                <?php if (!empty($t['depth'])): ?><span class="tree-indent" style="--depth: <?= (int) $t['depth'] ?>"><?= str_repeat('— ', (int) $t['depth']) ?></span><?php endif; ?>
                <a class="row-title" href="<?= e("$route/$id/edit") ?>"><?= e($t['name']) ?></a>
                <?php if ($isDefault): ?><span class="badge badge-soft">Mặc định</span><?php endif; ?>
              </td>
              <td class="hide-sm mono muted"><?= e($t['slug']) ?></td>
              <td class="hide-md muted clamp"><?= e($t['description'] ?: '—') ?></td>
              <td class="num">
                <a class="count-pill" href="/admin/posts?term=<?= $id ?>"><?= (int) $t['post_count'] ?></a>
              </td>
              <td class="col-actions">
                <div class="row-actions">
                  <a class="icon-btn sm" href="<?= e("$route/$id/edit") ?>" title="Sửa"><?= icon('pencil') ?></a>
                  <?php if (!$isDefault): ?>
                    <form method="post" action="<?= e("$route/$id/delete") ?>"
                          data-confirm="Xoá <?= e($info['singular']) ?> &quot;<?= e($t['name']) ?>&quot;?"
                          data-confirm-text="<?= $tax === 'category' ? 'Bài viết chỉ thuộc chuyên mục này sẽ được chuyển về chuyên mục mặc định.' : 'Mục này sẽ được gỡ khỏi mọi bài viết.' ?>"
                          data-confirm-danger>
                      <?= csrf_field() ?>
                      <button class="icon-btn sm danger" type="submit" title="Xoá"><?= icon('trash-2') ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
