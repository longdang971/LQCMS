<?php
/** Khung ảnh đại diện dùng chung cho bài viết và trang: chọn từ thư viện media hoặc kéo ảnh vào để tải lên. */
$url = (string) old('featured_url', $post['featured_image'] ?? '');
?>
<section class="card">
  <div class="card-head">
    <h3>Ảnh đại diện</h3>
    <button type="button" class="link small btn-plain" data-featured-clear<?= $url === '' ? ' hidden' : '' ?>>Gỡ ảnh</button>
  </div>
  <div class="featured" data-featured>
    <input type="hidden" name="featured_url" value="<?= e($url) ?>" data-featured-value>
    <button type="button" class="featured-preview" data-featured-pick<?= $url === '' ? ' hidden' : '' ?> title="Đổi ảnh">
      <img src="<?= e($url) ?>" alt="" data-featured-img>
      <span><?= icon('repeat') ?> Đổi ảnh</span>
    </button>
    <div class="dropzone" data-featured-drop<?= $url !== '' ? ' hidden' : '' ?>>
      <?= icon('image-up') ?>
      <span>Kéo ảnh vào đây để tải lên</span>
      <button type="button" class="btn btn-secondary btn-sm" data-featured-pick><?= icon('images') ?> Chọn từ thư viện</button>
    </div>
  </div>
</section>
