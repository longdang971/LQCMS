<?php
defined('LQ_ROOT') || exit;
/** Cài đặt › AI: mỗi nhà cung cấp một thẻ — cài thư viện, nhập key, chọn model; chọn một nhà cung cấp để bật trợ lý. */
?>
<div class="page-head">
  <div>
    <h1>Trợ lý AI</h1>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="submit" form="ai-settings-form">Lưu thay đổi</button>
  </div>
</div>

<?php if ($cannotInstall): ?>
  <div class="notice notice-warning"><?= icon('triangle-alert') ?><div><b>Không tự cài được thư viện:</b> <?= e($cannotInstall) ?> Bạn có thể cài tay bằng lệnh ghi ở từng thẻ bên dưới.</div></div>
<?php endif; ?>

<form method="post" action="/admin/settings/ai" id="ai-settings-form" class="ai-settings" data-dirty-guard>
  <?= csrf_field() ?>

  <label class="ai-off switch-row boxed">
    <span><strong>Tắt trợ lý AI</strong><small class="muted">Ẩn mọi nút AI trong admin. Key và model đã nhập vẫn được giữ.</small></span>
    <input type="radio" name="ai_provider" value=""<?= $active === '' ? ' checked' : '' ?>>
  </label>

  <div class="ai-grid">
    <?php foreach ($providers as $p): $id = $p['id']; ?>
      <section class="ai-card<?= $active === $id ? ' is-active' : '' ?>" data-ai-card="<?= e($id) ?>">
        <div class="ai-card-top">
          <span class="ai-logo" aria-hidden="true"><?= \LQ\AI\Connectors::logo($id) ?></span>
          <label class="ai-use">
            <input type="radio" name="ai_provider" value="<?= e($id) ?>"<?= $active === $id ? ' checked' : '' ?><?= $p['version'] ? '' : ' disabled' ?>>
            <span class="ai-use-off">Dùng</span><span class="ai-use-on">Đang dùng</span>
          </label>
        </div>
        <h3><?= e($p['label']) ?> <span class="muted">· <?= e($p['vendor']) ?></span></h3>
        <p class="ai-status small" data-ai-status><?php if ($p['version']): ?>Đã cài thư viện v<?= e($p['version']) ?><?php else: ?>Chưa cài thư viện<?php endif; ?></p>
        <p class="ai-note muted small"><?= e($p['note']) ?></p>
        <div class="ai-card-foot">
          <button type="button" class="btn btn-accent btn-sm" data-ai-install<?= $p['version'] ? ' hidden' : '' ?><?= $cannotInstall ? ' disabled' : '' ?>><?= icon('download') ?> Cài đặt</button>
          <button type="button" class="btn btn-secondary btn-sm" data-ai-configure<?= $p['version'] ? '' : ' hidden' ?>><?= icon('sliders-horizontal') ?> Cấu hình</button>
          <button type="button" class="btn btn-ghost btn-sm danger ai-remove" data-ai-uninstall<?= $p['version'] ? '' : ' hidden' ?> data-tip="Gỡ thư viện <?= e($p['label']) ?>" aria-label="Gỡ thư viện <?= e($p['label']) ?>"><?= icon('trash-2') ?></button>
        </div>
        <?php if ($cannotInstall): ?><code class="ai-manual"><?= e(\LQ\AI\Connectors::manualCommand($id)) ?></code><?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <?php // Panel cấu hình dùng chung: chỉ hiện phần của nhà cung cấp đang mở (bấm "Cấu hình" trên card). ?>
  <div class="ai-config card">
    <?php foreach ($providers as $p): $id = $p['id']; ?>
      <div class="ai-panel" data-ai-panel="<?= e($id) ?>" hidden>
        <div class="ai-panel-head">
          <span class="ai-logo sm" aria-hidden="true"><?= \LQ\AI\Connectors::logo($id) ?></span>
          <h3>Cấu hình <?= e($p['label']) ?></h3>
          <div class="ai-panel-actions">
            <button type="button" class="btn btn-ghost btn-sm" data-ai-install data-update<?= $p['version'] ? '' : ' hidden' ?><?= $cannotInstall ? ' disabled' : '' ?>><?= icon('refresh-cw') ?> Cập nhật thư viện</button>
          </div>
        </div>
        <div class="ai-fields" data-ai-fields<?= $p['version'] ? '' : ' hidden' ?>>
          <div class="field">
            <label for="key_<?= e($id) ?>">API key <a class="link small" href="<?= e($p['key_url']) ?>" target="_blank" rel="noopener noreferrer">Lấy key</a></label>
            <input class="input mono" type="password" id="key_<?= e($id) ?>" name="key_<?= e($id) ?>" autocomplete="off" placeholder="<?= $p['key_hint'] ? 'Đã lưu (' . e($p['key_hint']) . ') — để trống nếu không đổi' : 'Dán API key' ?>" data-ai-key>
            <?php if ($p['key_hint']): ?>
              <label class="check small"><input type="checkbox" name="clear_key_<?= e($id) ?>" value="1"> Xoá key đã lưu</label>
            <?php endif; ?>
          </div>
          <div class="field">
            <label for="model_<?= e($id) ?>">Model</label>
            <div class="ai-model-field">
              <input class="mono" id="model_<?= e($id) ?>" name="model_<?= e($id) ?>" value="<?= e($p['model']) ?>" placeholder="Bấm ↻ để tải danh sách model" autocomplete="off" data-ai-model>
              <select class="mono" name="model_<?= e($id) ?>" aria-label="Model" data-ai-model-select hidden disabled></select>
              <span class="ai-model-tools">
                <button type="button" data-ai-model-manual hidden data-tip="Gõ tên model" aria-label="Gõ tên model"><?= icon('pencil') ?></button>
                <button type="button" data-ai-load-models data-tip="Tải danh sách model" aria-label="Tải danh sách model"><?= icon('refresh-cw') ?></button>
              </span>
            </div>
          </div>
          <div class="ai-test">
            <button type="button" class="btn btn-secondary btn-sm" data-ai-test><?= icon('zap') ?> Kiểm tra kết nối</button>
            <span class="small" data-ai-test-result></span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="ai-config-empty" data-ai-config-empty hidden>
      <?= icon('plug') ?>
      <p>Cài thư viện cho một nhà cung cấp ở trên, rồi nhập API key và chọn model tại đây.</p>
    </div>
  </div>

  <p class="help">API key được mã hoá trước khi lưu vào database. Chi phí gọi AI tính vào tài khoản của nhà cung cấp mà key thuộc về. Nội dung bài viết/tệp theme được gửi tới nhà cung cấp đang dùng mỗi khi bấm nút AI.</p>

  <div class="form-footer">
    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
  </div>
</form>
