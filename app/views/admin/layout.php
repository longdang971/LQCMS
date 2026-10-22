<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\Kernel;
use LQ\Core\Auth;

$user = current_user();
$path = request_path();
$menu = Kernel::menu();

// Dữ liệu cho thanh lệnh (⌘K / Ctrl K): mọi trang trong menu (đã lọc theo quyền).
$navItems = [];
$hint = fn (string $url, array $it = []) => \LQ\Admin\Kernel::PAGE_HINTS[$url] ?? ($it['keywords'] ?? '');
foreach ($menu as $item) {
    if (isset($item['section'])) {
        continue;
    }
    $navItems[] = ['label' => $item['label'], 'url' => $item['url'], 'icon' => $item['icon'] ?? 'circle', 'keywords' => $hint($item['url'], $item)];
    foreach ($item['children'] ?? [] as $c) {
        $navItems[] = ['label' => $c['label'], 'url' => $c['url'], 'icon' => $item['icon'] ?? 'circle', 'parent' => $item['label'], 'keywords' => $hint($c['url'], $c)];
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
<?php require __DIR__ . '/partials/head.php'; ?>
<?php do_action('admin_head'); ?>
</head>
<body class="admin">
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="/admin">
      <span class="brand-mark">LQ</span>
      <span class="brand-text">
        <strong><?= e(option('site_title', 'LQCMS')) ?></strong>
        <small><?= e(preg_replace('#^https?://#', '', (string) option('site_url', ''))) ?></small>
      </span>
    </a>

    <button class="sidebar-search" type="button" data-cmdk-open>
      <?= icon('search') ?><span>Tìm kiếm…</span><?= kbd('K') ?>
    </button>

    <nav class="nav">
      <?php foreach ($menu as $item): ?>
        <?php if (isset($item['section'])): ?>
          <div class="nav-section"><?= e($item['section']) ?></div>
          <?php continue; endif; ?>
        <?php $active = Kernel::isActive($item, $path); $hasChildren = !empty($item['children']); ?>
        <div class="nav-group<?= $active ? ' is-open' : '' ?>">
          <a class="nav-link<?= $active ? ' is-active' : '' ?>" href="<?= e($item['url']) ?>">
            <?= icon($item['icon'] ?? 'circle') ?>
            <span><?= e($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= e($item['badge']) ?></span><?php endif; ?>
            <?php if ($hasChildren): ?><?= icon('chevron-down', 'nav-caret') ?><?php endif; ?>
          </a>
          <?php if ($hasChildren): ?>
            <div class="nav-children">
              <?php foreach ($item['children'] as $child): ?>
                <a class="nav-sublink<?= Kernel::isActive($child, $path) ? ' is-active' : '' ?>" href="<?= e($child['url']) ?>"><?= e($child['label']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-user dropdown dropup" data-dropdown>
      <button class="sidebar-user-btn" type="button" data-dropdown-toggle aria-haspopup="menu">
        <?= avatar($user['display_name'] ?: $user['username'], '', (string) $user['id']) ?>
        <span class="sidebar-user-meta">
          <strong><?= e($user['display_name']) ?></strong>
          <small><?= e(Auth::ROLES[$user['role']] ?? $user['role']) ?></small>
        </span>
        <?= icon('chevrons-up-down') ?>
      </button>
      <div class="dropdown-menu" role="menu">
        <div class="dropdown-head">
          <strong><?= e($user['display_name']) ?></strong>
          <small><?= e($user['email']) ?></small>
        </div>
        <a href="/admin/profile"><?= icon('user-round') ?> Hồ sơ</a>
        <button type="button" data-theme-toggle><?= icon('moon', 'i-moon') ?><?= icon('sun', 'i-sun') ?> Đổi giao diện sáng / tối</button>
        <form method="post" action="/admin/logout">
          <?= csrf_field() ?>
          <button type="submit"><?= icon('log-out') ?> Đăng xuất</button>
        </form>
      </div>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>

  <div class="main">
    <header class="topbar">
      <button class="topbar-btn" type="button" data-sidebar-open aria-label="Mở menu"><?= icon('menu') ?></button>
      <a class="topbar-brand" href="/admin">
        <span class="brand-mark">LQ</span>
        <span class="topbar-titles">
          <strong><?= e(option('site_title', 'LQCMS')) ?></strong>
          <small><?= e($title ?? 'Quản trị') ?></small>
        </span>
      </a>
      <div class="topbar-actions">
        <button class="topbar-btn" type="button" data-cmdk-open aria-label="Tìm kiếm"><?= icon('search') ?></button>
      </div>
    </header>

    <main class="content">
      <?php if (LQ_CONFIG !== LQ_CONFIG_OUTSIDE && can('manage_options')): ?>
        <div class="notice notice-warning config-notice"><?= icon('shield-alert') ?><div>
          <strong>config.php đang nằm trong thư mục web.</strong> LQCMS không ghi được thư mục cha để tự chuyển ra ngoài
          (thiếu quyền ghi, hoặc panel hosting giới hạn open_basedir). Chuyển tay để mật khẩu database không bao giờ tải được qua web:
          <code>mv <?= e(LQ_CONFIG) ?> <?= e(LQ_CONFIG_OUTSIDE) ?></code>
        </div></div>
      <?php endif; ?>
      <?= $content ?>
    </main>

    <footer class="admin-footer">
      <div class="admin-footer-brand">
        <span><strong>Powered by LQCMS <?= e(LQ_VERSION) ?></strong></span>
      </div>
    </footer>
  </div>
</div>

<div class="cmdk" data-cmdk hidden>
  <div class="cmdk-backdrop" data-cmdk-close></div>
  <div class="cmdk-panel" role="dialog" aria-modal="true" aria-label="Thanh lệnh">
    <div class="cmdk-input">
      <?= icon('search') ?>
      <input type="text" placeholder="Tìm bài viết, chuyên mục, trang hoặc lệnh…" data-cmdk-input autocomplete="off" spellcheck="false">
      <kbd>Esc</kbd>
    </div>
    <div class="cmdk-list" data-cmdk-list role="listbox"></div>
    <div class="cmdk-foot">
      <span><kbd>↑</kbd><kbd>↓</kbd> di chuyển</span>
      <span><kbd>↵</kbd> mở</span>
      <span><kbd>Esc</kbd> đóng</span>
    </div>
  </div>
</div>
<div class="tooltip" data-tooltip role="tooltip" hidden></div>

<script>
  window.LQ_NAV = <?= json_encode($navItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  window.LQ_CAN = <?= json_encode(array_combine($caps = ['edit_posts', 'edit_pages', 'manage_terms', 'upload_files', 'manage_options', 'manage_plugins', 'manage_themes', 'edit_themes'], array_map('can', $caps))) ?>;
</script>
<?php require __DIR__ . '/partials/scripts.php'; ?>
<?php do_action('admin_footer'); ?>
</body>
</html>
