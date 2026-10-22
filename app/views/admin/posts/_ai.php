<?php
defined('LQ_ROOT') || exit;
/** Khung "Trợ lý AI" ở cột phải form bài viết/trang — chỉ có khi đã bật AI (Cài đặt › AI). Logic ở assets/admin/ai.js. */
if (!\LQ\AI\AI::enabled()) {
    return;
}
?>
<section class="card ai-box" data-ai-suggest data-post-id="<?= (int) ($post['id'] ?? 0) ?>">
  <div class="card-head">
    <h3><?= icon('sparkles') ?> Trợ lý AI</h3>
    <span class="muted small"><?= e(\LQ\AI\Connectors::LIST[\LQ\AI\AI::active()]['label']) ?></span>
  </div>
  <p class="muted small">Viết nội dung từ tiêu đề, gợi ý tiêu đề, sapo, mô tả SEO. Bôi đen một đoạn trong bài rồi bấm nút <b>AI</b> trên thanh công cụ để sửa đoạn đó.</p>
  <div class="ai-box-actions">
    <button type="button" class="btn btn-secondary btn-sm" data-ai-write-run><?= icon('pen-line') ?> Viết nội dung từ tiêu đề</button>
    <button type="button" class="btn btn-secondary btn-sm" data-ai-suggest-run><?= icon('wand-sparkles') ?> Gợi ý tiêu đề, sapo, SEO</button>
    <?php // Chuyên mục/thẻ chỉ có ở bài viết (không ở trang, loại nội dung tuỳ biến). ?>
    <?php if (($type ?? $post['type'] ?? 'post') === 'post'): ?>
      <button type="button" class="btn btn-secondary btn-sm" data-ai-taxonomy-run><?= icon('tags') ?> Gợi ý chuyên mục, thẻ</button>
    <?php endif; ?>
    <button type="button" class="btn btn-secondary btn-sm" data-ai-links-run><?= icon('link') ?> Gợi ý liên kết nội bộ</button>
  </div>
  <div class="ai-results" data-ai-results hidden></div>
</section>
