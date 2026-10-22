<?php
defined('LQ_ROOT') || exit;
use LQ\Core\Auth;

$initials = mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 1));
?>
<div class="profile-head">
  <span class="avatar xl"><?= e($initials) ?></span>
  <div>
    <h1><?= e($user['display_name']) ?></h1>
    <p class="muted">@<?= e($user['username']) ?> · <?= e(Auth::ROLES[$user['role']] ?? $user['role']) ?> · tham gia <?= e(format_date($user['created_at'], (string) option('date_format', 'd/m/Y'))) ?></p>
  </div>
</div>

<form method="post" action="/admin/profile" class="settings" id="profile-form" data-dirty-guard>
  <?= csrf_field() ?>
  <section class="settings-section">
    <div class="settings-label">
      <h3>Thông tin</h3>
      <p class="muted">Tên hiển thị xuất hiện ở bài viết của bạn.</p>
    </div>
    <div class="settings-fields">
      <div class="field">
        <label>Tên đăng nhập</label>
        <input class="input" value="<?= e($user['username']) ?>" disabled>
        <p class="help">Không thể đổi tên đăng nhập.</p>
      </div>
      <div class="field">
        <label for="display_name">Tên hiển thị</label>
        <input id="display_name" name="display_name" class="input<?= invalid($errors, 'display_name') ?>" value="<?= e(old('display_name', $user['display_name'])) ?>" required>
        <?= field_error($errors, 'display_name') ?>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" class="input<?= invalid($errors, 'email') ?>" value="<?= e(old('email', $user['email'])) ?>" required>
        <?= field_error($errors, 'email') ?>
      </div>
    </div>
  </section>

  <section class="settings-section">
    <div class="settings-label">
      <h3>Đổi mật khẩu</h3>
      <p class="muted">Để trống nếu không muốn đổi. Đổi mật khẩu sẽ đăng xuất các thiết bị đang "ghi nhớ đăng nhập".</p>
    </div>
    <div class="settings-fields">
      <div class="grid-2">
        <div class="field">
          <label for="new_password">Mật khẩu mới</label>
          <input id="new_password" name="new_password" type="password" minlength="8" class="input<?= invalid($errors, 'new_password') ?>" autocomplete="new-password" data-strength>
          <div class="strength" data-strength-bar><span></span></div>
          <?= field_error($errors, 'new_password') ?>
        </div>
        <div class="field">
          <label for="new_password_confirm">Nhập lại mật khẩu mới</label>
          <input id="new_password_confirm" name="new_password_confirm" type="password" class="input<?= invalid($errors, 'new_password_confirm') ?>" autocomplete="new-password">
          <?= field_error($errors, 'new_password_confirm') ?>
        </div>
      </div>
      <div class="field">
        <label for="current_password">Mật khẩu hiện tại</label>
        <input id="current_password" name="current_password" type="password" class="input<?= invalid($errors, 'current_password') ?>" autocomplete="current-password">
        <p class="help">Bắt buộc khi đổi email hoặc mật khẩu.</p>
        <?= field_error($errors, 'current_password') ?>
      </div>
    </div>
  </section>

  <section class="settings-section" id="admin-bar">
    <div class="settings-label">
      <h3>Thanh quản trị</h3>
      <p class="muted">Thanh màu đen ở mép trên khi bạn xem website lúc đã đăng nhập.</p>
    </div>
    <div class="settings-fields">
      <label class="switch-row boxed">
        <span>
          <strong>Hiện thanh quản trị khi xem website</strong>
          <small class="muted">Lối tắt tới Bảng tin, viết bài mới, sửa trang đang xem. Chỉ áp dụng cho tài khoản của bạn.</small>
        </span>
        <span class="switch">
          <input type="checkbox" name="admin_bar" value="1"<?= option('admin_bar_' . $user['id'], true) !== false ? ' checked' : '' ?>>
          <span class="switch-ui"></span>
        </span>
      </label>
    </div>
  </section>

  <div class="form-footer">
    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
  </div>
</form>
