<?php
declare(strict_types=1);

namespace LQ\Core;

/**
 * Theme nằm ở public/content/themes/<slug>/ và cần:
 *   theme.json   {"name","description","version","author","screenshot"}
 *   index.php    template mặc định
 *   functions.php (tuỳ chọn) — nạp mỗi request khi theme đang kích hoạt, giống WordPress.
 */
final class Themes
{
    public static function dir(): string
    {
        return LQ_CONTENT . '/themes';
    }

    public static function active(): string
    {
        return (string) option('active_theme', 'lq-default');
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        $themes = [];
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);
            if ($meta = self::read($slug)) {
                $themes[$slug] = $meta;
            }
        }
        ksort($themes);
        // Theme đang dùng lên đầu.
        $active = self::active();
        if (isset($themes[$active])) {
            $themes = [$active => $themes[$active]] + $themes;
        }
        return $themes;
    }

    public static function read(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            return null;
        }
        $path = self::dir() . "/$slug";
        $json = @file_get_contents("$path/theme.json");
        if ($json === false || !is_file("$path/index.php")) {
            return null;
        }
        $m = json_decode($json, true);
        if (!is_array($m) || empty($m['name'])) {
            return null;
        }
        $shot = isset($m['screenshot']) && is_file("$path/" . basename((string) $m['screenshot']))
            ? "/content/themes/$slug/" . basename((string) $m['screenshot'])
            : null;
        return [
            'slug' => $slug,
            'name' => (string) $m['name'],
            'description' => (string) ($m['description'] ?? ''),
            'version' => (string) ($m['version'] ?? ''),
            'author' => (string) ($m['author'] ?? ''),
            'author_url' => (string) ($m['author_url'] ?? ''),
            'screenshot' => $shot,
            'tags' => array_values(array_filter((array) ($m['tags'] ?? []), 'is_string')),
        ];
    }

    public static function validatePackage(string $dir): ?string
    {
        if (!is_file("$dir/theme.json") || !is_file("$dir/index.php")) {
            return 'Theme phải có theme.json và index.php ở thư mục gốc.';
        }
        $m = json_decode((string) file_get_contents("$dir/theme.json"), true);
        return is_array($m) && !empty($m['name']) ? null : 'theme.json không hợp lệ (thiếu "name").';
    }

    public static function loadActiveFunctions(): void
    {
        $file = self::dir() . '/' . self::active() . '/functions.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    /** Frontend chưa làm: tạm giao toàn bộ request cho index.php của theme. */
    public static function renderFront(string $path): void
    {
        $theme = self::active();
        $file = self::dir() . "/$theme/index.php";
        if (!is_file($file)) {
            http_response_code(500);
            echo 'Theme đang kích hoạt không tồn tại. Vào /admin/themes để chọn theme khác.';
            return;
        }
        $lq = [
            'path' => $path,
            'site_title' => option('site_title', 'LQCMS'),
            'tagline' => option('tagline', ''),
            'theme_url' => "/content/themes/$theme",
        ];
        require $file;
    }
}
