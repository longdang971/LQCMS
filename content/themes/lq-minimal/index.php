<?php
defined('LQ_ROOT') || exit;
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($lq['site_title']) ?></title>
  <style>
    body{margin:0;min-height:100vh;display:flex;flex-direction:column;justify-content:center;padding:8vw;background:#0f1117;color:#f5f5f5;font-family:Georgia,"Times New Roman",serif}
    small{font-family:ui-monospace,Menlo,monospace;color:#a1a1aa;letter-spacing:.1em;text-transform:uppercase}
    h1{font-size:clamp(40px,8vw,96px);line-height:1;margin:16px 0;letter-spacing:-.03em}
    p{color:#9aa0b8;font-size:20px;max-width:40ch}
    a{color:#fafafa}
  </style>
</head>
<body>
  <small>Sắp ra mắt</small>
  <h1><?= e($lq['site_title']) ?></h1>
  <p><?= e($lq['tagline']) ?> · <a href="/admin">Quản trị</a></p>
</body>
</html>
