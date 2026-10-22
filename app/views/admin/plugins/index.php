<?php
defined('LQ_ROOT') || exit;
$tabs = ['' => 'Tất cả', 'active' => 'Đang bật', 'inactive' => 'Đang tắt'];
?>
<div class="page-head">
  <div>
    <h1>Plugin</h1>
  </div>
  <form method="post" action="/admin/plugins/upload" enctype="multipart/form-data" data-upload-form>
    <?= csrf_field() ?>
    <label class="btn btn-primary">
      <?= icon('upload') ?> Tải lên plugin
      <input type="file" name="package" accept=".zip,application/zip" hidden data-upload-input>
    </label>
  </form>
</div>

<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="tab<?= $filter === $key ? ' is-active' : '' ?>" href="/admin/plugins<?= $key ? '?filter=' . $key : '' ?>">
      <?= e($label) ?> <span class="tab-count"><?= $counts[$key ?: 'all'] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$plugins): ?>
  <div class="card empty"><?= icon('puzzle') ?><h3>Không có plugin nào</h3><p class="muted">Tải plugin dạng .zip lên hoặc chép vào thư mục plugins.</p></div>
<?php else: ?>
  <div class="plugin-list">
    <?php foreach ($plugins as $p): ?>
      <article class="plugin-item<?= $p['active'] ? ' is-active' : '' ?>">
        <div class="plugin-top">
          <?php if ($p['icon_url']): ?>
            <span class="plugin-icon has-image"><img src="<?= e($p['icon_url']) ?>" alt=""></span>
          <?php else: ?>
            <span class="plugin-icon"><?= icon($p['icon'] ?: 'puzzle') ?></span>
          <?php endif; ?>
          <div class="plugin-body">
            <div class="plugin-title">
              <h3><?= e($p['name']) ?></h3>
              <?php if ($p['active']): ?><span class="badge status-published">Đang bật</span><?php endif; ?>
            </div>
            <p class="plugin-meta">
              <?= $p['version'] ? 'Phiên bản ' . e($p['version']) : '' ?>
              <?php if ($p['author']): ?> ·
                <?php if ($p['author_url'] && preg_match('#^https?://#', $p['author_url'])): ?>
                  <a class="link" href="<?= e($p['author_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['author']) ?></a>
                <?php else: ?><?= e($p['author']) ?><?php endif; ?>
              <?php endif; ?>
            </p>
          </div>
        </div>
        <p class="plugin-desc"><?= e($p['description'] ?: 'Không có mô tả.') ?></p>
        <div class="plugin-actions">
          <?php if ($p['requires_php']): ?><span class="plugin-meta">PHP ≥ <?= e($p['requires_php']) ?></span><?php endif; ?>
          <span class="spacer"></span>
          <?php if (\LQ\AI\AI::enabled()): ?>
            <button type="button" class="icon-btn sm" data-ai-plugin-scan data-slug="<?= e($p['slug']) ?>" data-name="<?= e($p['name']) ?>" data-tip="Kiểm tra bằng AI: lỗ hổng bảo mật, mã độc, lỗi logic" aria-label="Kiểm tra bằng AI"><?= icon('scan-search') ?></button>
          <?php endif; ?>
          <?php if ($p['active']): ?>
            <form method="post" action="/admin/plugins/deactivate">
              <?= csrf_field() ?>
              <input type="hidden" name="plugin" value="<?= e($p['slug']) ?>">
              <button class="btn btn-secondary btn-sm" type="submit">Tắt</button>
            </form>
          <?php else: ?>
            <form method="post" action="/admin/plugins/delete" data-confirm="Xoá plugin &quot;<?= e($p['name']) ?>&quot;?" data-confirm-text="Toàn bộ tệp của plugin sẽ bị xoá khỏi máy chủ." data-confirm-danger>
              <?= csrf_field() ?>
              <input type="hidden" name="plugin" value="<?= e($p['slug']) ?>">
              <button class="icon-btn sm danger" type="submit" title="Xoá plugin"><?= icon('trash-2') ?></button>
            </form>
            <form method="post" action="/admin/plugins/activate">
              <?= csrf_field() ?>
              <input type="hidden" name="plugin" value="<?= e($p['slug']) ?>">
              <button class="btn btn-accent btn-sm" type="submit">Kích hoạt</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
