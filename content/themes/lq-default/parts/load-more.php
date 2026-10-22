<?php
/**
 * Phân trang + nút "Xem thêm bài" (AJAX). Không có JS thì vẫn là phân trang thường;
 * có JS (assets/theme.js) thì ẩn phân trang, bấm nút gọi action 'posts' với cùng điều kiện của trang đang xem.
 * Đặt ngay sau khối .grid-cards. $args['query'] = truy vấn khác truy vấn chính (tuỳ chọn).
 */
defined('LQ_ROOT') || exit;
$q = $args['query'] ?? lq_main_query();
the_posts_pagination(['query' => $q] + ($args['pagination'] ?? []));
if ($q->page >= $q->maxPages) {
    return;
}
?>
<div class="load-more" data-load-more data-page="<?= $q->page ?>" data-max="<?= $q->maxPages ?>"
     data-query="<?= e(json_encode(lq_ajax_query_args($q), JSON_UNESCAPED_UNICODE)) ?>" hidden>
  <button type="button" class="btn btn-ghost">Xem thêm bài</button>
  <span class="load-more-status muted small" aria-live="polite"></span>
</div>
