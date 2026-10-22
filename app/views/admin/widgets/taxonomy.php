<?php
defined('LQ_ROOT') || exit;
use LQ\Admin\DashboardStats as S;

$t = S::termCounts();
// Widget mở cho mọi người có edit_posts, nhưng trang Chuyên mục cần manage_terms (tác giả bấm vào gặp 403).
$tag = can('manage_terms') ? 'a' : 'div';
?>
<<?= $tag ?> class="tile"<?= $tag === 'a' ? ' href="/admin/categories"' : '' ?>>
  <div class="tile-head"><span>Phân loại</span><?= icon('folder-tree') ?></div>
  <div class="tile-value" data-count="<?= $t['category'] ?>"><?= number_format($t['category'], 0, ',', '.') ?></div>
  <div class="tile-foot tile-split">
    <span><b><?= $t['category'] ?></b> chuyên mục</span>
    <span><b><?= $t['tag'] ?></b> thẻ</span>
  </div>
</<?= $tag ?>>
