<?php
use LQ\Content\Posts;
use LQ\Core\View;

$isNew = $post === null;
$status = $post['status'] ?? 'draft';
$v = fn (string $k, mixed $d = '') => old($k, $post[$k] ?? $d);
$localDate = '';
if (!empty($post['published_at'])) {
    $localDate = (new DateTimeImmutable($post['published_at'], new DateTimeZone('UTC')))->setTimezone(site_timezone())->format('Y-m-d\TH:i');
}
$localDate = old('published_at', $localDate);
$currentTemplate = (string) $v('template', '');
$currentParent = (int) $v('parent_id', 0);
?>
<form method="post" action="/admin/pages/save" class="editor-layout" data-post-form data-dirty-guard>
  <?= csrf_field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><?php endif; ?>

  <div class="editor-main">
    <input class="title-input<?= invalid($errors, 'title') ?>" name="title" value="<?= e($v('title')) ?>" placeholder="Tiêu đề trang" autocomplete="off" data-slug-source>
    <?= field_error($errors, 'title') ?>

    <div class="permalink-row">
      <span data-page-prefix><?= e(rtrim($pathPrefix, '/') . '/') ?></span><input class="slug-input<?= invalid($errors, 'slug') ?>" name="slug" value="<?= e($v('slug')) ?>" placeholder="slug-trang" data-slug-target spellcheck="false" aria-label="Slug"><span>/</span>
      <?php if ($permalink): ?>
        <a class="link" href="<?= e($permalink) ?>" target="_blank" rel="noopener">Xem trang <?= icon('arrow-up-right') ?></a>
      <?php endif; ?>
    </div>
    <?= field_error($errors, 'slug') ?>

    <div class="card card-flush editor-card">
      <textarea id="content" name="content" data-editor><?= e($v('content')) ?></textarea>
    </div>

    <?php foreach ($boxes['main'] as $box): ?>
      <?= View::capture('admin/posts/_box', ['box' => $box, 'post' => $post, 'errors' => $errors]) ?>
    <?php endforeach; ?>
  </div>

  <aside class="editor-side">
    <section class="card">
      <div class="card-head">
        <h3>Xuất bản</h3>
        <span class="badge status-<?= e($status) ?>"><?= e($isNew ? 'Trang mới' : (Posts::STATUSES[$status] ?? $status)) ?></span>
      </div>
      <dl class="kv compact">
        <dt>Tác giả</dt><dd><?= e($author['display_name'] ?? '—') ?></dd>
        <?php if (!$isNew): ?>
          <dt>Sửa lần cuối</dt><dd><?= e(format_date($post['updated_at'])) ?></dd>
        <?php endif; ?>
      </dl>
      <div class="field">
        <label for="published_at">Ngày đăng</label>
        <input id="published_at" class="input<?= invalid($errors, 'published_at') ?>" type="datetime-local" name="published_at" value="<?= e($localDate) ?>">
        <p class="help">Để trống để đăng ngay, chọn ngày tương lai để lên lịch.</p>
      </div>
      <div class="publish-actions">
        <?php if (in_array($status, ['published', 'scheduled'], true)): ?>
          <button class="btn btn-secondary" type="submit" name="action" value="draft">Về nháp</button>
          <button class="btn btn-accent" type="submit" name="action" value="update">Cập nhật</button>
        <?php else: ?>
          <button class="btn btn-secondary" type="submit" name="action" value="draft">Lưu nháp</button>
          <button class="btn btn-accent" type="submit" name="action" value="publish" data-publish-btn>Đăng trang</button>
        <?php endif; ?>
      </div>
      <?php if (!$isNew): ?>
        <div class="side-foot">
          <button class="btn-link-danger" type="submit" form="trash-form"><?= icon('trash-2') ?> Chuyển vào thùng rác</button>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h3>Thuộc tính trang</h3></div>
      <div class="stack" style="gap: 14px">
        <div class="field">
          <label for="parent_id">Trang cha</label>
          <select id="parent_id" name="parent_id" class="input<?= invalid($errors, 'parent_id') ?>" data-page-parent>
            <option value="0" data-path="">— Không có (trang gốc) —</option>
            <?php foreach ($parents as $pg): ?>
              <option value="<?= (int) $pg['id'] ?>" data-path="<?= e(\LQ\Content\Pages::path($pg)) ?>"<?= selected($pg['id'], $currentParent) ?>><?= str_repeat('— ', $pg['depth']) . e($pg['title'] ?: '(Không tiêu đề)') ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'parent_id') ?>
        </div>
        <div class="field">
          <label for="template">Mẫu trang</label>
          <select id="template" name="template" class="input<?= invalid($errors, 'template') ?>" data-page-template>
            <option value="">Mặc định</option>
            <?php foreach ($templates as $t): ?>
              <option value="<?= e($t['key']) ?>"<?= selected($t['key'], $currentTemplate) ?>><?= e($t['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php foreach ($templates as $t): ?>
            <p class="help" data-template-help="<?= e($t['key']) ?>"<?= $t['key'] !== $currentTemplate ? ' hidden' : '' ?>>
              <?= e($t['description']) ?>
              <?php if (!$t['file_exists']): ?><span class="field-error">Theme chưa có tệp <code><?= e($t['file']) ?></code>.</span><?php endif; ?>
            </p>
          <?php endforeach; ?>
          <?php if (!$templates): ?><p class="help">Theme đang dùng chưa khai báo mẫu trang riêng.</p><?php endif; ?>
          <?= field_error($errors, 'template') ?>
        </div>
        <div class="field">
          <label for="menu_order">Thứ tự</label>
          <input id="menu_order" name="menu_order" type="number" class="input" value="<?= (int) $v('menu_order', 0) ?>" style="max-width: 120px">
          <p class="help">Số nhỏ đứng trước khi liệt kê các trang cùng cấp.</p>
        </div>
      </div>
    </section>

    <?= View::capture('admin/posts/_featured', ['post' => $post]) ?>

    <?php foreach ($boxes['side'] as $box): ?>
      <?= View::capture('admin/posts/_box', ['box' => $box, 'post' => $post, 'errors' => $errors]) ?>
    <?php endforeach; ?>
  </aside>
</form>

<?php if (!$isNew): ?>
  <form id="trash-form" method="post" action="/admin/pages/<?= (int) $post['id'] ?>/trash" hidden data-confirm="Chuyển trang này vào thùng rác?" data-confirm-text="Trang con (nếu có) vẫn giữ nguyên. Bạn có thể khôi phục trong mục Thùng rác." data-confirm-label="Chuyển vào thùng rác"><?= csrf_field() ?></form>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
