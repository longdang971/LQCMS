<?php
defined('LQ_ROOT') || exit;
use LQ\Core\Updater;

$checkedAt = (int) ($status['checked_at'] ?? 0);
$fmtTime = fn (int $t) => format_date(gmdate('Y-m-d H:i:s', $t), 'H:i d/m/Y');
$fmtDay = fn (string $iso) => $iso !== '' && ($t = strtotime($iso)) ? format_date(gmdate('Y-m-d H:i:s', $t), 'd/m/Y') : '';
$failed = array_values(array_filter($readiness, fn ($r) => !$r['ok']));
// Ghi chú phát hành: của bản mới nếu có, không thì của chính bản đang dùng.
$notesOf = $latest ?? $current;
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Cài đặt</p>
    <h1>Cập nhật</h1>
  </div>
</div>

<div class="bento upd">
  <section class="tile span-12 upd-hero<?= $latest ? ' has-update' : '' ?>">
    <div class="upd-hero-head">
      <span class="upd-eyebrow"><?= icon('package') ?> LQCMS</span>
      <?php if ($latest): ?>
        <span class="upd-pill is-new"><?= icon('sparkles') ?> Có bản mới</span>
      <?php elseif (!empty($status['error']) && !$checkedAt): ?>
        <span class="upd-pill is-warn"><?= icon('cloud-off') ?> Chưa kiểm tra được</span>
      <?php else: ?>
        <span class="upd-pill is-ok"><?= icon('check') ?> Mới nhất</span>
      <?php endif; ?>
    </div>

    <div class="upd-version">
      <?php if ($latest): ?>
        <span class="upd-from" title="Đang dùng"><?= e(LQ_VERSION) ?></span>
        <span class="upd-arrow"><?= icon('arrow-right') ?></span>
        <span class="upd-num"><?= e($latest['version']) ?></span>
      <?php else: ?>
        <span class="upd-num"><?= e(LQ_VERSION) ?></span>
      <?php endif; ?>
    </div>
    <p class="upd-lead">
      <?php if ($latest): ?>
        <?= e($latest['name']) ?><?= $fmtDay($latest['published_at']) ? ', phát hành ' . e($fmtDay($latest['published_at'])) : '' ?>. Theme, plugin và dữ liệu được giữ nguyên.
      <?php else: ?>
        Đang chạy bản mới nhất<?= $current && $fmtDay($current['published_at']) ? ' · phát hành ' . e($fmtDay($current['published_at'])) : '' ?>.
      <?php endif; ?>
    </p>

    <?php if (!empty($status['error'])): ?>
      <div class="notice notice-warning upd-notice"><?= icon('triangle-alert') ?><div>Lần kiểm tra gần nhất lỗi: <?= e($status['error']) ?></div></div>
    <?php endif; ?>
    <?php if ($latest && $failed): ?>
      <div class="notice notice-error upd-notice"><?= icon('circle-alert') ?><div>
        <strong>Chưa cập nhật được từ trang này:</strong>
        <ul><?php foreach ($failed as $r): ?><li><?= e($r['problem']) ?></li><?php endforeach; ?></ul>
      </div></div>
    <?php elseif ($latest && !$latest['asset_url']): ?>
      <div class="notice notice-warning upd-notice"><?= icon('triangle-alert') ?><div>Bản này chưa có gói cập nhật (<code>lqcms-<?= e($latest['version']) ?>-update.zip</code>) — tải về và cập nhật thủ công.</div></div>
    <?php endif; ?>

    <div class="upd-hero-foot">
      <div class="upd-actions">
        <?php if ($latest && !$failed && $latest['asset_url']): ?>
          <form method="post" action="/admin/updates/apply"
                data-confirm="Cập nhật lên LQCMS <?= e($latest['version']) ?>?"
                data-confirm-text="Mã lõi (app/, assets/…) sẽ được thay bằng bản mới; theme, plugin, ảnh tải lên, cài đặt và dữ liệu giữ nguyên. Bản đang chạy được sao lưu để khôi phục khi cần. Nên sao lưu database trước."
                data-confirm-label="Cập nhật" data-confirm-busy="Đang tải và cài bản mới…">
            <?= csrf_field() ?>
            <button class="btn btn-accent" type="submit"><?= icon('download') ?> Cập nhật lên <?= e($latest['version']) ?></button>
          </form>
        <?php endif; ?>
        <form method="post" action="/admin/updates/check">
          <?= csrf_field() ?>
          <button class="btn btn-secondary" type="submit"><?= icon('refresh-cw') ?> Kiểm tra ngay</button>
        </form>
      </div>
      <div class="upd-meta">
        <span><?= icon('clock') ?> <?= $checkedAt ? 'Kiểm tra lúc ' . e($fmtTime($checkedAt)) : 'Chưa kiểm tra lần nào' ?></span>
        <?php if ($backup): ?>
          <form method="post" action="/admin/updates/rollback"
                data-confirm="Khôi phục LQCMS <?= e($backup['version']) ?>?"
                data-confirm-text="Mã lõi trở về bản <?= e($backup['version']) ?>; bản đang chạy (<?= e(LQ_VERSION) ?>) được sao lưu lại nên có thể cập nhật lại sau. Thay đổi cơ sở dữ liệu của bản mới (nếu có) không được hoàn tác."
                data-confirm-label="Khôi phục" data-confirm-danger data-confirm-busy="Đang khôi phục…">
            <?= csrf_field() ?>
            <button class="upd-link" type="submit"<?= Updater::allowed() ? '' : ' disabled' ?>><?= icon('history') ?> Khôi phục bản <?= e($backup['version']) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="tile span-12 upd-notes">
    <div class="tile-title">
      <div><h3><?= $notesOf ? 'Có gì mới trong ' . e($notesOf['version']) : 'Ghi chú phát hành' ?></h3></div>
      <a class="btn btn-ghost btn-sm" href="<?= e(($notesOf['url'] ?? '') ?: 'https://github.com/' . Updater::repo() . '/releases') ?>" target="_blank" rel="noopener">GitHub <?= icon('arrow-up-right') ?></a>
    </div>
    <?php if ($notesOf && $notesOf['notes'] !== ''): ?>
      <div class="release-notes"><?= Updater::notesHtml($notesOf['notes']) ?></div>
    <?php else: ?>
      <p class="muted"><?= $notesOf ? 'Bản phát hành này không kèm ghi chú.' : 'Chưa có thông tin bản ' . e(LQ_VERSION) . ' trên GitHub Releases.' ?></p>
    <?php endif; ?>
  </section>
</div>
