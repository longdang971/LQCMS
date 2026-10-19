<?php
/**
 * Template tạm cho trang chủ. Frontend thật (danh sách bài, trang bài viết, chuyên mục...) sẽ làm ở giai đoạn sau.
 * Biến có sẵn: $lq = ['path', 'site_title', 'tagline', 'theme_url']
 */
defined('LQ_ROOT') || exit;
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($lq['site_title']) ?></title>
  <link rel="stylesheet" href="<?= e($lq['theme_url']) ?>/style.css">
</head>
<body>
  <main class="soon">
    <span class="badge">Đang xây dựng</span>
    <h1><?= e($lq['site_title']) ?></h1>
    <p><?= e($lq['tagline']) ?></p>
    <a class="btn" href="/admin">Vào trang quản trị →</a>
  </main>
</body>
</html>
