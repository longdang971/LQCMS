<?php
/** Lịch sử phiên bản: danh sách bên trái, so sánh bản được chọn với bản hiện tại bên phải. */
$editUrl = $base . '/' . (int) $post['id'] . '/edit';
$changes = count(array_filter($diff, fn ($d) => $d['op'] !== '='));
?>
<div class="page-head">
  <div>
    <h1>Lịch sử phiên bản</h1>
    <p><a class="link" href="<?= e($editUrl) ?>"><?= e($post['title'] ?: '(Không tiêu đề)') ?></a> · giữ tối đa <?= (int) option('revisions_keep', 25) ?> phiên bản gần nhất</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-secondary" href="<?= e($editUrl) ?>"><?= icon('arrow-left') ?> Quay lại soạn thảo</a>
  </div>
</div>

<?php if (!$list): ?>
  <div class="card empty">
    <?= icon('history') ?>
    <h3>Chưa có phiên bản nào</h3>
    <p class="muted">Mỗi lần bấm lưu, một phiên bản sẽ được ghi lại tại đây.</p>
  </div>
<?php else: ?>
  <div class="rev-layout">
    <nav class="card card-flush rev-list" aria-label="Danh sách phiên bản">
      <?php foreach ($list as $i => $r): ?>
        <a class="rev-item<?= $rev && (int) $rev['id'] === (int) $r['id'] ? ' is-active' : '' ?>" href="?rev=<?= (int) $r['id'] ?>">
          <?= avatar($r['author_name'] ?? '?', 'sm', (string) $r['author_id']) ?>
          <span class="rev-meta">
            <strong><?= e(format_date($r['created_at'], 'H:i:s · d/m/Y')) ?></strong>
            <small><?= e($r['author_name'] ?? 'Không rõ') ?><?= $i === 0 && $r['kind'] === 'revision' ? ' · bản mới nhất' : '' ?></small>
          </span>
          <?php if ($r['kind'] === 'autosave'): ?><span class="badge status-scheduled">Tự lưu</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <section class="card rev-compare">
      <?php if (!$rev): ?>
        <div class="empty small"><?= icon('git-compare') ?><h3>Chọn một phiên bản để so sánh</h3><p class="muted">Bản mới nhất đang trùng với nội dung hiện tại.</p></div>
      <?php else: ?>
        <div class="rev-head">
          <div>
            <h3>So sánh bản lúc <?= e(format_date($rev['created_at'], 'H:i d/m/Y')) ?> với bản hiện tại</h3>
            <p class="muted small">
              <span class="rev-key del">Chỉ có trong bản cũ</span>
              <span class="rev-key ins">Chỉ có trong bản hiện tại</span>
              · <?= $changes ? $changes . ' dòng khác nhau' : 'Nội dung giống nhau' ?>
            </p>
          </div>
          <form method="post" action="<?= e($base . '/' . (int) $post['id'] . '/revisions/' . (int) $rev['id'] . '/restore') ?>"
                data-confirm="Khôi phục phiên bản lúc <?= e(format_date($rev['created_at'], 'H:i d/m')) ?>?"
                data-confirm-text="Tiêu đề và nội dung hiện tại sẽ được thay bằng phiên bản này. Bản hiện tại vẫn được giữ trong lịch sử."
                data-confirm-label="Khôi phục">
            <?= csrf_field() ?>
            <button class="btn btn-accent btn-sm" type="submit"><?= icon('rotate-ccw') ?> Khôi phục bản này</button>
          </form>
        </div>

        <div class="rev-section">
          <div class="subhead">Tiêu đề</div>
          <?php if ($rev['title'] === $post['title']): ?>
            <div class="diff-line"><?= e($post['title']) ?></div>
          <?php else: ?>
            <div class="diff-line is-del"><span class="diff-sign">−</span><?= $titleDiff[0] ?></div>
            <div class="diff-line is-ins"><span class="diff-sign">+</span><?= $titleDiff[1] ?></div>
          <?php endif; ?>
        </div>

        <div class="rev-section">
          <div class="subhead">Nội dung</div>
          <?php if (!$diff): ?>
            <p class="muted small">(Trống)</p>
          <?php endif; ?>
          <div class="diff">
            <?php foreach ($diff as $d): ?>
              <div class="diff-line<?= $d['op'] === '-' ? ' is-del' : ($d['op'] === '+' ? ' is-ins' : '') ?>">
                <span class="diff-sign"><?= $d['op'] === '-' ? '−' : ($d['op'] === '+' ? '+' : '') ?></span><?= $d['html'] ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>
<?php endif; ?>
