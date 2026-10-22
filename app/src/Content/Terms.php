<?php
declare(strict_types=1);

namespace LQ\Content;

use LQ\Core\Database;
use LQ\Core\Slug;

final class Terms
{
    /** Thông tin taxonomy (nhãn, route, có phân cấp…). */
    public static function info(string $taxonomy): array
    {
        return Taxonomies::get($taxonomy) ?? throw new \InvalidArgumentException("Taxonomy chưa đăng ký: $taxonomy");
    }

    public static function find(int $id, ?string $taxonomy = null): ?array
    {
        $t = db()->row('SELECT * FROM {terms} WHERE id = ?', [$id]);
        return $t && ($taxonomy === null || $t['taxonomy'] === $taxonomy) ? $t : null;
    }

    /** Tất cả term kèm số bài (không tính thùng rác). */
    public static function all(string $taxonomy, string $search = ''): array
    {
        $params = [$taxonomy];
        $where = 't.taxonomy = ?';
        if ($search !== '') {
            $where .= ' AND (t.name LIKE ? OR t.slug LIKE ?)';
            // Thoát % và _ để tìm đúng chữ người dùng gõ (không thành ký tự đại diện của LIKE).
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        return db()->all(
            "SELECT t.*, COUNT(p.id) AS post_count
             FROM {terms} t
             LEFT JOIN {post_terms} pt ON pt.term_id = t.id
             LEFT JOIN {posts} p ON p.id = pt.post_id AND p.status <> 'trash'
             WHERE $where
             GROUP BY t.id
             ORDER BY t.name",
            $params
        );
    }

    /** Sắp xếp danh mục thành cây phẳng (cha rồi tới con), gắn thêm 'depth'. */
    public static function tree(array $terms): array
    {
        $byParent = [];
        $ids = array_column($terms, 'id');
        foreach ($terms as $t) {
            // Cha không có trong danh sách (vd. đang lọc tìm kiếm) thì coi như gốc.
            $parent = $t['parent_id'] && in_array($t['parent_id'], $ids) ? (int) $t['parent_id'] : 0;
            $byParent[$parent][] = $t;
        }
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $t) {
                $out[] = $t + ['depth' => $depth];
                $walk((int) $t['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    /** ID của term và mọi con cháu (để chặn chọn cha là chính con của mình). */
    public static function descendantIds(int $id): array
    {
        $tax = (string) db()->value('SELECT taxonomy FROM {terms} WHERE id = ?', [$id]);
        $all = db()->all('SELECT id, parent_id FROM {terms} WHERE taxonomy = ?', [$tax]);
        $children = [];
        foreach ($all as $t) {
            $children[(int) $t['parent_id']][] = (int) $t['id'];
        }
        $result = [$id];
        for ($i = 0; $i < count($result); $i++) {
            foreach ($children[$result[$i]] ?? [] as $c) {
                $result[] = $c;
            }
        }
        return $result;
    }

    /** Đường dẫn slug đầy đủ của chuyên mục, vd. "tin-tuc/cong-nghe" (dùng cho %category%). */
    public static function path(int $id): string
    {
        static $cache = [];
        if (isset($cache[$id])) {
            return $cache[$id];
        }
        $start = $id;
        $parts = [];
        $seen = [];
        while ($id && !isset($seen[$id]) && ($t = self::find($id))) {
            $seen[$id] = true;
            array_unshift($parts, $t['slug']);
            $id = (int) $t['parent_id'];
        }
        return $cache[$start] = implode('/', $parts);
    }

    /** URL công khai của trang lưu trữ term: /tien-to/slug/ (chuyên mục con: /tien-to/cha/con/). */
    public static function url(array $term): string
    {
        $info = Taxonomies::get((string) $term['taxonomy']);
        $path = ($info['hierarchical'] ?? false) ? self::path((int) $term['id']) : (string) $term['slug'];
        return site_url('/' . trim(($info['base'] ?? '') . '/' . $path, '/') . '/');
    }

    /** $root: term cấp gốc của taxonomy không tiền tố — slug còn phải tránh trang/bài/term khác ở gốc site. */
    public static function uniqueSlug(string $taxonomy, string $slug, int $exceptId = 0, bool $root = false): string
    {
        // Giữ nguyên slug đang dùng thì không kiểm chéo (trùng từ trước mà tự đổi khi lưu lại sẽ làm hỏng URL đang chạy).
        // Term con vừa chuyển lên cấp gốc thì URL đằng nào cũng đổi: không miễn (giống Posts::uniqueSlug).
        $current = $exceptId ? (string) db()->value('SELECT slug FROM {terms} WHERE id = ? AND parent_id IS NULL', [$exceptId]) : '';
        $exists = fn ($s) => (bool) db()->value(
            'SELECT 1 FROM {terms} WHERE taxonomy = ? AND slug = ? AND id <> ?',
            [$taxonomy, $s, $exceptId]
        ) || ($root && $s !== $current && \LQ\Core\Permalink::rootSlugTaken($s, 'term', $taxonomy));
        // Slug sát giới hạn cột (191) mà bị trùng: cắt bớt để chừa chỗ cho hậu tố -2, -3…
        if (strlen($slug) > 180 && $exists($slug)) {
            $slug = rtrim(substr($slug, 0, 180), '-');
        }
        return Slug::unique($slug, $exists);
    }

    /**
     * Thêm/sửa term. Trả về [lỗi theo field, id].
     * @return array{0: array<string,string>, 1: ?int}
     */
    public static function save(string $taxonomy, array $in, int $id = 0): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $slugIn = trim((string) ($in['slug'] ?? ''));
        $parent = (int) ($in['parent_id'] ?? 0);
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Tên không được để trống.';
        } elseif (mb_strlen($name) > 191) {
            $errors['name'] = 'Tên tối đa 191 ký tự.';
        }
        $slug = Slug::make($slugIn !== '' ? $slugIn : $name);
        if ($slug === '') {
            $errors['slug'] = 'Không tạo được slug từ tên này, hãy nhập slug thủ công.';
        }
        // Taxonomy không tiền tố: term cấp gốc nằm thẳng ở /slug/, không được trùng đường dẫn đã có chủ.
        $info = self::info($taxonomy);
        if ($info['base'] === '' && !$errors && ($parent === 0 || !$info['hierarchical'])) {
            $candidate = Slug::make($slugIn !== '' ? $slugIn : $name);
            if (in_array($candidate, \LQ\Core\Permalink::takenRootSegments($taxonomy), true)) {
                $errors['slug'] = "Slug \"$candidate\" trùng đường dẫn hệ thống hoặc tiền tố khác (vì {$info['singular']} đang không có tiền tố). Hãy chọn slug khác.";
            }
        }
        // Cấu trúc bài /%category%/…: chuyên mục cấp gốc thành đoạn đầu URL của bài, slug "admin", "assets"… làm bài rơi vào đường hệ thống.
        if (!$errors && $taxonomy === 'category' && $parent === 0 && str_starts_with((string) option('permalink_structure', ''), '/%category%')
            && in_array(Slug::make($slugIn !== '' ? $slugIn : $name), \LQ\Core\Permalink::RESERVED_ROOT, true)) {
            $errors['slug'] = 'Slug này trùng đường dẫn hệ thống (cấu trúc đường dẫn bài bắt đầu bằng chuyên mục). Hãy chọn slug khác.';
        }
        if (!self::info($taxonomy)['hierarchical']) {
            $parent = 0;
        } elseif ($parent) {
            if (!self::find($parent, $taxonomy)) {
                $errors['parent_id'] = 'Chuyên mục cha không tồn tại.';
            } elseif ($id && in_array($parent, self::descendantIds($id), true)) {
                $errors['parent_id'] = 'Không thể chọn chính nó hoặc chuyên mục con làm chuyên mục cha.';
            }
        }
        if ($errors) {
            return [$errors, null];
        }

        $data = [
            'name' => $name,
            'slug' => self::uniqueSlug($taxonomy, $slug, $id, $info['base'] === '' && !$parent),
            'parent_id' => $parent ?: null,
            'description' => trim((string) ($in['description'] ?? '')),
        ];
        if ($id) {
            db()->update('terms', $data, ['id' => $id]);
        } else {
            $id = db()->insert('terms', $data + ['taxonomy' => $taxonomy]);
        }
        do_action('term_saved', $id, $taxonomy);
        return [[], $id];
    }

    /** Xoá term. Bài viết mất hết chuyên mục sẽ được gán về chuyên mục mặc định. */
    public static function delete(int $id): ?string
    {
        $t = self::find($id);
        if (!$t) {
            return 'Không tìm thấy mục cần xoá.';
        }
        $default = (int) option('default_category');
        if ($t['taxonomy'] === 'category' && $id === $default) {
            return 'Không thể xoá chuyên mục mặc định. Hãy đổi chuyên mục mặc định trong Cài đặt trước.';
        }
        db()->transaction(function (Database $db) use ($t, $id, $default): void {
            $affected = $db->column('SELECT post_id FROM {post_terms} WHERE term_id = ?', [$id]);
            $db->query('UPDATE {terms} SET parent_id = ? WHERE parent_id = ?', [$t['parent_id'], $id]);
            $db->delete('terms', ['id' => $id]);
            if ($t['taxonomy'] === 'category' && $default && $affected) {
                $db->query(
                    "INSERT IGNORE INTO {post_terms} (post_id, term_id)
                     SELECT p.id, ? FROM {posts} p
                     WHERE p.id IN (" . Database::placeholders($affected) . ")
                       AND NOT EXISTS (SELECT 1 FROM {post_terms} pt JOIN {terms} tt ON tt.id = pt.term_id
                                       WHERE pt.post_id = p.id AND tt.taxonomy = 'category')",
                    [$default, ...$affected]
                );
            }
        });
        do_action('term_deleted', $id, $t['taxonomy']);
        return null;
    }

    /**
     * Tìm hoặc tạo term theo tên (taxonomy không phân cấp, vd. thẻ, diễn viên); trả về danh sách ID.
     * $create = false: chỉ lấy term đã có, tên chưa có thì bỏ qua (người không có quyền quản lý taxonomy đó).
     */
    public static function ensureTerms(string $taxonomy, array $names, bool $create = true): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 191) {
                continue;
            }
            // Collation utf8mb4_unicode_ci bỏ qua dấu ("mã" = "ma" = "mà") nên lọc lại trong PHP:
            // chỉ coi là trùng khi giống hệt, không phân biệt hoa thường.
            $id = null;
            $want = self::nameKey($name);
            foreach (db()->all('SELECT id, name FROM {terms} WHERE taxonomy = ? AND name = ? ORDER BY id', [$taxonomy, $name]) as $r) {
                if (self::nameKey((string) $r['name']) === $want) {
                    $id = (int) $r['id'];
                    break;
                }
            }
            if (!$id && $create) {
                [, $id] = self::save($taxonomy, ['name' => $name]);
            }
            if ($id) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** Khoá so sánh tên term: gộp dạng NFC (chuỗi dán từ macOS hay ở dạng NFD) + chữ thường, giữ nguyên dấu. */
    private static function nameKey(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }
        return mb_strtolower($name, 'UTF-8');
    }

    /** @return array<int, array> term của một bài theo taxonomy */
    public static function forPost(int $postId, string $taxonomy): array
    {
        return db()->all(
            'SELECT t.* FROM {terms} t JOIN {post_terms} pt ON pt.term_id = t.id WHERE pt.post_id = ? AND t.taxonomy = ? ORDER BY t.name',
            [$postId, $taxonomy]
        );
    }
}
