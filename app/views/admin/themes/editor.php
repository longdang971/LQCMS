<?php
defined('LQ_ROOT') || exit;
/**
 * Trình sửa mã theme. Biến: $themes, $theme, $isActive, $tree, $fileCount, $file, $current (tệp đang mở), $line.
 */
use LQ\Core\ThemeFiles;

$base = '/admin/themes/editor?theme=' . rawurlencode($theme['slug']);
$currentDir = str_contains($file, '/') ? dirname($file) : '';

/** Vẽ cây: thư mục (gập/mở được) trước, rồi tới tệp. */
$renderTree = function (array $node, int $depth) use (&$renderTree, $base, $file, $currentDir): void {
    foreach ($node['dirs'] as $name => $dir) {
        // Thư mục chứa tệp đang mở luôn mở sẵn; còn lại JS khôi phục trạng thái đã lưu.
        $open = $currentDir === $dir['path'] || str_starts_with($currentDir, $dir['path'] . '/');
        $count = count($dir['files']) + count($dir['dirs']);
        ?>
        <details class="tree-dir" data-dir="<?= e($dir['path']) ?>"<?= $open ? ' open' : '' ?> style="--depth: <?= $depth ?>">
          <summary class="tree-row">
            <?= icon('chevron-right', 'tree-chevron') ?>
            <span class="tree-icon tree-folder"><?= icon('folder', 'i-closed') ?><?= icon('folder-open', 'i-open') ?></span>
            <span class="tree-name"><?= e($name) ?></span>
            <span class="tree-count"><?= $count ?></span>
          </summary>
          <div class="tree-children"><?php $renderTree($dir, $depth + 1); ?></div>
        </details>
        <?php
    }
    foreach ($node['files'] as $f) {
        [$ic, $kind] = ThemeFiles::icon($f['path']);
        $isCurrent = $f['path'] === $file;
        ?>
        <a class="tree-row tree-file<?= $isCurrent ? ' is-current' : '' ?>" style="--depth: <?= $depth ?>"
           href="<?= e($base . '&file=' . rawurlencode($f['path'])) ?>" data-te-file="<?= e($f['path']) ?>"
           <?= $f['desc'] !== '' ? 'data-tip="' . e($f['desc']) . '"' : '' ?><?= $isCurrent ? ' aria-current="page"' : '' ?>>
          <span class="tree-chevron-space"></span>
          <span class="tree-icon kind-<?= e($kind) ?>"><?= icon($ic) ?></span>
          <span class="tree-name"><?= e($f['name']) ?></span>
          <?php if ($f['desc'] !== '' && $depth === 0): ?><span class="tree-desc"><?= e($f['desc']) ?></span><?php endif; ?>
        </a>
        <?php
    }
};
?>
<div class="te" data-theme-editor data-theme="<?= e($theme['slug']) ?>" data-theme-name="<?= e($theme['name']) ?>" data-error="<?= e(mb_substr((string) input_str('error'), 0, 500)) ?>" data-line="<?= (int) $line ?>">
  <script type="application/json" data-te-initial><?= json_encode($current, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

  <aside class="te-explorer" data-te-explorer>
    <label class="te-theme-picker">
      <span class="te-theme-shot">
        <?php if ($theme['screenshot']): ?><img src="<?= e($theme['screenshot']) ?>" alt=""><?php else: ?><?= icon('palette') ?><?php endif; ?>
      </span>
      <span class="te-theme-meta">
        <strong><?= e($theme['name']) ?></strong>
        <small><?= $isActive ? '<span class="te-live"></span>Đang dùng' : 'Không kích hoạt' ?><?= $theme['version'] ? ' · v' . e($theme['version']) : '' ?></small>
      </span>
      <?= icon('chevrons-up-down', 'te-theme-caret') ?>
      <select data-te-theme aria-label="Chọn theme để sửa">
        <?php foreach ($themes as $t): ?>
          <option value="<?= e($t['slug']) ?>"<?= selected($t['slug'], $theme['slug']) ?>><?= e($t['name']) ?><?= $t['slug'] === \LQ\Core\Themes::active() ? ' — đang dùng' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <div class="te-explorer-head">
      <span class="te-label">Tệp</span>
      <div class="te-explorer-tools">
        <button class="icon-btn xs" type="button" data-te-new data-tip="Tệp mới"><?= icon('file-plus') ?></button>
        <button class="icon-btn xs" type="button" data-te-collapse data-tip="Thu gọn thư mục"><?= icon('chevrons-down-up') ?></button>
      </div>
    </div>

    <div class="te-search">
      <?= icon('search') ?>
      <input type="search" placeholder="Tìm tệp…" data-te-filter aria-label="Tìm tệp" autocomplete="off" spellcheck="false">
      <?= kbd('P') ?>
    </div>

    <form class="te-new" data-te-new-form hidden>
      <span class="tree-icon kind-php"><?= icon('file-plus') ?></span>
      <input class="mono" name="file" placeholder="single-review.php · parts/banner.php" autocomplete="off" spellcheck="false" required aria-label="Tên tệp mới">
      <button class="icon-btn xs" type="button" data-te-new-cancel aria-label="Huỷ"><?= icon('x') ?></button>
    </form>

    <nav class="te-tree" aria-label="Tệp của theme" data-te-tree>
      <?php $renderTree($tree, 0); ?>
      <p class="te-tree-empty" data-te-tree-empty hidden>Không có tệp nào khớp.</p>
    </nav>

    <div class="te-explorer-foot">
      <span><?= $fileCount ?> tệp</span>
      <a href="/admin/themes" class="link-muted">Quản lý theme</a>
    </div>
  </aside>

  <section class="te-main">
    <div class="te-tabs" role="tablist" data-te-tabs></div>

    <header class="te-bar">
      <button class="icon-btn sm" type="button" data-te-toggle-explorer data-tip="Ẩn/hiện danh sách tệp"><?= icon('panel-left') ?></button>
      <nav class="te-crumbs" data-te-crumbs aria-label="Đường dẫn tệp"></nav>
      <span class="chip chip-muted te-desc" data-te-desc hidden></span>
      <div class="te-bar-tools">
        <?php if ($isActive): ?>
          <span class="te-pill" data-tip="Lỗi cú pháp sẽ bị chặn khi lưu, nhưng lỗi lúc chạy vẫn có thể làm website báo lỗi. Bản trước mỗi lần lưu được giữ lại để khôi phục."><span class="te-live"></span>Theme đang chạy</span>
        <?php endif; ?>
        <?php if (\LQ\AI\AI::enabled()): ?>
          <div class="dropdown" data-dropdown>
            <button class="btn btn-ghost btn-sm" type="button" data-dropdown-toggle aria-haspopup="menu"><?= icon('sparkles') ?> AI <?= icon('chevron-down') ?></button>
            <div class="dropdown-menu" role="menu">
              <div class="dropdown-head"><strong>Trợ lý AI</strong><small><?= e(\LQ\AI\Connectors::LIST[\LQ\AI\AI::active()]['label']) ?> · không tự lưu, bạn xem rồi mới áp dụng</small></div>
              <button type="button" data-ai-theme-fix><?= icon('bug') ?> Tìm lỗi trong tệp này</button>
              <button type="button" data-ai-theme-scan><?= icon('scan-search') ?> Tìm bug toàn bộ theme</button>
              <button type="button" data-ai-theme-explain><?= icon('book-open') ?> Giải thích tệp này</button>
              <button type="button" data-ai-theme-generate><?= icon('file-plus-2') ?> Tạo tệp mới từ mô tả</button>
            </div>
          </div>
        <?php endif; ?>
        <button class="icon-btn sm" type="button" data-te-wrap data-tip="Ngắt dòng dài" aria-pressed="false"><?= icon('wrap-text') ?></button>
        <button class="btn btn-ghost btn-sm" type="button" data-te-restore hidden data-tip="Trả tệp về bản ngay trước lần lưu gần nhất"><?= icon('history') ?> Khôi phục</button>
        <button class="btn btn-sm te-save" type="button" data-te-save disabled><?= icon('check', 'i-saved') ?><?= icon('save', 'i-dirty') ?><span data-te-save-label>Đã lưu</span><?= kbd('S') ?></button>
      </div>
    </header>

    <div class="te-readonly" data-te-readonly hidden><?= icon('lock') ?><span>Máy chủ không cho ghi tệp này. Cấp quyền ghi cho thư mục <code>content/themes/<?= e($theme['slug']) ?></code> để sửa.</span></div>

    <div class="te-editor-wrap">
      <textarea class="te-fallback" data-te-source spellcheck="false" aria-label="Nội dung tệp"><?= e($current['content']) ?></textarea>
      <div class="te-editor" data-te-editor hidden></div>
      <div class="te-loading" data-te-loading hidden><span></span></div>
    </div>

    <footer class="te-status">
      <span class="te-status-item" data-te-saved-at><?= icon('circle-check') ?><span>Chưa sửa</span></span>
      <span class="te-status-spacer"></span>
      <span class="te-status-item" data-te-pos>Dòng 1, Cột 1</span>
      <span class="te-status-item">Dấu cách: 4</span>
      <span class="te-status-item">UTF-8</span>
      <span class="te-status-item" data-te-size></span>
      <span class="te-status-item te-lang" data-te-lang></span>
    </footer>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/ace-builds@1.36.5/src-min-noconflict/ace.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ace-builds@1.36.5/src-min-noconflict/ext-language_tools.js"></script>
<script src="<?= e(asset('admin/theme-editor.js')) ?>"></script>
