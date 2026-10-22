<?php defined('LQ_ROOT') || exit; ?>
<div class="page-head">
  <div>
    <h1>Giao diện</h1>
  </div>
  <form method="post" action="/admin/themes/upload" enctype="multipart/form-data" data-upload-form>
    <?= csrf_field() ?>
    <label class="btn btn-primary">
      <?= icon('upload') ?> Tải lên theme
      <input type="file" name="package" accept=".zip,application/zip" hidden data-upload-input>
    </label>
  </form>
</div>

<?php if (!$themes): ?>
  <div class="card empty"><?= icon('palette') ?><h3>Chưa có theme nào</h3><p class="muted">Tải lên một theme dạng .zip để bắt đầu.</p></div>
<?php else: ?>
  <div class="theme-grid">
    <?php foreach ($themes as $t): $isActive = $t['slug'] === $active; ?>
      <article class="theme-card<?= $isActive ? ' is-active' : '' ?>">
        <div class="theme-shot">
          <?php if ($t['screenshot']): ?>
            <img src="<?= e($t['screenshot']) ?>" alt="Ảnh chụp theme <?= e($t['name']) ?>" loading="lazy">
          <?php else: ?>
            <span class="theme-shot-empty"><?= icon('image-off') ?></span>
          <?php endif; ?>
        </div>
        <div class="theme-body">
          <div class="theme-title">
            <h3><?= e($t['name']) ?></h3>
            <?php if ($isActive): ?><span class="badge status-published">Đang dùng</span><?php endif; ?>
          </div>
          <p class="muted small"><?= $t['version'] ? 'Phiên bản ' . e($t['version']) : '' ?><?= $t['version'] && $t['author'] ? ' · ' : '' ?><?= $t['author'] ? e($t['author']) : '' ?></p>
          <p class="theme-desc"><?= e($t['description']) ?></p>
        </div>
        <div class="theme-actions">
          <?php if (\LQ\Admin\ThemeEditorController::allowed()): ?>
            <a class="icon-btn sm" href="/admin/themes/editor?theme=<?= e(rawurlencode($t['slug'])) ?>" title="Chỉnh sửa"><?= icon('code-xml') ?></a>
          <?php endif; ?>
          <?php if ($isActive): ?>
            <a class="btn btn-secondary btn-sm" href="/" target="_blank" rel="noopener">Xem trang web</a>
          <?php else: ?>
            <form method="post" action="/admin/themes/activate">
              <?= csrf_field() ?>
              <input type="hidden" name="theme" value="<?= e($t['slug']) ?>">
              <button class="btn btn-accent btn-sm" type="submit">Kích hoạt</button>
            </form>
            <form method="post" action="/admin/themes/delete" data-confirm="Xoá theme &quot;<?= e($t['name']) ?>&quot;?" data-confirm-text="Toàn bộ tệp của theme sẽ bị xoá khỏi máy chủ." data-confirm-danger>
              <?= csrf_field() ?>
              <input type="hidden" name="theme" value="<?= e($t['slug']) ?>">
              <button class="icon-btn sm danger" type="submit" title="Xoá theme"><?= icon('trash-2') ?></button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
