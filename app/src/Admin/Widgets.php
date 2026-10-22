<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Auth;
use LQ\Core\View;
use Throwable;

/**
 * Hệ thống widget của Bảng tin.
 * - Core và plugin đăng ký widget qua Widgets::register() / lq_add_dashboard_widget().
 * - Mỗi người dùng có bố cục riêng (thứ tự + kích thước), lưu ở option "dashboard_layout_{id}".
 * - Kích thước tính theo lưới 12 cột: 3 = 1/4, 4 = 1/3, 6 = 1/2, 8 = 2/3, 12 = cả hàng.
 */
final class Widgets
{
    public const SIZES = [3 => '1/4', 4 => '1/3', 6 => '1/2', 8 => '2/3', 12 => 'Cả hàng'];
    private const MAX = 40;

    /** @var array<string, array> */
    private static array $widgets = [];
    private static bool $coreLoaded = false;

    /**
     * @param array{title:string, render:callable, description?:string, icon?:string, size?:int, sizes?:int[], cap?:string, default?:bool, group?:string} $def
     */
    public static function register(string $id, array $def): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.\/-]{0,63}$/', $id)) {
            throw new \InvalidArgumentException("ID widget không hợp lệ: $id");
        }
        $sizes = array_values(array_intersect(array_map('intval', $def['sizes'] ?? [4, 6, 8, 12]), array_keys(self::SIZES)));
        $size = (int) ($def['size'] ?? $sizes[0] ?? 4);
        self::$widgets[$id] = [
            'id' => $id,
            'title' => (string) $def['title'],
            'description' => (string) ($def['description'] ?? ''),
            'icon' => (string) ($def['icon'] ?? 'layout-grid'),
            'size' => in_array($size, $sizes, true) ? $size : ($sizes[0] ?? 4),
            'sizes' => $sizes ?: [4],
            'cap' => (string) ($def['cap'] ?? 'access_admin'),
            'default' => (bool) ($def['default'] ?? false),
            'group' => (string) ($def['group'] ?? 'Khác'),
            'source' => self::source(),
            'render' => $def['render'],
        ];
    }

    /**
     * Plugin/theme đã gọi register(): dò ngược call stack tìm tệp nằm trong content/plugins/<slug>/ hoặc content/themes/<slug>/.
     * Nhờ vậy plugin không phải tự khai báo, và đăng ký muộn (trong hook) vẫn nhận đúng.
     * @return array{type:string, slug:string, name:string}
     */
    private static function source(): array
    {
        static $cache = [];
        $roots = ['plugin' => \LQ\Core\Plugins::dir() . '/', 'theme' => \LQ\Core\Themes::dir() . '/'];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            foreach ($roots as $type => $root) {
                if (!str_starts_with($file, $root)) {
                    continue;
                }
                $slug = strstr(substr($file, strlen($root)), '/', true);
                if ($slug === false || $slug === '') {
                    continue;
                }
                $meta = $cache["$type/$slug"] ??= $type === 'plugin' ? \LQ\Core\Plugins::read($slug) : \LQ\Core\Themes::read($slug);
                return ['type' => $type, 'slug' => $slug, 'name' => (string) ($meta['name'] ?? $slug)];
            }
        }
        return ['type' => 'core', 'slug' => '', 'name' => ''];
    }

    /** Widget người dùng hiện tại được phép dùng. */
    public static function available(): array
    {
        self::loadCore();
        return array_filter(self::$widgets, fn ($w) => Auth::can($w['cap']));
    }

    public static function get(string $id): ?array
    {
        return self::available()[$id] ?? null;
    }

    public static function defaults(): array
    {
        $out = [];
        foreach (self::available() as $w) {
            if ($w['default']) {
                $out[] = ['id' => $w['id'], 'size' => $w['size']];
            }
        }
        // Tài khoản chỉ có quyền xem: không có widget mặc định nào, cho lời chào.
        if (!$out && isset(self::available()['welcome'])) {
            $out[] = ['id' => 'welcome', 'size' => 12];
        }
        return $out;
    }

    /** Bố cục đã lưu (bỏ widget không còn tồn tại / không có quyền), hoặc mặc định. */
    public static function layout(int $userId): array
    {
        $saved = option("dashboard_layout_$userId");
        if (!is_array($saved)) {
            return self::defaults();
        }
        return self::sanitize($saved);
    }

    public static function saveLayout(int $userId, array $layout): array
    {
        $clean = self::sanitize($layout);
        update_option("dashboard_layout_$userId", $clean, false);
        return $clean;
    }

    public static function resetLayout(int $userId): void
    {
        \LQ\Core\Options::delete("dashboard_layout_$userId");
    }

    private static function sanitize(array $layout): array
    {
        $available = self::available();
        $seen = [];
        $out = [];
        foreach ($layout as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? '') : '';
            if (!isset($available[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $size = (int) ($item['size'] ?? 0);
            $out[] = ['id' => $id, 'size' => in_array($size, $available[$id]['sizes'], true) ? $size : $available[$id]['size']];
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return $out;
    }

    /** HTML của một widget kèm khung (thanh công cụ khi tuỳ chỉnh). Lỗi trong widget không làm hỏng cả trang. */
    public static function render(string $id, int $size): string
    {
        $w = self::get($id);
        if (!$w) {
            return '';
        }
        $level = ob_get_level();
        ob_start();
        try {
            $ret = ($w['render'])($size);
            if (is_string($ret)) {
                echo $ret;
            }
            $inner = (string) ob_get_clean();
        } catch (Throwable $e) {
            // Dọn hết buffer widget mở dở (vd. View::capture ném lỗi giữa chừng) chứ không chỉ một tầng — sót tầng nào thì
            // phần HTML dở dang của widget bị đẩy lên trước <!doctype> của trang.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            error_log("[LQCMS] Widget $id lỗi: " . $e->getMessage());
            $inner = '<section class="tile"><div class="tile-title"><div><h3>' . e($w['title']) . '</h3><p>Widget gặp lỗi khi hiển thị.</p></div></div></section>';
        }
        return View::capture('admin/widgets/_frame', ['w' => $w, 'size' => $size, 'inner' => $inner]);
    }

    /**
     * Danh sách cho ngăn "Thêm widget", chia nhóm: widget có sẵn trước, rồi theme, rồi mỗi plugin một nhóm (theo tên).
     * @return list<array{key:string, type:string, title:string, items:list<array>}>
     */
    public static function catalog(): array
    {
        $groups = [];
        foreach (self::available() as $w) {
            $src = $w['source'];
            $key = $src['type'] === 'core' ? 'core/' . $w['group'] : $src['type'] . '/' . $src['slug'];
            $groups[$key] ??= ['key' => $key, 'type' => $src['type'], 'title' => $src['type'] === 'core' ? $w['group'] : $src['name'], 'items' => []];
            $groups[$key]['items'][] = [
                'id' => $w['id'],
                'title' => $w['title'],
                'description' => $w['description'],
                'icon' => $w['icon'],
                'size' => $w['size'],
            ];
        }
        $rank = ['core' => 0, 'theme' => 1, 'plugin' => 2];
        // usort giữ nguyên thứ tự khi bằng nhau (PHP 8), nên các nhóm có sẵn giữ thứ tự đăng ký.
        usort($groups, fn ($a, $b) => ($rank[$a['type']] <=> $rank[$b['type']]) ?: ($a['type'] === 'core' ? 0 : strcasecmp($a['title'], $b['title'])));
        return $groups;
    }

    private static function loadCore(): void
    {
        if (self::$coreLoaded) {
            return;
        }
        self::$coreLoaded = true;
        CoreWidgets::register();
    }
}
