<?php
declare(strict_types=1);

namespace LQ\Content;

use InvalidArgumentException;

/**
 * Trang tĩnh (posts.type = 'page'): cây cha/con, đường dẫn /cha/con/, và mẫu trang do theme khai báo.
 *
 * Theme thêm mẫu trang riêng:
 *   lq_register_page_template('contact', [
 *       'label' => 'Trang liên hệ',
 *       'file' => 'templates/contact.php',          // tệp trong thư mục theme, frontend sẽ dùng để hiển thị
 *       'fields' => ['contact_phone' => ['label' => 'Số điện thoại', 'type' => 'text']],
 *   ]);
 * Field của mẫu chỉ hiện (và chỉ được lưu) khi trang chọn đúng mẫu đó.
 */
final class Pages
{
    /** @var array<string, array> */
    private static array $templates = [];

    public static function registerTemplate(string $key, array $args): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $key)) {
            throw new InvalidArgumentException("Tên mẫu trang không hợp lệ: \"$key\" (chữ thường, số, -, _).");
        }
        $file = ltrim((string) ($args['file'] ?? "page-$key.php"), '/');
        if (str_contains($file, '..')) {
            throw new InvalidArgumentException("Đường dẫn tệp mẫu trang không hợp lệ: $file");
        }
        $label = (string) ($args['label'] ?? $key);
        self::$templates[$key] = [
            'key' => $key,
            'label' => $label,
            'description' => (string) ($args['description'] ?? ''),
            'file' => $file,
            'source' => (string) ($args['source'] ?? self::caller()),
        ];
        // Field riêng của mẫu: gắn vào trang và chỉ áp dụng khi chọn mẫu này.
        foreach ((array) ($args['fields'] ?? []) as $fieldKey => $field) {
            PostFields::register((string) $fieldKey, $field + [
                'post_type' => 'page',
                'template' => $key,
                'box' => $label,
            ]);
        }
    }

    /** @return array<string, array> mẫu trang + cờ file_exists (theme có tệp đó chưa) */
    public static function templates(): array
    {
        $themeDir = \LQ\Core\Themes::dir() . '/' . \LQ\Core\Themes::active();
        $out = [];
        foreach (self::$templates as $k => $t) {
            $out[$k] = $t + ['file_exists' => is_file("$themeDir/{$t['file']}")];
        }
        return $out;
    }

    public static function template(?string $key): ?array
    {
        return $key ? (self::templates()[$key] ?? null) : null;
    }

    /** Tất cả trang (trừ thùng rác) xếp theo cây: cha trước, con sau, theo thứ tự rồi tên. */
    public static function tree(?string $status = null, string $q = ''): array
    {
        $where = ["p.type = 'page'"];
        $params = [];
        if ($status) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        } else {
            $where[] = "p.status <> 'trash'";
        }
        if ($q !== '') {
            $where[] = '(p.title LIKE ? OR p.slug LIKE ?)';
            array_push($params, '%' . addcslashes($q, '%_\\') . '%', '%' . (\LQ\Core\Slug::make($q) ?: $q) . '%');
        }
        $rows = db()->all(
            'SELECT p.*, u.display_name AS author_name FROM {posts} p LEFT JOIN {users} u ON u.id = p.author_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY p.menu_order, p.title',
            $params
        );
        // Khi lọc/tìm kiếm thì danh sách có thể thiếu cha: những trang đó coi như cấp gốc.
        $ids = array_flip(array_column($rows, 'id'));
        $byParent = [];
        foreach ($rows as $r) {
            $parent = $r['parent_id'] && isset($ids[$r['parent_id']]) ? (int) $r['parent_id'] : 0;
            $byParent[$parent][] = $r;
        }
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $r) {
                $out[] = $r + ['depth' => $depth];
                $walk((int) $r['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    /** ID trang và mọi trang con cháu (chặn chọn cha là chính nó hoặc con của nó). */
    public static function descendantIds(int $id): array
    {
        $children = [];
        foreach (db()->all("SELECT id, parent_id FROM {posts} WHERE type = 'page'") as $r) {
            $children[(int) $r['parent_id']][] = (int) $r['id'];
        }
        $result = [$id];
        for ($i = 0; $i < count($result); $i++) {
            foreach ($children[$result[$i]] ?? [] as $c) {
                $result[] = $c;
            }
        }
        return $result;
    }

    /** Đường dẫn đầy đủ "cha/con" của trang. */
    public static function path(array $page): string
    {
        $parts = [$page['slug']];
        $seen = [(int) $page['id'] => true];
        $parent = (int) ($page['parent_id'] ?? 0);
        while ($parent && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $row = db()->row("SELECT id, slug, parent_id FROM {posts} WHERE id = ? AND type = 'page'", [$parent]);
            if (!$row) {
                break;
            }
            array_unshift($parts, $row['slug']);
            $parent = (int) $row['parent_id'];
        }
        return implode('/', $parts);
    }

    private static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
            $file = $f['file'] ?? '';
            if (str_contains($file, '/content/themes/')) {
                return 'theme';
            }
            if (str_contains($file, '/content/plugins/')) {
                return 'plugin';
            }
        }
        return 'core';
    }
}
