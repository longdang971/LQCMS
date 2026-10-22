<?php defined('LQ_ROOT') || exit; ?>
<div class="page-head">
  <div>
    <h1>Thư viện media</h1>
  </div>
  <div class="page-actions">
    <?php if (can('manage_options')): ?>
      <button class="btn btn-secondary" type="button" data-media-cleanup title="Tìm và xoá ảnh không bài viết, trang hay cài đặt nào dùng đến"><?= icon('eraser') ?> Dọn dẹp</button>
    <?php endif; ?>
    <button class="btn btn-secondary" type="button" data-media-select-mode title="Hoặc giữ <?= is_mac_client() ? '⌘' : 'Ctrl' ?> và bấm vào ảnh"><?= icon('square-check') ?> Chọn nhiều</button>
    <label class="btn btn-accent">
      <?= icon('upload') ?> Tải ảnh lên
      <input type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" multiple hidden data-media-upload-input>
    </label>
  </div>
</div>

<div class="media-page" data-media-page data-max-mb="<?= (int) $maxMb ?>">
  <div class="media-toolbar">
    <div class="input-icon grow">
      <?= icon('search') ?>
      <input class="input" type="search" placeholder="Tìm theo tên tệp, mô tả…" data-media-search>
    </div>
    <select class="input w-auto" data-media-month>
      <option value="">Mọi thời gian</option>
      <?php foreach ($months as $k => $label): ?>
        <option value="<?= e($k) ?>"><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="bulk-bar media-bulk" data-media-bulk hidden>
    <span><strong data-media-bulk-count>0</strong> ảnh được chọn</span>
    <span class="bulk-hint" data-media-bulk-hint></span>
    <button class="btn btn-sm btn-ghost" type="button" data-media-bulk-cancel>Huỷ</button>
    <button class="btn btn-sm btn-danger" type="button" data-media-bulk-delete><?= icon('trash-2') ?> Xoá</button>
  </div>

  <div class="upload-queue" data-upload-queue hidden></div>

  <div class="media-grid" data-media-grid></div>
  <div class="media-more" data-media-more hidden><button class="btn btn-secondary" type="button">Tải thêm</button></div>

  <div class="dash-empty" data-media-empty hidden>
    <?= icon('images') ?>
    <h3>Chưa có ảnh nào</h3>
    <p class="muted">Kéo thả ảnh vào đây, hoặc bấm “Tải ảnh lên”. Tối đa <?= (int) $maxMb ?> MB mỗi ảnh.</p>
  </div>
</div>

<div class="drop-overlay" data-drop-overlay hidden>
  <div><?= icon('upload') ?><strong>Thả ảnh để tải lên thư viện</strong><span>JPG, PNG, GIF, WEBP, AVIF · tối đa <?= (int) $maxMb ?> MB mỗi ảnh</span></div>
</div>

<aside class="widget-drawer media-drawer" data-media-drawer aria-hidden="true">
  <div class="drawer-head">
    <div><h3>Chi tiết ảnh</h3><p class="muted small" data-md-name></p></div>
    <button class="icon-btn" type="button" data-media-drawer-close aria-label="Đóng"><?= icon('x') ?></button>
  </div>
  <div class="drawer-body media-detail" data-media-detail></div>
</aside>
