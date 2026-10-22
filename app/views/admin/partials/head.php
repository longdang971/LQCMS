<?php defined('LQ_ROOT') || exit; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<?php
// $subject: tên thứ đang xem/sửa (tiêu đề bài, tên chuyên mục, tệp…) — đứng trước tên trang trên tab trình duyệt.
$siteName = is_file(LQ_CONFIG) ? option('site_title', 'LQCMS') : 'LQCMS';
?>
<title data-page="<?= e($title ?? 'Quản trị') ?>" data-site="<?= e($siteName) ?>"><?= e((($subject ?? '') !== '' ? $subject . ' — ' : '') . ($title ?? 'Quản trị') . ' · ' . $siteName) ?></title>
<script>
  // Áp theme sáng/tối trước khi vẽ trang để không bị nháy.
  (function () {
    try {
      var t = localStorage.getItem('lq-theme');
      if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.dataset.theme = 'dark';
    } catch (e) {}
  })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
<script>window.LQ_FLASH = <?= json_encode(\LQ\Core\Session::pullFlash(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
