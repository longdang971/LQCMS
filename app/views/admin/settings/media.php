<?php
defined('LQ_ROOT') || exit;
$o = fn (string $k, mixed $d = '') => old($k, option($k, $d));
// old() trả '' khi form gửi lại mà checkbox tắt — chỉ dùng option khi chưa có dữ liệu cũ.
$keep = !empty($_SESSION['_old_current']) ? !empty(old('media_keep_original')) : (bool) option('media_keep_original', true);
?>
<div class="page-head">
  <div>
    <h1>Cài đặt media</h1>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="submit" form="media-settings-form">Lưu thay đổi</button>
  </div>
</div>

<form method="post" action="/admin/settings/media" class="settings" id="media-settings-form" data-dirty-guard>
  <?= csrf_field() ?>

  <section class="settings-section first">
    <div class="settings-label">
      <h3>Tải lên</h3>
      <p class="muted">Ảnh nặng hơn giới hạn sẽ bị từ chối ngay khi chọn tệp.</p>
    </div>
    <div class="settings-fields">
      <div class="field">
        <label for="upload_max_mb">Dung lượng ảnh tối đa (MB)</label>
        <input id="upload_max_mb" name="upload_max_mb" type="number" min="1" max="512" class="input<?= invalid($errors, 'upload_max_mb') ?>" value="<?= (int) $o('upload_max_mb', 8) ?>" style="max-width: 160px">
        <p class="help">Máy chủ đang giới hạn <?= e(ini_get('upload_max_filesize')) ?> mỗi tệp — đặt cao hơn mức này cũng không có tác dụng.</p>
        <?= field_error($errors, 'upload_max_mb') ?>
      </div>
    </div>
  </section>

  <section class="settings-section">
    <div class="settings-label">
      <h3>Kích thước ảnh</h3>
      <p class="muted">Mỗi ảnh chỉ lưu một tệp, không tạo thêm cỡ phụ. Chỉ áp dụng cho ảnh tải lên từ nay về sau.</p>
    </div>
    <div class="settings-fields">
      <label class="switch-row boxed">
        <span>
          <strong>Giữ kích thước gốc</strong>
          <small class="muted">Lưu đúng tệp bạn tải lên, không thu nhỏ hay nén lại.</small>
        </span>
        <span class="switch">
          <input type="checkbox" name="media_keep_original" value="1"<?= $keep ? ' checked' : '' ?>>
          <span class="switch-ui"></span>
        </span>
      </label>
      <div class="stack" data-off-when="media_keep_original">
        <div class="grid-2">
          <div class="field">
            <label for="media_max_width">Chiều rộng tối đa (px)</label>
            <input id="media_max_width" name="media_max_width" type="number" min="0" max="20000" class="input<?= invalid($errors, 'media_max_width') ?>" value="<?= (int) $o('media_max_width', 2560) ?>">
            <?= field_error($errors, 'media_max_width') ?>
          </div>
          <div class="field">
            <label for="media_max_height">Chiều cao tối đa (px)</label>
            <input id="media_max_height" name="media_max_height" type="number" min="0" max="20000" class="input<?= invalid($errors, 'media_max_height') ?>" value="<?= (int) $o('media_max_height', 2560) ?>">
            <?= field_error($errors, 'media_max_height') ?>
          </div>
        </div>
        <p class="help">Ảnh lớn hơn khung này được thu nhỏ theo đúng tỉ lệ; ảnh nhỏ hơn giữ nguyên. Nhập 0 để không giới hạn chiều đó. Ảnh GIF luôn giữ nguyên để không mất ảnh động.</p>
        <div class="field">
          <label for="media_quality">Chất lượng nén (JPG, WEBP, AVIF)</label>
          <input id="media_quality" name="media_quality" type="number" min="10" max="100" class="input<?= invalid($errors, 'media_quality') ?>" value="<?= (int) $o('media_quality', 82) ?>" style="max-width: 160px">
          <p class="help">10–100. Khoảng 80–85 cho ảnh nhẹ mà mắt thường khó thấy khác biệt. PNG nén không mất chất lượng nên không dùng mức này.</p>
          <?= field_error($errors, 'media_quality') ?>
        </div>
      </div>
    </div>
  </section>

  <?php do_action('settings_media_fields'); ?>

  <div class="form-footer">
    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
  </div>
</form>
