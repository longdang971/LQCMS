<?php
use LQ\Admin\Kernel;
use LQ\Core\Auth;

$user = current_user();
$path = request_path();
$menu = Kernel::menu();

// Breadcrumb: mục menu cha đang mở › tiêu đề trang.
$crumbs = [];
foreach ($menu as $item) {
    if (!isset($item['section']) && Kernel::isActive($item, $path)) {
        if ($item['label'] !== ($title ?? '')) {
            $crumbs[] = ['label' => $item['label'], 'url' => $item['url']];
        }
        break;
    }
}

// Dữ liệu cho thanh lệnh ⌘K: mọi trang trong menu (đã lọc theo quyền).
$navItems = [];
foreach ($menu as $item) {
    if (isset($item['section'])) {
        continue;
    }
    $navItems[] = ['label' => $item['label'], 'url' => $item['url'], 'icon' => $item['icon'] ?? 'circle'];
    foreach ($item['children'] ?? [] as $c) {
        $navItems[] = ['label' => $c['label'], 'url' => $c['url'], 'icon' => $item['icon'] ?? 'circle', 'parent' => $item['label']];
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
      <?= icon('search') ?><span>Tìm kiếm…</span><kbd data-kbd-mod>⌘K</kbd>
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
      <button class="icon-btn only-mobile" type="button" data-sidebar-open aria-label="Mở menu"><?= icon('panel-left') ?></button>
      <nav class="crumbs" aria-label="Breadcrumb">
        <?php foreach ($crumbs as $c): ?>
          <a href="<?= e($c['url']) ?>"><?= e($c['label']) ?></a>
          <?= icon('chevron-right', 'sep') ?>
        <?php endforeach; ?>
        <span class="current"><?= e($title ?? '') ?></span>
      </nav>
      <div class="topbar-actions">
        <button class="icon-btn only-mobile" type="button" data-cmdk-open aria-label="Tìm kiếm"><?= icon('search') ?></button>
        <button class="icon-btn" type="button" data-theme-toggle title="Chế độ sáng / tối"><?= icon('moon', 'i-moon') ?><?= icon('sun', 'i-sun') ?></button>
        <a class="icon-btn" href="/" target="_blank" rel="noopener" title="Xem trang web"><?= icon('square-arrow-out-up-right') ?></a>
      </div>
    </header>

    <main class="content">
      <?= $content ?>
    </main>
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
  window.LQ_NAV = <?= json_encode($navItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  window.LQ_CAN = <?= json_encode(['edit_posts' => can('edit_posts'), 'manage_terms' => can('manage_terms')]) ?>;
</script>
<?php require __DIR__ . '/partials/scripts.php'; ?>
<?php do_action('admin_footer'); ?>
</body>
</html>
