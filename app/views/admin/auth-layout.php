<!doctype html>
<html lang="vi">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
</head>
<body class="auth">
  <aside class="auth-aside">
    <div class="auth-grid" aria-hidden="true"></div>
    <div class="brand">
      <span class="brand-mark">LQ</span>
      <span class="brand-text"><strong>LQCMS</strong></span>
    </div>
    <div class="auth-quote">
      <h2>Quản lý nội dung <em>nhanh</em>, gọn và không rườm rà.</h2>
      <p>Viết bài, sắp xếp chuyên mục, đổi giao diện và mở rộng bằng plugin trong một trang quản trị duy nhất.</p>
    </div>
    <div class="auth-stats">
      <span><b>PHP</b>thuần, không framework</span>
      <span><b>MariaDB</b>nhanh và ổn định</span>
      <span><b>0</b>bước build</span>
    </div>
  </aside>
  <main class="auth-main">
    <button class="icon-btn auth-theme" type="button" data-theme-toggle title="Chế độ sáng / tối"><?= icon('moon', 'i-moon') ?><?= icon('sun', 'i-sun') ?></button>
    <div class="auth-wrap">
      <?= $content ?>
    </div>
  </main>
<?php require __DIR__ . '/partials/scripts.php'; ?>
</body>
</html>
