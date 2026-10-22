<?php
defined('LQ_ROOT') || exit;
/** Khung ảnh đại diện dùng chung cho bài viết và trang: bấm/kéo ảnh vào khung để tải lên, hoặc chọn từ thư viện media. */
$url = (string) old('featured_url', $post['featured_image'] ?? '');
?>
<section class="card">
  <div class="card-head">
    <h3>Ảnh đại diện</h3>
    <button type="button" class="link small btn-plain" data-featured-clear<?= $url === '' ? ' hidden' : '' ?>>Gỡ ảnh</button>
  </div>
  <div class="featured" data-featured>
    <input type="hidden" name="featured_url" value="<?= e($url) ?>" data-featured-value>
    <input type="hidden" name="featured_pending" value="<?= e((string) old('featured_pending', '')) ?>" data-featured-pending>
    <button type="button" class="featured-preview" data-featured-pick<?= $url === '' ? ' hidden' : '' ?> title="Đổi ảnh">
      <img src="<?= e($url) ?>" alt="" data-featured-img>
      <span><?= icon('repeat') ?> Đổi ảnh</span>
    </button>
    <div class="dropzone" data-featured-drop role="button" tabindex="0" aria-label="Tải ảnh đại diện lên"<?= $url !== '' ? ' hidden' : '' ?>>
      <?= icon('image-up') ?>
      <span><b>Bấm để chọn ảnh</b> hoặc kéo thả vào đây</span>
      <small class="muted">Ảnh sẽ được tải lên thư viện media</small>
      <input type="file" accept="image/*" data-featured-file>
      <button type="button" class="btn btn-secondary btn-sm" data-featured-pick><?= icon('images') ?> Chọn từ thư viện</button>
    </div>
  </div>
</section>
