<?php
defined('LQ_ROOT') || exit;
use LQ\Core\Updater;

$checkedAt = (int) ($status['checked_at'] ?? 0);
$next = Updater::nextCheck();
$releases = array_values(array_filter((array) ($status['releases'] ?? []), 'is_array'));
$readyOk = count(array_filter($readiness, fn ($r) => $r['ok']));
$canUpdate = $readyOk === count($readiness);
$fmtTime = fn (int $t) => format_date(gmdate('Y-m-d H:i:s', $t), 'H:i d/m/Y');
$fmtDay = fn (string $iso) => $iso !== '' && ($t = strtotime($iso)) ? format_date(gmdate('Y-m-d H:i:s', $t), 'd/m/Y') : '';
$mb = fn (int $bytes) => number_format($bytes / 1048576, 1, ',', '.') . ' MB';
// Ghi chú hiện ở ô "Có gì mới": bản mới nếu có, không thì chính bản đang dùng.
$notesOf = $latest ?? $current;
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Cài đặt</p>
    <h1>Cập nhật</h1>
  </div>
  <div class="page-actions">
    <a class="btn btn-ghost" href="https://github.com/<?= e(Updater::repo()) ?>/releases" target="_blank" rel="noopener"><?= icon('github') ?> Bản phát hành</a>
  </div>
</div>

<div class="bento upd">
  <?php /* ---------- Phiên bản ---------- */ ?>
  <section class="tile span-8 upd-hero<?= $latest ? ' has-update' : '' ?>">
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
        <?= e($latest['name']) ?><?= $fmtDay($latest['published_at']) ? ' · phát hành ' . e($fmtDay($latest['published_at'])) : '' ?>.
        Mã lõi được thay, theme, plugin và dữ liệu giữ nguyên.
      <?php else: ?>
        Website đang chạy bản LQCMS mới nhất<?= $current && $fmtDay($current['published_at']) ? ', phát hành ' . e($fmtDay($current['published_at'])) : '' ?>. Không cần làm gì thêm.
      <?php endif; ?>
    </p>

    <?php $rel = $latest ?? $current; ?>
    <dl class="upd-facts">
      <div><dt>Phát hành</dt><dd><?= $rel && $fmtDay($rel['published_at']) ? e($fmtDay($rel['published_at'])) : '—' ?></dd></div>
      <div><dt>Bản trên GitHub</dt><dd><?= $releases ? count($releases) : '—' ?></dd></div>
      <div><dt>Cập nhật gần nhất</dt><dd><?= $last ? e(format_date(gmdate('Y-m-d H:i:s', (int) $last['at']), 'd/m/Y')) : 'Chưa từng' ?></dd></div>
    </dl>

    <?php if (!empty($status['error'])): ?>
      <div class="notice notice-warning upd-notice"><?= icon('triangle-alert') ?><div>Lần kiểm tra gần nhất lỗi: <?= e($status['error']) ?></div></div>
    <?php endif; ?>

    <div class="upd-hero-foot">
      <div class="upd-actions">
        <?php if ($latest && $canUpdate && $latest['asset_url']): ?>
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
        <?php if ($next): ?><span><?= icon('calendar-clock') ?> Tự kiểm tra lại khoảng <?= e($fmtTime(max($next, time()))) ?></span><?php endif; ?>
        <?php if ($latest && $latest['asset_size']): ?><span><?= icon('hard-drive-download') ?> Gói tải về <?= e($mb($latest['asset_size'])) ?></span><?php endif; ?>
      </div>
    </div>

    <?php if ($latest && !$latest['asset_url']): ?>
      <div class="notice notice-warning upd-notice"><?= icon('triangle-alert') ?><div>Bản này chưa có gói cập nhật (<code>lqcms-<?= e($latest['version']) ?>-update.zip</code>) — tải về và cập nhật thủ công.</div></div>
    <?php elseif ($latest && !$canUpdate): ?>
      <div class="notice notice-error upd-notice"><?= icon('circle-alert') ?><div>Máy chủ chưa đủ điều kiện để cập nhật từ trang này — xem mục <b>Sẵn sàng cập nhật</b> bên cạnh.</div></div>
    <?php endif; ?>
  </section>

  <?php /* ---------- Điều kiện máy chủ ---------- */ ?>
  <section class="tile span-4 upd-ready">
    <div class="tile-title">
      <div><h3>Sẵn sàng cập nhật</h3><p>Kiểm tra trên máy chủ này</p></div>
      <span class="upd-score<?= $canUpdate ? ' is-ok' : '' ?>"><?= $readyOk ?>/<?= count($readiness) ?></span>
    </div>
    <ul class="upd-checks">
      <?php foreach ($readiness as $r): ?>
        <li class="<?= $r['ok'] ? 'is-ok' : 'is-bad' ?>">
          <?= icon($r['ok'] ? 'check' : 'x') ?>
          <div><strong><?= e($r['label']) ?></strong><small><?= e($r['hint']) ?></small></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php /* ---------- Ghi chú phát hành ---------- */ ?>
  <section class="tile span-8 upd-notes">
    <div class="tile-title">
      <div>
        <h3><?= $notesOf ? 'Có gì mới trong ' . e($notesOf['version']) : 'Ghi chú phát hành' ?></h3>
        <p><?= $latest ? 'Bản mới' : ($current ? 'Bản bạn đang dùng' : 'Chưa có thông tin từ GitHub') ?></p>
      </div>
      <?php if ($notesOf && $notesOf['url'] !== ''): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e($notesOf['url']) ?>" target="_blank" rel="noopener">GitHub <?= icon('arrow-up-right') ?></a>
      <?php endif; ?>
    </div>
    <?php if ($notesOf && $notesOf['notes'] !== ''): ?>
      <div class="release-notes"><?= Updater::notesHtml($notesOf['notes']) ?></div>
    <?php else: ?>
      <div class="empty small">
        <?= icon('scroll-text') ?>
        <h3>Chưa có ghi chú</h3>
        <p class="muted"><?= $notesOf ? 'Bản phát hành này không kèm ghi chú.' : 'Bản ' . e(LQ_VERSION) . ' chưa có trên GitHub Releases, hoặc chưa kiểm tra được.' ?></p>
      </div>
    <?php endif; ?>
  </section>

  <div class="span-4 upd-stack">
    <?php /* ---------- Phạm vi ---------- */ ?>
    <section class="tile upd-scope">
      <div class="tile-title"><div><h3>Phạm vi cập nhật</h3><p>Những gì được thay và được giữ</p></div></div>
      <p class="upd-scope-label"><?= icon('refresh-cw') ?> Thay bằng bản mới</p>
      <div class="upd-chips">
        <?php foreach (['app/', 'assets/', 'index.php', '.htaccess'] as $c): ?><code><?= e($c) ?></code><?php endforeach; ?>
      </div>
      <p class="upd-scope-label is-keep"><?= icon('shield-check') ?> Giữ nguyên</p>
      <div class="upd-chips is-keep">
        <?php foreach (['Theme', 'Plugin', 'Ảnh tải lên', 'Cấu hình', 'Database'] as $c): ?><span><?= e($c) ?></span><?php endforeach; ?>
      </div>
    </section>

    <?php /* ---------- Sao lưu ---------- */ ?>
    <section class="tile upd-backup">
      <div class="tile-title"><div><h3>Bản sao lưu</h3><p>Tự tạo trước mỗi lần cập nhật</p></div><?= icon('archive') ?></div>
      <?php if ($backup): ?>
        <div class="upd-backup-row">
          <span class="upd-ver-chip"><?= e($backup['version']) ?></span>
          <small>lưu lúc <?= e($fmtTime((int) $backup['at'])) ?></small>
        </div>
        <form method="post" action="/admin/updates/rollback"
              data-confirm="Khôi phục LQCMS <?= e($backup['version']) ?>?"
              data-confirm-text="Mã lõi trở về bản <?= e($backup['version']) ?>; bản đang chạy (<?= e(LQ_VERSION) ?>) được sao lưu lại nên có thể cập nhật lại sau. Thay đổi cơ sở dữ liệu của bản mới (nếu có) không được hoàn tác."
              data-confirm-label="Khôi phục" data-confirm-danger data-confirm-busy="Đang khôi phục…">
          <?= csrf_field() ?>
          <button class="btn btn-secondary btn-sm" type="submit"<?= Updater::allowed() ? '' : ' disabled' ?>><?= icon('history') ?> Khôi phục bản <?= e($backup['version']) ?></button>
        </form>
      <?php else: ?>
        <p class="muted upd-backup-empty">Chưa có bản nào. Khi cập nhật, bản đang chạy được giữ lại trong <code>storage/updates</code> để khôi phục bằng một cú bấm.</p>
      <?php endif; ?>
      <?php if ($last): ?>
        <p class="tile-note"><?= icon(!empty($last['rollback']) ? 'history' : 'circle-check') ?> <?= !empty($last['rollback']) ? 'Khôi phục' : 'Cập nhật' ?> <?= e($last['from']) ?> → <?= e($last['to']) ?> lúc <?= e($fmtTime((int) $last['at'])) ?></p>
      <?php endif; ?>
    </section>
  </div>

  <?php /* ---------- Lịch sử phiên bản ---------- */ ?>
  <section class="tile span-12 upd-history">
    <div class="tile-title"><div><h3>Lịch sử phiên bản</h3><p><?= $releases ? count($releases) . ' bản phát hành gần nhất trên GitHub' : 'Chưa có bản phát hành nào' ?></p></div></div>
    <?php if ($releases): ?>
      <ol class="upd-timeline">
        <?php foreach ($releases as $r): $cmp = version_compare($r['version'], LQ_VERSION); ?>
          <li class="<?= $cmp === 0 ? 'is-current' : ($cmp > 0 ? 'is-newer' : '') ?>">
            <span class="upd-dot"></span>
            <span class="upd-ver-chip"><?= e($r['version']) ?></span>
            <span class="upd-tl-name"><?= e($r['name']) ?></span>
            <?php if ($cmp === 0): ?><span class="upd-tag is-current">Đang dùng</span><?php elseif ($cmp > 0): ?><span class="upd-tag is-new">Mới</span><?php endif; ?>
            <span class="upd-tl-date"><?= e($fmtDay($r['published_at'])) ?></span>
            <?php if ($r['url'] !== ''): ?><a class="icon-btn sm" href="<?= e($r['url']) ?>" target="_blank" rel="noopener" title="Xem trên GitHub"><?= icon('arrow-up-right') ?></a><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php else: ?>
      <div class="empty small"><?= icon('git-branch') ?><h3>Chưa có lịch sử</h3><p class="muted">Bấm “Kiểm tra ngay” để lấy danh sách bản phát hành.</p></div>
    <?php endif; ?>
  </section>
</div>
