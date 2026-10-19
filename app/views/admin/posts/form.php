<?php
use LQ\Content\Posts;
use LQ\Core\View;

$isNew = $post === null;
$status = $post['status'] ?? 'draft';
$v = fn (string $k, mixed $d = '') => old($k, $post[$k] ?? $d);
$oldCats = old('categories', null);
$checkedCats = is_array($oldCats) ? array_map('intval', $oldCats) : $selectedCats;
$tagValue = old('tags', implode(', ', $tags));
$localDate = '';
if (!empty($post['published_at'])) {
    $localDate = (new DateTimeImmutable($post['published_at'], new DateTimeZone('UTC')))->setTimezone(site_timezone())->format('Y-m-d\TH:i');
}
$localDate = old('published_at', $localDate);
?>
<form method="post" action="/admin/posts/save" enctype="multipart/form-data" class="editor-layout" data-post-form data-dirty-guard data-base="/admin/posts" data-post-id="<?= (int) ($post['id'] ?? 0) ?>">
  <?= csrf_field() ?>
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $post['id'] ?>"><?php endif; ?>

  <div class="editor-main">
    <?= View::capture('admin/posts/_guard', compact('post', 'errors', 'autosave', 'lockedBy')) ?>
    <input class="title-input<?= invalid($errors, 'title') ?>" name="title" value="<?= e($v('title')) ?>" placeholder="Tiêu đề bài viết" autocomplete="off" data-slug-source>
    <?= field_error($errors, 'title') ?>

    <div class="permalink-row">
      <?php if ($permalinkPattern): ?>
        <?php [$before, $after] = explode('__SLUG__', $permalinkPattern) + [1 => '']; ?>
        <span><?= e($before) ?></span><input class="slug-input<?= invalid($errors, 'slug') ?>" name="slug" value="<?= e($v('slug')) ?>" placeholder="slug-bai-viet" data-slug-target spellcheck="false" aria-label="Slug"><span><?= e($after) ?></span>
      <?php else: ?>
        <span><?= e(site_url('/?p=' . ($post['id'] ?? '…'))) ?></span>
        <input type="hidden" name="slug" value="<?= e($v('slug')) ?>" data-slug-target>
      <?php endif; ?>
      <?php if ($permalink): ?>
        <a class="link" href="<?= e($permalink) ?>" target="_blank" rel="noopener">Xem bài <?= icon('arrow-up-right') ?></a>
      <?php endif; ?>
    </div>
    <?= field_error($errors, 'slug') ?>

    <div class="card card-flush editor-card">
      <textarea id="content" name="content" data-editor><?= e($v('content')) ?></textarea>
    </div>

    <?php foreach ($customTax as $t): if ($t['context'] !== 'main') continue; ?>
      <?= View::capture('admin/posts/_tax_box', ['t' => $t]) ?>
    <?php endforeach; ?>
    <?php foreach ($boxes['main'] as $box): ?>
      <?= View::capture('admin/posts/_box', ['box' => $box, 'post' => $post, 'errors' => $errors]) ?>
    <?php endforeach; ?>
  </div>

  <aside class="editor-side">
    <section class="card">
      <div class="card-head">
        <h3>Xuất bản</h3>
        <span class="badge status-<?= e($status) ?>"><?= e($isNew ? 'Bài mới' : (Posts::STATUSES[$status] ?? $status)) ?></span>
      </div>
      <dl class="kv compact">
        <dt>Tác giả</dt><dd><?= e($author['display_name'] ?? '—') ?></dd>
        <?php if (!$isNew): ?>
          <dt>Sửa lần cuối</dt><dd><?= e(format_date($post['updated_at'])) ?></dd>
        <?php endif; ?>
        <dt>Tự lưu</dt><dd data-autosave-status class="muted">—</dd>
      </dl>
      <?php if ($revisionCount > 0): ?>
        <a class="link small revisions-link" href="/admin/<?= e(Posts::TYPES[$type]['route']) ?>/<?= (int) $post['id'] ?>/revisions"><?= icon('history') ?> Lịch sử phiên bản (<?= (int) $revisionCount ?>)</a>
      <?php endif; ?>
      <div class="field">
        <label for="published_at">Ngày đăng</label>
        <input id="published_at" class="input<?= invalid($errors, 'published_at') ?>" type="datetime-local" name="published_at" value="<?= e($localDate) ?>">
        <p class="help">Để trống để đăng ngay, chọn ngày tương lai để lên lịch. Múi giờ <?= e(option('timezone')) ?>.</p>
      </div>
      <div class="publish-actions">
        <?php if (in_array($status, ['published', 'scheduled'], true)): ?>
          <button class="btn btn-secondary" type="submit" name="action" value="draft">Về nháp</button>
          <button class="btn btn-accent" type="submit" name="action" value="update">Cập nhật</button>
        <?php else: ?>
          <button class="btn btn-secondary" type="submit" name="action" value="draft">Lưu nháp</button>
          <?php if (can('publish_posts')): ?>
            <button class="btn btn-accent" type="submit" name="action" value="publish" data-publish-btn>Đăng bài</button>
          <?php else: ?>
            <button class="btn btn-primary" type="submit" name="action" value="pending">Gửi duyệt</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if (!$isNew): ?>
        <div class="side-foot">
          <button class="btn-link-danger" type="submit" form="trash-form"><?= icon('trash-2') ?> Chuyển vào thùng rác</button>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Chuyên mục</h3>
        <?php if (can('manage_terms')): ?><a class="link small" href="/admin/categories" target="_blank">Quản lý</a><?php endif; ?>
      </div>
      <div class="checklist">
        <?php foreach ($categories as $c): ?>
          <label class="check" style="--depth: <?= (int) $c['depth'] ?>">
            <input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $checkedCats, true) ? ' checked' : '' ?>>
            <span><?= e($c['name']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-head"><h3>Thẻ</h3></div>
      <div class="tag-input" data-tag-input>
        <div class="tag-chips" data-tag-chips></div>
        <input class="tag-entry" type="text" placeholder="Thêm thẻ…" data-tag-entry autocomplete="off" aria-label="Thêm thẻ">
        <div class="tag-suggest" data-tag-suggest hidden></div>
        <input type="hidden" name="tags" value="<?= e($tagValue) ?>" data-tag-value>
      </div>
      <p class="help" style="margin-top: 6px">Nhấn Enter hoặc dấu phẩy để thêm.</p>
      <?php if ($popularTags): ?>
        <div class="popular-tags">
          <span>Hay dùng:</span>
          <?php foreach ($popularTags as $t): ?>
            <button type="button" class="chip chip-btn" data-tag-add="<?= e($t['name']) ?>"><?= e($t['name']) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php foreach ($customTax as $t): if ($t['context'] !== 'side') continue; ?>
      <?= View::capture('admin/posts/_tax_box', ['t' => $t]) ?>
    <?php endforeach; ?>

    <?= View::capture('admin/posts/_featured', ['post' => $post]) ?>
    <?php foreach ($boxes['side'] as $box): ?>
      <?= View::capture('admin/posts/_box', ['box' => $box, 'post' => $post, 'errors' => $errors]) ?>
    <?php endforeach; ?>
  </aside>
</form>

<?php if (!$isNew): ?>
  <form id="trash-form" method="post" action="/admin/posts/<?= (int) $post['id'] ?>/trash" hidden data-confirm="Chuyển bài viết vào thùng rác?" data-confirm-text="Bạn có thể khôi phục lại trong mục Thùng rác." data-confirm-label="Chuyển vào thùng rác"><?= csrf_field() ?></form>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
