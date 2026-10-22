<?php defined('LQ_ROOT') || exit; ?>
<div class="auth-card">
  <h1>Tạo tài khoản</h1>
  <?php if (!$enabled): ?>
    <div class="notice notice-warning"><?= icon('info') ?> Website hiện không mở đăng ký thành viên.</div>
    <p class="auth-switch"><a href="/admin/login">← Quay lại đăng nhập</a></p>
  <?php else: ?>
    <p class="muted">Điền thông tin bên dưới để tạo tài khoản mới.</p>
    <form method="post" action="/admin/register" class="stack" data-loading-form>
      <?= csrf_field() ?>
      <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="grid-2">
        <div class="field">
          <label for="username">Tên đăng nhập</label>
          <input id="username" name="username" class="input<?= invalid($errors, 'username') ?>" value="<?= e(old('username')) ?>" autocomplete="username" required>
          <?= field_error($errors, 'username') ?>
        </div>
        <div class="field">
          <label for="display_name">Tên hiển thị</label>
          <input id="display_name" name="display_name" class="input" value="<?= e(old('display_name')) ?>" placeholder="Nguyễn Văn A">
        </div>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" class="input<?= invalid($errors, 'email') ?>" value="<?= e(old('email')) ?>" autocomplete="email" required>
        <?= field_error($errors, 'email') ?>
      </div>
      <div class="field">
        <label for="password">Mật khẩu</label>
        <div class="input-icon">
          <?= icon('lock-keyhole') ?>
          <input id="password" name="password" type="password" class="input<?= invalid($errors, 'password') ?>" minlength="8" autocomplete="new-password" required data-strength>
          <button type="button" class="input-addon" data-toggle-password aria-label="Hiện mật khẩu"><?= icon('eye') ?></button>
        </div>
        <div class="strength" data-strength-bar><span></span></div>
        <?= field_error($errors, 'password') ?>
      </div>
      <div class="field">
        <label for="password_confirm">Nhập lại mật khẩu</label>
        <input id="password_confirm" name="password_confirm" type="password" class="input<?= invalid($errors, 'password_confirm') ?>" autocomplete="new-password" required>
        <?= field_error($errors, 'password_confirm') ?>
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Tạo tài khoản</button>
    </form>
    <p class="auth-switch">Đã có tài khoản? <a href="/admin/login">Đăng nhập</a></p>
  <?php endif; ?>
</div>
