<?php
declare(strict_types=1);

namespace LQ\Content;

use InvalidArgumentException;

/**
 * Menu điều hướng: theme khai báo vị trí (lq_register_nav_menu), admin dựng menu ở Giao diện › Menu rồi gán vào vị trí,
 * theme in bằng lq_nav_menu() hoặc tự vẽ từ get_nav_menu_items(). Menu lưu theo tên (option nav_menus) nên đổi theme
 * không mất; vị trí ↔ menu ở option nav_menu_locations.
 *
 * Mục menu: type home | post (bài, trang, loại tuỳ biến — object = id) | term (object = id) | archive (trang danh sách của
 * loại tuỳ biến — object = tên loại) | custom (url). Nhãn để trống = lấy theo đối tượng (tiêu đề bài, tên chuyên mục…).
 * Mục trỏ tới bài chưa đăng / đã xoá, term đã xoá tự ẩn khi hiển thị.
 */
final class Menus
{
    public const TYPES = ['home', 'post', 'term', 'archive', 'custom'];
    private const MAX_ITEMS = 300;
    private const MAX_DEPTH = 5;

    /** @var array<string, string> vị trí => nhãn */
    private static array $locations = [];

    public static function registerLocation(string $location, string $label): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $location)) {
            throw new InvalidArgumentException("Tên vị trí menu không hợp lệ: \"$location\" (chữ thường, số, _ -).");
        }
        self::$locations[$location] = $label;
    }

    public static function locations(): array
    {
        return self::$locations;
    }

    /** @return array<int, array{id: int, name: string, items: list<array>}> */
    public static function all(): array
    {
        $all = option('nav_menus', []);
        return is_array($all) ? $all : [];
    }

    public static function find(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** @return array<string, int> vị trí => id menu */
    public static function assigned(): array
    {
        $a = option('nav_menu_locations', []);
        return is_array($a) ? array_map('intval', $a) : [];
    }

    public static function forLocation(string $location): ?array
    {
        $id = self::assigned()[$location] ?? 0;
        return $id ? self::find($id) : null;
    }

    /**
     * Lưu menu (id 0 = tạo mới) + gán vị trí. $items: cây từ trình dựng menu, được làm sạch.
     * @return array{0: ?string, 1: int} [lỗi, id menu]
     */
    public static function save(int $id, string $name, array $items, array $locations): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            return ['Tên menu không được trống (tối đa 80 ký tự).', $id];
        }
        $all = self::all();
        if ($id && !isset($all[$id])) {
            return ['Menu không còn tồn tại.', $id];
        }
        foreach ($all as $mid => $m) {
            if ($mid !== $id && mb_strtolower($m['name']) === mb_strtolower($name)) {
                return ["Đã có menu tên \"$name\".", $id];
            }
        }
        $count = 0;
        $clean = self::sanitize($items, 1, $count);
        if ($count > self::MAX_ITEMS) {
            return ['Menu có quá nhiều mục (tối đa ' . self::MAX_ITEMS . ').', $id];
        }
        $id = $id ?: (($all ? max(array_keys($all)) : 0) + 1);
        $all[$id] = ['id' => $id, 'name' => $name, 'items' => $clean];
        update_option('nav_menus', $all);

        // Vị trí: tích = gán menu này; bỏ tích vị trí đang gán menu này = gỡ. Vị trí theme không khai báo giữ nguyên.
        $assigned = self::assigned();
        foreach (array_keys(self::$locations) as $loc) {
            if (in_array($loc, $locations, true)) {
                $assigned[$loc] = $id;
            } elseif (($assigned[$loc] ?? 0) === $id) {
                unset($assigned[$loc]);
            }
        }
        update_option('nav_menu_locations', $assigned);
        do_action('nav_menu_saved', $id);
        return [null, $id];
    }

    public static function delete(int $id): void
    {
        $all = self::all();
        unset($all[$id]);
        update_option('nav_menus', $all);
        update_option('nav_menu_locations', array_filter(self::assigned(), fn ($m) => $m !== $id));
    }

    /** Cây mục đã làm sạch (bỏ mục sai dạng, link javascript:, lồng quá sâu). */
    private static function sanitize(array $items, int $depth, int &$count): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it) || !in_array($it['type'] ?? '', self::TYPES, true) || ++$count > self::MAX_ITEMS) {
                continue;
            }
            $type = $it['type'];
            $object = match ($type) {
                'post', 'term' => (int) ($it['object'] ?? 0),
                'archive' => preg_match('/^[a-z][a-z0-9_]{1,19}$/', (string) ($it['object'] ?? '')) ? (string) $it['object'] : '',
                default => null,
            };
            if ((in_array($type, ['post', 'term'], true) && $object <= 0) || ($type === 'archive' && $object === '')) {
                continue;
            }
            $url = '';
            if ($type === 'custom') {
                $url = trim((string) ($it['url'] ?? ''));
                // Chỉ link web, đường dẫn trong site, neo, mailto, tel — chặn javascript:, data:…
                if ($url === '' || mb_strlen($url) > 500 || !preg_match('#^(https?://|/|\#|mailto:|tel:)#i', $url)) {
                    continue;
                }
            }
            $label = mb_substr(trim((string) ($it['label'] ?? '')), 0, 100);
            if ($type === 'custom' && $label === '') {
                $label = $url;
            }
            $out[] = [
                'id' => preg_match('/^[a-z0-9-]{1,24}$/', (string) ($it['id'] ?? '')) ? (string) $it['id'] : bin2hex(random_bytes(4)),
                'type' => $type,
                'object' => $object,
                'label' => $label,
                'url' => $url,
                'new_tab' => !empty($it['new_tab']),
                'classes' => trim((string) preg_replace('/[^a-z0-9 _-]/i', '', mb_substr((string) ($it['classes'] ?? ''), 0, 60))),
                'children' => $depth < self::MAX_DEPTH && is_array($it['children'] ?? null) ? self::sanitize($it['children'], $depth + 1, $count) : [],
            ];
        }
        return $out;
    }

    /**
     * Cây mục để hiển thị: thêm url, label (đã điền theo đối tượng), current / current_ancestor; bỏ mục không còn hợp lệ.
     * $forAdmin = true: giữ cả mục hỏng (đánh dấu 'missing') để trình dựng menu hiện cho người quản trị gỡ.
     */
    public static function resolve(array $items, bool $forAdmin = false): array
    {
        $postIds = $termIds = [];
        $walk = function (array $list) use (&$walk, &$postIds, &$termIds): void {
            foreach ($list as $it) {
                $it['type'] === 'post' && $postIds[] = (int) $it['object'];
                $it['type'] === 'term' && $termIds[] = (int) $it['object'];
                $walk($it['children'] ?? []);
            }
        };
        $walk($items);
        $posts = $postIds ? array_column(db()->all('SELECT * FROM {posts} WHERE id IN (' . \LQ\Core\Database::placeholders($postIds) . ')', $postIds), null, 'id') : [];
        $terms = $termIds ? array_column(db()->all('SELECT * FROM {terms} WHERE id IN (' . \LQ\Core\Database::placeholders($termIds) . ')', $termIds), null, 'id') : [];
        return self::build($items, $posts, $terms, $forAdmin);
    }

    private static function build(array $items, array $posts, array $terms, bool $forAdmin): array
    {
        $out = [];
        $q = \LQ\Front\Front::main();
        $queried = $q->queried ?? null;
        $path = rtrim(request_path(), '/') ?: '/';
        foreach ($items as $it) {
            $missing = false;
            $current = false;
            $url = '';
            $auto = '';
            $kind = '';
            switch ($it['type']) {
                case 'home':
                    $url = home_url('/');
                    $auto = 'Trang chủ';
                    $kind = 'Trang chủ';
                    $current = !empty($q->is['front_page']);
                    break;
                case 'post':
                    $p = $posts[$it['object']] ?? null;
                    $live = $p && $p['status'] === 'published' && $p['published_at'] <= now_utc() && ($t = Posts::type($p['type'])) && $t['public'];
                    $missing = !$live;
                    if ($p) {
                        $url = $live ? Posts::permalink($p) : '';
                        $auto = $p['title'] ?: '(Không tiêu đề)';
                        $kind = lq_ucfirst(Posts::type($p['type'])['singular'] ?? $p['type']);
                        $current = !empty($q->is['singular']) && (int) ($queried['id'] ?? 0) === (int) $p['id']
                            || $p['type'] === 'page' && !empty($q->is['home']) && (int) option('page_for_posts', 0) === (int) $p['id'];
                    }
                    break;
                case 'term':
                    $t = $terms[$it['object']] ?? null;
                    $missing = !$t || !Taxonomies::get($t['taxonomy']);
                    if ($t) {
                        $url = $missing ? '' : Terms::url($t);
                        $auto = $t['name'];
                        $kind = lq_ucfirst(Taxonomies::get($t['taxonomy'])['singular'] ?? $t['taxonomy']);
                        $current = (!empty($q->is['category']) || !empty($q->is['tag']) || !empty($q->is['tax'])) && (int) ($queried['id'] ?? 0) === (int) $t['id'];
                    }
                    break;
                case 'archive':
                    $pt = Posts::type((string) $it['object']);
                    $url = get_post_type_archive_link((string) $it['object']);
                    $missing = $url === '';
                    $auto = $pt['label'] ?? (string) $it['object'];
                    $kind = 'Danh sách';
                    $current = !empty($q->is['post_type_archive']) && ($queried['key'] ?? '') === $it['object'];
                    break;
                default: // custom
                    $url = $it['url'];
                    $kind = 'Liên kết';
                    $u = parse_url($url);
                    $sameSite = !isset($u['host']) || strcasecmp($u['host'], (string) parse_url(site_url('/'), PHP_URL_HOST)) === 0;
                    $current = $sameSite && isset($u['path']) && (rtrim(rawurldecode($u['path']), '/') ?: '/') === $path && !isset($u['fragment']);
            }
            $children = self::build($it['children'] ?? [], $posts, $terms, $forAdmin);
            if ($missing && !$forAdmin) {
                // Mục hỏng ẩn đi, nhưng mục con của nó vẫn lên thay chỗ (không mất cả nhánh).
                array_push($out, ...$children);
                continue;
            }
            // Dữ liệu đã resolve đứng trước để ghi đè khoá trùng của $it (nhất là children thô → children đã resolve).
            $out[] = [
                'url_resolved' => $url,
                'label_resolved' => $it['label'] !== '' ? $it['label'] : $auto,
                'auto_label' => $auto,
                'kind' => $kind,
                'missing' => $missing,
                'current' => $current,
                'current_ancestor' => (bool) array_filter($children, fn ($c) => $c['current'] || $c['current_ancestor']),
                'children' => $children,
            ] + $it;
        }
        return $out;
    }

    /** HTML <ul> của menu cho lq_nav_menu(). */
    public static function html(array $items, array $args, int $depth = 1): string
    {
        $max = (int) ($args['depth'] ?? 0);
        $li = '';
        foreach ($items as $it) {
            $kids = $it['children'] && ($max === 0 || $depth < $max) ? $it['children'] : [];
            $cls = array_filter(['menu-item', 'menu-item-' . $it['type'], $it['current'] ? 'is-current' : '', $it['current_ancestor'] ? 'is-current-ancestor' : '',
                $kids ? 'has-children' : '', $it['classes']]);
            $li .= '<li class="' . e(implode(' ', $cls)) . '"><a href="' . e($it['url_resolved']) . '"'
                . ($it['current'] ? ' aria-current="page"' : '')
                . ($it['new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($it['label_resolved']) . '</a>'
                . ($kids ? self::html($kids, $args, $depth + 1) : '') . '</li>';
        }
        if ($li === '') {
            return '';
        }
        if ($depth > 1) {
            return '<ul class="sub-menu">' . $li . '</ul>';
        }
        $attrs = ' class="' . e((string) ($args['class'] ?? 'menu')) . '"' . (($args['id'] ?? '') !== '' ? ' id="' . e($args['id']) . '"' : '');
        return '<ul' . $attrs . '>' . $li . '</ul>';
    }
}
