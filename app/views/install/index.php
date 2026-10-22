<?php defined('LQ_ROOT') || exit; ?>
<div class="auth-card is-wide">
  <h1>Cài đặt LQCMS</h1>
  <p class="muted">Chuẩn bị sẵn thông tin database MariaDB/MySQL, việc cài đặt chỉ mất khoảng một phút.</p>

  <?php if ($configText): ?>
    <div class="notice notice-warning">
      <?= icon('triangle-alert') ?>
      <div>
        <strong>Không ghi được tệp config.php.</strong> Database đã được cài xong — hãy tạo tệp
        <code><?= e(LQ_CONFIG) ?></code> với nội dung dưới đây rồi tải lại trang.
      </div>
    </div>
    <pre class="code-block"><?= e($configText) ?></pre>
    <a class="btn btn-primary" href="/admin">Tôi đã tạo xong, vào trang quản trị</a>
  <?php else: ?>
    <ul class="req-list">
      <?php foreach ($requirements as [$label, $ok]): ?>
        <li class="<?= $ok ? 'ok' : 'fail' ?>"><?= icon($ok ? 'circle-check' : 'circle-x') ?> <?= e($label) ?></li>
      <?php endforeach; ?>
    </ul>

    <?php if (!empty($errors['_'])): ?>
      <div class="notice notice-error"><?= icon('circle-alert') ?> <div><?= e($errors['_']) ?></div></div>
    <?php endif; ?>

    <form method="post" action="/install" class="stack" data-loading-form>
      <?= csrf_field() ?>
      <fieldset class="fieldset">
        <legend><?= icon('database') ?> Database</legend>
        <div class="grid-3">
          <div class="field span-2">
            <label for="db_host">Máy chủ</label>
            <input id="db_host" name="db_host" class="input" value="<?= e(old('db_host', 'localhost')) ?>" required>
          </div>
          <div class="field">
            <label for="db_port">Cổng</label>
            <input id="db_port" name="db_port" type="number" class="input" value="<?= e(old('db_port', '3306')) ?>">
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="db_name">Tên database</label>
            <input id="db_name" name="db_name" class="input<?= invalid($errors, 'db_name') ?>" value="<?= e(old('db_name', 'lqcms')) ?>" required>
            <?= field_error($errors, 'db_name') ?>
          </div>
          <div class="field">
            <label for="db_prefix">Tiền tố bảng</label>
            <input id="db_prefix" name="db_prefix" class="input<?= invalid($errors, 'db_prefix') ?>" value="<?= e(old('db_prefix', 'lq_')) ?>">
            <?= field_error($errors, 'db_prefix') ?>
          </div>
          <div class="field">
            <label for="db_user">Người dùng</label>
            <input id="db_user" name="db_user" class="input<?= invalid($errors, 'db_user') ?>" value="<?= e(old('db_user')) ?>" required>
            <?= field_error($errors, 'db_user') ?>
          </div>
          <div class="field">
            <label for="db_pass">Mật khẩu</label>
            <input id="db_pass" name="db_pass" type="password" class="input" autocomplete="off">
          </div>
        </div>
      </fieldset>

      <fieldset class="fieldset">
        <legend><?= icon('globe') ?> Website &amp; tài khoản quản trị</legend>
        <div class="field">
          <label for="site_title">Tên website</label>
          <input id="site_title" name="site_title" class="input<?= invalid($errors, 'site_title') ?>" value="<?= e(old('site_title')) ?>" required>
          <?= field_error($errors, 'site_title') ?>
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="admin_username">Tên đăng nhập</label>
            <input id="admin_username" name="admin_username" class="input<?= invalid($errors, 'admin_username') ?>" value="<?= e(old('admin_username', 'admin')) ?>" required>
            <?= field_error($errors, 'admin_username') ?>
          </div>
          <div class="field">
            <label for="admin_email">Email</label>
            <input id="admin_email" name="admin_email" type="email" class="input<?= invalid($errors, 'admin_email') ?>" value="<?= e(old('admin_email')) ?>" required>
            <?= field_error($errors, 'admin_email') ?>
          </div>
          <div class="field">
            <label for="admin_password">Mật khẩu</label>
            <input id="admin_password" name="admin_password" type="password" minlength="8" class="input<?= invalid($errors, 'admin_password') ?>" autocomplete="new-password" required data-strength>
            <div class="strength" data-strength-bar><span></span></div>
            <?= field_error($errors, 'admin_password') ?>
          </div>
          <div class="field">
            <label for="admin_password_confirm">Nhập lại mật khẩu</label>
            <input id="admin_password_confirm" name="admin_password_confirm" type="password" class="input<?= invalid($errors, 'admin_password_confirm') ?>" autocomplete="new-password" required>
            <?= field_error($errors, 'admin_password_confirm') ?>
          </div>
        </div>
      </fieldset>

      <button class="btn btn-primary btn-lg btn-block" type="submit">Cài đặt LQCMS</button>
    </form>
  <?php endif; ?>
</div>
