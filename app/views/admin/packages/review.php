<?php
defined('LQ_ROOT') || exit;
/** Trang kiểm tra plugin/theme trước khi cài (xem LQ\Admin\PackageInstall). */
use LQ\Content\Media;

$danger = array_values(array_filter($pkg['findings'], fn ($f) => $f['severity'] === 'danger'));
$warning = array_values(array_filter($pkg['findings'], fn ($f) => $f['severity'] !== 'danger'));
$state = $danger ? 'danger' : ($warning ? 'warning' : 'ok');
$byFile = [];
foreach ($pkg['findings'] as $f) {
    $byFile[$f['file']][] = $f;
}
?>
<div class="page-head">
  <div>
    <h1>Kiểm tra <?= e($label) ?> trước khi cài</h1>
  </div>
</div>

<section class="card pkg-summary pkg-<?= $state ?>">
  <span class="pkg-state-icon"><?= icon(['danger' => 'shield-alert', 'warning' => 'triangle-alert', 'ok' => 'shield-check'][$state]) ?></span>
  <div class="pkg-summary-body">
    <h3>
      <?= e($pkg['name']) ?><?php if ($pkg['version'] !== ''): ?> <span class="muted">v<?= e($pkg['version']) ?></span><?php endif; ?>
    </h3>
    <p class="pkg-verdict">
      <?php if ($state === 'danger'): ?>
        Phát hiện <b><?= count($danger) ?> dấu hiệu nguy hiểm</b><?= $warning ? ' và ' . count($warning) . ' điểm cần xem' : '' ?>. Chỉ cài nếu bạn tin tưởng nguồn của gói này.
      <?php elseif ($state === 'warning'): ?>
        Có <b><?= count($warning) ?> điểm cần xem</b>, không có dấu hiệu nguy hiểm rõ ràng. Nhiều plugin hợp lệ cũng dùng các hàm này.
      <?php else: ?>
        Không phát hiện dấu hiệu đáng ngờ. Bộ quét không bắt được mọi loại mã độc — vẫn nên chỉ cài từ nguồn tin cậy.
      <?php endif; ?>
    </p>
    <p class="pkg-meta">
      <code><?= e($pkg['slug']) ?></code>
      · <?= e($pkg['file_name']) ?> (<?= e(Media::bytes((int) $pkg['zip_size'])) ?>)
      · <?= (int) $pkg['files'] ?> tệp, <?= (int) $pkg['php'] ?> tệp PHP · giải nén <?= e(Media::bytes((int) $pkg['bytes'])) ?>
    </p>
  </div>
</section>

<?php if (\LQ\AI\AI::enabled() && ($byFile || $label === 'plugin')): ?>
  <section class="card pkg-ai" data-pkg-ai data-kind="<?= e($label) ?>" data-token="<?= e($pkg['token']) ?>">
    <div class="pkg-ai-head">
      <div>
        <h3><?= icon('sparkles') ?> Nhờ AI đánh giá</h3>
        <p class="muted small">
          <?= e(\LQ\AI\Connectors::LIST[\LQ\AI\AI::active()]['label']) ?>
          <?php if ($byFile): ?>đọc từng chỗ bị đánh dấu kèm mã xung quanh và cho ý kiến.<?php endif; ?>
          <?php if ($label === 'plugin'): ?><?= $byFile ? 'Kiểm tra sâu' : '' ?> đọc toàn bộ từng tệp mã để tìm lỗ hổng bảo mật, mã độc và lỗi logic mà bộ quét tự động không bắt được.<?php endif; ?>
          Chỉ để tham khảo: chính mã độc có thể chứa câu cài bẫy để đánh lừa AI.
        </p>
      </div>
      <div class="pkg-ai-actions">
        <?php if ($byFile): ?><button type="button" class="btn btn-secondary btn-sm" data-pkg-ai-run><?= icon('shield-question') ?> Đánh giá chỗ bị đánh dấu</button><?php endif; ?>
        <?php if ($label === 'plugin'): ?><button type="button" class="btn btn-secondary btn-sm" data-ai-plugin-scan data-token="<?= e($pkg['token']) ?>" data-name="<?= e($pkg['name']) ?>"><?= icon('scan-search') ?> Kiểm tra sâu</button><?php endif; ?>
      </div>
    </div>
    <div data-pkg-ai-result hidden></div>
  </section>
<?php endif; ?>

<?php if ($byFile): ?>
  <section class="card pkg-findings">
    <div class="card-head"><h3>Chi tiết (<?= count($pkg['findings']) ?>)</h3></div>
    <?php foreach ($byFile as $file => $items): ?>
      <div class="pkg-file">
        <div class="pkg-file-name"><?= icon('file-code') ?><code><?= e($pkg['slug'] . '/' . $file) ?></code></div>
        <?php foreach ($items as $f): ?>
          <div class="pkg-finding is-<?= e($f['severity']) ?>">
            <span class="pkg-sev"><?= $f['severity'] === 'danger' ? 'Nguy hiểm' : 'Cần xem' ?></span>
            <div class="pkg-finding-body">
              <p><?= e($f['message']) ?><?php if ($f['line']): ?> <span class="muted">· dòng <?= (int) $f['line'] ?></span><?php endif; ?></p>
              <?php if ($f['snippet'] !== ''): ?><pre><?= e($f['snippet']) ?></pre><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<div class="pkg-actions">
  <form method="post" action="<?= e($base) ?>/cancel">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($pkg['token']) ?>">
    <button class="btn btn-secondary" type="submit">Huỷ và xoá gói</button>
  </form>
  <form method="post" action="<?= e($base) ?>/install"<?php if ($state === 'danger'): ?> data-confirm="Vẫn cài <?= e($label) ?> có dấu hiệu nguy hiểm?" data-confirm-text="Mã trong gói có thể chiếm quyền website khi được kích hoạt. Chỉ tiếp tục nếu bạn biết rõ nguồn gốc." data-confirm-label="Vẫn cài" data-confirm-danger<?php endif; ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($pkg['token']) ?>">
    <button class="btn <?= $state === 'danger' ? 'btn-danger' : 'btn-accent' ?>" type="submit"><?= icon('download') ?> <?= $state === 'danger' ? 'Vẫn cài đặt' : 'Cài đặt' ?></button>
  </form>
</div>
