<?php
use LQ\Admin\DashboardStats as S;
use LQ\Content\Posts;

$recent = S::recent($size >= 12 ? 8 : 6);
?>
<section class="tile tile-flush">
  <div class="tile-title padded">
    <div><h3>Cập nhật gần đây</h3></div>
    <a class="link small" href="/admin/posts">Tất cả bài viết <?= icon('arrow-right') ?></a>
  </div>
  <?php if (!$recent): ?>
    <div class="empty small"><?= icon('file-text') ?><h3>Chưa có bài viết</h3><p class="muted">Bài viết bạn tạo sẽ hiện ở đây.</p></div>
  <?php else: ?>
    <ul class="post-rows">
      <?php foreach ($recent as $p): ?>
        <li>
          <a class="post-row" href="/admin/posts/<?= (int) $p['id'] ?>/edit">
            <?= post_thumb($p['featured_image'], $p['title'], 'thumb thumb-lg') ?>
            <span class="post-row-main">
              <strong><?= e($p['title'] ?: '(Không tiêu đề)') ?></strong>
              <span class="post-row-meta">
                <?php if ($p['categories']): $c = $p['categories'][0]; ?><span class="cat"><?= cat_dot((int) $c['id']) ?><?= e($c['name']) ?></span><?php endif; ?>
                <span><?= e(format_date($p['updated_at'])) ?></span>
              </span>
            </span>
            <span class="post-row-side">
              <?= avatar($p['author_name'] ?? '?', 'sm', (string) $p['author_id']) ?>
              <span class="badge status-<?= e($p['status']) ?>"><?= e(Posts::STATUSES[$p['status']] ?? $p['status']) ?></span>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
