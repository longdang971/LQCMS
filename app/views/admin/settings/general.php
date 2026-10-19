<?php
use LQ\Admin\SettingsController;
use LQ\Core\Auth;

$o = fn (string $k, mixed $d = '') => old($k, option($k, $d));
$dateFormat = (string) $o('date_format', 'd/m/Y');
$timeFormat = (string) $o('time_format', 'H:i');
$nowLocal = new DateTimeImmutable('now', site_timezone());
$tzGroups = [];
foreach ($timezones as $tz) {
    $tzGroups[str_contains($tz, '/') ? explode('/', $tz, 2)[0] : 'Khác'][] = $tz;
}
?>
<div class="page-head">
  <div>
    <h1>Cài đặt chung</h1>
    <p>Thông tin cơ bản, thành viên, ngày giờ và nội dung mặc định.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="submit" form="settings-form">Lưu thay đổi</button>
  </div>
</div>

<form method="post" action="/admin/settings" class="settings" id="settings-form" data-dirty-guard>
  <?= csrf_field() ?>

  <section class="settings-section first">
    <div class="settings-label">
      <h3>Website</h3>
      <p class="muted">Tên và mô tả xuất hiện ở tiêu đề trang, thẻ meta, email.</p>
    </div>
    <div class="settings-fields">
      <div class="field">
        <label for="site_title">Tên website</label>
        <input id="site_title" name="site_title" class="input<?= invalid($errors, 'site_title') ?>" value="<?= e($o('site_title')) ?>" required>
        <?= field_error($errors, 'site_title') ?>
      </div>
      <div class="field">
        <label for="tagline">Khẩu hiệu</label>
        <input id="tagline" name="tagline" class="input" value="<?= e($o('tagline')) ?>" placeholder="Vài chữ giới thiệu website">
      </div>
      <div class="field">
        <label for="site_url">Địa chỉ website (URL)</label>
        <input id="site_url" name="site_url" type="url" class="input mono<?= invalid($errors, 'site_url') ?>" value="<?= e($o('site_url')) ?>" required>
        <p class="help">Dùng để tạo đường dẫn đầy đủ, vd. <code>https://lqcms.vn</code>.</p>
        <?= field_error($errors, 'site_url') ?>
      </div>
      <div class="field">
        <label for="admin_email">Email quản trị</label>
        <input id="admin_email" name="admin_email" type="email" class="input<?= invalid($errors, 'admin_email') ?>" value="<?= e($o('admin_email')) ?>" required>
        <p class="help">Nhận thông báo quản trị của hệ thống.</p>
        <?= field_error($errors, 'admin_email') ?>
      </div>
    </div>
  </section>

  <section class="settings-section">
    <div class="settings-label">
      <h3>Thành viên</h3>
      <p class="muted">Cho phép khách tự tạo tài khoản tại <code>/admin/register</code>.</p>
    </div>
    <div class="settings-fields">
      <label class="switch-row boxed">
        <span>
          <strong>Cho phép đăng ký</strong>
          <small class="muted">Bất kỳ ai cũng có thể đăng ký tài khoản.</small>
        </span>
        <span class="switch">
          <input type="checkbox" name="users_can_register" value="1"<?= $o('users_can_register') ? ' checked' : '' ?>>
          <span class="switch-ui"></span>
        </span>
      </label>
      <div class="field">
        <label for="default_role">Vai trò mặc định của thành viên mới</label>
        <select id="default_role" name="default_role" class="input<?= invalid($errors, 'default_role') ?>">
          <?php foreach (Auth::ROLES as $key => $label): if ($key === 'admin') continue; ?>
            <option value="<?= e($key) ?>"<?= selected($key, $o('default_role', 'subscriber')) ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'default_role') ?>
      </div>
    </div>
  </section>

  <section class="settings-section">
    <div class="settings-label">
      <h3>Ngày giờ</h3>
      <p class="muted">Giờ hiện tại theo cài đặt: <strong><?= e($nowLocal->format('H:i d/m/Y')) ?></strong></p>
    </div>
    <div class="settings-fields">
      <div class="field">
        <label for="timezone">Múi giờ</label>
        <select id="timezone" name="timezone" class="input<?= invalid($errors, 'timezone') ?>">
          <?php foreach ($tzGroups as $group => $list): ?>
            <optgroup label="<?= e($group) ?>">
              <?php foreach ($list as $tz): ?>
                <option value="<?= e($tz) ?>"<?= selected($tz, $o('timezone', 'Asia/Ho_Chi_Minh')) ?>><?= e(str_replace('_', ' ', $tz)) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'timezone') ?>
      </div>
      <div class="grid-2">
        <fieldset class="field radio-group">
          <legend>Định dạng ngày</legend>
          <?php $isCustom = !in_array($dateFormat, SettingsController::DATE_FORMATS, true); ?>
          <?php foreach (SettingsController::DATE_FORMATS as $f): ?>
            <label class="check"><input type="radio" name="date_format" value="<?= e($f) ?>"<?= checked($f, $dateFormat) ?>>
              <span><?= e($nowLocal->format($f)) ?> <code class="muted"><?= e($f) ?></code></span></label>
          <?php endforeach; ?>
          <label class="check"><input type="radio" name="date_format" value="custom"<?= $isCustom ? ' checked' : '' ?>>
            <span>Tuỳ chỉnh <input class="input input-sm mono w-6" name="date_format_custom" value="<?= e($isCustom ? $dateFormat : 'd/m/Y') ?>"></span></label>
          <?= field_error($errors, 'date_format') ?>
        </fieldset>
        <fieldset class="field radio-group">
          <legend>Định dạng giờ</legend>
          <?php $isCustom = !in_array($timeFormat, SettingsController::TIME_FORMATS, true); ?>
          <?php foreach (SettingsController::TIME_FORMATS as $f): ?>
            <label class="check"><input type="radio" name="time_format" value="<?= e($f) ?>"<?= checked($f, $timeFormat) ?>>
              <span><?= e($nowLocal->format($f)) ?> <code class="muted"><?= e($f) ?></code></span></label>
          <?php endforeach; ?>
          <label class="check"><input type="radio" name="time_format" value="custom"<?= $isCustom ? ' checked' : '' ?>>
            <span>Tuỳ chỉnh <input class="input input-sm mono w-6" name="time_format_custom" value="<?= e($isCustom ? $timeFormat : 'H:i') ?>"></span></label>
          <?= field_error($errors, 'time_format') ?>
        </fieldset>
      </div>
      <p class="help">Ký hiệu theo hàm <code>date()</code> của PHP: d = ngày, m = tháng, Y = năm, H = giờ 24h, i = phút.</p>
    </div>
  </section>

  <section class="settings-section">
    <div class="settings-label">
      <h3>Nội dung</h3>
      <p class="muted">Mặc định khi viết bài và hiển thị.</p>
    </div>
    <div class="settings-fields">
      <div class="grid-2">
        <div class="field">
          <label for="posts_per_page">Số bài mỗi trang</label>
          <input id="posts_per_page" name="posts_per_page" type="number" min="1" max="100" class="input<?= invalid($errors, 'posts_per_page') ?>" value="<?= (int) $o('posts_per_page', 10) ?>">
          <?= field_error($errors, 'posts_per_page') ?>
        </div>
        <div class="field">
          <label for="upload_max_mb">Dung lượng ảnh tối đa (MB)</label>
          <input id="upload_max_mb" name="upload_max_mb" type="number" min="1" max="512" class="input<?= invalid($errors, 'upload_max_mb') ?>" value="<?= (int) $o('upload_max_mb', 8) ?>">
          <p class="help">Máy chủ đang giới hạn <?= e(ini_get('upload_max_filesize')) ?> mỗi tệp.</p>
          <?= field_error($errors, 'upload_max_mb') ?>
        </div>
      </div>
      <div class="field">
        <label for="revisions_keep">Số phiên bản giữ lại cho mỗi bài</label>
        <input id="revisions_keep" name="revisions_keep" type="number" min="0" max="200" class="input<?= invalid($errors, 'revisions_keep') ?>" value="<?= (int) $o('revisions_keep', 25) ?>" style="max-width: 160px">
        <p class="help">Mỗi lần bấm lưu tạo một phiên bản để xem lại và khôi phục. Nhập 0 để tắt lịch sử phiên bản (vẫn tự lưu).</p>
        <?= field_error($errors, 'revisions_keep') ?>
      </div>
      <div class="field">
        <label for="default_category">Chuyên mục mặc định</label>
        <select id="default_category" name="default_category" class="input<?= invalid($errors, 'default_category') ?>">
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>"<?= selected($c['id'], $o('default_category')) ?>><?= str_repeat('— ', $c['depth']) . e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="help">Bài viết không chọn chuyên mục nào sẽ được xếp vào đây.</p>
        <?= field_error($errors, 'default_category') ?>
      </div>
    </div>
  </section>

  <section class="settings-section" id="tac-vu">
    <div class="settings-label">
      <h3>Tác vụ định kỳ</h3>
      <p>Đăng bài đã lên lịch khi tới giờ, dọn dữ liệu cũ, và tác vụ của plugin. Không cần cài cron trên máy chủ.</p>
    </div>
    <div class="settings-fields" style="max-width: none">
      <div class="cron-status<?= $cron['last_at'] && time() - $cron['last_at'] < 600 ? ' is-ok' : '' ?>">
        <?= icon($cron['last_at'] ? 'circle-check' : 'clock') ?>
        <div>
          <strong><?= $cron['last_at'] ? 'Tự chạy theo lượt truy cập' : 'Chưa chạy lần nào' ?></strong>
          <small><?= $cron['last_at'] ? 'Lần chạy gần nhất: ' . e(format_date(gmdate('Y-m-d H:i:s', $cron['last_at']), 'H:i:s d/m/Y')) . ($cron['last_via'] === 'manual' ? ' (bấm chạy tay)' : '') : 'Bấm “Chạy ngay” để thử.' ?></small>
        </div>
      </div>
      <p class="help">Mỗi khi có người truy cập website hoặc trang quản trị, hệ thống kiểm tra tối đa 1 lần/phút và chạy các tác vụ đến hạn sau khi đã trả trang, nên người xem không phải chờ. Nếu website không có lượt truy cập nào, bài đã lên lịch sẽ được đăng ở lượt truy cập kế tiếp.</p>
      <div class="table-wrap boxed" style="padding: 0">
        <table class="table">
          <thead><tr><th>Tác vụ</th><th>Chu kỳ</th><th>Lần chạy cuối</th><th>Kết quả</th></tr></thead>
          <tbody>
            <?php foreach ($cron['tasks'] as $name => $t): $s = $t['state']; ?>
              <tr>
                <td><strong><?= e($t['label']) ?></strong><div class="row-sub"><?= e($name) ?></div></td>
                <td class="date-cell"><?= $t['interval'] >= 86400 ? 'Mỗi ' . intdiv($t['interval'], 86400) . ' ngày' : ($t['interval'] >= 3600 ? 'Mỗi ' . intdiv($t['interval'], 3600) . ' giờ' : 'Mỗi ' . intdiv($t['interval'], 60) . ' phút') ?></td>
                <td class="date-cell"><?= isset($s['last']) ? e(format_date(gmdate('Y-m-d H:i:s', $s['last']), 'H:i d/m')) : '—' ?></td>
                <td>
                  <?php if (!isset($s['last'])): ?><span class="muted">Chưa chạy</span>
                  <?php elseif ($s['ok']): ?><span class="badge status-published"><?= e($s['result'] ?: 'Xong') ?></span>
                  <?php else: ?><span class="badge status-trash" title="<?= e($s['error']) ?>">Lỗi: <?= e(mb_strimwidth((string) $s['error'], 0, 60, '…')) ?></span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div><button class="btn btn-secondary btn-sm" type="submit" form="cron-run-form"><?= icon('play') ?> Chạy ngay</button></div>
    </div>
  </section>

  <?php do_action('settings_general_fields'); ?>

  <div class="form-footer">
    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
  </div>
</form>

<form id="cron-run-form" method="post" action="/admin/settings/cron-run" hidden><?= csrf_field() ?></form>
