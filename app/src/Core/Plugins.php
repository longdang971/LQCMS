<?php
declare(strict_types=1);

namespace LQ\Core;

use Throwable;

/**
 * Plugin nằm ở content/plugins/<slug>/<slug>.php, đầu file có khối chú thích:
 *   /**
 *    * Plugin Name: Tên plugin
 *    * Description: Mô tả
 *    * Version: 1.0.0
 *    * Author: Tên tác giả
 *    * Icon: cloud           (tuỳ chọn) tên icon Lucide hiện cạnh tên trong danh sách plugin
 *    *\/
 * Muốn dùng logo riêng thì đặt icon.svg / icon.png / icon.webp / icon.jpg trong thư mục plugin (ưu tiên hơn "Icon:").
 * Khi kích hoạt / tắt, hệ thống gọi action "activate_<slug>" / "deactivate_<slug>".
 */
final class Plugins
{
    private const HEADERS = [
        'name' => 'Plugin Name',
        'description' => 'Description',
        'version' => 'Version',
        'author' => 'Author',
        'author_url' => 'Author URI',
        'requires_php' => 'Requires PHP',
        'icon' => 'Icon',
    ];
    private const ICON_FILES = ['icon.svg', 'icon.png', 'icon.webp', 'icon.jpg'];

    public static function dir(): string
    {
        return LQ_CONTENT . '/plugins';
    }

    /** @return string[] */
    public static function activeSlugs(): array
    {
        return array_values(array_filter((array) option('active_plugins', []), 'is_string'));
    }

    public static function isActive(string $slug): bool
    {
        return in_array($slug, self::activeSlugs(), true);
    }

    public static function mainFile(string $slug): string
    {
        return self::dir() . "/$slug/$slug.php";
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        $list = [];
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);
            if ($meta = self::read($slug)) {
                $list[$slug] = $meta + ['active' => self::isActive($slug)];
            }
        }
        uasort($list, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $list;
    }

    public static function read(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            return null;
        }
        $meta = self::parseHeader(self::mainFile($slug));
        if (!$meta) {
            return null;
        }
        // Tên icon Lucide chỉ gồm chữ thường, số, gạch nối — sai dạng thì bỏ, dùng icon mặc định.
        if (!preg_match('/^[a-z0-9-]{1,40}$/', $meta['icon'])) {
            $meta['icon'] = '';
        }
        $meta['icon_url'] = '';
        foreach (self::ICON_FILES as $f) {
            if (is_file($file = self::dir() . "/$slug/$f")) {
                $meta['icon_url'] = "/content/plugins/$slug/$f?v=" . filemtime($file);
                break;
            }
        }
        return ['slug' => $slug] + $meta;
    }

    private static function parseHeader(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $head = (string) file_get_contents($file, false, null, 0, 8192);
        $meta = [];
        foreach (self::HEADERS as $key => $label) {
            /* Bỏ dấu đóng chú thích (hoặc thẻ đóng PHP) cùng dòng: một dòng "Plugin Name: Foo" kết thúc bằng dấu đóng → "Foo". */
            $meta[$key] = preg_match('/^[\s\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi', $head, $m) ? trim(preg_replace('/\*\/.*|\?>.*/', '', $m[1])) : '';
        }
        return $meta['name'] !== '' ? $meta : null;
    }

    /** Nạp plugin đang kích hoạt; plugin nào gây lỗi thì bị tắt tự động để admin không chết theo. */
    public static function loadActive(): void
    {
        $active = self::activeSlugs();
        $broken = [];
        foreach ($active as $slug) {
            $file = self::mainFile($slug);
            if (!preg_match('/^[a-z0-9_-]+$/', $slug) || !is_file($file)) {
                $broken[] = $slug;
                continue;
            }
            try {
                require_once $file;
            } catch (Throwable $e) {
                $broken[] = $slug;
                error_log("[LQCMS] Plugin $slug lỗi khi nạp, đã tự tắt: " . $e->getMessage());
            }
        }
        if ($broken) {
            update_option('active_plugins', array_values(array_diff($active, $broken)));
        }
    }

    public static function activate(string $slug): ?string
    {
        $meta = self::read($slug);
        if (!$meta) {
            return 'Plugin không tồn tại hoặc thiếu khối thông tin "Plugin Name".';
        }
        if (self::isActive($slug)) {
            return null;
        }
        if ($meta['requires_php'] !== '' && version_compare(PHP_VERSION, $meta['requires_php'], '<')) {
            return "Plugin cần PHP {$meta['requires_php']} trở lên (máy chủ đang chạy " . PHP_VERSION . ').';
        }
        try {
            require_once self::mainFile($slug);
            Hooks::runStrict("activate_$slug"); // không qua do_action: lỗi plugin ở đó bị nuốt, mà ở đây lỗi = từ chối kích hoạt
        } catch (Throwable $e) {
            return 'Plugin gây lỗi khi kích hoạt: ' . $e->getMessage();
        }
        update_option('active_plugins', [...self::activeSlugs(), $slug]);
        return null;
    }

    public static function deactivate(string $slug): void
    {
        if (!self::isActive($slug)) {
            return;
        }
        try {
            do_action("deactivate_$slug");
        } catch (Throwable $e) {
            error_log("[LQCMS] deactivate_$slug lỗi: " . $e->getMessage());
        }
        update_option('active_plugins', array_values(array_diff(self::activeSlugs(), [$slug])));
    }

    /** Tên + phiên bản của plugin đã giải nén (chưa cài) — cho trang kiểm tra trước khi cài. */
    public static function describePackage(string $dir): array
    {
        return self::parseHeader("$dir/" . basename($dir) . '.php') ?? [];
    }

    public static function validatePackage(string $dir): ?string
    {
        $slug = basename($dir);
        $file = "$dir/" . basename($dir) . '.php';
        if (!is_file($file)) {
            return "Plugin phải có tệp chính $slug/$slug.php.";
        }
        return self::parseHeader($file) ? null : 'Tệp chính thiếu khối thông tin "Plugin Name:".';
    }
}
