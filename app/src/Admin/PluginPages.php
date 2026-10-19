<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Auth;
use LQ\Core\View;

/** Trang admin do plugin đăng ký qua lq_add_admin_page(). */
final class PluginPages
{
    private static array $pages = [];

    public static function add(string $slug, string $title, callable $callback, string $icon, string $cap): void
    {
        self::$pages[$slug] = compact('slug', 'title', 'callback', 'icon', 'cap');
    }

    public static function menuItems(): array
    {
        return array_values(array_map(fn ($p) => [
            'label' => $p['title'],
            'icon' => $p['icon'],
            'url' => '/admin/plugin/' . $p['slug'],
            'cap' => $p['cap'],
        ], self::$pages));
    }

    public static function render(array $params): void
    {
        $page = self::$pages[$params['slug']] ?? null;
        if (!$page) {
            Kernel::notFound();
        }
        if (!Auth::can($page['cap'])) {
            Kernel::forbidden();
        }
        ob_start();
        ($page['callback'])();
        View::render('admin/plugin-page', ['title' => $page['title'], 'body' => (string) ob_get_clean()]);
    }
}
