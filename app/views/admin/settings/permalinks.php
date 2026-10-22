<?php
defined('LQ_ROOT') || exit;
$custom = old('custom_structure', $current === 'custom' ? $structure : '/%year%/%postname%/');
$choice = old('preset', $current);
$site = rtrim((string) option('site_url'), '/');
?>
<div class="page-head">
  <div>
    <h1>Đường dẫn tĩnh</h1>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="submit" form="permalink-form">Lưu thay đổi</button>
  </div>
</div>

<form method="post" action="/admin/settings/permalinks" class="settings" id="permalink-form" data-permalink-form data-dirty-guard
      data-site="<?= e($site) ?>"
      data-sample="<?= e(json_encode([
          '%year%' => format_date($sample['published_at'], 'Y'),
          '%monthnum%' => format_date($sample['published_at'], 'm'),
          '%day%' => format_date($sample['published_at'], 'd'),
          '%hour%' => format_date($sample['published_at'], 'H'),
          '%minute%' => format_date($sample['published_at'], 'i'),
          '%second%' => format_date($sample['published_at'], 's'),
          '%post_id%' => (string) $sample['id'],
          '%postname%' => $sample['slug'],
          '%category%' => $sample['category_path'],
          '%author%' => $sample['author'],
      ], JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>

  <section class="settings-section first">
    <div class="settings-label">
      <h3>Cấu trúc bài viết</h3>
      <p>Mẫu có sẵn hoặc tự ghép từ các thẻ. Cấu trúc phải chứa <code>%postname%</code> hoặc <code>%post_id%</code>.</p>
    </div>
    <div class="settings-fields" style="max-width: none">
      <div class="preset-list">
        <?php foreach ($presets as $key => $p): ?>
          <label class="preset<?= $choice === $key ? ' is-checked' : '' ?>">
            <input type="radio" name="preset" value="<?= e($key) ?>" data-structure="<?= e($p['value']) ?>"<?= checked($key, $choice) ?>>
            <span class="preset-label"><?= e($p['label']) ?></span>
            <code class="preset-example"><?= e($p['example']) ?></code>
          </label>
        <?php endforeach; ?>
        <label class="preset<?= $choice === 'custom' ? ' is-checked' : '' ?>">
          <input type="radio" name="preset" value="custom"<?= checked('custom', $choice) ?>>
          <span class="preset-label">Tuỳ chỉnh</span>
          <span class="preset-custom">
            <span class="muted"><?= e($site) ?></span>
            <input class="input mono<?= invalid($errors, 'structure') ?>" name="custom_structure" value="<?= e($custom) ?>" data-custom-structure spellcheck="false" aria-label="Cấu trúc tuỳ chỉnh">
          </span>
        </label>
      </div>
      <?= field_error($errors, 'structure') ?>

      <div>
        <p class="help" style="margin-bottom: 8px">Bấm để chèn thẻ vào cấu trúc tuỳ chỉnh:</p>
        <div class="chips">
          <?php foreach ($tags as $tag => [, $desc]): ?>
            <button type="button" class="chip chip-btn mono" data-insert-tag="<?= e($tag) ?>" title="<?= e($desc) ?>"><?= e($tag) ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="preview-box">
        <span>Xem trước</span>
        <code data-permalink-preview></code>
      </div>
    </div>
  </section>

  <section class="settings-section">
    <div class="settings-label">
      <h3>Tiền tố lưu trữ</h3>
      <p>Đứng trước slug ở trang chuyên mục và trang thẻ. Chuyên mục có thể bỏ tiền tố để URL gọn hơn, vd. <code>/the-loai/</code> thay vì <code>/chuyen-muc/the-loai/</code>.</p>
    </div>
    <div class="settings-fields">
      <?php
        // Sau khi lưu lỗi: lấy trạng thái người dùng vừa chọn; bình thường: theo cài đặt đã lưu.
        $hasOld = old('preset', null) !== null;
        $noCatBase = $hasOld ? (bool) old('category_no_base', '') : $categoryBase === '';
        $catValue = (string) old('category_base', $categoryBase);
      ?>
      <div class="field" data-base-field>
        <label for="category_base">Chuyên mục</label>
        <div class="input-prefix">
          <span>/</span>
          <input id="category_base" name="category_base" class="input mono<?= invalid($errors, 'category_base') ?>" value="<?= e($catValue) ?>" placeholder="chuyen-muc" data-base-input<?= $noCatBase ? ' disabled' : '' ?>>
          <span>/the-loai/</span>
        </div>
        <label class="check">
          <input type="checkbox" name="category_no_base" value="1" data-no-base<?= $noCatBase ? ' checked' : '' ?>>
          <span>Không dùng tiền tố</span>
        </label>
        <p class="help">URL chuyên mục: <code data-base-preview data-sample="the-loai"></code></p>
        <?= field_error($errors, 'category_base') ?>
      </div>
      <div class="field" data-base-field>
        <label for="tag_base">Thẻ</label>
        <div class="input-prefix">
          <span>/</span>
          <input id="tag_base" name="tag_base" class="input mono<?= invalid($errors, 'tag_base') ?>" value="<?= e(old('tag_base', $tagBase)) ?>" placeholder="the" data-base-input>
          <span>/php/</span>
        </div>
        <p class="help">URL thẻ: <code data-base-preview data-sample="php"></code></p>
        <?= field_error($errors, 'tag_base') ?>
      </div>
    </div>
  </section>

  <section class="settings-section">
    <div class="settings-label">
      <h3>Cấu hình máy chủ</h3>
      <p>Chỉ làm một lần khi triển khai.</p>
    </div>
    <div class="settings-fields">
      <details class="boxed help-card">
        <summary><?= icon('server') ?> nginx</summary>
        <pre class="code-block">location / {
    try_files $uri $uri/ /index.php?$args;
}</pre>
      </details>
      <details class="boxed help-card">
        <summary><?= icon('server') ?> Apache</summary>
        <p class="small">Tệp <code>.htaccess</code> ở thư mục gốc đã có sẵn nội dung này:</p>
        <pre class="code-block">RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]</pre>
      </details>
      <p class="help">Khi đổi cấu trúc, cấu trúc cũ được lưu lại để chuyển hướng 301 link cũ sang link mới.</p>
    </div>
  </section>

  <div class="form-footer">
    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
  </div>
</form>
