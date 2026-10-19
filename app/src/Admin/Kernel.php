<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Auth;
use LQ\Core\Csrf;
use LQ\Core\Router;
use LQ\Core\View;

final class Kernel
{
    /** Route không cần đăng nhập. */
    private const PUBLIC_ROUTES = ['/admin/login', '/admin/register'];

    public static function handle(string $path): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');

        $r = new Router();
        $r->pattern('tax', implode('|', array_map(fn ($t) => preg_quote($t['route'], '#'), \LQ\Content\Taxonomies::all())));
        $r->get('/admin/login', [AuthController::class, 'loginForm']);
        $r->post('/admin/login', [AuthController::class, 'login']);
        $r->get('/admin/register', [AuthController::class, 'registerForm']);
        $r->post('/admin/register', [AuthController::class, 'register']);
        $r->post('/admin/logout', [AuthController::class, 'logout']);

        $r->get('/admin', [DashboardController::class, 'index']);
        $r->post('/admin/quick-draft', [DashboardController::class, 'quickDraft']);
        $r->get('/admin/api/search', [DashboardController::class, 'search']);
        $r->post('/admin/dashboard/layout', [DashboardController::class, 'saveLayout']);
        $r->post('/admin/dashboard/reset', [DashboardController::class, 'resetLayout']);
        $r->get('/admin/dashboard/widget', [DashboardController::class, 'widget']);

        // Bài viết (/admin/posts) và trang tĩnh (/admin/pages) dùng chung controller.
        foreach (\LQ\Content\Posts::TYPES as $type => $t) {
            $b = '/admin/' . $t['route'];
            $h = fn (string $m) => fn (array $p) => PostsController::{$m}($p + ['type' => $type]);
            $r->get($b, $h('index'));
            $r->get("$b/new", $h('create'));
            $r->get("$b/{id}/edit", $h('edit'));
            $r->post("$b/save", $h('save'));
            $r->post("$b/{id}/trash", $h('trash'));
            $r->post("$b/{id}/restore", $h('restore'));
            $r->post("$b/{id}/delete", $h('destroy'));
            $r->post("$b/bulk", $h('bulk'));
            $r->post("$b/empty-trash", $h('emptyTrash'));
        }
        $r->post('/admin/upload', [PostsController::class, 'upload']);

        $r->get('/admin/media', [MediaController::class, 'index']);
        $r->get('/admin/api/media', [MediaController::class, 'list']);
        $r->get('/admin/api/media/{id}', [MediaController::class, 'show']);
        $r->post('/admin/media/upload', [MediaController::class, 'upload']);
        $r->post('/admin/media/{id}/update', [MediaController::class, 'update']);
        $r->post('/admin/media/{id}/delete', [MediaController::class, 'destroy']);
        $r->post('/admin/media/bulk-delete', [MediaController::class, 'bulkDelete']);

        $r->get('/admin/{tax}', [TermsController::class, 'index']);
        $r->get('/admin/{tax}/{id}/edit', [TermsController::class, 'edit']);
        $r->post('/admin/{tax}/save', [TermsController::class, 'save']);
        $r->post('/admin/{tax}/{id}/delete', [TermsController::class, 'destroy']);
        $r->get('/admin/tags/search', [TermsController::class, 'searchTags']);
        $r->get('/admin/api/terms', [TermsController::class, 'searchTags']);

        $r->get('/admin/themes', [ThemesController::class, 'index']);
        $r->post('/admin/themes/activate', [ThemesController::class, 'activate']);
        $r->post('/admin/themes/delete', [ThemesController::class, 'destroy']);
        $r->post('/admin/themes/upload', [ThemesController::class, 'upload']);

        $r->get('/admin/plugins', [PluginsController::class, 'index']);
        $r->post('/admin/plugins/activate', [PluginsController::class, 'activate']);
        $r->post('/admin/plugins/deactivate', [PluginsController::class, 'deactivate']);
        $r->post('/admin/plugins/delete', [PluginsController::class, 'destroy']);
        $r->post('/admin/plugins/upload', [PluginsController::class, 'upload']);
        $r->get('/admin/plugin/{slug}', [PluginPages::class, 'render']);

        $r->get('/admin/settings', [SettingsController::class, 'general']);
        $r->post('/admin/settings', [SettingsController::class, 'saveGeneral']);
        $r->post('/admin/settings/cron-run', [SettingsController::class, 'runCron']);
        $r->get('/admin/settings/permalinks', [SettingsController::class, 'permalinks']);
        $r->post('/admin/settings/permalinks', [SettingsController::class, 'savePermalinks']);

        $r->get('/admin/profile', [ProfileController::class, 'edit']);
        $r->post('/admin/profile', [ProfileController::class, 'save']);

        do_action('admin_routes', $r);

        $method = request_method();
        $match = $r->match($method, $path);

        if (!in_array($path, self::PUBLIC_ROUTES, true)) {
            if (!Auth::user()) {
                if (wants_json()) {
                    json_response(['ok' => false, 'message' => 'Phiên đăng nhập đã hết hạn.'], 401);
                }
                redirect('/admin/login' . ($method === 'GET' && $path !== '/admin' ? '?redirect=' . rawurlencode($_SERVER['REQUEST_URI']) : ''));
            }
            if (!Auth::can('access_admin')) {
                self::forbidden();
            }
        }
        if ($method === 'POST' && !Csrf::valid()) {
            if (wants_json()) {
                json_response(['ok' => false, 'message' => 'Phiên làm việc đã hết hạn, hãy tải lại trang.'], 419);
            }
            flash('error', 'Phiên làm việc đã hết hạn, vui lòng thử lại.');
            redirect_back('/admin');
        }

        if ($match === null) {
            self::notFound();
        }
        if ($match === 'method_not_allowed') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }
        [$handler, $params] = $match;
        is_array($handler) ? $handler[0]::{$handler[1]}($params) : $handler($params);
    }

    /** Dừng nếu thiếu quyền. */
    public static function require(string $cap): void
    {
        if (!Auth::can($cap)) {
            self::forbidden();
        }
    }

    public static function forbidden(): never
    {
        http_response_code(403);
        if (wants_json()) {
            json_response(['ok' => false, 'message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
        }
        View::render('admin/error', ['title' => 'Không có quyền', 'code' => 403, 'message' => 'Bạn không có quyền truy cập trang này.']);
        exit;
    }

    public static function notFound(): never
    {
        http_response_code(404);
        View::render('admin/error', ['title' => 'Không tìm thấy', 'code' => 404, 'message' => 'Trang bạn tìm không tồn tại hoặc đã bị xoá.']);
        exit;
    }

    /** Menu sidebar; plugin có thể thêm/sửa qua filter "admin_menu". */
    public static function menu(): array
    {
        $menu = [
            ['label' => 'Bảng tin', 'icon' => 'layout-dashboard', 'url' => '/admin', 'cap' => 'access_admin', 'exact' => true],
            ['label' => 'Bài viết', 'icon' => 'file-text', 'url' => '/admin/posts', 'cap' => 'edit_posts', 'children' => [
                ['label' => 'Tất cả bài viết', 'url' => '/admin/posts', 'cap' => 'edit_posts', 'exact' => true, 'match' => ['#^/admin/posts/\\d+/edit$#']],
                ['label' => 'Viết bài mới', 'url' => '/admin/posts/new', 'cap' => 'edit_posts', 'exact' => true],
                ['label' => 'Chuyên mục', 'url' => '/admin/categories', 'cap' => 'manage_terms'],
                ['label' => 'Thẻ', 'url' => '/admin/tags', 'cap' => 'manage_terms'],
                ...array_values(array_map(fn ($t) => ['label' => $t['label'], 'url' => '/admin/' . $t['route'], 'cap' => $t['cap']],
                    array_filter(\LQ\Content\Taxonomies::custom(), fn ($t) => $t['show_in_menu']))),
            ]],
            ['label' => 'Trang', 'icon' => 'files', 'url' => '/admin/pages', 'cap' => 'edit_pages', 'children' => [
                ['label' => 'Tất cả trang', 'url' => '/admin/pages', 'cap' => 'edit_pages', 'exact' => true, 'match' => ['#^/admin/pages/\\d+/edit$#']],
                ['label' => 'Thêm trang mới', 'url' => '/admin/pages/new', 'cap' => 'edit_pages', 'exact' => true],
            ]],
            ['label' => 'Media', 'icon' => 'images', 'url' => '/admin/media', 'cap' => 'upload_files'],
            ['section' => 'Hệ thống'],
            ['label' => 'Giao diện', 'icon' => 'palette', 'url' => '/admin/themes', 'cap' => 'manage_themes'],
            ['label' => 'Plugin', 'icon' => 'puzzle', 'url' => '/admin/plugins', 'cap' => 'manage_plugins', 'badge' => count(\LQ\Core\Plugins::activeSlugs()) ?: null],
            ['label' => 'Cài đặt', 'icon' => 'settings', 'url' => '/admin/settings', 'cap' => 'manage_options', 'children' => [
                ['label' => 'Tổng quan', 'url' => '/admin/settings', 'cap' => 'manage_options', 'exact' => true],
                ['label' => 'Đường dẫn tĩnh', 'url' => '/admin/settings/permalinks', 'cap' => 'manage_options'],
            ]],
            ['label' => 'Hồ sơ', 'icon' => 'user-round', 'url' => '/admin/profile', 'cap' => 'access_admin'],
        ];

        $pages = PluginPages::menuItems();
        if ($pages) {
            array_splice($menu, 2, 0, [['section' => 'Plugin'], ...$pages]);
        }
        $menu = apply_filters('admin_menu', $menu);

        // Lọc theo quyền.
        $out = [];
        foreach ($menu as $item) {
            if (isset($item['section'])) {
                $out[] = $item;
                continue;
            }
            if (!Auth::can($item['cap'] ?? 'manage_options')) {
                continue;
            }
            $item['children'] = array_values(array_filter($item['children'] ?? [], fn ($c) => Auth::can($c['cap'] ?? 'manage_options')));
            $out[] = $item;
        }
        // Bỏ tiêu đề nhóm không còn mục nào phía sau.
        $clean = [];
        foreach ($out as $i => $item) {
            if (isset($item['section']) && (!isset($out[$i + 1]) || isset($out[$i + 1]['section']))) {
                continue;
            }
            $clean[] = $item;
        }
        return $clean;
    }

    public static function isActive(array $item, string $path): bool
    {
        if ($path === $item['url']) {
            return true;
        }
        foreach ($item['match'] ?? [] as $re) {
            if (preg_match($re, $path)) {
                return true;
            }
        }
        foreach ($item['children'] ?? [] as $c) {
            if (self::isActive($c, $path)) {
                return true;
            }
        }
        return empty($item['exact']) && $item['url'] !== '/admin' && str_starts_with($path, $item['url'] . '/');
    }
}
