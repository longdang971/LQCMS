<?php
defined('LQ_ROOT') || exit;
use LQ\Core\Updater;

$checkedAt = (int) ($status['checked_at'] ?? 0);
$fmtTime = fn (int $t) => format_date(gmdate('Y-m-d H:i:s', $t), 'H:i d/m/Y');
?>
<div class="page-head">
  <div>
    <h1>Cập nhật</h1>
  </div>
  <div class="page-actions">
    <form method="post" action="/admin/updates/check">
      <?= csrf_field() ?>
      <button class="btn btn-secondary" type="submit"><?= icon('refresh-cw') ?> Kiểm tra ngay</button>
    </form>
  </div>
</div>

<div class="settings">
  <section class="settings-section first">
    <div class="settings-label">
      <h3>Phiên bản</h3>
      <p>Bản mới lấy từ GitHub Releases của <a class="link" href="https://github.com/<?= e(Updater::repo()) ?>/releases" target="_blank" rel="noopener"><?= e(Updater::repo()) ?></a>. Hệ thống tự kiểm tra 12 giờ một lần.</p>
    </div>
    <div class="settings-fields" style="max-width: none">
      <div class="update-status<?= $latest ? ' is-new' : ' is-ok' ?>">
        <?= icon($latest ? 'circle-arrow-up' : 'circle-check') ?>
        <div>
          <strong><?= $latest ? 'Có bản mới: LQCMS ' . e($latest['version']) : 'Bạn đang dùng bản mới nhất' ?></strong>
          <small>
            Đang dùng LQCMS <?= e(LQ_VERSION) ?>
            · <?= $checkedAt ? 'kiểm tra lúc ' . e($fmtTime($checkedAt)) : 'chưa kiểm tra lần nào' ?>
          </small>
        </div>
      </div>
      <?php if (!empty($status['error'])): ?>
        <div class="notice notice-warning"><?= icon('triangle-alert') ?><div>Lần kiểm tra gần nhất lỗi: <?= e($status['error']) ?></div></div>
      <?php endif; ?>
      <?php if ($last): ?>
        <p class="help">
          Lần <?= !empty($last['rollback']) ? 'khôi phục' : 'cập nhật' ?> gần nhất: <?= e($last['from']) ?> → <?= e($last['to']) ?>, lúc <?= e($fmtTime((int) $last['at'])) ?>.
        </p>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($latest): ?>
    <section class="settings-section">
      <div class="settings-label">
        <h3><?= e($latest['name']) ?></h3>
        <p>
          <?php if ($latest['published_at'] !== ''): ?>Phát hành <?= e(format_date(gmdate('Y-m-d H:i:s', strtotime($latest['published_at'])), 'd/m/Y')) ?>.<?php endif; ?>
          <?php if ($latest['url'] !== ''): ?><a class="link" href="<?= e($latest['url']) ?>" target="_blank" rel="noopener">Xem trên GitHub</a><?php endif; ?>
        </p>
      </div>
      <div class="settings-fields" style="max-width: none">
        <div class="release-notes boxed">
          <?= $latest['notes'] !== '' ? Updater::notesHtml($latest['notes']) : '<p class="muted">Bản phát hành không có ghi chú.</p>' ?>
        </div>

        <?php if ($problems): ?>
          <div class="notice notice-error"><?= icon('circle-alert') ?><div>
            <strong>Không cập nhật được từ trang này:</strong>
            <ul><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
          </div></div>
        <?php elseif (!$latest['asset_url']): ?>
          <div class="notice notice-warning"><?= icon('triangle-alert') ?><div>Bản này chưa có gói cập nhật (<code>lqcms-<?= e($latest['version']) ?>-update.zip</code>) — tải về và cập nhật thủ công.</div></div>
        <?php else: ?>
          <form method="post" action="/admin/updates/apply"
                data-confirm="Cập nhật lên LQCMS <?= e($latest['version']) ?>?"
                data-confirm-text="Mã lõi (app/, assets/…) sẽ được thay bằng bản mới; theme, plugin, ảnh tải lên, cài đặt và dữ liệu giữ nguyên. Bản đang chạy được sao lưu để khôi phục khi cần. Nên sao lưu database trước."
                data-confirm-label="Cập nhật" data-confirm-busy="Đang tải và cài bản mới…">
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit"><?= icon('download') ?> Cập nhật lên <?= e($latest['version']) ?></button>
            <span class="help">Gói tải về: <?= $latest['asset_size'] ? e(number_format($latest['asset_size'] / 1048576, 1, ',', '.')) . ' MB' : '—' ?>. Có thể mất khoảng một phút.</span>
          </form>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($backup): ?>
    <section class="settings-section">
      <div class="settings-label">
        <h3>Bản trước</h3>
        <p>Bản lõi bị thay ở lần cập nhật gần nhất, giữ trong <code>storage/updates</code>.</p>
      </div>
      <div class="settings-fields" style="max-width: none">
        <form method="post" action="/admin/updates/rollback"
              data-confirm="Khôi phục LQCMS <?= e($backup['version']) ?>?"
              data-confirm-text="Mã lõi trở về bản <?= e($backup['version']) ?>; bản đang chạy (<?= e(LQ_VERSION) ?>) được sao lưu lại nên có thể cập nhật lại sau. Thay đổi cơ sở dữ liệu của bản mới (nếu có) không được hoàn tác."
              data-confirm-label="Khôi phục" data-confirm-danger data-confirm-busy="Đang khôi phục…">
          <?= csrf_field() ?>
          <button class="btn btn-secondary" type="submit"<?= Updater::allowed() ? '' : ' disabled' ?>><?= icon('history') ?> Khôi phục bản <?= e($backup['version']) ?></button>
          <span class="help">Sao lưu lúc <?= e($fmtTime((int) $backup['at'])) ?>.</span>
        </form>
      </div>
    </section>
  <?php endif; ?>
</div>
