<?php
/**
 * Phần chống mất bài dùng chung cho form bài viết & trang: mốc thời gian lúc mở (phát hiện ghi đè),
 * thông báo xung đột, banner khôi phục bản tự lưu, cảnh báo người khác đang sửa.
 * Biến: $post, $errors, $autosave, $lockedBy
 */
?>
<input type="hidden" name="loaded_at" value="<?= e(old('loaded_at', $post['updated_at'] ?? '')) ?>" data-loaded-at>

<?php if (!empty($errors['_conflict'])): ?>
  <div class="notice notice-warning guard-notice"><?= icon('triangle-alert') ?><div><?= e($errors['_conflict']) ?></div></div>
<?php endif; ?>

<?php if ($autosave): ?>
  <div class="notice guard-notice" data-autosave-banner>
    <?= icon('history') ?>
    <div class="guard-text">
      <strong>Có bản tự lưu lúc <?= e(format_date($autosave['created_at'], 'H:i d/m')) ?> mới hơn bản đã lưu.</strong>
      <span class="muted">Có thể bạn đã đóng trang trước khi bấm lưu.</span>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" data-autosave-dismiss>Bỏ qua</button>
    <button type="button" class="btn btn-accent btn-sm" data-autosave-restore>Khôi phục</button>
    <script type="application/json" data-autosave-data><?= json_encode(['title' => $autosave['title'], 'content' => $autosave['content']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </div>
<?php endif; ?>

<?php if ($lockedBy): ?>
  <div hidden data-locked-by="<?= e($lockedBy['name']) ?>"></div>
<?php endif; ?>
<div class="notice notice-warning guard-notice" data-takeover-banner hidden><?= icon('users') ?><div data-takeover-text></div></div>
