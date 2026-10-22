<?php
declare(strict_types=1);

namespace LQ\Front;

use LQ\Content\Pages;
use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Core\Permalink;

/**
 * URL -> truy vấn chính. Thứ tự khớp:
 *   ?p= / ?page_id= -> tìm kiếm ?s= -> trang chủ -> taxonomy có tiền tố -> tác giả
 *   -> trang tĩnh (/cha/con/) -> taxonomy không tiền tố -> bài viết theo permalink -> lưu trữ theo ngày
 *   -> slug cũ / cấu trúc permalink cũ (301) -> 404.
 * Mọi đường dẫn hợp lệ nhưng không đúng dạng chuẩn đều được 301 về URL chuẩn.
 */
final class Resolver
{
    public const AUTHOR_BASE = 'tac-gia';
    public const PAGE_BASE = 'page';

    private static int $paged = 1;

    public static function resolve(string $path): Query
    {
        // Phân trang: /chuyen-muc/tin-tuc/page/2/ -> path gốc + trang 2.
        self::$paged = 1;
        // Cờ D: không cho "$" khớp trước "\n" cuối chuỗi (/tac-gia/admin%0a không được thành URL hợp lệ thứ hai).
        if (preg_match('#^(.*)/' . self::PAGE_BASE . '/(\d+)$#D', $path, $m)) {
            $path = $m[1] === '' ? '/' : $m[1];
            self::$paged = (int) $m[2];
            // Đường dẫn lấy từ request đã giải mã: mã hoá lại trước khi đưa vào redirect() (parse_url biến byte UTF-8 0x80–0x9F thành "_").
            $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
            if (self::$paged <= 1) {
                self::redirect($path === '/' ? '/' : $encoded . '/');
            }
            // /page/02 -> /page/2/
            if ($m[2] !== (string) self::$paged) {
                self::redirect($encoded, true);
            }
        }

        $q = self::byQueryString($path)
            ?? self::search($path)
            ?? self::home($path)
            ?? self::prefixedTaxonomy($path)
            ?? self::postType($path)
            ?? self::author($path)
            ?? self::page($path)
            ?? self::rootTaxonomy($path)
            ?? self::post($path)
            ?? self::date($path)
            ?? self::legacy($path)
            ?? self::notFound();

        // Bài/trang đơn không có phân trang: /bai-viet/page/2/ -> /bai-viet/
        if (self::$paged > 1 && ($q->is['singular'] ?? false)) {
            // Xem trước bài chưa đăng (/page/2/?p=ID): permalink đẹp của bài nháp là 404, giữ lại ?p=ID.
            self::redirect(($q->is['preview'] ?? false) ? '/?' . http_build_query($_GET) : Posts::permalink($q->queried));
        }
        // Trang vượt quá số trang thật -> 404 (trang 1 rỗng vẫn hiện "chưa có bài").
        if (self::$paged > 1 && $q->page > max(1, $q->maxPages) && !($q->is['404'] ?? false)) {
            return self::notFound();
        }
        if (!($q->is['404'] ?? false)) {
            self::canonicalSlash($q);
        }
        return apply_filters('main_query', $q);
    }

    /**
     * /xin-chao và /xin-chao/ là một trang: 301 về dạng chuẩn để không thành hai URL trùng nội dung.
     * Chuẩn = có "/" cuối; riêng bài viết theo cấu trúc permalink (vd. "/%postname%.html" thì không có).
     */
    private static function canonicalSlash(Query $q): void
    {
        if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
            return;
        }
        // request_path() đã cắt "/" cuối nên đọc lại đường dẫn gốc của request.
        $uri = (string) preg_replace('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', '', (string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $raw = rawurldecode(substr($uri, 0, strcspn($uri, '?#')));
        if (trim($raw, '/') === '') {
            return;
        }
        $isPost = ($q->is['singular'] ?? false) && ($q->queried['type'] ?? '') === 'post';
        $want = !$isPost || str_ends_with((string) option('permalink_structure', '/%postname%/'), '/');
        if (str_ends_with($raw, '/') !== $want) {
            $path = '/' . trim($raw, '/');
            self::redirect($want ? $path . '/' : $path);
        }
    }

    /* ---------- Từng loại URL ---------- */

    private static function byQueryString(string $path): ?Query
    {
        $id = (int) ($_GET['p'] ?? $_GET['page_id'] ?? 0);
        if ($path !== '/' || $id <= 0) {
            return null;
        }
        $post = self::visible(db()->row('SELECT * FROM {posts} WHERE id = ?', [$id]));
        if (!$post) {
            return self::notFound();
        }
        // Loại nội dung không công khai (lq_register_post_type public = false): chỉ người sửa được mới xem trước.
        if (!(Posts::type((string) $post['type'])['public'] ?? false)) {
            return Posts::canEdit($post) ? self::singular($post) : self::notFound();
        }
        // Đang dùng permalink đẹp (hoặc là trang tĩnh) -> chuyển về URL chuẩn, trừ khi đang xem trước bài chưa đăng.
        $canonical = Posts::permalink($post);
        if ($post['status'] === 'published' && parse_url($canonical, PHP_URL_QUERY) === null) {
            self::redirect($canonical);
        }
        return self::singular($post);
    }

    private static function search(string $path): ?Query
    {
        $s = isset($_GET['s']) && is_string($_GET['s']) ? trim($_GET['s']) : '';
        if ($path !== '/' || $s === '') {
            return null;
        }
        $q = new Query(['type' => 'any', 'search' => mb_substr($s, 0, 200), 'page' => self::$paged]);
        $q->is = ['search' => true, 'paged' => self::$paged > 1];
        return $q;
    }

    private static function home(string $path): ?Query
    {
        if ($path !== '/') {
            return null;
        }
        $front = (int) option('page_on_front', 0);
        if ($front && ($page = self::visible(Posts::find($front, 'page'), false))) {
            if (self::$paged > 1) {
                self::redirect('/');
            }
            $q = self::singular($page);
            $q->is['front_page'] = true;
            return $q;
        }
        $q = new Query(['page' => self::$paged]);
        $q->is = ['home' => true, 'front_page' => true, 'paged' => self::$paged > 1];
        return $q;
    }

    /** /chuyen-muc/tin-tuc/cong-nghe/, /the/php/, /dien-vien/ten/ */
    private static function prefixedTaxonomy(string $path): ?Query
    {
        foreach (Taxonomies::all() as $key => $t) {
            if ($t['base'] === '' || !str_starts_with($path . '/', '/' . $t['base'] . '/')) {
                continue;
            }
            $rest = trim(substr($path, strlen($t['base']) + 1), '/');
            // Tiền tố đã có chủ: không khớp term thì là 404, không dò tiếp.
            return ($rest !== '' ? self::termArchive($key, $rest) : null) ?? self::notFound();
        }
        return null;
    }

    /** Chuyên mục/taxonomy bỏ tiền tố: /tin-tuc/cong-nghe/ */
    /** Loại nội dung tuỳ biến: /<base>/ (danh sách, nếu has_archive) và /<base>/<slug>/ (chi tiết). */
    private static function postType(string $path): ?Query
    {
        foreach (Posts::customTypes() as $key => $t) {
            if (!$t['public'] || !str_starts_with($path . '/', '/' . $t['base'] . '/')) {
                continue;
            }
            // Tiền tố đã có chủ: không khớp thì là 404, không dò tiếp.
            $rest = trim(substr($path, strlen($t['base']) + 1), '/');
            if ($rest === '') {
                if (!$t['has_archive']) {
                    return self::notFound();
                }
                $q = new Query(['type' => $key, 'page' => self::$paged]);
                $q->is = ['archive' => true, 'post_type_archive' => true, 'paged' => self::$paged > 1];
                $q->queried = $t + ['url' => site_url('/' . $t['base'] . '/')];
                return $q;
            }
            if (!preg_match('#^[a-z0-9-]+$#D', $rest)) {
                return self::notFound();
            }
            $post = self::visible(db()->row('SELECT * FROM {posts} WHERE type = ? AND slug = ?', [$key, $rest]));
            return $post ? self::singular($post) : self::notFound();
        }
        return null;
    }

    private static function rootTaxonomy(string $path): ?Query
    {
        foreach (Taxonomies::all() as $key => $t) {
            if ($t['base'] === '' && ($q = self::termArchive($key, trim($path, '/')))) {
                return $q;
            }
        }
        return null;
    }

    private static function termArchive(string $taxonomy, string $termPath): ?Query
    {
        if (!preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#D', $termPath)) {
            return null;
        }
        $info = Taxonomies::get($taxonomy);
        $segments = explode('/', $termPath);
        if (!$info['hierarchical'] && count($segments) > 1) {
            return null;
        }
        $term = db()->row('SELECT * FROM {terms} WHERE taxonomy = ? AND slug = ?', [$taxonomy, end($segments)]);
        if (!$term) {
            return null;
        }
        // Chuyên mục con phải đi đúng đường cha; sai đường (hoặc gõ thiếu cha) thì 301 về URL chuẩn.
        if ($info['hierarchical'] && Terms::path((int) $term['id']) !== $termPath) {
            if ($info['base'] === '' && count($segments) > 1) {
                return null; // không tiền tố: để các bước sau (bài viết, trang) thử tiếp
            }
            self::redirect(Terms::url($term), true);
        }
        // Taxonomy gắn cho loại tuỳ biến: liệt kê đúng các loại đó (chỉ loại công khai), không mặc định chỉ bài viết.
        $types = array_values(array_intersect($info['post_type'], Posts::publicTypes())) ?: 'post';
        $q = new Query(['type' => $types, 'tax' => [$taxonomy => (int) $term['id']], 'page' => self::$paged]);
        $flag = match ($taxonomy) {
            'category' => 'category',
            'tag' => 'tag',
            default => 'tax',
        };
        $q->is = ['archive' => true, $flag => true, 'paged' => self::$paged > 1];
        $q->queried = $term + ['url' => Terms::url($term)];
        return $q;
    }

    private static function author(string $path): ?Query
    {
        if (!preg_match('#^/' . self::AUTHOR_BASE . '/([a-zA-Z0-9_.-]+)$#D', $path, $m)) {
            return null;
        }
        $user = db()->row('SELECT id, username, display_name, role, created_at FROM {users} WHERE username = ?', [$m[1]]);
        if (!$user) {
            return self::notFound();
        }
        // Collation không phân biệt hoa thường: /tac-gia/ADMIN/ cũng tìm ra -> 301 về đúng tên đăng nhập.
        if ($user['username'] !== $m[1]) {
            self::redirect(author_url($user), true);
        }
        $q = new Query(['author' => (int) $user['id'], 'page' => self::$paged]);
        $q->is = ['archive' => true, 'author' => true, 'paged' => self::$paged > 1];
        $q->queried = $user + ['url' => author_url($user)];
        return $q;
    }

    /** Trang tĩnh /cha/con/. Slug trang là duy nhất nên chỉ cần tìm theo đoạn cuối rồi đối chiếu đường cha. */
    private static function page(string $path): ?Query
    {
        $p = trim($path, '/');
        if ($p === '' || !preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#D', $p)) {
            return null;
        }
        $segments = explode('/', $p);
        $page = self::visible(db()->row("SELECT * FROM {posts} WHERE type = 'page' AND slug = ?", [end($segments)]), false);
        if (!$page) {
            return null;
        }
        if (Pages::path($page) !== $p) {
            // Gõ sai đường cha: chỉ chuyển hướng khi trang ở cấp gốc không thể trùng với thứ khác.
            if (count($segments) > 1) {
                return null;
            }
            self::redirect(Posts::permalink($page));
        }
        if ((int) $page['id'] === (int) option('page_on_front', 0)) {
            self::redirect('/');
        }
        // Trang được chọn làm "trang bài viết": hiện danh sách bài mới nhất.
        if ((int) $page['id'] === (int) option('page_for_posts', 0) && (int) option('page_on_front', 0)) {
            $q = new Query(['page' => self::$paged]);
            $q->is = ['home' => true, 'paged' => self::$paged > 1];
            $q->queried = $page;
            return $q;
        }
        return self::singular($page);
    }

    /** Bài viết theo cấu trúc permalink hiện tại. */
    private static function post(string $path): ?Query
    {
        $structure = (string) option('permalink_structure', '/%postname%/');
        if ($structure === '' || !preg_match(Permalink::compile($structure), $path, $m)) {
            return null;
        }
        $post = self::findPost($m);
        if (!$post) {
            // Đổi slug bài đã đăng -> slug cũ vẫn về được bài mới.
            if (isset($m['postname']) && ($old = self::byOldSlug($m['postname']))) {
                self::redirect(Posts::permalink($old));
            }
            return null;
        }
        // Khớp slug nhưng sai năm/tháng/chuyên mục… (vd. bài đổi ngày đăng) -> 301 về URL chuẩn.
        $canonical = Posts::permalink($post);
        if (rtrim((string) parse_url($canonical, PHP_URL_PATH), '/') !== rtrim($path, '/')) {
            self::redirect($canonical);
        }
        return self::singular($post);
    }

    /** /2026/, /2026/10/, /2026/10/19/ */
    private static function date(string $path): ?Query
    {
        if (!preg_match('#^/(\d{4})(?:/(\d{2}))?(?:/(\d{2}))?$#D', $path, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
        if ($y < 1970 || $mo > 12 || ($mo && $d && !checkdate($mo, $d, $y)) || ($d && !$mo)) {
            return null;
        }
        $q = new Query(['year' => $y, 'month' => $mo, 'day' => $d, 'page' => self::$paged]);
        $q->is = ['archive' => true, 'date' => true, 'year' => !$mo, 'month' => $mo && !$d, 'day' => (bool) $d, 'paged' => self::$paged > 1];
        $q->queried = ['year' => $y, 'month' => $mo, 'day' => $d];
        return $q;
    }

    /** Link cũ: theo các cấu trúc permalink đã dùng trước đây, hoặc slug cũ ở đoạn cuối. */
    private static function legacy(string $path): ?Query
    {
        foreach ((array) option('permalink_history', []) as $old) {
            $old = (string) $old;
            if ($old === '' || !preg_match(Permalink::compile($old), $path, $m)) {
                continue;
            }
            $post = self::findPost($m) ?? (isset($m['postname']) ? self::byOldSlug($m['postname']) : null);
            if ($post) {
                self::redirect(Posts::permalink($post));
            }
        }
        return null;
    }

    /* ---------- Tiện ích ---------- */

    private static function findPost(array $m): ?array
    {
        if (isset($m['post_id'])) {
            $post = db()->row("SELECT * FROM {posts} WHERE type = 'post' AND id = ?", [(int) $m['post_id']]);
        } elseif (isset($m['postname'])) {
            $post = db()->row("SELECT * FROM {posts} WHERE type = 'post' AND slug = ?", [$m['postname']]);
        } else {
            return null;
        }
        return self::visible($post, false);
    }

    private static function byOldSlug(string $slug): ?array
    {
        $post = db()->row(
            "SELECT p.* FROM {old_slugs} o JOIN {posts} p ON p.id = o.post_id
             WHERE o.slug = ? AND p.type = 'post' AND p.status = 'published' AND p.published_at <= ? ORDER BY o.id DESC LIMIT 1",
            [$slug, now_utc()]
        );
        return $post ?: null;
    }

    /**
     * Bài có được xem không: đã đăng (và tới giờ) thì ai cũng xem được;
     * bài chưa đăng chỉ người có quyền sửa xem được, và chỉ qua ?p=ID (xem trước).
     */
    private static function visible(?array $post, bool $allowPreview = true): ?array
    {
        if (!$post || $post['status'] === 'trash') {
            return null;
        }
        if ($post['status'] === 'published' && $post['published_at'] && $post['published_at'] <= now_utc()) {
            return $post;
        }
        return $allowPreview && Posts::canEdit($post) ? $post : null;
    }

    private static function singular(array $post): Query
    {
        $post += [
            'author_name' => (string) db()->value('SELECT display_name FROM {users} WHERE id = ?', [$post['author_id']]),
            'author_username' => (string) db()->value('SELECT username FROM {users} WHERE id = ?', [$post['author_id']]),
        ];
        $q = Query::fromPosts([$post]);
        $isPage = $post['type'] === 'page';
        $q->is = [
            'singular' => true,
            $isPage ? 'page' : 'single' => true,
            'preview' => $post['status'] !== 'published',
        ];
        $q->queried = $q->posts[0];
        return $q;
    }

    private static function notFound(): Query
    {
        $q = Query::fromPosts([]);
        $q->is = ['404' => true];
        return $q;
    }

    /** 301 (vĩnh viễn) giữ nguyên query string, kèm số trang nếu có. */
    private static function redirect(string $to, bool $keepPage = false): never
    {
        $path = (string) (parse_url($to, PHP_URL_PATH) ?: '/');
        // Chuẩn hoá mã hoá từng đoạn (đoạn đã mã hoá giữ nguyên) để header Location không chứa dấu cách, byte UTF-8 thô.
        $path = implode('/', array_map(fn ($s) => rawurlencode(rawurldecode($s)), explode('/', $path)));
        if ($keepPage && self::$paged > 1) {
            $path = rtrim($path, '/') . '/' . self::PAGE_BASE . '/' . self::$paged . '/';
        }
        $qs = (string) (parse_url($to, PHP_URL_QUERY) ?? '');
        if ($qs === '') {
            // Giữ tham số khác (vd. utm_*), bỏ tham số định tuyến đã được thay bằng URL đẹp.
            $qs = http_build_query(array_diff_key($_GET, array_flip(['p', 'page_id', 'preview'])));
        }
        redirect(site_url($path) . ($qs !== '' ? '?' . $qs : ''), 301);
    }
}
