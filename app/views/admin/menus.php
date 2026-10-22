<?php
defined('LQ_ROOT') || exit;
/** Giao diện › Menu. Biến: $menus, $current, $items, $locations, $assigned, $sources */
$menuId = (int) ($current['id'] ?? 0);
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Giao diện</p>
    <h1>Menu</h1>
  </div>
  <div class="page-actions">
    <?php if ($menus): ?>
      <form method="get" action="/admin/menus" class="menu-pick">
        <select class="input w-auto" name="menu" data-menu-pick aria-label="Chọn menu để sửa">
          <?php foreach ($menus as $m): ?>
            <option value="<?= (int) $m['id'] ?>"<?= selected($m['id'], $menuId) ?>><?= e($m['name']) ?></option>
          <?php endforeach; ?>
          <?php if (!$current): ?><option value="new" selected>Menu mới</option><?php endif; ?>
        </select>
      </form>
    <?php endif; ?>
    <a class="btn btn-secondary" href="/admin/menus?menu=new"><?= icon('plus') ?> Tạo menu mới</a>
  </div>
</div>

<?php if (!$locations): ?>
  <div class="notice notice-warning" style="margin-bottom: 14px"><?= icon('triangle-alert') ?><div>Theme đang dùng chưa khai báo vị trí menu nào (<code>lq_register_nav_menu()</code> trong functions.php) — vẫn dựng menu được, nhưng chưa hiển thị ở đâu.</div></div>
<?php endif; ?>

<div class="menu-editor" data-menu-editor data-menu-id="<?= $menuId ?>">
  <aside class="menu-sources">
    <?php foreach ($sources as $i => $src): ?>
      <details class="card menu-src"<?= $i === 0 ? ' open' : '' ?>>
        <summary><?= e($src['title']) ?> <span class="muted"><?= count($src['items']) ?></span></summary>
        <?php if (count($src['items']) > 8): ?>
          <input class="input input-sm menu-src-filter" type="search" placeholder="Lọc…" data-src-filter aria-label="Lọc <?= e($src['title']) ?>">
        <?php endif; ?>
        <div class="checklist menu-src-list">
          <?php foreach ($src['items'] as $it): ?>
            <label class="check" style="--depth: <?= (int) $it['depth'] ?>">
              <input type="checkbox" data-src-item data-type="<?= e($it['type']) ?>" data-object="<?= e((string) $it['object']) ?>" data-label="<?= e($it['label']) ?>" data-kind="<?= e($it['kind']) ?>">
              <span><?= e($it['label']) ?><?php if (!empty($it['hint'])): ?> <small class="muted">· <?= e($it['hint']) ?></small><?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-secondary btn-sm" type="button" data-src-add><?= icon('plus') ?> Thêm vào menu</button>
      </details>
    <?php endforeach; ?>
    <details class="card menu-src"<?= !$sources ? ' open' : '' ?>>
      <summary>Liên kết tuỳ chỉnh</summary>
      <div class="stack" style="gap: 10px">
        <input class="input input-sm mono" type="text" placeholder="https://… hoặc /duong-dan" data-custom-url aria-label="Địa chỉ">
        <input class="input input-sm" type="text" placeholder="Nhãn hiển thị" data-custom-label aria-label="Nhãn">
      </div>
      <button class="btn btn-secondary btn-sm" type="button" data-custom-add><?= icon('plus') ?> Thêm vào menu</button>
    </details>
  </aside>

  <section class="card menu-main">
    <div class="menu-main-head">
      <input class="input menu-name" type="text" value="<?= e($current['name'] ?? '') ?>" placeholder="Tên menu, vd. Menu chính" maxlength="80" data-menu-name aria-label="Tên menu">
      <button class="btn btn-accent" type="button" data-menu-save><?= icon('check') ?> Lưu menu</button>
    </div>
    <p class="help">Kéo để sắp xếp; kéo một mục sang phải vào dưới mục khác để làm menu con. Bấm vào mục để sửa nhãn.</p>
    <ol class="menu-tree" data-menu-tree></ol>
    <div class="empty small menu-empty" data-menu-empty hidden>
      <?= icon('list-tree') ?>
      <h3>Menu chưa có mục nào</h3>
      <p class="muted">Chọn trang, bài viết, chuyên mục… ở cột bên trái rồi bấm “Thêm vào menu”.</p>
    </div>

    <?php if ($locations): ?>
      <div class="menu-locations">
        <h3>Hiển thị ở</h3>
        <?php foreach ($locations as $loc => $label): $other = ($assigned[$loc] ?? 0) && ($assigned[$loc] ?? 0) !== $menuId ? ($menus[$assigned[$loc]]['name'] ?? null) : null; ?>
          <label class="check">
            <input type="checkbox" value="<?= e($loc) ?>" data-menu-location<?= ($assigned[$loc] ?? 0) === $menuId && $menuId ? ' checked' : '' ?>>
            <span><?= e($label) ?> <small class="muted mono"><?= e($loc) ?></small><?php if ($other): ?> <small class="muted">— đang dùng “<?= e($other) ?>”, tích để thay</small><?php endif; ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($current): ?>
      <form class="menu-delete" method="post" action="/admin/menus/<?= $menuId ?>/delete" data-confirm="Xoá menu này?" data-confirm-text="Các vị trí đang dùng menu sẽ trống." data-confirm-label="Xoá menu" data-confirm-danger>
        <?= csrf_field() ?>
        <button class="btn-link-danger" type="submit"><?= icon('trash-2') ?> Xoá menu</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<script type="application/json" id="menu-data"><?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script src="<?= e(asset('admin/menus.js')) ?>"></script>
