<?php defined('LQ_ROOT') || exit; ?>
<form class="search-form" role="search" method="get" action="<?= e(home_url('/')) ?>">
  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
  <input type="search" name="s" value="<?php the_search_query(); ?>" placeholder="Nhập từ khoá, gõ không dấu cũng được…" aria-label="Tìm kiếm">
  <button type="submit" class="btn">Tìm</button>
</form>
