<?php
declare(strict_types=1);

namespace LQ\Front;

use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Core\Csrf;

/**
 * AJAX cho theme/plugin: mỗi action là một URL /ajax/{action}.
 *
 *   lq_add_ajax('vote', function (array $in) {
 *       return ['count' => 12];                // mảng -> JSON {ok: true, count: 12}
 *   }, ['public' => true]);
 *
 *   // JS (lq_script() in sẵn LQ.get / LQ.post):
 *   LQ.post('vote', {id: 5}).then(r => r.count);
 *
 * Tuỳ chọn khi đăng ký:
 *   public  true = khách chưa đăng nhập gọi được          (mặc định false: phải đăng nhập)
 *   method  'POST' | 'GET' | 'ANY'                         (mặc định 'POST')
 *   cap     quyền cần có, vd. 'edit_posts'
 *   csrf    kiểm tra token với request không phải GET    (mặc định true)
 *   rate    số lượt tối đa mỗi phút cho một IP            (mặc định 20 với action public không phải GET)
 *
 * Callback nhận dữ liệu gửi lên (query string + form + JSON body) và trả về:
 *   mảng -> JSON {ok: true, ...}; mảng danh sách -> {ok: true, data: [...]}
 *   chuỗi -> HTML (text/html), tiện chèn thẳng vào trang
 *   null -> {ok: true}
 * Báo lỗi cho người dùng: lq_ajax_error('Thông báo', 422) -> {ok: false, message}.
 *
 * Lõi có sẵn 2 action công khai, chỉ đọc (GET): 'posts' (danh sách bài, HTML hoặc JSON) và 'search' (gợi ý tìm kiếm).
 */
final class Ajax
{
    public const BASE = 'ajax';
    /** Số bài tối đa một lần gọi 'posts'. */
    public const MAX_PER_PAGE = 50;

    /** @var array<string, array> */
    private static array $actions = [];

    public static function register(string $action, callable $callback, array $opts = []): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $action)) {
            throw new \InvalidArgumentException("Tên action AJAX không hợp lệ: \"$action\" (chỉ chữ thường, số, _ . -).");
        }
        $method = strtoupper((string) ($opts['method'] ?? 'POST'));
        $public = (bool) ($opts['public'] ?? false);
        self::$actions[$action] = [
            'callback' => $callback,
            'public' => $public,
            'method' => in_array($method, ['GET', 'POST', 'ANY'], true) ? $method : 'POST',
            'cap' => $opts['cap'] ?? null,
            'csrf' => (bool) ($opts['csrf'] ?? true),
            'rate' => array_key_exists('rate', $opts) ? $opts['rate'] : ($public && $method !== 'GET' ? 20 : null),
        ];
    }

    public static function url(string $action = ''): string
    {
        return '/' . self::BASE . '/' . $action;
    }

    /** Xử lý request /ajax/{action}. Luôn kết thúc request. */
    public static function handle(string $action): never
    {
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');
        $a = self::$actions[$action] ?? null;
        if (!$a) {
            // mb_scrub: byte UTF-8 hỏng trong URL làm json_encode trả false -> phản hồi rỗng.
            self::fail('Không có action "' . mb_scrub(mb_substr($action, 0, 64)) . '".', 404);
        }
        $method = request_method();
        if ($a['method'] !== 'ANY' && $method !== $a['method'] && !($a['method'] === 'GET' && $method === 'HEAD')) {
            header('Allow: ' . ($a['method'] === 'GET' ? 'GET, HEAD' : $a['method'])); // action GET nhận cả HEAD (dòng trên)
            self::fail('Action này chỉ nhận ' . $a['method'] . '.', 405);
        }
        $user = current_user(); // đọc (và có thể khôi phục từ cookie ghi nhớ) trước khi đóng session bên dưới
        if (!$a['public'] && !$user) {
            self::fail('Bạn cần đăng nhập.', 401);
        }
        if ($a['cap'] !== null && !can((string) $a['cap'])) {
            self::fail('Bạn không có quyền làm việc này.', 403);
        }
        if ($method !== 'GET' && $method !== 'HEAD' && $a['csrf'] && !Csrf::valid()) {
            self::fail('Phiên làm việc đã hết hạn, hãy tải lại trang.', 403);
        }
        if ($a['rate'] && !self::withinRate("ajax:$action:" . client_ip(), (int) $a['rate'])) {
            header('Retry-After: 60');
            self::fail('Bạn thao tác quá nhanh, thử lại sau ít phút.', 429);
        }
        // GET chỉ đọc: nhả khoá session để các request song song (gõ tìm kiếm, tải thêm) không phải xếp hàng.
        if ($method === 'GET' || $method === 'HEAD') {
            session_write_close();
        }

        try {
            $result = ($a['callback'])(self::input());
        } catch (AjaxError $e) {
            self::fail($e->getMessage(), $e->status, $e->extra);
        } catch (\Throwable $e) {
            error_log('[LQCMS ajax ' . $action . '] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::fail(can('manage_options') ? $e->getMessage() : 'Máy chủ gặp lỗi, thử lại sau.', 500);
        }

        if (is_string($result)) {
            header('Content-Type: text/html; charset=utf-8');
            echo $result;
            exit;
        }
        if (is_array($result)) {
            json_response(array_is_list($result) && $result !== [] ? ['ok' => true, 'data' => $result] : ['ok' => true] + $result);
        }
        json_response(['ok' => true]);
    }

    public static function fail(string $message, int $status = 400, array $extra = []): never
    {
        json_response(['ok' => false, 'message' => $message] + $extra, $status);
    }

    /** Query string + form, cộng JSON body nếu gửi bằng Content-Type: application/json. */
    private static function input(): array
    {
        $in = $_POST;
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($json)) {
                $in = $json + $in;
            }
        }
        return $in + $_GET;
    }

    /** Đếm lượt theo cửa sổ 1 phút; false khi đã vượt $limit. */
    private static function withinRate(string $bucket, int $limit): bool
    {
        $bucket = substr($bucket, 0, 191);
        $win = intdiv(time(), 60) * 60;
        db()->query('INSERT INTO {rate_limits} (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', [$bucket, $win]);
        return (int) db()->value('SELECT hits FROM {rate_limits} WHERE bucket = ? AND window_start = ?', [$bucket, $win]) <= $limit;
    }

    /* ---------- Script cho trình duyệt ---------- */

    /** window.LQ = {ajaxUrl, token, get(), post()} — lq_script() in ra; tắt bằng add_filter('lq_ajax_script', fn () => false). */
    public static function script(): string
    {
        $cfg = json_encode(['ajaxUrl' => self::url(), 'token' => Csrf::token()], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return '<script>' . <<<JS
window.LQ=Object.assign(window.LQ||{},$cfg);(L=>{
const add=(q,k,v)=>{if(v==null)return;if(Array.isArray(v))v.forEach(x=>add(q,k+'[]',x));else if(typeof v=='object')Object.keys(v).forEach(s=>add(q,k+'['+s+']',v[s]));else q.append(k,v===true?1:v===false?0:v)};
const req=(m,a,d,o={})=>{let u=L.ajaxUrl+a,b,h={'X-Requested-With':'XMLHttpRequest',Accept:'application/json, text/html'};
if(m=='GET'){const q=new URLSearchParams();Object.keys(d||{}).forEach(k=>add(q,k,d[k]));if(q.size)u+='?'+q}
else{h['X-CSRF-Token']=L.token;if(d instanceof FormData)b=d;else{h['Content-Type']='application/json';b=JSON.stringify(d||{})}}
return fetch(u,{method:m,headers:h,body:b,credentials:'same-origin',signal:o.signal}).then(r=>{const j=(r.headers.get('Content-Type')||'').includes('json');
return(j?r.json():r.text()).then(x=>{if(r.ok&&!(j&&x.ok===false))return x;const e=new Error(j&&x.message||'Lỗi '+r.status);e.status=r.status;e.data=x;throw e})})};
L.get=(a,d,o)=>req('GET',a,d,o);L.post=(a,d,o)=>req('POST',a,d,o)})(window.LQ);
JS . "</script>\n";
    }

    /* ---------- Action có sẵn ---------- */

    public static function registerCore(): void
    {
        self::register('posts', [self::class, 'posts'], ['public' => true, 'method' => 'GET', 'rate' => 120]);
        self::register('search', [self::class, 'search'], ['public' => true, 'method' => 'GET', 'rate' => 120]);
    }

    /**
     * Danh sách bài đã đăng — dùng cho "Tải thêm", lọc theo chuyên mục…
     * Tham số: type, page, per_page (≤ 50), category, tag, tax[key], author, search (hoặc s), year, month, day,
     *          parent, exclude, orderby (date|modified|title|menu_order), order, format (html|json), part.
     * format=html (mặc định): mỗi bài vẽ bằng template part của theme (mặc định parts/card), trả trong 'html'.
     * format=json: 'posts' là mảng dữ liệu công khai của bài (thêm field qua filter ajax_post_data).
     */
    public static function posts(array $in): array
    {
        $args = self::queryArgs($in);
        $q = new Query($args);
        $out = [
            'page' => $q->page,
            'per_page' => $q->perPage,
            'found' => $q->found,
            'max_pages' => $q->maxPages,
            'has_more' => $q->page < $q->maxPages,
        ];
        if (($in['format'] ?? 'html') === 'json') {
            return $out + ['posts' => array_map([self::class, 'postData'], $q->posts)];
        }
        $part = self::part(is_scalar($in['part'] ?? null) ? (string) $in['part'] : '');
        ob_start();
        while ($q->have_posts()) {
            $q->the_post();
            get_template_part($part);
        }
        Front::setPost(null);
        return $out + ['html' => (string) ob_get_clean()];
    }

    /** Gợi ý khi đang gõ ô tìm kiếm: vài bài/trang khớp + chuyên mục, thẻ khớp tên. Tham số: q (hoặc s), limit (≤ 10). */
    public static function search(array $in): array
    {
        // ?s[]=… là mảng: (string) ra "Array" kèm warning in lẫn vào JSON. Byte UTF-8 hỏng làm json_encode trả rỗng.
        $raw = is_scalar($in['q'] ?? null) ? $in['q'] : (is_scalar($in['s'] ?? null) ? $in['s'] : '');
        $term = trim(mb_scrub(mb_substr((string) $raw, 0, 200)));
        if (mb_strlen($term) < 2) {
            return ['query' => $term, 'found' => 0, 'posts' => [], 'terms' => [], 'more_url' => ''];
        }
        $limit = max(1, min(10, (int) ($in['limit'] ?? 6)));
        $q = new Query(['type' => 'any', 'search' => $term, 'per_page' => $limit]);
        $posts = array_map(fn ($p) => [
            'id' => (int) $p['id'],
            'type' => $p['type'],
            'title' => get_the_title($p),
            'url' => get_permalink($p),
            'date' => get_the_date('', $p),
            'thumbnail' => get_the_post_thumbnail_url($p) ?: null,
        ], $q->posts);

        $slug = \LQ\Core\Slug::make($term);
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $rows = db()->all(
            'SELECT * FROM {terms} WHERE taxonomy IN (' . \LQ\Core\Database::placeholders(array_keys(Taxonomies::all())) . ')
             AND (name LIKE ?' . ($slug !== '' ? ' OR slug LIKE ?' : '') . ') ORDER BY CHAR_LENGTH(name), name LIMIT 4',
            array_merge(array_keys(Taxonomies::all()), [$like], $slug !== '' ? ['%' . addcslashes($slug, '%_\\') . '%'] : [])
        );
        $terms = array_map(fn ($t) => [
            'id' => (int) $t['id'],
            'taxonomy' => $t['taxonomy'],
            'taxonomy_label' => (string) (Taxonomies::get($t['taxonomy'])['label'] ?? $t['taxonomy']),
            'name' => $t['name'],
            'url' => get_term_link($t),
        ], $rows);

        return [
            'query' => $term,
            'found' => $q->found,
            'posts' => $posts,
            'terms' => apply_filters('ajax_search_terms', $terms, $term),
            'more_url' => site_url('/') . '?s=' . rawurlencode($term),
        ];
    }

    /** Lọc tham số từ trình duyệt thành tham số Query an toàn: chỉ bài đã đăng, không có meta/include/status. */
    public static function queryArgs(array $in): array
    {
        $scalars = static function (mixed $v): array {
            $list = is_array($v) ? $v : explode(',', (string) $v);
            $out = [];
            foreach ($list as $x) {
                if (is_scalar($x) && ($x = trim((string) $x)) !== '') {
                    $out[] = ctype_digit($x) ? (int) $x : mb_substr($x, 0, 191);
                }
            }
            return array_slice($out, 0, 20);
        };
        $type = $in['type'] ?? 'post';
        $sitePerPage = (int) option('posts_per_page', 10);
        $args = [
            'type' => $type === 'any' ? 'any' : array_values(array_intersect($scalars($type), array_keys(Posts::TYPES))),
            'page' => max(1, (int) ($in['page'] ?? 1)),
            // Cài đặt cho tới 100 bài/trang: trần phải ≥ số đó, không thì "Tải thêm" (gửi per_page của trang) lấy lệch trang, trùng bài.
            'per_page' => max(1, min(max(self::MAX_PER_PAGE, $sitePerPage), (int) ($in['per_page'] ?? $sitePerPage))),
        ];
        $tax = [];
        foreach (is_array($in['tax'] ?? null) ? $in['tax'] : [] as $key => $terms) {
            if (Taxonomies::get((string) $key) && ($t = $scalars($terms))) {
                $tax[(string) $key] = $t;
            }
        }
        foreach (['category', 'tag'] as $k) {
            if (isset($in[$k]) && ($t = $scalars($in[$k]))) {
                $tax[$k] = $t;
            }
        }
        if ($tax) {
            $args['tax'] = $tax;
        }
        if (isset($in['author']) && is_scalar($in['author']) && (string) $in['author'] !== '') {
            $args['author'] = ctype_digit((string) $in['author']) ? (int) $in['author'] : mb_substr((string) $in['author'], 0, 64);
        }
        $search = $in['search'] ?? $in['s'] ?? '';
        if (is_scalar($search) && trim((string) $search) !== '') {
            $args['search'] = mb_substr(trim((string) $search), 0, 200);
        }
        foreach (['year', 'month', 'day'] as $k) {
            if (!empty($in[$k]) && is_scalar($in[$k])) {
                $args[$k] = (int) $in[$k];
            }
        }
        if (isset($in['parent']) && is_scalar($in['parent']) && $in['parent'] !== '') {
            $args['parent'] = (int) $in['parent'];
        }
        if (isset($in['exclude']) && ($ex = array_filter($scalars($in['exclude']), 'is_int'))) {
            $args['exclude'] = array_values($ex);
        }
        if (in_array($in['orderby'] ?? '', ['date', 'modified', 'title', 'menu_order'], true)) {
            $args['orderby'] = $in['orderby'];
        }
        if (isset($in['order']) && is_scalar($in['order'])) {
            $args['order'] = strtoupper((string) $in['order']) === 'ASC' ? 'ASC' : 'DESC';
        }
        return apply_filters('ajax_posts_args', $args, $in);
    }

    /** Dữ liệu công khai của một bài cho format=json. */
    public static function postData(array $p): array
    {
        $terms = static fn (string $tax) => array_map(
            fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'slug' => $t['slug'], 'url' => $t['url']],
            get_the_terms($tax, $p)
        );
        $data = [
            'id' => (int) $p['id'],
            'type' => $p['type'],
            'title' => get_the_title($p),
            'url' => get_permalink($p),
            'excerpt' => get_the_excerpt($p),
            'date' => get_the_date('c', $p),
            'date_display' => get_the_date('', $p),
            'modified' => get_the_modified_date('c', $p),
            'author' => ['name' => get_the_author($p), 'url' => get_author_posts_url($p)],
            'thumbnail' => get_the_post_thumbnail_url($p) ?: null,
            'reading_time' => get_reading_time($p),
            'categories' => $terms('category'),
            'tags' => $terms('tag'),
        ];
        return apply_filters('ajax_post_data', $data, $p);
    }

    /**
     * Template part dùng để vẽ bài cho format=html. Chỉ nhận part nằm trong danh sách cho phép
     * (filter ajax_template_parts, mặc định ['parts/card']) để khách không gọi được tệp bất kỳ của theme.
     */
    private static function part(string $want): string
    {
        $allowed = array_values((array) apply_filters('ajax_template_parts', ['parts/card']));
        $part = $want === '' ? (string) ($allowed[0] ?? 'parts/card') : $want;
        if (!in_array($part, $allowed, true)) {
            self::fail('Template part "' . mb_substr($part, 0, 64) . '" chưa được theme cho phép (filter ajax_template_parts).', 400);
        }
        if (!is_file(Front::themeDir() . "/$part.php")) {
            self::fail("Theme đang dùng chưa có tệp $part.php.", 500);
        }
        return $part;
    }

    /**
     * Tham số truy vấn chính của trang đang xem, đã lọc để gửi lại qua AJAX — dùng cho nút "Tải thêm":
     * cùng chuyên mục/thẻ/tác giả/tìm kiếm/ngày với trang hiện tại.
     */
    public static function mainQueryArgs(Query $q): array
    {
        // Gồm cả exclude/parent/orderby/order (queryArgs nhận được): truy vấn riêng truyền vào ($args['query'] của load-more)
        // sắp theo tên hay bỏ bài nổi bật mà "Tải thêm" quên mất thì trang sau lệch thứ tự, lặp bài.
        $keep = array_intersect_key($q->args, array_flip(['type', 'tax', 'category', 'tag', 'author', 'search', 'year', 'month', 'day', 'exclude', 'parent', 'orderby', 'order']));
        return array_filter($keep, fn ($v) => $v !== null && $v !== '' && $v !== []) + ['per_page' => $q->perPage];
    }
}

