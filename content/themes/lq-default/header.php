<?php defined('LQ_ROOT') || exit; ?>
<!doctype html>
<html lang="<?php bloginfo('language'); ?>">
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap">
  <title><?= e(get_document_title()) ?></title>
<?php
$lqd_desc = lq_meta_description();
$lqd_canonical = lq_canonical_url();
$lqd_obj = get_queried_object();
$lqd_image = is_singular() ? get_the_post_thumbnail_url($lqd_obj) : '';
?>
<?php if ($lqd_desc !== ''): ?>
  <meta name="description" content="<?= e($lqd_desc) ?>">
<?php endif; ?>
<?php if (is_404() || is_search() || is_preview()): ?>
  <meta name="robots" content="noindex">
<?php endif; ?>
<?php if ($lqd_canonical !== ''): ?>
  <link rel="canonical" href="<?= e($lqd_canonical) ?>">
  <meta property="og:url" content="<?= e($lqd_canonical) ?>">
<?php endif; ?>
  <meta property="og:site_name" content="<?php bloginfo('name'); ?>">
  <meta property="og:title" content="<?= e(is_singular() ? get_the_title($lqd_obj) : get_document_title()) ?>">
  <meta property="og:type" content="<?= is_single() ? 'article' : 'website' ?>">
<?php if ($lqd_desc !== ''): ?>
  <meta property="og:description" content="<?= e($lqd_desc) ?>">
<?php endif; ?>
<?php if ($lqd_image !== ''): ?>
  <meta property="og:image" content="<?= e($lqd_image) ?>">
<?php endif; ?>
  <script>try{var t=localStorage.getItem("lqd-theme");if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
  <link rel="stylesheet" href="<?= e(theme_url('style.css')) ?>">
  <?php lq_script(); ?>
</head>
<body<?php body_class(); ?>>
<?php lq_body_open(); ?>
<a class="skip-link" href="#main">Bỏ qua tới nội dung</a>

<?php if (is_preview()): ?>
  <div class="preview-bar">Bản xem trước — bài này chưa đăng nên chỉ người có quyền sửa mới thấy. <?php edit_post_link('Quay lại sửa'); ?></div>
<?php endif; ?>

<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="<?= e(home_url('/')) ?>" rel="home">
      <span class="brand-mark" aria-hidden="true"></span>
      <span class="brand-name"><?php bloginfo('name'); ?></span>
    </a>

    <nav class="main-nav" id="main-nav" aria-label="Menu chính">
      <?php foreach (lqd_nav_items() as $item): ?>
        <a href="<?= e($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="header-tools">
      <form class="header-search" role="search" method="get" action="<?= e(home_url('/')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="s" value="<?php the_search_query(); ?>" placeholder="Tìm bài viết…" aria-label="Tìm kiếm">
        <kbd>/</kbd>
      </form>
      <button class="icon-btn" type="button" data-theme-toggle aria-label="Đổi giao diện sáng/tối">
        <svg class="i-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="i-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
      </button>
      <button class="icon-btn nav-toggle" type="button" data-nav-toggle aria-controls="main-nav" aria-expanded="false" aria-label="Mở menu">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
    </div>
  </div>
</header>

<main id="main" class="site-main">
