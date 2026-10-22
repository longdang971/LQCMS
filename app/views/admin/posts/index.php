<?php
defined('LQ_ROOT') || exit;
use LQ\Content\Posts;
use LQ\Content\Taxonomies;

$listTax = array_filter(Taxonomies::custom(), fn ($t) => $t['list_column']);

$isTrash = $filters['status'] === 'trash';
$qs = function (array $override) use ($filters): string {
    $q = array_filter(array_merge($filters, $override), fn ($v) => $v !== '' && $v !== 0 && $v !== null);
    return '/admin/posts' . ($q ? '?' . http_build_query($q) : '');
};
$tabs = ['' => 'Tất cả', 'published' => 'Đã đăng', 'scheduled' => 'Lên lịch', 'draft' => 'Bản nháp', 'pending' => 'Chờ duyệt', 'trash' => 'Thùng rác'];
?>
<div class="page-head">
  <div>
    <h1>Bài viết</h1>
  </div>
  <div class="page-actions">
    <?php if ($isTrash && $counts['trash'] > 0 && can('edit_others_posts')): ?>
      <form method="post" action="/admin/posts/empty-trash" data-confirm="Xoá vĩnh viễn toàn bộ <?= (int) $counts['trash'] ?> bài trong thùng rác?" data-confirm-danger>
        <?= csrf_field() ?>
        <button class="btn btn-danger-soft" type="submit">Dọn thùng rác</button>
      </form>
    <?php endif; ?>
    <a class="btn btn-accent" href="/admin/posts/new"><?= icon('pen-line') ?> Viết bài mới</a>
  </div>
</div>

<div class="tabs">
  <?php foreach ($tabs as $key => $label):
      $count = $key === '' ? $counts['all'] : ($counts[$key] ?? 0);
      if ($key !== '' && $count === 0 && $filters['status'] !== $key) continue; ?>
    <a class="tab<?= $filters['status'] === $key ? ' is-active' : '' ?>" href="<?= e($qs(['status' => $key, 'page' => ''])) ?>">
      <?= e($label) ?> <span class="tab-count"><?= $count ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="card card-flush">
  <form class="toolbar" method="get" action="/admin/posts">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <?php if ($filters['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php endif; ?>
    <select class="input w-auto" name="category">
      <option value="">Mọi chuyên mục</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>"<?= selected($c['id'], $filters['category']) ?>><?= str_repeat('— ', $c['depth']) . e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input w-auto" name="month">
      <option value="">Mọi thời gian</option>
      <?php foreach ($months as $ym => $label): ?>
        <option value="<?= e($ym) ?>"<?= selected($ym, $filters['month']) ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit"><?= icon('filter') ?> Lọc</button>
    <?php if ($filters['q'] !== '' || $filters['category'] || $filters['tag'] || $filters['term'] || $filters['month'] !== ''): ?>
      <a class="btn btn-ghost" href="<?= e($qs(['q' => '', 'category' => 0, 'tag' => 0, 'term' => 0, 'month' => '', 'page' => ''])) ?>"><?= icon('x') ?> Xoá lọc</a>
    <?php endif; ?>
  </form>

  <?php if (!$items): ?>
    <div class="empty">
      <?= icon($isTrash ? 'trash' : 'file-text') ?>
      <h3><?= $isTrash ? 'Thùng rác trống' : ($filters['q'] !== '' || $filters['category'] || $filters['tag'] || $filters['term'] ? 'Không có bài viết phù hợp' : 'Chưa có bài viết nào') ?></h3>
      <?php if (!$isTrash): ?>
        <p class="muted">Bài viết bạn tạo sẽ được liệt kê ở đây.</p>
        <a class="btn btn-primary" href="/admin/posts/new"><?= icon('plus') ?> Viết bài mới</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <form method="post" action="/admin/posts/bulk" id="bulk-form" data-bulk-form>
      <?= csrf_field() ?>
      <div class="bulk-bar" data-bulk-bar hidden>
        <span><strong data-bulk-count>0</strong> bài được chọn</span>
        <select class="input input-sm w-auto" name="bulk_action">
          <?php if ($isTrash): ?>
            <option value="restore">Khôi phục</option>
            <option value="delete">Xoá vĩnh viễn</option>
          <?php else: ?>
            <option value="trash">Chuyển vào thùng rác</option>
            <?php if (can('publish_posts')): ?><option value="publish">Đăng</option><?php endif; ?>
            <option value="draft">Chuyển về nháp</option>
          <?php endif; ?>
        </select>
        <button class="btn btn-sm btn-primary" type="submit">Áp dụng</button>
      </div>
      <div class="table-wrap">
        <table class="table table-fit">
          <thead>
            <tr>
              <th class="col-check"><input type="checkbox" data-check-all aria-label="Chọn tất cả"></th>
              <th class="col-title">Tiêu đề</th>
              <th class="hide-sm">Tác giả</th>
              <th class="hide-md">Thẻ</th>
              <?php foreach ($listTax as $lt): ?><th class="hide-md"><?= e($lt['label']) ?></th><?php endforeach; ?>
              <th>Trạng thái</th>
              <th class="hide-sm">Ngày đăng</th>
              <th class="col-actions">Hành động</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($items as $p): $editUrl = '/admin/posts/' . (int) $p['id'] . '/edit'; ?>
            <tr>
              <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" data-check-item aria-label="Chọn bài"></td>
              <td class="col-title">
                <div class="post-cell">
                  <div>
                    <?php if ($isTrash): ?>
                      <strong><?= e($p['title'] ?: '(Không tiêu đề)') ?></strong>
                    <?php else: ?>
                      <a class="row-title" href="<?= e($editUrl) ?>"><?= e($p['title'] ?: '(Không tiêu đề)') ?></a>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="hide-sm"><span class="author-cell"><?= avatar($p['author_name'] ?? '?', 'sm', (string) $p['author_id']) ?><?= e($p['author_name'] ?? '—') ?></span></td>
              <td class="hide-md term-links tag-text">
                <?= implode(', ', array_map(fn ($t) => '<a href="' . e($qs(['tag' => $t['id'], 'page' => ''])) . '">#' . e($t['name']) . '</a>', array_slice($p['tags'], 0, 2))) ?: '—' ?>
                <?php if (count($p['tags']) > 2): ?> +<?= count($p['tags']) - 2 ?><?php endif; ?>
              </td>
              <?php foreach ($listTax as $lt): $ts = $p['terms'][$lt['key']] ?? []; ?>
                <td class="hide-md term-links tag-text">
                  <?= implode(', ', array_map(fn ($t) => '<a href="' . e($qs(['term' => $t['id'], 'page' => ''])) . '">' . e($t['name']) . '</a>', array_slice($ts, 0, 2))) ?: '—' ?>
                  <?php if (count($ts) > 2): ?> +<?= count($ts) - 2 ?><?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td><span class="badge status-<?= e($p['status']) ?>"><?= e(Posts::STATUSES[$p['status']] ?? $p['status']) ?></span></td>
              <td class="hide-sm date-cell">
                <?php if ($p['published_at'] && in_array($p['status'], ['published', 'scheduled'], true)): ?>
                  <?= e(format_date($p['published_at'])) ?>
                <?php else: ?>
                  Sửa <?= e(format_date($p['updated_at'])) ?>
                <?php endif; ?>
              </td>
              <td class="col-actions">
                <div class="row-actions">
                  <?php if ($isTrash): ?>
                    <button class="icon-btn sm" type="submit" form="act-restore-<?= (int) $p['id'] ?>" title="Khôi phục"><?= icon('undo-2') ?></button>
                    <button class="icon-btn sm danger" type="submit" form="act-delete-<?= (int) $p['id'] ?>" title="Xoá vĩnh viễn"><?= icon('trash-2') ?></button>
                  <?php else: ?>
                    <a class="icon-btn sm" href="<?= e($editUrl) ?>" title="Sửa"><?= icon('pencil') ?></a>
                    <?php if ($p['status'] === 'published'): ?>
                      <a class="icon-btn sm" href="<?= e(Posts::permalink($p)) ?>" target="_blank" rel="noopener" title="Xem"><?= icon('eye') ?></a>
                    <?php endif; ?>
                    <button class="icon-btn sm danger" type="submit" form="act-trash-<?= (int) $p['id'] ?>" title="Chuyển vào thùng rác"><?= icon('trash-2') ?></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>

    <?php // Form hành động riêng từng dòng (nằm ngoài form bulk vì HTML không cho form lồng nhau).
          // Tiêu đề bài chỉ đặt ở data-confirm-text: data-confirm thành tiêu đề SweetAlert, được vẽ như HTML. ?>
    <?php foreach ($items as $p): $id = (int) $p['id']; $t = e($p['title'] ?: '(Không tiêu đề)'); ?>
      <?php if ($isTrash): ?>
        <form id="act-restore-<?= $id ?>" method="post" action="/admin/posts/<?= $id ?>/restore" hidden><?= csrf_field() ?></form>
        <form id="act-delete-<?= $id ?>" method="post" action="/admin/posts/<?= $id ?>/delete" hidden
              data-confirm="Xoá vĩnh viễn bài viết?" data-confirm-text="&quot;<?= $t ?>&quot; — thao tác này không thể hoàn tác." data-confirm-label="Xoá vĩnh viễn" data-confirm-danger><?= csrf_field() ?></form>
      <?php else: ?>
        <form id="act-trash-<?= $id ?>" method="post" action="/admin/posts/<?= $id ?>/trash" hidden
              data-confirm="Chuyển bài viết vào thùng rác?" data-confirm-text="&quot;<?= $t ?>&quot; — bạn có thể khôi phục lại trong mục Thùng rác." data-confirm-label="Chuyển vào thùng rác"><?= csrf_field() ?></form>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($pages > 1):
        // Luôn có trang đầu, trang cuối và 1 trang hai bên trang hiện tại; chỗ hở thay bằng "…" (hở đúng 1 trang thì hiện luôn số đó).
        $nums = array_values(array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], fn ($n) => $n >= 1 && $n <= $pages)));
        sort($nums);
        $from = ($page - 1) * $perPage + 1;
        $to = min($total, $page * $perPage);
    ?>
      <nav class="pagination" aria-label="Phân trang">
        <span class="pagination-info"><b><?= $from ?>–<?= $to ?></b> trong <?= number_format($total, 0, ',', '.') ?> bài viết</span>
        <div class="pager">
          <a class="pager-step<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= e($qs(['page' => max(1, $page - 1)])) ?>" rel="prev"><?= icon('chevron-left') ?><span>Trước</span></a>
          <div class="pager-nums">
            <?php $prev = 0; foreach ($nums as $n): ?>
              <?php if ($n - $prev === 2): ?>
                <a class="page-num" href="<?= e($qs(['page' => $n - 1])) ?>"><?= $n - 1 ?></a>
              <?php elseif ($n - $prev > 2): ?>
                <span class="page-gap" aria-hidden="true">…</span>
              <?php endif; ?>
              <?php if ($n === $page): ?>
                <span class="page-num is-active" aria-current="page"><?= $n ?></span>
              <?php else: ?>
                <a class="page-num" href="<?= e($qs(['page' => $n])) ?>"><?= $n ?></a>
              <?php endif; ?>
            <?php $prev = $n; endforeach; ?>
          </div>
          <a class="pager-step<?= $page >= $pages ? ' disabled' : '' ?>" href="<?= e($qs(['page' => min($pages, $page + 1)])) ?>" rel="next"><span>Sau</span><?= icon('chevron-right') ?></a>
        </div>
        <span class="pagination-info is-end">Trang <b><?= $page ?></b> / <?= $pages ?></span>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
