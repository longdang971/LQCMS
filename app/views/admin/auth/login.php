<div class="auth-card">
  <h1>Đăng nhập</h1>
  <p class="muted">Nhập tài khoản để vào trang quản trị.</p>

  <form method="post" action="/admin/login" class="stack" data-loading-form>
    <?= csrf_field() ?>
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <div class="field">
      <label for="login">Tên đăng nhập hoặc email</label>
      <div class="input-icon">
        <?= icon('user-round') ?>
        <input id="login" name="login" class="input" placeholder="admin hoặc ban@email.com" value="<?= e(old('login')) ?>" autocomplete="username" required autofocus>
      </div>
    </div>
    <div class="field">
      <label for="password">Mật khẩu</label>
      <div class="input-icon">
        <?= icon('lock-keyhole') ?>
        <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
        <button type="button" class="input-addon" data-toggle-password aria-label="Hiện mật khẩu"><?= icon('eye') ?></button>
      </div>
    </div>
    <label class="check">
      <input type="checkbox" name="remember" value="1">
      <span>Ghi nhớ đăng nhập trong 30 ngày</span>
    </label>
    <button class="btn btn-primary btn-block btn-lg" type="submit">Đăng nhập</button>
  </form>

  <?php if ($canRegister): ?>
    <p class="auth-switch">Chưa có tài khoản? <a href="/admin/register">Đăng ký ngay</a></p>
  <?php endif; ?>
</div>
