<?php defined('LQ_ROOT') || exit; ?>
<section class="tile">
  <div class="tile-title"><div><h3>Bản nháp nhanh</h3><p>Ghi lại ý tưởng, viết tiếp sau.</p></div></div>
  <form method="post" action="/admin/quick-draft" class="quick-draft">
    <?= csrf_field() ?>
    <input class="input" name="title" placeholder="Tiêu đề" required>
    <textarea class="input" name="content" rows="3" placeholder="Nội dung…"></textarea>
    <button class="btn btn-primary btn-sm" type="submit">Lưu nháp</button>
  </form>
</section>
