<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Auth;
use LQ\Core\View;

/** Trang admin do plugin đăng ký qua lq_add_admin_page(). */
final class PluginPages
{
    private static array $pages = [];

    public static function add(string $slug, string $title, callable $callback, string $icon, string $cap, string $keywords = ''): void
    {
        self::$pages[$slug] = compact('slug', 'title', 'callback', 'icon', 'cap', 'keywords');
    }

    public static function menuItems(): array
    {
        return array_values(array_map(fn ($p) => [
            'label' => $p['title'],
            'icon' => $p['icon'],
            'url' => '/admin/plugin/' . $p['slug'],
            'cap' => $p['cap'],
            'keywords' => $p['keywords'],
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
        $level = ob_get_level();
        ob_start();
        try {
            ($page['callback'])();
        } catch (\Throwable $e) {
            // Trang plugin ném lỗi: báo lỗi trong khung admin thay vì trang trắng "Uncaught …" (giống cách Widgets xử lý).
            // Dọn mọi tầng buffer plugin mở dở, không thì phần đã echo lọt ra trước <!doctype>.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            \LQ\Core\ErrorLog::add('error', "Trang plugin {$page['slug']} lỗi: " . $e->getMessage(), $e->getFile(), $e->getLine());
            http_response_code(500);
            View::render('admin/error', ['title' => $page['title'], 'code' => 500, 'message' => 'Trang của plugin gặp lỗi khi hiển thị. Chi tiết đã được ghi vào nhật ký lỗi.']);
            return;
        }
        View::render('admin/plugin-page', ['title' => $page['title'], 'body' => (string) ob_get_clean()]);
    }
}
