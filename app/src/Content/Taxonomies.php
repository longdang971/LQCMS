<?php
declare(strict_types=1);

namespace LQ\Content;

use InvalidArgumentException;

/**
 * Sổ đăng ký taxonomy (cách phân loại bài viết). Có sẵn "category" và "tag";
 * theme/plugin thêm loại mới qua lq_register_taxonomy(), vd. "Diễn viên", "Đạo diễn", "Hãng sản xuất".
 * Term của mọi taxonomy dùng chung bảng terms (phân biệt bằng cột taxonomy).
 */
final class Taxonomies
{
    /** @var array<string, array> */
    private static array $list = [];
    private const RESERVED_ROUTES = ['posts', 'themes', 'plugins', 'plugin', 'settings', 'profile', 'login', 'register', 'logout', 'upload', 'api', 'dashboard', 'quick-draft', 'terms', 'pages', 'media'];

    public static function register(string $key, array $args): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key)) {
            throw new InvalidArgumentException("Tên taxonomy không hợp lệ: \"$key\" (chữ thường, số, gạch dưới; 2–32 ký tự).");
        }
        if (empty($args['_builtin']) && in_array($key, ['category', 'tag', 'post', 'page'], true)) {
            throw new InvalidArgumentException("Tên taxonomy \"$key\" đã được hệ thống dùng.");
        }
        $route = (string) ($args['route'] ?? str_replace('_', '-', $key));
        if (empty($args['_builtin']) && in_array($route, ['categories', 'tags'], true)) {
            throw new InvalidArgumentException("Đường dẫn admin \"$route\" đã được hệ thống dùng.");
        }
        if (!preg_match('/^[a-z0-9-]+$/', $route) || in_array($route, self::RESERVED_ROUTES, true)) {
            throw new InvalidArgumentException("Đường dẫn admin \"$route\" của taxonomy $key không hợp lệ hoặc trùng đường dẫn hệ thống.");
        }
        foreach (self::$list as $k => $t) {
            if ($k !== $key && $t['route'] === $route) {
                throw new InvalidArgumentException("Đường dẫn admin \"$route\" đã được taxonomy $k dùng.");
            }
        }
        $label = (string) ($args['label'] ?? ucfirst($key));
        $hier = (bool) ($args['hierarchical'] ?? false);
        self::$list[$key] = [
            'key' => $key,
            'label' => $label,
            'singular' => (string) ($args['singular'] ?? mb_strtolower($label)),
            'route' => $route,
            'hierarchical' => $hier,
            'icon' => (string) ($args['icon'] ?? ($hier ? 'folder-tree' : 'tags')),
            'description' => (string) ($args['description'] ?? ''),
            'placeholder' => (string) ($args['placeholder'] ?? ''),
            'base' => trim((string) ($args['base'] ?? $route), '/'), // tiền tố URL frontend, vd. /dien-vien/ten ("" = không tiền tố)
            'cap' => (string) ($args['cap'] ?? 'manage_terms'),     // quyền quản lý danh sách
            'show_in_menu' => (bool) ($args['show_in_menu'] ?? true),
            'list_column' => (bool) ($args['list_column'] ?? false),// cột trong danh sách bài viết
            'context' => in_array($args['context'] ?? 'side', ['side', 'main'], true) ? ($args['context'] ?? 'side') : 'side',
            'builtin' => (bool) ($args['_builtin'] ?? false),
        ];
    }

    public static function all(): array
    {
        self::boot();
        return self::$list;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function byRoute(string $route): ?array
    {
        foreach (self::all() as $t) {
            if ($t['route'] === $route) {
                return $t;
            }
        }
        return null;
    }

    /** Taxonomy do theme/plugin thêm (không gồm chuyên mục, thẻ). */
    public static function custom(): array
    {
        return array_filter(self::all(), fn ($t) => !$t['builtin']);
    }

    private static function boot(): void
    {
        if (isset(self::$list['category'])) {
            return;
        }
        $custom = self::$list;
        self::$list = [];
        // Tiền tố chuyên mục/thẻ lấy từ Cài đặt › Đường dẫn tĩnh; chuỗi rỗng = không tiền tố (/the-loai/).
        self::register('category', ['label' => 'Chuyên mục', 'singular' => 'chuyên mục', 'route' => 'categories', 'hierarchical' => true, 'base' => (string) option('category_base', 'chuyen-muc'), '_builtin' => true, 'list_column' => true]);
        self::register('tag', ['label' => 'Thẻ', 'singular' => 'thẻ', 'route' => 'tags', 'hierarchical' => false, 'base' => (string) option('tag_base', 'the'), '_builtin' => true, 'list_column' => true]);
        self::$list += $custom;
    }
}
