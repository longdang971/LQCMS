<?php
declare(strict_types=1);

/**
 * Hàm dùng trong theme (template tags), đặt tên theo WordPress cho quen tay.
 * Quy ước: get_*() trả về giá trị THÔ (chưa escape); the_*() in ra đã escape —
 * riêng nội dung bài (the_content) là HTML đã được làm sạch lúc lưu nên in nguyên.
 * Tham số $post ở các hàm: null = bài hiện tại trong vòng lặp, số = ID, mảng = bản ghi bài.
 */

use LQ\Content\Media;
use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Front\Front;
use LQ\Front\Query;
use LQ\Front\Resolver;

/* ---------- Vòng lặp ---------- */

function have_posts(): bool
{
    return Front::main()->have_posts();
}

function the_post(): void
{
    Front::main()->the_post();
}

function rewind_posts(): void
{
    Front::main()->rewind_posts();
}

/** Truy vấn riêng (danh sách bài mới, bài liên quan…). Xem tham số ở LQ\Front\Query. */
function lq_query(array $args = []): Query
{
    return new Query($args);
}

/** Như lq_query() nhưng trả về mảng bài, không cần vòng lặp. */
function get_posts(array $args = []): array
{
    return (new Query($args + ['no_count' => true]))->posts;
}

/** Sau khi lặp một lq_query() riêng: trả bài hiện tại về bài của trang đang xem. */
function lq_reset_postdata(): void
{
    Front::resetPost();
}

/** Đặt một bài làm "bài hiện tại" để dùng the_title(), the_permalink()… với nó. */
function setup_postdata(array|int $post): void
{
    Front::setPost(is_array($post) ? $post : get_post($post));
}

/** Truy vấn chính của trang đang xem (số bài, số trang…). */
function lq_main_query(): Query
{
    return Front::main();
}

/* ---------- Bài viết ---------- */

/**
 * Bản ghi bài: null = bài hiện tại; số = tìm theo ID (bài chưa đăng chỉ trả về cho người có quyền sửa).
 * Có thêm: author_name, author_username, terms[taxonomy], categories, tags.
 */
function get_post(array|int|null $post = null): ?array
{
    if ($post === null) {
        return Front::post();
    }
    if (is_array($post)) {
        return $post;
    }
    static $cache = [];
    if (!array_key_exists($post, $cache)) {
        $row = db()->row(
            'SELECT p.*, u.display_name AS author_name, u.username AS author_username
             FROM {posts} p LEFT JOIN {users} u ON u.id = p.author_id WHERE p.id = ?',
            [$post]
        );
        $ok = $row && $row['status'] !== 'trash'
            && (($row['status'] === 'published' && $row['published_at'] <= now_utc()) || Posts::canEdit($row));
        $list = $ok ? [$row] : [];
        Posts::attachTerms($list);
        $cache[$post] = $list[0] ?? null;
    }
    return $cache[$post];
}

function get_the_ID(): int
{
    return (int) (get_post()['id'] ?? 0);
}

function the_ID(): void
{
    echo get_the_ID();
}

function get_the_title(array|int|null $post = null): string
{
    $p = get_post($post);
    return $p ? (string) apply_filters('the_title', $p['title'] !== '' ? $p['title'] : '(Không có tiêu đề)', $p) : '';
}

function the_title(string $before = '', string $after = ''): void
{
    $t = get_the_title();
    if ($t !== '') {
        echo $before . e($t) . $after;
    }
}

function get_permalink(array|int|null $post = null): string
{
    $p = get_post($post);
    return $p ? (string) apply_filters('post_link', Posts::permalink($p), $p) : '';
}

function the_permalink(array|int|null $post = null): void
{
    echo e(get_permalink($post));
}

function get_the_content(array|int|null $post = null): string
{
    return (string) (get_post($post)['content'] ?? '');
}

function the_content(): void
{
    echo apply_filters('the_content', get_the_content(), get_post());
}

/**
 * Nội dung bài lưu không có <p> bọc đoạn (trình soạn thảo gỡ đi): dòng trống = đoạn mới, xuống dòng đơn = <br>.
 * Hàm này bọc lại khi hiển thị; khối HTML (h2, ul, blockquote, table, <p class=…>…) giữ nguyên. Phải khớp autoP() trong admin.js.
 * Nội dung cũ đã có <p> vẫn hiển thị đúng vì mỗi đoạn bắt đầu bằng thẻ khối.
 */
function lq_autop(string $text): string
{
    if (trim($text) === '') {
        return '';
    }
    $keep = [];
    $text = preg_replace_callback('#<(pre|script|style|textarea)\b.*?</\1>#is', function ($m) use (&$keep) {
        $keep[] = $m[0];
        return "\x01" . (count($keep) - 1) . "\x01"; // không dùng \0: trim() xoá mất
    }, str_replace(["\r\n", "\r"], "\n", $text));
    $block = '#^</?(p|h[1-6]|ul|ol|li|dl|blockquote|pre|table|thead|tbody|tfoot|tr|figure|figcaption|div|hr|section|article|aside|header|footer|nav|iframe|video|audio|details|summary|form|address)\b#i';
    $out = [];
    foreach (preg_split('#\n\s*\n#', $text) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        $out[] = preg_match($block, $chunk) || preg_match('#^\x01\d+\x01$#', $chunk)
            ? $chunk
            : '<p>' . str_replace("\n", "<br>\n", $chunk) . '</p>';
    }
    return preg_replace_callback('#\x01(\d+)\x01#', fn ($m) => $keep[(int) $m[1]], implode("\n", $out));
}
add_filter('the_content', 'lq_autop', 5);

/** Tóm tắt tự cắt từ nội dung (bỏ thẻ HTML), mặc định 40 từ — đổi qua filter excerpt_length. */
function get_the_excerpt(array|int|null $post = null, ?int $words = null): string
{
    $p = get_post($post);
    if (!$p) {
        return '';
    }
    $words ??= (int) apply_filters('excerpt_length', 40);
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $p['content']);
    // Thẻ khối thành khoảng trắng để chữ của hai đoạn liền nhau không dính vào nhau.
    $html = preg_replace('#</?(p|div|h[1-6]|li|ul|ol|blockquote|br|hr|tr|td|th|figure|figcaption|section|article|pre|table)\b[^>]*>#i', ' ', (string) $html);
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $list = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($list) > $words) {
        $text = implode(' ', array_slice($list, 0, $words)) . apply_filters('excerpt_more', '…');
    }
    return (string) apply_filters('get_the_excerpt', $text, $p);
}

function the_excerpt(?int $words = null): void
{
    echo e(get_the_excerpt(null, $words));
}

/** Ngày đăng theo định dạng trong Cài đặt (hoặc $format kiểu date() của PHP, vd. 'c' cho thẻ <time>). */
function get_the_date(string $format = '', array|int|null $post = null): string
{
    $p = get_post($post);
    return $p ? format_date($p['published_at'] ?? $p['created_at'], $format !== '' ? $format : (string) option('date_format', 'd/m/Y')) : '';
}

function the_date(string $format = ''): void
{
    echo e(get_the_date($format));
}

function get_the_time(string $format = '', array|int|null $post = null): string
{
    return get_the_date($format !== '' ? $format : (string) option('time_format', 'H:i'), $post);
}

function the_time(string $format = ''): void
{
    echo e(get_the_time($format));
}

function get_the_modified_date(string $format = '', array|int|null $post = null): string
{
    $p = get_post($post);
    return $p ? format_date($p['updated_at'], $format !== '' ? $format : (string) option('date_format', 'd/m/Y')) : '';
}

function the_modified_date(string $format = ''): void
{
    echo e(get_the_modified_date($format));
}

/** Số phút đọc ước tính (~220 từ/phút). */
function get_reading_time(array|int|null $post = null): int
{
    $text = strip_tags(get_the_content($post));
    return max(1, (int) ceil(count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []) / 220));
}

/** Giá trị field tuỳ biến (lq_add_post_field) của bài hiện tại. */
function get_field(string $key, mixed $default = null, array|int|null $post = null): mixed
{
    $p = get_post($post);
    return $p ? post_meta((int) $p['id'], $key, $default) : $default;
}

function the_field(string $key, array|int|null $post = null): void
{
    $v = get_field($key, '', $post);
    echo e(is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v);
}

/* ---------- Ảnh đại diện ---------- */

function has_post_thumbnail(array|int|null $post = null): bool
{
    return !empty(get_post($post)['featured_image']);
}

function get_the_post_thumbnail_url(array|int|null $post = null): string
{
    $url = (string) (get_post($post)['featured_image'] ?? '');
    return $url !== '' && $url[0] === '/' ? site_url($url) : $url;
}

/** Thẻ <img> của ảnh đại diện, có sẵn width/height, alt (lấy từ thư viện media), loading="lazy". */
function get_the_post_thumbnail(array|int|null $post = null, array $attrs = []): string
{
    $p = get_post($post);
    if (!$p || empty($p['featured_image'])) {
        return '';
    }
    $media = Media::findByPath((string) $p['featured_image']);
    $attrs = array_merge([
        'src' => get_the_post_thumbnail_url($p),
        'alt' => ($media['alt'] ?? '') !== '' ? $media['alt'] : get_the_title($p),
        'width' => $media['width'] ?? null,
        'height' => $media['height'] ?? null,
        'loading' => 'lazy',
        'decoding' => 'async',
    ], $attrs);
    return '<img' . html_attrs($attrs) . '>';
}

function the_post_thumbnail(array $attrs = []): void
{
    echo get_the_post_thumbnail(null, $attrs);
}

/** ['class' => 'a', 'hidden' => true, 'x' => null] -> ' class="a" hidden' */
function html_attrs(array $attrs): string
{
    $out = '';
    foreach ($attrs as $k => $v) {
        if ($v === null || $v === false) {
            continue;
        }
        $out .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    return $out;
}

/* ---------- Tác giả ---------- */

function get_the_author(array|int|null $post = null): string
{
    $p = get_post($post);
    return (string) ($p['author_name'] ?? '');
}

function the_author(): void
{
    echo e(get_the_author());
}

/** @param array|string $user bản ghi người dùng (có 'username') hoặc tên đăng nhập */
function author_url(array|string $user): string
{
    $name = is_array($user) ? (string) ($user['username'] ?? '') : $user;
    return site_url('/' . Resolver::AUTHOR_BASE . '/' . rawurlencode($name) . '/');
}

function get_author_posts_url(array|int|null $post = null): string
{
    $p = get_post($post);
    return $p && !empty($p['author_username']) ? author_url((string) $p['author_username']) : '';
}

function the_author_link(): void
{
    $url = get_author_posts_url();
    echo $url !== '' ? '<a href="' . e($url) . '" rel="author">' . e(get_the_author()) . '</a>' : e(get_the_author());
}

/* ---------- Chuyên mục, thẻ, taxonomy ---------- */

/** Term của bài theo taxonomy, mỗi term có thêm 'url'. */
function get_the_terms(string $taxonomy, array|int|null $post = null): array
{
    $p = get_post($post);
    if (!$p) {
        return [];
    }
    $terms = $p['terms'][$taxonomy] ?? (isset($p['terms']) ? [] : Terms::forPost((int) $p['id'], $taxonomy));
    return array_map(fn ($t) => $t + ['taxonomy' => $taxonomy, 'url' => Terms::url($t + ['taxonomy' => $taxonomy])], $terms);
}

/** In danh sách link term: the_terms('actor', ', ', 'Diễn viên: '). */
function the_terms(string $taxonomy, string $sep = ', ', string $before = '', string $after = ''): void
{
    $links = array_map(fn ($t) => '<a href="' . e($t['url']) . '" rel="tag">' . e($t['name']) . '</a>', get_the_terms($taxonomy));
    if ($links) {
        echo $before . implode($sep, $links) . $after;
    }
}

function get_the_category(array|int|null $post = null): array
{
    return get_the_terms('category', $post);
}

function the_category(string $sep = ', '): void
{
    the_terms('category', $sep);
}

function get_the_tags(array|int|null $post = null): array
{
    return get_the_terms('tag', $post);
}

function the_tags(string $before = '', string $sep = ', ', string $after = ''): void
{
    the_terms('tag', $sep, $before, $after);
}

function has_term(string|int $term, string $taxonomy, array|int|null $post = null): bool
{
    foreach (get_the_terms($taxonomy, $post) as $t) {
        // Chỉ so id khi $term là số: slug "5-meo-hay" ép (int) thành 5 sẽ khớp nhầm term id 5.
        if ((string) $t['slug'] === (string) $term || ((is_int($term) || ctype_digit($term)) && (int) $t['id'] === (int) $term)) {
            return true;
        }
    }
    return false;
}

/** Một term theo id hoặc slug. */
function get_term(int|string $term, string $taxonomy = 'category'): ?array
{
    $row = is_int($term)
        ? db()->row('SELECT * FROM {terms} WHERE id = ?', [$term])
        : db()->row('SELECT * FROM {terms} WHERE taxonomy = ? AND slug = ?', [$taxonomy, $term]);
    return $row ? $row + ['url' => Terms::url($row)] : null;
}

function get_term_link(array|int $term): string
{
    $t = is_array($term) ? $term : get_term($term);
    return $t ? Terms::url($t) : '';
}

/**
 * Danh sách term của một taxonomy, kèm 'count' (số bài đã đăng, chưa tính chuyên mục con) và 'url'.
 * $args: hide_empty (true), parent (null = mọi cấp, 0 = cấp gốc), orderby ('name' | 'count'), number (0 = tất cả), exclude [id].
 */
function get_terms(string $taxonomy, array $args = []): array
{
    $args += ['hide_empty' => true, 'parent' => null, 'orderby' => 'name', 'number' => 0, 'exclude' => []];
    $where = ['t.taxonomy = ?'];
    $params = [now_utc(), $taxonomy];
    if ($args['parent'] !== null) {
        $where[] = (int) $args['parent'] === 0 ? 't.parent_id IS NULL' : 't.parent_id = ' . (int) $args['parent'];
    }
    if ($ex = array_map('intval', (array) $args['exclude'])) {
        $where[] = 't.id NOT IN (' . implode(',', $ex) . ')';
    }
    $order = $args['orderby'] === 'count' ? 'post_count DESC, t.name' : 't.name';
    $limit = (int) $args['number'] > 0 ? 'LIMIT ' . (int) $args['number'] : '';
    $rows = db()->all(
        "SELECT t.*, COUNT(p.id) AS post_count FROM {terms} t
         LEFT JOIN {post_terms} pt ON pt.term_id = t.id
         LEFT JOIN {posts} p ON p.id = pt.post_id AND p.status = 'published' AND p.published_at <= ?
         WHERE " . implode(' AND ', $where) . ' GROUP BY t.id ' . ($args['hide_empty'] ? 'HAVING post_count > 0 ' : '') . "ORDER BY $order $limit",
        $params
    );
    return array_map(fn ($t) => $t + ['count' => (int) $t['post_count'], 'url' => Terms::url($t)], $rows);
}

/* ---------- Trang tĩnh ---------- */

/**
 * Trang đã đăng, xếp theo thứ tự rồi tên, mỗi trang có 'url'.
 * $args: parent (null = mọi cấp, 0 = cấp gốc, id), exclude [id], include [id].
 */
function get_pages(array $args = []): array
{
    $q = new Query(['type' => 'page', 'per_page' => -1, 'orderby' => 'menu_order'] + array_intersect_key($args, array_flip(['parent', 'exclude', 'include'])));
    return array_map(fn ($p) => $p + ['url' => Posts::permalink($p)], $q->posts);
}

/** Trang cha, ông… của một trang, từ gốc xuống (dùng cho breadcrumb). */
function get_post_ancestors(array|int|null $post = null): array
{
    $p = get_post($post);
    $out = [];
    $seen = [];
    $parent = (int) ($p['parent_id'] ?? 0);
    while ($parent && !isset($seen[$parent]) && ($row = get_post($parent))) {
        $seen[$parent] = true;
        array_unshift($out, $row);
        $parent = (int) $row['parent_id'];
    }
    return $out;
}

/** Key mẫu trang đang dùng ('' = mặc định). */
function get_page_template_slug(array|int|null $post = null): string
{
    return (string) (get_post($post)['template'] ?? '');
}

/* ---------- Bài trước / sau ---------- */

/** Bài cũ hơn ($previous = true) hoặc mới hơn liền kề bài hiện tại, cùng chuyên mục nếu $sameCategory. */
function get_adjacent_post(bool $previous = true, bool $sameCategory = false, array|int|null $post = null): ?array
{
    $p = get_post($post);
    if (!$p || $p['type'] !== 'post' || empty($p['published_at'])) {
        return null;
    }
    $op = $previous ? '<' : '>';
    $dir = $previous ? 'DESC' : 'ASC';
    $where = "p.type = 'post' AND p.status = 'published' AND p.published_at <= ? AND (p.published_at $op ? OR (p.published_at = ? AND p.id $op ?))";
    $params = [now_utc(), $p['published_at'], $p['published_at'], $p['id']];
    if ($sameCategory && ($cats = array_column(get_the_category($p), 'id'))) {
        $where .= ' AND EXISTS (SELECT 1 FROM {post_terms} x WHERE x.post_id = p.id AND x.term_id IN (' . implode(',', array_map('intval', $cats)) . '))';
    }
    $id = db()->value("SELECT p.id FROM {posts} p WHERE $where ORDER BY p.published_at $dir, p.id $dir LIMIT 1", $params);
    return $id ? get_post((int) $id) : null;
}

function get_previous_post(bool $sameCategory = false): ?array
{
    return get_adjacent_post(true, $sameCategory);
}

function get_next_post(bool $sameCategory = false): ?array
{
    return get_adjacent_post(false, $sameCategory);
}

/**
 * Bài liên quan, chấm điểm theo term dùng chung với bài đang xem: mỗi term trùng cộng trọng số của taxonomy đó
 * (mặc định thẻ 3, taxonomy tuỳ biến 2, chuyên mục 1), xếp theo điểm rồi mới nhất trước.
 * Thiếu bài thì bù bằng bài mới nhất cùng chuyên mục (kể cả chuyên mục con). Bài không có term nào → [].
 * $args: weights ['tag' => 3, 'actor' => 5, 'category' => 0 (bỏ qua)…] ghi đè từng taxonomy,
 *        fallback (true) — false để không bù, exclude [id] — bỏ thêm các bài này.
 */
function get_related_posts(array|int|null $post = null, int $limit = 3, array $args = []): array
{
    $p = get_post($post);
    if (!$p || $limit < 1) {
        return [];
    }
    $weights = [];
    foreach (Taxonomies::all() as $key => $t) {
        $weights[$key] = match ($key) { 'tag' => 3, 'category' => 1, default => 2 };
    }
    $weights = array_merge($weights, array_map('intval', (array) ($args['weights'] ?? [])));

    // Trọng số theo từng term của bài: CASE pt.term_id WHEN … THEN …
    $termWeight = [];
    foreach ($weights as $tax => $w) {
        if ($w > 0) {
            foreach (get_the_terms((string) $tax, $p) as $t) {
                $termWeight[(int) $t['id']] = $w;
            }
        }
    }
    $exclude = array_values(array_unique([(int) $p['id'], ...array_map('intval', (array) ($args['exclude'] ?? []))]));
    $ids = [];
    if ($termWeight) {
        $params = [...array_keys($termWeight), $p['type'], now_utc(), ...$exclude];
        foreach ($termWeight as $id => $w) {
            array_push($params, $id, $w); // cho CASE trong ORDER BY — đứng sau WHERE nên tham số cũng ở sau
        }
        $case = 'CASE pt.term_id' . str_repeat(' WHEN ? THEN ?', count($termWeight)) . ' END';
        $ids = array_map('intval', db()->column(
            "SELECT p.id FROM {post_terms} pt JOIN {posts} p ON p.id = pt.post_id
             WHERE pt.term_id IN (" . \LQ\Core\Database::placeholders(array_keys($termWeight)) . ")
               AND p.type = ? AND p.status = 'published' AND p.published_at <= ?
               AND p.id NOT IN (" . \LQ\Core\Database::placeholders($exclude) . ")
             GROUP BY p.id ORDER BY SUM($case) DESC, MAX(p.published_at) DESC, p.id DESC LIMIT " . $limit,
            $params
        ));
    }
    $cats = array_column(get_the_category($p), 'id');
    if (count($ids) < $limit && ($args['fallback'] ?? true) && $cats) {
        $more = new Query([
            'type' => $p['type'],
            'category' => $cats,
            'exclude' => [...$exclude, ...$ids],
            'per_page' => $limit - count($ids),
            'no_count' => true,
        ]);
        $ids = [...$ids, ...array_map(fn ($r) => (int) $r['id'], $more->posts)];
    }
    $posts = array_values(array_filter(array_map('get_post', $ids)));
    return (array) apply_filters('related_posts', $posts, $p, $limit, $args);
}

/* ---------- Câu hỏi điều kiện ---------- */

/** So khớp đối tượng đang xem với id / slug / mảng các giá trị đó. */
function lq_matches_queried(mixed $want, array $keys = ['id', 'slug']): bool
{
    if ($want === null || $want === '' || $want === []) {
        return true;
    }
    $o = Front::main()->queried ?? [];
    foreach ((array) $want as $w) {
        foreach ($keys as $k) {
            if (isset($o[$k]) && (string) $o[$k] === (string) $w) {
                return true;
            }
        }
    }
    return false;
}

/** Trang danh sách bài mới nhất (trang chủ mặc định, hoặc trang được chọn làm "trang bài viết"). */
function is_home(): bool
{
    return Front::is('home');
}

/** Đúng địa chỉ gốc của website (/), dù đang hiện bài mới nhất hay một trang tĩnh. */
function is_front_page(): bool
{
    return Front::is('front_page');
}

function is_single(mixed $post = null): bool
{
    return Front::is('single') && lq_matches_queried($post);
}

function is_page(mixed $page = null): bool
{
    return Front::is('page') && lq_matches_queried($page);
}

/** Bài hoặc trang đơn; $type = 'post' | 'page' để hỏi cụ thể. */
function is_singular(?string $type = null): bool
{
    return Front::is('singular') && ($type === null || (Front::main()->queried['type'] ?? '') === $type);
}

function is_archive(): bool
{
    return Front::is('archive');
}

function is_category(mixed $category = null): bool
{
    return Front::is('category') && lq_matches_queried($category);
}

function is_tag(mixed $tag = null): bool
{
    return Front::is('tag') && lq_matches_queried($tag);
}

/** Lưu trữ taxonomy tuỳ biến: is_tax('actor'), is_tax('actor', 'ten-dien-vien'). */
function is_tax(?string $taxonomy = null, mixed $term = null): bool
{
    return Front::is('tax')
        && ($taxonomy === null || (Front::main()->queried['taxonomy'] ?? '') === $taxonomy)
        && lq_matches_queried($term);
}

function is_author(mixed $author = null): bool
{
    return Front::is('author') && lq_matches_queried($author, ['id', 'username']);
}

function is_date(): bool
{
    return Front::is('date');
}

function is_year(): bool
{
    return Front::is('year');
}

function is_month(): bool
{
    return Front::is('month');
}

function is_day(): bool
{
    return Front::is('day');
}

function is_search(): bool
{
    return Front::is('search');
}

function is_404(): bool
{
    return Front::is('404');
}

/** Đang ở trang 2 trở đi của một danh sách. */
function is_paged(): bool
{
    return Front::is('paged');
}

/** Người có quyền sửa đang xem bài chưa đăng. */
function is_preview(): bool
{
    return Front::is('preview');
}

function is_page_template(?string $template = null): bool
{
    if (!is_page()) {
        return false;
    }
    $current = get_page_template_slug(Front::main()->queried);
    return $template === null ? $current !== '' : $current === $template;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/* ---------- Đối tượng đang xem & tiêu đề ---------- */

/** Bài/trang, term (có 'taxonomy'), tác giả (có 'username') hoặc ['year','month','day'] — tuỳ trang. */
function get_queried_object(): ?array
{
    return Front::main()->queried;
}

function get_queried_object_id(): int
{
    return (int) (Front::main()->queried['id'] ?? 0);
}

/** Tiêu đề trang lưu trữ: "Chuyên mục: Tin tức", "Tác giả: An", "Tháng 10/2026"… */
function get_the_archive_title(): string
{
    $o = get_queried_object() ?? [];
    $title = match (true) {
        is_category(), is_tag(), is_tax() => lq_ucfirst((string) (Taxonomies::get((string) $o['taxonomy'])['singular'] ?? '')) . ': ' . $o['name'],
        is_author() => 'Tác giả: ' . $o['display_name'],
        is_day() => sprintf('Ngày %02d/%02d/%04d', $o['day'], $o['month'], $o['year']),
        is_month() => sprintf('Tháng %d/%04d', $o['month'], $o['year']),
        is_year() => 'Năm ' . $o['year'],
        is_search() => 'Kết quả tìm kiếm: “' . get_search_query() . '”',
        default => 'Lưu trữ',
    };
    return (string) apply_filters('archive_title', $title, $o);
}

function lq_ucfirst(string $s): string
{
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

function the_archive_title(string $before = '', string $after = ''): void
{
    echo $before . e(get_the_archive_title()) . $after;
}

/** Mô tả của term đang xem (nhập ở trang quản lý chuyên mục/thẻ). */
function get_the_archive_description(): string
{
    return (is_category() || is_tag() || is_tax()) ? trim((string) (get_queried_object()['description'] ?? '')) : '';
}

function the_archive_description(string $before = '', string $after = ''): void
{
    $d = get_the_archive_description();
    if ($d !== '') {
        echo $before . nl2br(e($d)) . $after;
    }
}

/** Nội dung thẻ <title>: "Tiêu đề bài – Tên website", "Chuyên mục: X – Trang 2 – Tên website"… */
function get_document_title(): string
{
    $pre = apply_filters('pre_get_document_title', '');
    if (is_string($pre) && $pre !== '') {
        return $pre;
    }
    $site = (string) option('site_title', 'LQCMS');
    $parts = [];
    if (is_404()) {
        $parts['title'] = 'Không tìm thấy trang';
    } elseif (is_search()) {
        $parts['title'] = 'Tìm kiếm “' . get_search_query() . '”';
    } elseif (is_front_page() && is_home()) {
        $parts['title'] = $site;
        if (($tagline = (string) option('tagline', '')) !== '') {
            $parts['tagline'] = $tagline;
        }
    } elseif (is_singular() || is_home()) {
        $parts['title'] = get_the_title(get_queried_object());
    } elseif (is_archive()) {
        $parts['title'] = is_category() || is_tag() || is_tax() ? (string) get_queried_object()['name'] : get_the_archive_title();
    }
    if (is_paged()) {
        $parts['page'] = 'Trang ' . lq_main_query()->page;
    }
    if (!(is_front_page() && is_home())) {
        $parts['site'] = $site;
    }
    $parts = (array) apply_filters('document_title_parts', $parts);
    return implode(' ' . apply_filters('document_title_separator', '–') . ' ', array_filter($parts, fn ($v) => $v !== ''));
}

/* ---------- Website & theme ---------- */

function home_url(string $path = ''): string
{
    return site_url($path);
}

/** name, description, url, charset, language, version, admin_email */
function get_bloginfo(string $show = 'name'): string
{
    return (string) match ($show) {
        'name' => option('site_title', 'LQCMS'),
        'description' => option('tagline', ''),
        'url', 'home' => site_url('/'),
        'charset' => 'UTF-8',
        'language' => apply_filters('site_language', 'vi'),
        'version' => LQ_VERSION,
        'admin_email' => option('admin_email', ''),
        default => '',
    };
}

function bloginfo(string $show = 'name'): void
{
    echo e(get_bloginfo($show));
}

/** URL tới tệp trong theme đang dùng, tự gắn ?v=<thời điểm sửa> để trình duyệt nạp bản mới. */
function theme_url(string $path = ''): string
{
    $theme = \LQ\Core\Themes::active();
    $path = ltrim($path, '/');
    $url = "/content/themes/$theme" . ($path !== '' ? "/$path" : '');
    $file = Front::themeDir() . '/' . $path;
    return $path !== '' && is_file($file) ? $url . '?v=' . filemtime($file) : $url;
}

/** Tệp template đang hiển thị trang này, vd. "single.php" — tiện để gỡ lỗi thứ bậc template. */
function get_current_template(): string
{
    return Front::template();
}

/** Nạp tệp con của theme: get_template_part('parts/card', 'compact', ['x' => 1]) thử parts/card-compact.php rồi parts/card.php. */
function get_template_part(string $slug, ?string $name = null, array $args = []): bool
{
    $names = $name !== null && $name !== '' ? ["$slug-$name.php", "$slug.php"] : ["$slug.php"];
    foreach ($names as $n) {
        if (!str_contains($n, '..') && is_file(Front::themeDir() . '/' . $n)) {
            Front::load(Front::themeDir() . '/' . $n, $args);
            return true;
        }
    }
    return false;
}

function get_header(?string $name = null, array $args = []): void
{
    get_template_part('header', $name, $args);
}

function get_footer(?string $name = null, array $args = []): void
{
    get_template_part('footer', $name, $args);
}

function get_sidebar(?string $name = null, array $args = []): void
{
    get_template_part('sidebar', $name, $args);
}

/** URL gọi AJAX: lq_ajax_url('posts') -> /ajax/posts. Trong JS dùng sẵn LQ.get('posts', {...}) / LQ.post(...). */
function lq_ajax_url(string $action = ''): string
{
    return site_url(\LQ\Front\Ajax::url($action));
}

/**
 * Tham số của truy vấn chính (chuyên mục, thẻ, tác giả, từ khoá, ngày…) để gửi lại cho action 'posts' —
 * nút "Tải thêm" lấy đúng các bài tiếp theo của trang đang xem. Thêm 'page' => số trang cần lấy.
 */
function lq_ajax_query_args(?Query $q = null): array
{
    return \LQ\Front\Ajax::mainQueryArgs($q ?? lq_main_query());
}

/**
 * Mô tả ngắn của trang đang xem cho thẻ meta description / og:description:
 * field seo_description → tóm tắt bài → mô tả chuyên mục/thẻ → khẩu hiệu website. Tối đa $length ký tự.
 */
function lq_meta_description(int $length = 300): string
{
    $o = get_queried_object();
    $seo = is_singular() ? trim((string) post_meta((int) $o['id'], 'seo_description', '')) : '';
    $desc = match (true) {
        $seo !== '' => $seo,
        is_singular() => get_the_excerpt($o, 30),
        is_category(), is_tag(), is_tax() => get_the_archive_description(),
        default => (string) option('tagline', ''),
    };
    return (string) apply_filters('meta_description', mb_substr(trim($desc), 0, $length));
}

/**
 * Đặt trong <head> (thường ngay trước </head>): chỗ để lõi, plugin và theme chèn script, CSS hay bất cứ thẻ nào.
 * Lõi in window.LQ cho AJAX (tắt bằng add_filter('lq_ajax_script', fn () => false)), sau đó chạy hook lq_script.
 * Các thẻ <title>, meta, canonical, Open Graph… theme tự viết.
 */
function lq_script(): void
{
    if (apply_filters('lq_ajax_script', true)) {
        echo \LQ\Front\Ajax::script();
    }
    do_action('lq_script');
}

/** Thanh quản trị có hiện ở trang này không (đã đăng nhập, chưa tắt trong Hồ sơ). Chiều cao: CSS var(--lq-admin-bar). */
function is_admin_bar_showing(): bool
{
    return \LQ\Front\AdminBar::showing();
}

/** Ngay sau <body>: cho plugin chèn mã (Google Tag Manager…). */
function lq_body_open(): void
{
    do_action('lq_body_open');
}

/** Trước </body>: cho plugin chèn script. */
function lq_footer(): void
{
    do_action('lq_footer');
}

/** URL chuẩn của trang đang xem (rỗng với 404 và tìm kiếm). */
function lq_canonical_url(): string
{
    $o = get_queried_object();
    $base = match (true) {
        is_404(), is_search() => '',
        is_front_page() => site_url('/'),
        is_singular(), is_home() && $o !== null => get_permalink($o),
        is_category(), is_tag(), is_tax(), is_author() => (string) ($o['url'] ?? ''),
        is_date() => site_url('/' . implode('/', array_filter([sprintf('%04d', $o['year']), $o['month'] ? sprintf('%02d', $o['month']) : '', $o['day'] ? sprintf('%02d', $o['day']) : ''])) . '/'),
        default => '',
    };
    if ($base !== '' && is_paged()) {
        $base = rtrim($base, '/') . '/' . Resolver::PAGE_BASE . '/' . lq_main_query()->page . '/';
    }
    return (string) apply_filters('canonical_url', $base);
}

/** Class cho <body>: home, single, postid-12, page-template-contact, category-tin-tuc, paged-2, logged-in… */
function get_body_class(array|string $extra = []): array
{
    $o = get_queried_object() ?? [];
    $c = [];
    if (is_front_page()) {
        $c[] = 'home';
    }
    if (is_home()) {
        $c[] = 'blog';
    }
    if (is_single()) {
        array_push($c, 'single', 'postid-' . $o['id']);
    }
    if (is_page()) {
        array_push($c, 'page', 'page-id-' . $o['id']);
        $c[] = ($t = get_page_template_slug($o)) !== '' ? 'page-template-' . $t : 'page-template-default';
    }
    if (is_archive()) {
        $c[] = 'archive';
    }
    if (is_category()) {
        array_push($c, 'category', 'category-' . $o['slug']);
    }
    if (is_tag()) {
        array_push($c, 'tag', 'tag-' . $o['slug']);
    }
    if (is_tax()) {
        array_push($c, 'tax-' . $o['taxonomy'], 'term-' . $o['slug']);
    }
    if (is_author()) {
        array_push($c, 'author', 'author-' . $o['username']);
    }
    if (is_date()) {
        $c[] = 'date';
    }
    if (is_search()) {
        $c[] = 'search';
    }
    if (is_404()) {
        $c[] = 'error404';
    }
    if (is_paged()) {
        array_push($c, 'paged', 'paged-' . lq_main_query()->page);
    }
    if (is_preview()) {
        $c[] = 'preview';
    }
    if (is_logged_in()) {
        $c[] = 'logged-in';
    }
    array_push($c, ...(is_array($extra) ? $extra : preg_split('/\s+/', $extra, -1, PREG_SPLIT_NO_EMPTY)));
    return array_values(array_unique((array) apply_filters('body_class', $c)));
}

function body_class(array|string $extra = []): void
{
    echo ' class="' . e(implode(' ', get_body_class($extra))) . '"';
}

/** Class cho khối bài: post-12 type-post has-thumbnail category-tin-tuc tag-php… */
function post_class(array|string $extra = [], array|int|null $post = null): void
{
    $p = get_post($post);
    $c = $p ? ['post-' . $p['id'], 'type-' . $p['type'], 'status-' . $p['status']] : [];
    if ($p && has_post_thumbnail($p)) {
        $c[] = 'has-thumbnail';
    }
    foreach ($p['terms'] ?? [] as $tax => $terms) {
        foreach ($terms as $t) {
            $c[] = $tax . '-' . $t['slug'];
        }
    }
    array_push($c, ...(is_array($extra) ? $extra : preg_split('/\s+/', $extra, -1, PREG_SPLIT_NO_EMPTY)));
    echo ' class="' . e(implode(' ', array_unique((array) apply_filters('post_class', $c, $p)))) . '"';
}

/** Link "Sửa" tới trang quản trị, chỉ hiện với người có quyền sửa bài đó. */
function edit_post_link(string $text = 'Sửa', string $before = '', string $after = '', array|int|null $post = null): void
{
    $p = get_post($post);
    if ($p && Posts::canEdit($p)) {
        $route = Posts::TYPES[$p['type']]['route'] ?? 'posts';
        echo $before . '<a class="edit-link" href="' . e(admin_url("$route/{$p['id']}/edit")) . '">' . e($text) . '</a>' . $after;
    }
}

/* ---------- Tìm kiếm ---------- */

function get_search_query(): string
{
    return isset($_GET['s']) && is_string($_GET['s']) ? trim($_GET['s']) : '';
}

function the_search_query(): void
{
    echo e(get_search_query());
}

/** Form tìm kiếm: dùng searchform.php của theme nếu có. */
function get_search_form(): void
{
    if (!get_template_part('searchform')) {
        echo '<form role="search" method="get" class="search-form" action="' . e(home_url('/')) . '">'
            . '<input type="search" name="s" value="' . e(get_search_query()) . '" placeholder="Tìm kiếm…" aria-label="Tìm kiếm">'
            . '<button type="submit">Tìm</button></form>';
    }
}

/* ---------- Phân trang ---------- */

/** URL của trang số $n trong danh sách đang xem (giữ nguyên ?s=… nếu có). */
function get_pagenum_link(int $n): string
{
    $path = request_path();
    $path = (string) preg_replace('#/' . Resolver::PAGE_BASE . '/\d+$#', '', $path);
    $path = rtrim($path, '/');
    $url = $path . ($n > 1 ? '/' . Resolver::PAGE_BASE . '/' . $n . '/' : '/');
    $qs = http_build_query(array_intersect_key($_GET, ['s' => 1]));
    return site_url($url) . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Dữ liệu phân trang để tự vẽ: mỗi mục ['type' => prev|next|page|dots, 'url', 'number', 'current'].
 * $args: mid_size (số trang hai bên trang hiện tại, mặc định 2), end_size (1).
 */
function paginate_links(array $args = []): array
{
    $q = $args['query'] ?? lq_main_query();
    $total = $q->maxPages;
    $cur = $q->page;
    if ($total <= 1) {
        return [];
    }
    $mid = (int) ($args['mid_size'] ?? 2);
    $end = (int) ($args['end_size'] ?? 1);
    $items = [];
    if ($cur > 1) {
        $items[] = ['type' => 'prev', 'url' => get_pagenum_link($cur - 1), 'number' => $cur - 1, 'current' => false];
    }
    $dots = false;
    for ($n = 1; $n <= $total; $n++) {
        if ($n <= $end || $n > $total - $end || abs($n - $cur) <= $mid) {
            $items[] = ['type' => 'page', 'url' => get_pagenum_link($n), 'number' => $n, 'current' => $n === $cur];
            $dots = false;
        } elseif (!$dots) {
            $items[] = ['type' => 'dots', 'url' => '', 'number' => 0, 'current' => false];
            $dots = true;
        }
    }
    if ($cur < $total) {
        $items[] = ['type' => 'next', 'url' => get_pagenum_link($cur + 1), 'number' => $cur + 1, 'current' => false];
    }
    return $items;
}

/** In khối phân trang có sẵn: <nav class="pagination">… */
function the_posts_pagination(array $args = []): void
{
    $items = paginate_links($args);
    if (!$items) {
        return;
    }
    $prev = $args['prev_text'] ?? '← Mới hơn';
    $next = $args['next_text'] ?? 'Cũ hơn →';
    echo '<nav class="pagination" aria-label="Phân trang">';
    foreach ($items as $i) {
        echo match ($i['type']) {
            'prev' => '<a class="page-prev" href="' . e($i['url']) . '" rel="prev">' . e($prev) . '</a>',
            'next' => '<a class="page-next" href="' . e($i['url']) . '" rel="next">' . e($next) . '</a>',
            'dots' => '<span class="page-dots">…</span>',
            default => $i['current']
                ? '<span class="page-num current" aria-current="page">' . $i['number'] . '</span>'
                : '<a class="page-num" href="' . e($i['url']) . '">' . $i['number'] . '</a>',
        };
    }
    echo '</nav>';
}
