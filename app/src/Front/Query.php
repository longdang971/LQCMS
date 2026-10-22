<?php
declare(strict_types=1);

namespace LQ\Front;

use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Core\Database;
use LQ\Core\Slug;

/**
 * Truy vấn bài viết/trang cho frontend + vòng lặp kiểu WordPress.
 *
 *   $q = lq_query(['category' => 'tin-tuc', 'per_page' => 5]);
 *   while ($q->have_posts()) { $q->the_post(); the_title(); }
 *   lq_reset_postdata();
 *
 * Tham số (đều tuỳ chọn):
 *   type       'post' | 'page' | loại tuỳ biến | 'any' | mảng    (mặc định 'post')
 *   per_page   số bài mỗi trang, -1 = tất cả                     (mặc định option posts_per_page)
 *   page       trang thứ mấy, bắt đầu từ 1
 *   offset     bỏ qua N bài đầu (khi dùng thì không tính theo page)
 *   include    [id, ...] chỉ lấy các bài này      exclude  [id, ...] bỏ các bài này
 *   category   id | slug | [id/slug, ...] — gồm cả chuyên mục con (include_children => false để tắt)
 *   tag        id | slug | [...]
 *   tax        ['actor' => 'slug' | id | [...], ...]  — mọi taxonomy, nhiều điều kiện thì AND
 *   author     id | tên đăng nhập
 *   search     từ khoá (tìm trong tiêu đề, nội dung; gõ không dấu cũng khớp tiêu đề)
 *   year, month, day
 *   parent     id trang cha (0 = trang cấp gốc)
 *   meta       ['key' => 'ten_field', 'value' => giá trị]  — giá trị so khớp chính xác
 *   orderby    'date' | 'modified' | 'title' | 'menu_order' | 'id' | 'rand'
 *   order      'DESC' | 'ASC'
 *   status     'published' (mặc định) | 'any' (gồm cả nháp — chỉ dùng nội bộ khi xem trước)
 */
final class Query
{
    public array $args;
    /** @var array<int, array> */
    public array $posts = [];
    public int $found = 0;
    public int $page = 1;
    public int $perPage = 10;
    public int $maxPages = 0;
    public ?array $post = null;
    private int $index = -1;

    /** Ngữ cảnh của truy vấn chính: is['single'], is['category']… do Resolver gắn. */
    public array $is = [];
    /** Đối tượng đang xem: bài/trang, term, tác giả — tuỳ ngữ cảnh. */
    public ?array $queried = null;

    public function __construct(array $args = [])
    {
        $this->args = $args;
        if (empty($args['_defer'])) {
            $this->run();
        }
    }

    public function run(): void
    {
        $a = $this->args;
        $this->perPage = (int) ($a['per_page'] ?? option('posts_per_page', 10));
        $this->page = max(1, (int) ($a['page'] ?? 1));
        if ($this->perPage > 0) {
            // /page/99999999999999999999 -> page = PHP_INT_MAX: (page - 1) * per_page tràn thành số thực, SQL hỏng.
            $this->page = min($this->page, intdiv(PHP_INT_MAX, $this->perPage));
        }

        [$where, $params] = $this->where($a);
        $w = implode(' AND ', $where);
        $order = $this->orderBy($a);

        $limit = '';
        if ($this->perPage > 0) {
            $offset = isset($a['offset']) ? max(0, (int) $a['offset']) : ($this->page - 1) * $this->perPage;
            $limit = "LIMIT {$this->perPage} OFFSET $offset";
        }
        $this->posts = db()->all(
            "SELECT p.*, u.display_name AS author_name, u.username AS author_username
             FROM {posts} p LEFT JOIN {users} u ON u.id = p.author_id
             WHERE $w ORDER BY $order $limit",
            $params
        );
        Posts::attachTerms($this->posts);

        if ($this->perPage > 0 && empty($a['no_count'])) {
            $this->found = (int) db()->value("SELECT COUNT(*) FROM {posts} p WHERE $w", $params);
            $this->maxPages = (int) ceil($this->found / $this->perPage);
        } else {
            $this->found = count($this->posts);
            $this->maxPages = $this->found ? 1 : 0;
        }
    }

    /** Dùng khi đã có sẵn danh sách bài (vd. trang đơn), không chạy SQL. */
    public static function fromPosts(array $posts): self
    {
        $q = new self(['_defer' => true]);
        Posts::attachTerms($posts);
        $q->posts = array_values($posts);
        $q->found = count($posts);
        $q->maxPages = $q->found ? 1 : 0;
        return $q;
    }

    /* ---------- Vòng lặp ---------- */

    public function have_posts(): bool
    {
        if ($this->index + 1 < count($this->posts)) {
            return true;
        }
        // Hết vòng: tua lại để có thể lặp lần nữa, giống WordPress.
        $this->index = -1;
        return false;
    }

    public function the_post(): array
    {
        $this->index++;
        $this->post = $this->posts[$this->index] ?? null;
        Front::setPost($this->post);
        return $this->post ?? [];
    }

    public function rewind_posts(): void
    {
        $this->index = -1;
        $this->post = null;
    }

    /** Vị trí bài hiện tại trong vòng lặp, bắt đầu từ 0. */
    public function current(): int
    {
        return $this->index;
    }

    /* ---------- Dựng SQL ---------- */

    private function where(array $a): array
    {
        $where = [];
        $params = [];

        $type = $a['type'] ?? 'post';
        // 'any' = các loại có trang công khai (bài viết, trang, loại tuỳ biến public); chỉ định rõ thì nhận mọi loại đã đăng ký.
        $types = $type === 'any' ? Posts::publicTypes() : array_values(array_intersect((array) $type, array_keys(Posts::types())));
        if (!$types) {
            return [['0 = 1'], []];
        }
        $where[] = 'p.type IN (' . Database::placeholders($types) . ')';
        array_push($params, ...$types);

        if (($a['status'] ?? 'published') === 'any') {
            $where[] = "p.status <> 'trash'";
        } else {
            $where[] = "p.status = 'published' AND p.published_at <= ?";
            $params[] = now_utc();
        }

        foreach (['include' => 'IN', 'exclude' => 'NOT IN'] as $key => $op) {
            $ids = array_map('intval', (array) ($a[$key] ?? []));
            if ($ids) {
                $where[] = "p.id $op (" . Database::placeholders($ids) . ')';
                array_push($params, ...$ids);
            } elseif ($key === 'include' && array_key_exists('include', $a)) {
                return [['0 = 1'], []]; // include rỗng = không có bài nào
            }
        }

        // Lọc theo term: category/tag là lối tắt của tax.
        $tax = (array) ($a['tax'] ?? []);
        foreach (['category', 'tag'] as $k) {
            if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== []) {
                $tax[$k] = $a[$k];
            }
        }
        foreach ($tax as $taxonomy => $terms) {
            $ids = self::termIds((string) $taxonomy, $terms, (bool) ($a['include_children'] ?? true));
            if (!$ids) {
                return [['0 = 1'], []];
            }
            $where[] = 'EXISTS (SELECT 1 FROM {post_terms} x WHERE x.post_id = p.id AND x.term_id IN (' . Database::placeholders($ids) . '))';
            array_push($params, ...$ids);
        }

        if (isset($a['author']) && $a['author'] !== '') {
            $uid = is_numeric($a['author'])
                ? (int) db()->value('SELECT id FROM {users} WHERE id = ?', [(int) $a['author']])
                : 0;
            // Tên đăng nhập toàn số ("20231234") hợp lệ: không có người dùng mang id đó thì thử như tên đăng nhập.
            if (!$uid) {
                $uid = (int) db()->value('SELECT id FROM {users} WHERE username = ?', [(string) $a['author']]);
            }
            $where[] = 'p.author_id = ?';
            $params[] = $uid;
        }

        $q = trim((string) ($a['search'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $slug = Slug::make($q);
            $where[] = '(p.title LIKE ? OR p.content LIKE ?' . ($slug !== '' ? ' OR p.slug LIKE ?' : '') . ')';
            array_push($params, $like, $like);
            if ($slug !== '') {
                $params[] = '%' . $slug . '%';
            }
        }

        // Ngày tháng tính theo múi giờ của site, nên đổi khoảng thời gian địa phương sang UTC.
        if (!empty($a['year'])) {
            [$y, $m, $d] = [(int) $a['year'], (int) ($a['month'] ?? 0), (int) ($a['day'] ?? 0)];
            // Ngày không tồn tại (tháng 13, năm âm, 31/02…) từ AJAX/plugin: không có bài nào thay vì ngoại lệ DateTime.
            if ($y < 1 || $y > 9999 || $m < 0 || $m > 12 || $d < 0 || $d > 31 || ($d && !$m) || ($d && !checkdate($m, $d, $y))) {
                return [['0 = 1'], []];
            }
            [$from, $to] = self::dateRange((int) $a['year'], (int) ($a['month'] ?? 0), (int) ($a['day'] ?? 0));
            $where[] = 'p.published_at >= ? AND p.published_at < ?';
            array_push($params, $from, $to);
        }

        if (array_key_exists('parent', $a) && $a['parent'] !== null) {
            if ((int) $a['parent'] === 0) {
                $where[] = 'p.parent_id IS NULL';
            } else {
                $where[] = 'p.parent_id = ?';
                $params[] = (int) $a['parent'];
            }
        }

        if (!empty($a['meta']['key'])) {
            $where[] = 'EXISTS (SELECT 1 FROM {post_meta} m WHERE m.post_id = p.id AND m.meta_key = ?'
                . (array_key_exists('value', $a['meta']) ? ' AND m.meta_value = ?' : '') . ')';
            $params[] = (string) $a['meta']['key'];
            if (array_key_exists('value', $a['meta'])) {
                $params[] = json_encode($a['meta']['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return [$where, $params];
    }

    private function orderBy(array $a): string
    {
        $dir = strtoupper((string) ($a['order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';
        return match ($a['orderby'] ?? 'date') {
            'title' => "p.title $dir, p.id $dir",
            'modified' => "p.updated_at $dir, p.id $dir",
            'menu_order' => 'p.menu_order ' . (isset($a['order']) ? $dir : 'ASC') . ', p.title ASC',
            'id' => "p.id $dir",
            'rand' => 'RAND()',
            default => "COALESCE(p.published_at, p.created_at) $dir, p.id $dir",
        };
    }

    /** id/slug (một hoặc nhiều) -> ID term; taxonomy phân cấp thì gồm cả con cháu. */
    public static function termIds(string $taxonomy, mixed $terms, bool $children = true): array
    {
        $ids = [];
        foreach ((array) $terms as $t) {
            if (is_array($t)) {
                $t = $t['id'] ?? 0;
            }
            $id = is_numeric($t)
                ? (int) db()->value('SELECT id FROM {terms} WHERE taxonomy = ? AND id = ?', [$taxonomy, (int) $t])
                : 0;
            // Slug toàn số (thẻ "2026") hợp lệ: không có term mang id đó thì thử như slug.
            if (!$id && is_scalar($t) && (string) $t !== '') {
                $id = (int) db()->value('SELECT id FROM {terms} WHERE taxonomy = ? AND slug = ?', [$taxonomy, (string) $t]);
            }
            if ($id) {
                $ids[] = $id;
            }
        }
        if ($children && $ids && (Taxonomies::get($taxonomy)['hierarchical'] ?? false)) {
            $all = [];
            foreach ($ids as $id) {
                array_push($all, ...Terms::descendantIds($id));
            }
            $ids = $all;
        }
        return array_values(array_unique($ids));
    }

    /** Khoảng [từ, đến) theo UTC cho một năm / tháng / ngày tính ở múi giờ site. */
    public static function dateRange(int $y, int $m = 0, int $d = 0): array
    {
        $tz = site_timezone();
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $y, $m ?: 1, $d ?: 1), $tz);
        $end = $start->modify($d ? '+1 day' : ($m ? '+1 month' : '+1 year'));
        $utc = new \DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}
