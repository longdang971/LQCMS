<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\LogsController;

$levels = LogsController::LEVELS;
$tone = fn (string $l) => match ($l) { 'error', 'fatal' => 'status-trash', 'warning' => 'status-pending', 'info' => 'status-scheduled', default => 'status-draft' };
$filtered = $filters['level'] !== '' || $filters['source'] !== '' || $filters['q'] !== '';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Cài đặt</p>
    <h1>Nhật ký</h1>
  </div>
  <?php if ($total): ?>
    <div class="page-actions">
      <form method="post" action="/admin/logs/clear" data-confirm="Xoá toàn bộ nhật ký?" data-confirm-text="Mọi lỗi PHP và nhật ký của theme/plugin đã ghi sẽ bị xoá." data-confirm-label="Xoá" data-confirm-danger>
        <?= csrf_field() ?>
        <button class="btn btn-secondary" type="submit"><?= icon('trash-2') ?> Xoá nhật ký</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<section class="card card-flush">
  <form class="toolbar" method="get" action="/admin/logs">
    <div class="input-icon grow">
      <?= icon('search') ?>
      <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Tìm trong thông báo, tệp…">
    </div>
    <select class="input w-auto" name="level">
      <option value="">Mọi mức độ</option>
      <?php foreach (['error' => 'Lỗi', 'warning' => 'Cảnh báo', 'notice' => 'Lưu ý', 'deprecated' => 'Lỗi thời', 'info' => 'Thông tin'] as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= selected($k, $filters['level']) ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input w-auto" name="source">
      <option value="">Mọi nguồn</option>
      <?php foreach ($sources as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= selected($k, $filters['source']) ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit"><?= icon('filter') ?> Lọc</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="/admin/logs"><?= icon('x') ?> Xoá lọc</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">
      <?= icon($filtered ? 'search-x' : 'circle-check') ?>
      <h3><?= $filtered ? 'Không có mục nào khớp' : 'Nhật ký trống' ?></h3>
      <p class="muted"><?= $filtered ? 'Thử bỏ bớt điều kiện lọc.' : 'Chưa ghi nhận lỗi PHP nào, theme/plugin cũng chưa ghi gì (lq_log).' ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table log-table">
        <thead>
          <tr>
            <th>Thông báo</th>
            <th class="hide-sm">Nguồn</th>
            <th class="num hide-sm">Số lần</th>
            <th>Lần cuối</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $l = (string) ($r['level'] ?? 'error'); ?>
            <tr>
              <td>
                <div class="log-msg"><span class="badge <?= $tone($l) ?>"><?= e($levels[$l] ?? $l) ?></span> <?= e((string) ($r['msg'] ?? '')) ?></div>
                <div class="row-sub mono">
                  <?= e((string) ($r['file'] ?? '')) ?><?= !empty($r['line']) ? ':' . (int) $r['line'] : '' ?>
                  <?php if (($r['url'] ?? '') !== ''): ?> · <?= e(mb_strimwidth((string) $r['url'], 0, 80, '…')) ?><?php endif; ?>
                </div>
              </td>
              <td class="hide-sm muted"><?= e(LogsController::sourceLabel((string) ($r['source'] ?? ''))) ?></td>
              <td class="num hide-sm"><?= (int) ($r['count'] ?? 1) ?></td>
              <td class="date-cell"><?= e(format_date((string) $r['t'], 'H:i d/m')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<p class="help" style="margin-top: 12px">Lỗi giống nhau được gộp lại. Theme/plugin ghi vào đây bằng <code>lq_log('Thông báo', 'info' | 'warning' | 'error')</code>.</p>
