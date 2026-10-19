<?php
$u = current_user();
$today = new DateTimeImmutable('now', site_timezone());
$weekday = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'][(int) $today->format('w')];
$hour = (int) $today->format('G');
$greet = $hour < 11 ? 'Chào buổi sáng' : ($hour < 14 ? 'Chào buổi trưa' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối'));
$groups = [];
foreach ($catalog as $w) {
    $groups[$w['group']][] = $w;
}
?>
<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($weekday) ?>, <?= e($today->format('d/m/Y')) ?></p>
    <h1><?= e($greet) ?>, <?= e($u['display_name']) ?></h1>
  </div>
  <div class="page-actions" data-dash-actions>
    <button class="btn btn-secondary" type="button" data-dash-edit><?= icon('layout-dashboard') ?> Tuỳ chỉnh</button>
    <?php if (can('edit_posts')): ?>
      <a class="btn btn-accent" href="/admin/posts/new"><?= icon('pen-line') ?> Viết bài mới</a>
    <?php endif; ?>
  </div>
</div>

<div class="dash-editbar" data-dash-editbar hidden>
  <span class="dash-editbar-text"><?= icon('move') ?> Kéo widget để sắp xếp, bấm <b>độ rộng</b> để đổi kích thước.</span>
  <form method="post" action="/admin/dashboard/reset" data-confirm="Khôi phục Bảng tin mặc định?" data-confirm-text="Bố cục bạn đã sắp xếp sẽ bị thay bằng bố cục ban đầu." data-confirm-label="Khôi phục">
    <?= csrf_field() ?>
    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('rotate-ccw') ?> Mặc định</button>
  </form>
  <button class="btn btn-secondary btn-sm" type="button" data-drawer-open><?= icon('plus') ?> Thêm widget</button>
  <button class="btn btn-accent btn-sm" type="button" data-dash-done><?= icon('check') ?> Xong</button>
</div>

<div class="bento widget-grid" data-widget-grid>
  <?= $widgetsHtml ?>
</div>

<div class="dash-empty" data-dash-empty<?= $layout ? ' hidden' : '' ?>>
  <?= icon('layout-grid') ?>
  <h3>Bảng tin đang trống</h3>
  <p class="muted">Kéo widget từ danh sách vào đây, hoặc bấm “Thêm widget”.</p>
  <button class="btn btn-accent" type="button" data-drawer-open><?= icon('plus') ?> Thêm widget</button>
</div>

<aside class="widget-drawer" data-widget-drawer aria-hidden="true">
  <div class="drawer-head">
    <div>
      <h3>Thêm widget</h3>
      <p class="muted small">Kéo thả vào bảng tin hoặc bấm “Thêm”.</p>
    </div>
    <button class="icon-btn" type="button" data-drawer-close aria-label="Đóng"><?= icon('x') ?></button>
  </div>
  <div class="drawer-search input-icon">
    <?= icon('search') ?>
    <input class="input" type="search" placeholder="Tìm widget…" data-drawer-search>
  </div>
  <div class="drawer-body">
    <?php foreach ($groups as $group => $items): ?>
      <div class="drawer-group" data-drawer-group>
        <div class="subhead"><?= e($group) ?></div>
        <div class="drawer-list" data-widget-catalog>
          <?php foreach ($items as $w): ?>
            <div class="catalog-item" data-catalog-id="<?= e($w['id']) ?>" data-size="<?= (int) $w['size'] ?>" data-search="<?= e(mb_strtolower($w['title'] . ' ' . $w['description'])) ?>">
              <span class="catalog-icon"><?= icon($w['icon']) ?></span>
              <span class="catalog-text">
                <strong><?= e($w['title']) ?></strong>
                <small><?= e($w['description']) ?></small>
              </span>
              <span class="catalog-side">
                <span class="catalog-size"><?= e($sizes[$w['size']] ?? '') ?></span>
                <button class="btn btn-secondary btn-sm" type="button" data-catalog-add>Thêm</button>
              </span>
              <span class="catalog-added"><?= icon('check') ?> Đang dùng</span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</aside>
<div class="drawer-backdrop" data-drawer-close></div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>window.LQ_WIDGET_SIZES = <?= json_encode($sizes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
