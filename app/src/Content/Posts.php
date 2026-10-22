<?php
declare(strict_types=1);

namespace LQ\Content;

use LQ\Core\Database;
use LQ\Core\Html;
use LQ\Core\Permalink;
use LQ\Core\Slug;

final class Posts
{
    public const STATUSES = [
        'published' => 'Đã đăng',
        'scheduled' => 'Lên lịch',
        'draft' => 'Bản nháp',
        'pending' => 'Chờ duyệt',
        'trash' => 'Thùng rác',
    ];

    /** Kiểu nội dung có sẵn: bài viết và trang tĩnh dùng chung bảng posts (loại tuỳ biến: registerType / types()). */
    public const TYPES = [
        'post' => ['label' => 'Bài viết', 'singular' => 'bài viết', 'route' => 'posts', 'cap' => 'edit_posts'],
        'page' => ['label' => 'Trang', 'singular' => 'trang', 'route' => 'pages', 'cap' => 'edit_pages'],
    ];
    /** Đường dẫn admin lõi đã dùng — loại nội dung / taxonomy tuỳ biến không được trùng. */
    public const RESERVED_ROUTES = ['posts', 'pages', 'media', 'categories', 'tags', 'themes', 'plugins', 'plugin', 'settings', 'profile',
        'login', 'register', 'logout', 'upload', 'api', 'dashboard', 'quick-draft', 'terms', 'updates', 'logs', 'notices', 'ai', 'menus', 'users'];

    /** @var array<string, array> loại nội dung tuỳ biến (lq_register_post_type) */
    private static array $custom = [];

    /**
     * Đăng ký loại nội dung tuỳ biến (sản phẩm, sự kiện…): dùng chung bảng posts, form soạn của bài viết (không có
     * chuyên mục/thẻ — gắn taxonomy tuỳ biến bằng 'post_type'), URL /<base>/<slug>/ và trang danh sách /<base>/.
     */
    public static function registerType(string $key, array $args): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,19}$/', $key) || isset(self::TYPES[$key]) || in_array($key, ['any', 'attachment', 'revision', 'menu'], true)) {
            throw new \InvalidArgumentException("Tên loại nội dung không hợp lệ hoặc đã có: \"$key\" (chữ thường, số, gạch dưới; 2–20 ký tự).");
        }
        $route = (string) ($args['route'] ?? str_replace('_', '-', $key));
        if (!preg_match('/^[a-z0-9-]+$/', $route) || in_array($route, self::RESERVED_ROUTES, true) || Taxonomies::byRoute($route)) {
            throw new \InvalidArgumentException("Đường dẫn admin \"$route\" của loại nội dung $key không hợp lệ hoặc đã được dùng.");
        }
        foreach (self::$custom as $k => $t) {
            if ($k !== $key && $t['route'] === $route) {
                throw new \InvalidArgumentException("Đường dẫn admin \"$route\" đã được loại nội dung $k dùng.");
            }
        }
        $base = trim((string) ($args['base'] ?? $route), '/');
        if ($base === '' || ($err = Permalink::validateBase($base))) {
            throw new \InvalidArgumentException("Tiền tố URL \"$base\" của loại nội dung $key: " . ($err ?? 'không được để trống.'));
        }
        // Đoạn đầu URL không được trùng tiền tố của taxonomy hay loại nội dung khác (Resolver khớp cái nào trước thì cái kia 404).
        $first = explode('/', $base)[0];
        foreach (Taxonomies::all() as $tk => $tx) {
            if ($tx['base'] !== '' && explode('/', $tx['base'])[0] === $first) {
                throw new \InvalidArgumentException("Tiền tố URL \"$base\" của loại nội dung $key trùng tiền tố của taxonomy $tk.");
            }
        }
        foreach (self::$custom as $k => $t) {
            if ($k !== $key && explode('/', $t['base'])[0] === $first) {
                throw new \InvalidArgumentException("Tiền tố URL \"$base\" của loại nội dung $key trùng loại $k.");
            }
        }
        $label = (string) ($args['label'] ?? ucfirst($key));
        self::$custom[$key] = [
            'key' => $key,
            'label' => $label,
            'singular' => (string) ($args['singular'] ?? mb_strtolower($label)),
            'route' => $route,
            'base' => $base,
            'icon' => (string) ($args['icon'] ?? 'file-text'),
            'cap' => (string) ($args['cap'] ?? 'edit_posts'),
            'description' => (string) ($args['description'] ?? ''),
            'public' => (bool) ($args['public'] ?? true),          // có trang chi tiết ngoài website
            'has_archive' => (bool) ($args['has_archive'] ?? true), // có trang danh sách /<base>/
            'show_in_menu' => (bool) ($args['show_in_menu'] ?? true),
            'builtin' => false,
        ];
    }

    /** Mọi loại nội dung (có sẵn + tuỳ biến), cùng một dạng thông tin. */
    public static function types(): array
    {
        $out = [];
        foreach (self::TYPES as $k => $t) {
            $out[$k] = $t + ['key' => $k, 'base' => '', 'icon' => $k === 'page' ? 'files' : 'file-text', 'description' => '',
                'public' => true, 'has_archive' => false, 'show_in_menu' => false, 'builtin' => true];
        }
        return $out + self::$custom;
    }

    public static function type(string $key): ?array
    {
        return self::types()[$key] ?? null;
    }

    /** Link sửa trong admin, vd. /admin/su-kien/12/edit; '' khi loại đó không còn đăng ký (plugin đã tắt). */
    public static function editUrl(string $type, int $id): string
    {
        $t = self::type($type);
        return $t ? "/admin/{$t['route']}/$id/edit" : '';
    }

    /** Loại nội dung tuỳ biến (không gồm bài viết, trang). */
    public static function customTypes(): array
    {
        return self::$custom;
    }

    /** Loại có trang công khai ngoài website (tìm kiếm "any" gồm những loại này). */
    public static function publicTypes(): array
    {
        return array_keys(array_filter(self::types(), fn ($t) => $t['public']));
    }

    public static function find(int $id, string $type = 'post'): ?array
    {
        return db()->row('SELECT * FROM {posts} WHERE id = ? AND type = ?', [$id, $type]);
    }

    /** Người dùng hiện tại có được sửa bài/trang này không. */
    public static function canEdit(array $post): bool
    {
        $u = current_user();
        if (!$u) {
            return false;
        }
        if (($post['type'] ?? 'post') === 'page') {
            return can('edit_pages');
        }
        // Bài viết và loại tuỳ biến: quyền của loại (mặc định edit_posts), bài của người khác cần edit_others_posts.
        if (!can(self::type((string) ($post['type'] ?? 'post'))['cap'] ?? 'edit_posts')) {
            return false;
        }
        return can('edit_others_posts') || (int) $post['author_id'] === (int) $u['id'];
    }

    /**
     * Danh sách có lọc + phân trang.
     * @return array{items: array, total: int, counts: array<string,int>}
     */
    /** Các tháng có bài (theo múi giờ site), mới nhất trước: ['2026-10' => 'Tháng 10/2026', …] — cho bộ lọc tháng. */
    public static function months(string $type = 'post'): array
    {
        $offset = site_timezone()->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $rows = db()->column(
            "SELECT DISTINCT DATE_FORMAT(DATE_ADD(COALESCE(published_at, created_at), INTERVAL ? SECOND), '%Y-%m') ym
             FROM {posts} WHERE type = ? AND status <> 'trash' ORDER BY ym DESC",
            [$offset, $type]
        );
        $out = [];
        foreach ($rows as $ym) {
            $out[$ym] = 'Tháng ' . (int) substr($ym, 5, 2) . '/' . substr($ym, 0, 4);
        }
        return $out;
    }

    public static function paginate(array $f, int $page, int $perPage, string $type = 'post'): array
    {
        $where = ['p.type = ?'];
        $params = [$type];

        if ($type !== 'page' && !can('edit_others_posts')) {
            $where[] = 'p.author_id = ?';
            $params[] = (int) current_user()['id'];
        }
        // Số lượng theo trạng thái cho các tab (trước khi áp lọc trạng thái).
        $countWhere = $where;
        $countParams = $params;

        if (($f['status'] ?? '') !== '' && isset(self::STATUSES[$f['status']])) {
            $where[] = 'p.status = ?';
            $params[] = $f['status'];
        } else {
            $where[] = "p.status <> 'trash'";
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE ? OR p.slug LIKE ?)';
            $params[] = '%' . addcslashes($f['q'], '%_\\') . '%';
            $params[] = '%' . (Slug::make($f['q']) ?: addcslashes($f['q'], '%_\\')) . '%';
        }
        if (!empty($f['category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM {post_terms} x WHERE x.post_id = p.id AND x.term_id = ?)';
            $params[] = (int) $f['category'];
        }
        if (!empty($f['term'])) {
            $where[] = 'EXISTS (SELECT 1 FROM {post_terms} x WHERE x.post_id = p.id AND x.term_id = ?)';
            $params[] = (int) $f['term'];
        }
        if (!empty($f['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM {post_terms} x WHERE x.post_id = p.id AND x.term_id = ?)';
            $params[] = (int) $f['tag'];
        }
        if (!empty($f['author'])) {
            $where[] = 'p.author_id = ?';
            $params[] = (int) $f['author'];
        }
        // Tháng phải 01–12: "2026-13" làm dateRange() ném DateMalformedStringException.
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', (string) ($f['month'] ?? ''), $m)) {
            // Tháng tính theo múi giờ của site (cùng cách hiển thị ngày trong danh sách).
            [$from, $to] = \LQ\Front\Query::dateRange((int) $m[1], (int) $m[2]);
            $where[] = 'COALESCE(p.published_at, p.created_at) >= ? AND COALESCE(p.published_at, p.created_at) < ?';
            array_push($params, $from, $to);
        }

        $w = implode(' AND ', $where);
        $total = (int) db()->value("SELECT COUNT(*) FROM {posts} p WHERE $w", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $items = db()->all(
            "SELECT p.id, p.title, p.slug, p.status, p.author_id, p.featured_image, p.published_at, p.created_at, p.updated_at,
                    u.display_name AS author_name
             FROM {posts} p LEFT JOIN {users} u ON u.id = p.author_id
             WHERE $w
             ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        self::attachTerms($items);

        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (db()->all('SELECT p.status, COUNT(*) c FROM {posts} p WHERE ' . implode(' AND ', $countWhere) . ' GROUP BY p.status', $countParams) as $r) {
            $counts[$r['status']] = (int) $r['c'];
        }
        $counts['all'] = array_sum($counts) - $counts['trash'];

        return ['items' => $items, 'total' => $total, 'counts' => $counts];
    }

    /**
     * Gắn term vào từng bài bằng một truy vấn: $p['terms'][taxonomy] = [...],
     * kèm $p['categories'] và $p['tags'] cho tiện dùng.
     */
    public static function attachTerms(array &$posts): void
    {
        if (!$posts) {
            return;
        }
        $ids = array_column($posts, 'id');
        $rows = db()->all(
            'SELECT pt.post_id, t.id, t.name, t.slug, t.taxonomy, t.parent_id, t.description FROM {post_terms} pt JOIN {terms} t ON t.id = pt.term_id
             WHERE pt.post_id IN (' . Database::placeholders($ids) . ') ORDER BY t.name',
            $ids
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['post_id']][$r['taxonomy']][] = $r;
        }
        foreach ($posts as &$p) {
            $p['terms'] = $map[$p['id']] ?? [];
            $p['categories'] = $p['terms']['category'] ?? [];
            $p['tags'] = $p['terms']['tag'] ?? [];
        }
    }

    /** $root: trang cấp gốc (bài viết luôn true) — khi đó slug còn phải tránh đoạn đầu URL đã có chủ (Permalink::rootSlugTaken). */
    public static function uniqueSlug(string $slug, int $exceptId = 0, string $type = 'post', bool $root = true): string
    {
        // Giữ nguyên slug đang dùng thì không kiểm chéo: trùng từ trước (vd. vừa đổi cấu trúc permalink) mà tự đổi slug khi lưu lại
        // sẽ làm hỏng URL đang chạy.
        // Trang con vừa chuyển lên cấp gốc thì URL đằng nào cũng đổi (/cha/slug/ → /slug/): không miễn, phải tránh đoạn đầu URL đã có chủ.
        $current = $exceptId ? (string) db()->value("SELECT slug FROM {posts} WHERE id = ? AND (type <> 'page' OR parent_id IS NULL)", [$exceptId]) : '';
        $exists = fn ($s) => (bool) db()->value(
            'SELECT 1 FROM {posts} WHERE type = ? AND slug = ? AND id <> ?',
            [$type, $s, $exceptId]
        ) || ($root && $s !== $current && \LQ\Core\Permalink::rootSlugTaken($s, $type === 'page' ? 'page' : 'post'));
        // Slug sát giới hạn cột (191) mà bị trùng: cắt bớt để chừa chỗ cho hậu tố -2, -3… (không thì lỗi "Data too long").
        if (strlen($slug) > 180 && $exists($slug)) {
            $slug = rtrim(substr($slug, 0, 180), '-');
        }
        return Slug::unique($slug, $exists);
    }

    /**
     * Lưu bài viết từ form admin.
     * @return array{0: array<string,string>, 1: ?int}
     */
    public static function save(array $in, ?array $existing, ?string $featuredImage, string $type = 'post'): array
    {
        $isPage = $type === 'page';
        $isPost = $type === 'post'; // chuyên mục & thẻ chỉ dành cho bài viết
        $user = current_user();
        $id = $existing ? (int) $existing['id'] : 0;
        $title = trim((string) ($in['title'] ?? ''));
        $errors = [];

        if (mb_strlen($title) > 500) {
            $errors['title'] = 'Tiêu đề tối đa 500 ký tự.';
        }

        // Trạng thái: người không có publish_posts chỉ được lưu nháp / gửi duyệt.
        $action = (string) ($in['action'] ?? 'draft');
        $status = match ($action) {
            'publish' => $isPage || can('publish_posts') ? 'published' : 'pending',
            'pending' => 'pending',
            'update' => $existing['status'] ?? 'draft',
            default => 'draft',
        };
        if ($status === 'trash') {
            $status = 'draft';
        }

        // Ngày đăng: để trống = giữ ngày cũ hoặc lấy giờ hiện tại khi đăng lần đầu.
        $publishedAt = $existing['published_at'] ?? null;
        $dateIn = trim((string) ($in['published_at'] ?? ''));
        if ($dateIn !== '') {
            $publishedAt = local_to_utc($dateIn);
            if ($publishedAt === null) {
                $errors['published_at'] = 'Ngày đăng không hợp lệ.';
            }
        }
        if (in_array($status, ['published', 'scheduled'], true)) {
            $publishedAt ??= now_utc();
            $status = $publishedAt > now_utc() ? 'scheduled' : 'published';
        }

        $slugIn = trim((string) ($in['slug'] ?? ''));
        $slug = Slug::make($slugIn !== '' ? $slugIn : $title);
        if ($slug === '' && $title !== '') {
            $errors['slug'] = 'Không tạo được slug, hãy nhập slug thủ công.';
        }

        // Thuộc tính trang: trang cha (không được là chính nó / trang con), thứ tự, mẫu trang.
        $pageData = [];
        if ($isPage) {
            $parent = (int) ($in['parent_id'] ?? 0);
            if ($parent && (!self::find($parent, 'page') || ($id && in_array($parent, Pages::descendantIds($id), true)))) {
                $errors['parent_id'] = 'Trang cha không hợp lệ (không được chọn chính nó hoặc trang con của nó).';
            }
            $template = trim((string) ($in['template'] ?? ''));
            // Giữ nguyên mẫu đang gắn thì không bắt lỗi dù theme hiện tại không khai báo (vd. vừa đổi theme, tắt plugin):
            // sửa trang lúc đó không được âm thầm xoá mẫu, bật lại theme thì trang trở về như cũ.
            if ($template !== '' && !Pages::template($template) && $template !== (string) ($existing['template'] ?? '')) {
                $errors['template'] = 'Mẫu trang không tồn tại trong theme đang dùng.';
            }
            // Trang cấp gốc nằm ở /slug/: không được trùng đường dẫn hệ thống / tiền tố chuyên mục, thẻ…
            // Trang có sẵn giữ nguyên slug + cấp gốc thì bỏ qua: plugin đăng ký route/loại nội dung trùng sau này
            // không được làm trang cũ hết lưu được (admin thấy cảnh báo trùng đường dẫn thay vào đó).
            $unchanged = $existing && $existing['slug'] === $slug && empty($existing['parent_id']);
            if (!$parent && $slug !== '' && !$unchanged && in_array($slug, \LQ\Core\Permalink::takenRootSegments(), true)) {
                $errors['slug'] = "Slug \"$slug\" trùng đường dẫn hệ thống hoặc tiền tố chuyên mục/thẻ. Hãy chọn slug khác.";
            }
            $pageData = ['parent_id' => $parent ?: null, 'menu_order' => (int) ($in['menu_order'] ?? 0), 'template' => $template !== '' ? $template : null];
        }

        // Chuyên mục (chỉ bài viết): chỉ nhận ID tồn tại; không chọn gì thì dùng mặc định.
        $catIds = !$isPost ? [] : array_map('intval', (array) ($in['categories'] ?? []));
        $catIds = $catIds ? db()->column(
            "SELECT id FROM {terms} WHERE taxonomy = 'category' AND id IN (" . Database::placeholders($catIds) . ')',
            $catIds
        ) : [];
        if ($isPost && !$catIds && ($def = (int) option('default_category'))) {
            $catIds = [$def];
        }

        if ($errors) {
            return [$errors, null];
        }

        $content = (string) ($in['content'] ?? '');
        if (!can('unfiltered_html')) {
            $content = Html::sanitize($content);
        }
        $content = apply_filters('content_save_pre', $content);

        $data = [
            'title' => $title,
            // Kiểm chéo đoạn đầu URL chỉ với bài viết / trang cấp gốc; loại tuỳ biến nằm dưới /<base>/ nên không đụng.
            'slug' => self::uniqueSlug($slug ?: 'khong-tieu-de', $id, $type, $isPost || ($isPage && empty($pageData['parent_id']))),
            'content' => $content,
            'status' => $status,
            'published_at' => $publishedAt,
            'updated_at' => now_utc(),
        ];
        if ($featuredImage !== null) {
            $data['featured_image'] = $featuredImage === '' ? null : $featuredImage;
        }
        $data += $pageData;

        // Term theo từng taxonomy. Chuyên mục & thẻ luôn được thay; taxonomy tuỳ biến chỉ thay khi form có gửi
        // (tránh xoá mất dữ liệu khi theme/plugin khai báo taxonomy đó đang tắt).
        // Trang tĩnh không dùng chuyên mục/thẻ; loại tuỳ biến chỉ có taxonomy tuỳ biến gắn với nó ('post_type').
        $termInput = !$isPost ? [] : [
            'category' => ['ids' => $catIds],
            'tag' => ['names' => self::splitNames($in['tags'] ?? '')],
        ];
        foreach ($isPage ? [] : Taxonomies::forType($type) as $key => $tax) {
            if (!array_key_exists($key, (array) ($in['tax_input'] ?? []))) {
                continue;
            }
            $raw = $in['tax_input'][$key];
            if ($tax['hierarchical']) {
                $ids = array_map('intval', array_filter((array) $raw, 'is_numeric'));
                $termInput[$key] = ['ids' => $ids ? db()->column(
                    'SELECT id FROM {terms} WHERE taxonomy = ? AND id IN (' . Database::placeholders($ids) . ')',
                    [$key, ...$ids]
                ) : []];
            } else {
                // Như WordPress: chỉ người có quyền quản lý taxonomy này mới tạo được term mới; người khác chỉ gắn term đã có.
                $termInput[$key] = ['names' => self::splitNames($raw), 'create' => can($tax['cap'])];
            }
        }

        $id = db()->transaction(function (Database $db) use ($data, $existing, $id, $user, $termInput, $type): int {
            if ($existing) {
                // Bài đã đăng mà đổi slug -> lưu slug cũ để frontend 301 về link mới.
                if ($existing['slug'] !== $data['slug'] && $existing['status'] === 'published') {
                    $db->insert('old_slugs', ['post_id' => $id, 'slug' => $existing['slug'], 'created_at' => now_utc()]);
                }
                $db->update('posts', $data, ['id' => $id]);
            } else {
                $id = $db->insert('posts', $data + [
                    'type' => $type,
                    'author_id' => $user['id'],
                    'created_at' => now_utc(),
                ]);
            }
            $termIds = [];
            foreach ($termInput as $tax => $in) {
                array_push($termIds, ...($in['ids'] ?? Terms::ensureTerms($tax, $in['names'], $in['create'] ?? true)));
            }
            $taxes = array_keys($termInput);
            if ($taxes) {
                $db->query(
                    'DELETE FROM {post_terms} WHERE post_id = ? AND term_id IN (SELECT id FROM {terms} WHERE taxonomy IN (' . Database::placeholders($taxes) . '))',
                    [$id, ...$taxes]
                );
            }
            foreach (array_unique($termIds) as $tid) {
                $db->insert('post_terms', ['post_id' => $id, 'term_id' => (int) $tid]);
            }
            return $id;
        });

        do_action('post_saved', $id, $data['status']);
        return [[], $id];
    }

    /** "A, B , C" hoặc mảng -> danh sách tên không rỗng. */
    private static function splitNames(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $parts), fn ($v) => $v !== ''));
    }

    public static function trash(int $id): void
    {
        db()->query("UPDATE {posts} SET previous_status = status, status = 'trash', updated_at = ? WHERE id = ? AND status <> 'trash'", [now_utc(), $id]);
        do_action('post_trashed', $id);
    }

    public static function restore(int $id): void
    {
        db()->query("UPDATE {posts} SET status = COALESCE(previous_status, 'draft'), previous_status = NULL, updated_at = ? WHERE id = ? AND status = 'trash'", [now_utc(), $id]);
        do_action('post_restored', $id);
    }

    public static function destroy(int $id): void
    {
        do_action('before_post_deleted', $id);
        // Trang con của trang bị xoá được chuyển lên làm con của trang ông.
        $parent = db()->value('SELECT parent_id FROM {posts} WHERE id = ?', [$id]);
        db()->query("UPDATE {posts} SET parent_id = ? WHERE parent_id = ? AND type = 'page'", [$parent, $id]);
        db()->delete('posts', ['id' => $id]);
        // Trang đang làm trang chủ / trang bài viết bị xoá hẳn: trả cài đặt về mặc định (trang chủ = danh sách bài).
        // Chuyển vào thùng rác thì giữ nguyên — frontend tự hiện danh sách bài, khôi phục trang là trở lại như cũ.
        if ($id === (int) option('page_on_front', 0)) {
            update_option('page_on_front', 0);
            update_option('page_for_posts', 0);
        } elseif ($id === (int) option('page_for_posts', 0)) {
            update_option('page_for_posts', 0);
        }
    }

    /** URL công khai của bài theo cấu trúc permalink hiện tại. */
    public static function permalink(array $post): string
    {
        $type = (string) ($post['type'] ?? 'post');
        if ($type !== 'post' && $type !== 'page') {
            // Loại tuỳ biến: /<base>/<slug>/ ; không công khai thì không có URL.
            $t = self::type($type);
            return $t && $t['public'] ? site_url('/' . $t['base'] . '/' . $post['slug'] . '/') : '';
        }
        if ($type === 'page') {
            if ((int) $post['id'] === (int) option('page_on_front', 0)) {
                return site_url('/');
            }
            return site_url('/' . Pages::path($post) . '/');
        }
        $structure = (string) option('permalink_structure', '/%postname%/');
        $extra = [];
        if (str_contains($structure, '%category%')) {
            // Dùng chuyên mục đã gắn sẵn (Posts::attachTerms) nếu có để khỏi truy vấn lại trong vòng lặp.
            $catId = isset($post['categories'])
                ? (int) (min(array_column($post['categories'], 'id') ?: [0]))
                : (int) db()->value(
                    "SELECT t.id FROM {terms} t JOIN {post_terms} pt ON pt.term_id = t.id WHERE pt.post_id = ? AND t.taxonomy = 'category' ORDER BY t.id LIMIT 1",
                    [$post['id']]
                );
            $extra['category_path'] = $catId ? Terms::path($catId) : 'chua-phan-loai';
        }
        if (str_contains($structure, '%author%')) {
            $extra['author'] = (string) ($post['author_username'] ?? db()->value('SELECT username FROM {users} WHERE id = ?', [$post['author_id']]));
        }
        return site_url(Permalink::build($structure, $post + $extra));
    }
}
