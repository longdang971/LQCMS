<?php
use LQ\Content\Posts;

$isTrash = $filters['status'] === 'trash';
$tabs = ['' => 'Tất cả', 'published' => 'Đã đăng', 'scheduled' => 'Lên lịch', 'draft' => 'Bản nháp', 'trash' => 'Thùng rác'];
$qs = fn (array $o) => '/admin/pages' . (($q = array_filter(array_merge(['status' => $filters['status'], 'q' => $filters['q']], $o), fn ($v) => $v !== '')) ? '?' . http_build_query($q) : '');
?>
<div class="page-head">
  <div>
    <h1>Trang</h1>
    <p>Các trang tĩnh như Giới thiệu, Liên hệ, Chính sách · <?= count($items) ?> trang</p>
  </div>
  <div class="page-actions">
    <?php if ($isTrash && $counts['trash'] > 0): ?>
      <form method="post" action="/admin/pages/empty-trash" data-confirm="Xoá vĩnh viễn toàn bộ <?= (int) $counts['trash'] ?> trang trong thùng rác?" data-confirm-danger>
        <?= csrf_field() ?>
        <button class="btn btn-danger-soft" type="submit">Dọn thùng rác</button>
      </form>
    <?php endif; ?>
    <a class="btn btn-accent" href="/admin/pages/new"><?= icon('plus') ?> Thêm trang mới</a>
  </div>
</div>

<div class="tabs">
  <?php foreach ($tabs as $key => $label):
      $count = $key === '' ? $counts['all'] : ($counts[$key] ?? 0);
      if ($key !== '' && $count === 0 && $filters['status'] !== $key) continue; ?>
    <a class="tab<?= $filters['status'] === $key ? ' is-active' : '' ?>" href="<?= e($qs(['status' => $key])) ?>"><?= e($label) ?> <span class="tab-count"><?= $count ?></span></a>
  <?php endforeach; ?>
</div>

<div class="card card-flush">
  <form class="toolbar" method="get" action="/admin/pages">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <div class="input-icon grow">
      <?= icon('search') ?>
      <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Tìm trang…">
    </div>
  </form>

  <?php if (!$items): ?>
    <div class="empty">
      <?= icon($isTrash ? 'trash' : 'files') ?>
      <h3><?= $isTrash ? 'Thùng rác trống' : ($filters['q'] !== '' ? 'Không có trang phù hợp' : 'Chưa có trang nào') ?></h3>
      <?php if (!$isTrash): ?>
        <p class="muted">Tạo các trang như Giới thiệu, Liên hệ…</p>
        <a class="btn btn-accent" href="/admin/pages/new"><?= icon('plus') ?> Thêm trang mới</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <form method="post" action="/admin/pages/bulk" data-bulk-form>
      <?= csrf_field() ?>
      <div class="bulk-bar" data-bulk-bar hidden>
        <span><strong data-bulk-count>0</strong> trang được chọn</span>
        <select class="input input-sm w-auto" name="bulk_action">
          <?php if ($isTrash): ?>
            <option value="restore">Khôi phục</option>
            <option value="delete">Xoá vĩnh viễn</option>
          <?php else: ?>
            <option value="trash">Chuyển vào thùng rác</option>
            <option value="publish">Đăng</option>
            <option value="draft">Chuyển về nháp</option>
          <?php endif; ?>
        </select>
        <button class="btn btn-sm btn-primary" type="submit">Áp dụng</button>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" data-check-all aria-label="Chọn tất cả"></th>
              <th>Tiêu đề</th>
              <th class="hide-md">Mẫu trang</th>
              <th class="hide-sm">Tác giả</th>
              <th>Trạng thái</th>
              <th class="hide-sm">Ngày</th>
              <th class="col-actions"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $p): $id = (int) $p['id']; ?>
            <tr>
              <td class="col-check"><input type="checkbox" name="ids[]" value="<?= $id ?>" data-check-item aria-label="Chọn trang"></td>
              <td>
                <div class="post-cell">
                  <?php if ($p['depth']): ?><span class="tree-indent" style="--depth: <?= (int) $p['depth'] ?>"><?= str_repeat('— ', (int) $p['depth']) ?></span><?php endif; ?>
                  <div>
                    <?php if ($isTrash): ?>
                      <strong><?= e($p['title'] ?: '(Không tiêu đề)') ?></strong>
                    <?php else: ?>
                      <a class="row-title" href="/admin/pages/<?= $id ?>/edit"><?= e($p['title'] ?: '(Không tiêu đề)') ?></a>
                    <?php endif; ?>
                    <div class="row-sub">/<?= e(\LQ\Content\Pages::path($p)) ?>/</div>
                  </div>
                </div>
              </td>
              <td class="hide-md cell-muted">
                <?php $tpl = $p['template'] ? ($templates[$p['template']] ?? null) : null; ?>
                <?= $p['template'] ? e($tpl['label'] ?? $p['template'] . ' (theme không còn)') : '<span class="muted">Mặc định</span>' ?>
              </td>
              <td class="hide-sm"><span class="author-cell"><?= avatar($p['author_name'] ?? '?', 'sm', (string) $p['author_id']) ?><?= e($p['author_name'] ?? '—') ?></span></td>
              <td><span class="badge status-<?= e($p['status']) ?>"><?= e(Posts::STATUSES[$p['status']] ?? $p['status']) ?></span></td>
              <td class="hide-sm date-cell"><?= e(format_date($p['published_at'] ?: $p['updated_at'])) ?></td>
              <td class="col-actions">
                <div class="row-actions">
                  <?php if ($isTrash): ?>
                    <button class="icon-btn sm" type="submit" form="act-restore-<?= $id ?>" title="Khôi phục"><?= icon('undo-2') ?></button>
                    <button class="icon-btn sm danger" type="submit" form="act-delete-<?= $id ?>" title="Xoá vĩnh viễn"><?= icon('trash-2') ?></button>
                  <?php else: ?>
                    <a class="icon-btn sm" href="/admin/pages/<?= $id ?>/edit" title="Sửa"><?= icon('pencil') ?></a>
                    <?php if ($p['status'] === 'published'): ?>
                      <a class="icon-btn sm" href="<?= e(Posts::permalink($p)) ?>" target="_blank" rel="noopener" title="Xem"><?= icon('eye') ?></a>
                    <?php endif; ?>
                    <button class="icon-btn sm danger" type="submit" form="act-trash-<?= $id ?>" title="Chuyển vào thùng rác"><?= icon('trash-2') ?></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
    <?php foreach ($items as $p): $id = (int) $p['id']; $t = e($p['title'] ?: '(Không tiêu đề)'); ?>
      <?php if ($isTrash): ?>
        <form id="act-restore-<?= $id ?>" method="post" action="/admin/pages/<?= $id ?>/restore" hidden><?= csrf_field() ?></form>
        <form id="act-delete-<?= $id ?>" method="post" action="/admin/pages/<?= $id ?>/delete" hidden data-confirm="Xoá vĩnh viễn &quot;<?= $t ?>&quot;?" data-confirm-text="Trang con sẽ được chuyển lên cấp trên. Không thể hoàn tác." data-confirm-label="Xoá vĩnh viễn" data-confirm-danger><?= csrf_field() ?></form>
      <?php else: ?>
        <form id="act-trash-<?= $id ?>" method="post" action="/admin/pages/<?= $id ?>/trash" hidden data-confirm="Chuyển &quot;<?= $t ?>&quot; vào thùng rác?" data-confirm-label="Chuyển vào thùng rác"><?= csrf_field() ?></form>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
