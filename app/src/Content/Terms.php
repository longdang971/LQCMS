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
            $params[] = "%$search%";
            $params[] = "%$search%";
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
        $parts = [];
        $seen = [];
        while ($id && !isset($seen[$id]) && ($t = self::find($id))) {
            $seen[$id] = true;
            array_unshift($parts, $t['slug']);
            $id = (int) $t['parent_id'];
        }
        return implode('/', $parts);
    }

    public static function uniqueSlug(string $taxonomy, string $slug, int $exceptId = 0): string
    {
        return Slug::unique($slug, fn ($s) => (bool) db()->value(
            'SELECT 1 FROM {terms} WHERE taxonomy = ? AND slug = ? AND id <> ?',
            [$taxonomy, $s, $exceptId]
        ));
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
            'slug' => self::uniqueSlug($taxonomy, $slug, $id),
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

    /** Tìm hoặc tạo term theo tên (taxonomy không phân cấp, vd. thẻ, diễn viên); trả về danh sách ID. */
    public static function ensureTerms(string $taxonomy, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '' || mb_strlen($name) > 191) {
                continue;
            }
            $id = db()->value('SELECT id FROM {terms} WHERE taxonomy = ? AND name = ?', [$taxonomy, $name]);
            if (!$id) {
                [, $id] = self::save($taxonomy, ['name' => $name]);
            }
            if ($id) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function ensureTags(array $names): array
    {
        return self::ensureTerms('tag', $names);
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
