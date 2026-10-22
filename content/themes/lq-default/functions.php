<?php
/**
 * functions.php của theme được nạp ở mỗi request khi theme đang kích hoạt (giống WordPress).
 * Dùng để đăng ký taxonomy, field, mẫu trang, hook và các hàm tiện ích riêng của theme.
 */
defined('LQ_ROOT') || exit;

/*
 * Taxonomy "Diễn viên" — chọn như Thẻ (gõ tên, có gợi ý, tự tạo mới), dùng chung giữa các bài.
 * Admin: Bài viết › Diễn viên. Frontend: /dien-vien/ten-dien-vien/ hiển thị bằng taxonomy-actor.php.
 * Trong template: the_terms('actor', ', ') hoặc get_the_terms('actor').
 */
lq_register_taxonomy('actor', [
    'label' => 'Diễn viên',
    'singular' => 'diễn viên',
    'route' => 'dien-vien',
    'base' => 'dien-vien',
    'hierarchical' => false,
    'icon' => 'clapperboard',
    'placeholder' => 'Gõ tên diễn viên…',
    'description' => 'Diễn viên tham gia. Gõ tên rồi nhấn Enter, tên mới sẽ tự được tạo.',
    'list_column' => true,
]);

/*
 * Field riêng của theme cho bài viết. Đọc ra bằng get_field('subtitle') / get_field('featured').
 * "Bài nổi bật" được truy vấn bằng lq_query(['meta' => ['key' => 'featured', 'value' => true]]) ở trang chủ.
 */
lq_add_post_field('subtitle', [
    'label' => 'Sapo',
    'type' => 'textarea',
    'box' => 'Hiển thị (theme LQ Default)',
    'max' => 300,
    'help' => 'Một hai câu dẫn hiện dưới tiêu đề. Để trống thì theme tự cắt từ nội dung.',
    'ai_role' => 'sapo',
]);
lq_add_post_field('featured', [
    'label' => 'Đưa lên ô lớn ở trang chủ',
    'type' => 'checkbox',
    'box' => 'Hiển thị (theme LQ Default)',
    'context' => 'side',
]);

/* Mẫu trang: chọn ở khung "Thuộc tính trang". Field trong 'fields' chỉ hiện khi trang chọn đúng mẫu. */
lq_register_page_template('contact', [
    'label' => 'Trang liên hệ',
    'description' => 'Có thông tin liên hệ và bản đồ.',
    'file' => 'templates/contact.php',
    'fields' => [
        'contact_phone' => ['label' => 'Số điện thoại', 'type' => 'text', 'width' => 'half', 'placeholder' => '0901 234 567'],
        'contact_email' => ['label' => 'Email nhận liên hệ', 'type' => 'email', 'width' => 'half'],
        'contact_address' => ['label' => 'Địa chỉ', 'type' => 'textarea', 'max' => 500],
        'contact_map' => ['label' => 'Link Google Maps', 'type' => 'url', 'help' => 'Dán link chia sẻ từ Google Maps.'],
    ],
]);

lq_register_page_template('full-width', [
    'label' => 'Toàn chiều rộng',
    'description' => 'Không có cột bên, nội dung trải hết chiều ngang (hợp cho landing page).',
    'file' => 'templates/full-width.php',
]);

/* ---------- Hook ---------- */

// Tóm tắt trên thẻ bài ngắn hơn mặc định (40 từ).
add_filter('excerpt_length', fn () => 28);

// Sapo (nếu có) được dùng làm tóm tắt — kéo theo cả thẻ meta description và og:description.
add_filter('get_the_excerpt', function (string $text, array $post) {
    $sub = trim((string) post_meta((int) $post['id'], 'subtitle', ''));
    return $sub !== '' ? $sub : $text;
}, 10);

// Thêm ?debug=1 khi đã đăng nhập: hiện template đang dùng và các câu hỏi điều kiện — để kiểm tra API.
add_action('lq_footer', function () {
    if (!is_logged_in() || !isset($_GET['debug'])) {
        return;
    }
    $checks = ['is_front_page', 'is_home', 'is_singular', 'is_single', 'is_page', 'is_archive', 'is_category', 'is_tag', 'is_tax', 'is_author', 'is_date', 'is_search', 'is_404', 'is_paged', 'is_preview', 'is_page_template'];
    $q = lq_main_query();
    echo '<aside class="debug-panel"><strong>' . e(get_current_template()) . '</strong>';
    foreach ($checks as $fn) {
        echo '<span class="' . ($fn() ? 'on' : '') . '">' . e($fn) . '()</span>';
    }
    echo '<span>found ' . $q->found . ' · page ' . $q->page . '/' . max(1, $q->maxPages) . '</span></aside>';
});

// AJAX: action 'posts' của lõi trả thêm sapo khi gọi format=json (nút "Xem thêm bài" dùng format=html với parts/card).
add_filter('ajax_post_data', function (array $data, array $post) {
    $data['subtitle'] = (string) post_meta((int) $post['id'], 'subtitle', '');
    return $data;
});

/* ---------- Hàm tiện ích của theme ---------- */

/** Menu chính: Trang chủ + các trang cấp gốc (trừ trang đang làm trang chủ) theo thứ tự trong admin. */
function lqd_nav_items(): array
{
    $items = [['label' => 'Trang chủ', 'url' => home_url('/'), 'current' => is_front_page()]];
    $front = (int) option('page_on_front', 0);
    foreach (get_pages(['parent' => 0, 'exclude' => $front ? [$front] : []]) as $p) {
        $current = (is_page() || is_home()) && (get_queried_object_id() === (int) $p['id']
            || in_array((int) $p['id'], array_map('intval', array_column(get_post_ancestors(get_queried_object()), 'id')), true));
        $items[] = ['label' => $p['title'], 'url' => $p['url'], 'current' => $current];
    }
    return $items;
}

/** Ảnh đại diện hoặc ô màu có chữ cái đầu (cùng tỉ lệ) khi bài không có ảnh. */
function lqd_thumb(array|int|null $post = null, string $class = 'thumb'): string
{
    if (has_post_thumbnail($post)) {
        return get_the_post_thumbnail($post, ['class' => $class]);
    }
    $title = get_the_title($post);
    return '<span class="' . e($class) . ' thumb-empty" style="--c:' . identity_color($title) . '" aria-hidden="true">'
        . e(mb_strtoupper(mb_substr($title, 0, 1))) . '</span>';
}

/** Chip chuyên mục đầu tiên của bài. */
function lqd_primary_category(array|int|null $post = null): string
{
    $cat = get_the_category($post)[0] ?? null;
    return $cat ? '<a class="chip" href="' . e($cat['url']) . '" style="--c:' . identity_color((int) $cat['id']) . '">' . e($cat['name']) . '</a>' : '';
}

/** "3 ngày trước" cho bài mới, quá 7 ngày thì hiện ngày theo định dạng trong Cài đặt. */
function lqd_time_ago(array|int|null $post = null): string
{
    $p = get_post($post);
    $ts = strtotime(($p['published_at'] ?? '') . ' UTC');
    if (!$ts) {
        return '';
    }
    $d = time() - $ts;
    return match (true) {
        $d < 3600 => max(1, intdiv($d, 60)) . ' phút trước',
        $d < 86400 => intdiv($d, 3600) . ' giờ trước',
        $d < 7 * 86400 => intdiv($d, 86400) . ' ngày trước',
        default => get_the_date('', $p),
    };
}

/** Số liệu nhỏ cho ô thống kê ở trang chủ. */
function lqd_site_stats(): array
{
    return [
        'posts' => lq_query(['per_page' => 1])->found,
        'categories' => count(get_terms('category')),
        'authors' => (int) db()->value("SELECT COUNT(DISTINCT author_id) FROM {posts} WHERE type = 'post' AND status = 'published'"),
        'pages' => count(get_pages()),
    ];
}

/** Số bài đăng theo từng tháng trong 12 tháng gần nhất (biểu đồ cột nhỏ ở trang chủ). */
function lqd_monthly_counts(int $months = 12): array
{
    $tz = site_timezone();
    $now = new DateTimeImmutable('first day of this month 00:00', $tz);
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = $now->modify("-$i month");
        $out[$m->format('Y-m')] = ['label' => $m->format('m'), 'year' => (int) $m->format('Y'), 'month' => (int) $m->format('m'), 'count' => 0];
    }
    $first = array_key_first($out);
    [$from] = \LQ\Front\Query::dateRange((int) substr($first, 0, 4), (int) substr($first, 5, 2));
    foreach (db()->all("SELECT published_at FROM {posts} WHERE type = 'post' AND status = 'published' AND published_at >= ? AND published_at <= ?", [$from, now_utc()]) as $r) {
        $k = format_date($r['published_at'], 'Y-m');
        if (isset($out[$k])) {
            $out[$k]['count']++;
        }
    }
    return array_values($out);
}
