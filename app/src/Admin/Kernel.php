<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Auth;
use LQ\Core\Csrf;
use LQ\Core\Router;
use LQ\Core\View;

final class Kernel
{
    /**
     * Nội dung chính của từng trang admin — để thanh ⌘K tìm được trang theo thứ cần chỉnh ("múi giờ", "kích thước ảnh")
     * và để trợ lý AI dẫn link đúng trang. Thêm trang mới có cài đặt riêng thì bổ sung ở đây.
     */
    public const PAGE_HINTS = [
        '/admin' => 'bảng tin, thống kê, widget, tổng quan',
        '/admin/posts' => 'danh sách bài viết, lọc trạng thái, bản nháp, chờ duyệt, lên lịch, thùng rác, xoá nhiều bài',
        '/admin/pages' => 'danh sách trang tĩnh, trang cha con, thùng rác',
        '/admin/categories' => 'danh mục, chuyên mục con, mô tả chuyên mục',
        '/admin/tags' => 'nhãn, từ khoá, gộp thẻ',
        '/admin/media' => 'thư viện ảnh, tải ảnh lên, sửa alt, chú thích, xoá ảnh, dọn dẹp ảnh không dùng',
        '/admin/themes' => 'giao diện, đổi theme, kích hoạt, cài theme từ zip, xoá theme',
        '/admin/themes/editor' => 'sửa mã theme, css, php, javascript, khôi phục bản trước, AI sửa lỗi theme',
        '/admin/plugins' => 'bật tắt plugin, cài plugin từ zip, xoá plugin, kiểm tra plugin bằng AI',
        '/admin/settings' => 'tên website, khẩu hiệu, URL, email quản trị, cho phép đăng ký + vai trò mặc định, múi giờ, định dạng ngày giờ, trang chủ hiển thị, trang danh sách bài, số bài mỗi trang, số phiên bản giữ lại, chuyên mục mặc định, tác vụ định kỳ',
        '/admin/settings/permalinks' => 'cấu trúc đường dẫn bài viết (permalink, slug), tiền tố chuyên mục/thẻ, cấu hình máy chủ (nginx)',
        '/admin/settings/media' => 'dung lượng ảnh tối đa khi tải lên (MB), kích thước ảnh: giữ kích thước gốc hoặc chiều rộng/chiều cao tối đa (px), chất lượng nén JPG/WEBP/AVIF',
        '/admin/settings/ai' => 'chọn nhà cung cấp AI (Claude, ChatGPT, Gemini), API key, model, cài thư viện AI',
        '/admin/profile' => 'tên hiển thị, email, đổi mật khẩu, bật/tắt thanh quản trị ngoài website',
    ];

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
            $r->post("$b/autosave", $h('autosave'));
            $r->post("$b/{id}/lock", $h('lock'));
            $r->post("$b/{id}/unlock", $h('unlock'));
            $r->get("$b/{id}/revisions", $h('revisions'));
            $r->post("$b/{id}/revisions/{rid}/restore", $h('restoreRevision'));
        }
        $r->post('/admin/upload', [PostsController::class, 'upload']);

        $r->get('/admin/media', [MediaController::class, 'index']);
        $r->get('/admin/api/media', [MediaController::class, 'list']);
        $r->get('/admin/api/media/{id}', [MediaController::class, 'show']);
        $r->post('/admin/media/upload', [MediaController::class, 'upload']);
        $r->post('/admin/media/{id}/update', [MediaController::class, 'update']);
        $r->post('/admin/media/{id}/delete', [MediaController::class, 'destroy']);
        $r->post('/admin/media/bulk-delete', [MediaController::class, 'bulkDelete']);
        $r->get('/admin/media/unused', [MediaController::class, 'unused']);
        $r->post('/admin/media/cleanup', [MediaController::class, 'cleanup']);

        $r->get('/admin/{tax}', [TermsController::class, 'index']);
        $r->get('/admin/{tax}/{id}/edit', [TermsController::class, 'edit']);
        $r->post('/admin/{tax}/save', [TermsController::class, 'save']);
        $r->post('/admin/{tax}/{id}/delete', [TermsController::class, 'destroy']);
        $r->get('/admin/tags/search', [TermsController::class, 'searchTags']);
        $r->get('/admin/api/terms', [TermsController::class, 'searchTags']);

        $r->get('/admin/themes', [ThemesController::class, 'index']);
        $r->post('/admin/themes/activate', [ThemesController::class, 'activate']);
        $r->post('/admin/themes/delete', [ThemesController::class, 'destroy']);
        foreach (['theme' => '/admin/themes', 'plugin' => '/admin/plugins'] as $kind => $b) {
            $r->post("$b/upload", fn () => PackageInstall::upload($kind));
            $r->get("$b/review", fn () => PackageInstall::review($kind));
            $r->post("$b/install", fn () => PackageInstall::install($kind));
            $r->post("$b/cancel", fn () => PackageInstall::cancel($kind));
        }
        $r->get('/admin/themes/editor', [ThemeEditorController::class, 'index']);
        $r->get('/admin/themes/editor/file', [ThemeEditorController::class, 'file']);
        $r->post('/admin/themes/editor/save', [ThemeEditorController::class, 'save']);
        $r->post('/admin/themes/editor/restore', [ThemeEditorController::class, 'restore']);
        $r->post('/admin/themes/editor/create', [ThemeEditorController::class, 'create']);

        $r->get('/admin/plugins', [PluginsController::class, 'index']);
        $r->post('/admin/plugins/activate', [PluginsController::class, 'activate']);
        $r->post('/admin/plugins/deactivate', [PluginsController::class, 'deactivate']);
        $r->post('/admin/plugins/delete', [PluginsController::class, 'destroy']);
        $r->get('/admin/plugin/{slug}', [PluginPages::class, 'render']);

        $r->get('/admin/settings', [SettingsController::class, 'general']);
        $r->post('/admin/settings', [SettingsController::class, 'saveGeneral']);
        $r->post('/admin/settings/cron-run', [SettingsController::class, 'runCron']);
        $r->get('/admin/settings/permalinks', [SettingsController::class, 'permalinks']);
        $r->get('/admin/settings/media', [SettingsController::class, 'media']);
        $r->get('/admin/settings/ai', [AIController::class, 'settings']);
        $r->post('/admin/settings/ai', [AIController::class, 'save']);
        $r->post('/admin/ai/install', [AIController::class, 'install']);
        $r->post('/admin/ai/uninstall', [AIController::class, 'uninstall']);
        $r->post('/admin/ai/models', [AIController::class, 'models']);
        $r->post('/admin/ai/test', [AIController::class, 'test']);
        $r->post('/admin/ai/rewrite', [AIController::class, 'rewrite']);
        $r->post('/admin/ai/write', [AIController::class, 'write']);
        $r->post('/admin/ai/suggest', [AIController::class, 'suggest']);
        $r->post('/admin/ai/theme-fix', [AIController::class, 'themeFix']);
        $r->post('/admin/ai/taxonomy', [AIToolsController::class, 'taxonomy']);
        $r->post('/admin/ai/links', [AIToolsController::class, 'links']);
        $r->post('/admin/ai/term-describe', [AIToolsController::class, 'termDescribe']);
        $r->post('/admin/ai/theme-explain', [AIToolsController::class, 'themeExplain']);
        $r->post('/admin/ai/theme-scan', [AIToolsController::class, 'themeScan']);
        $r->post('/admin/ai/plugin-scan', [AIToolsController::class, 'pluginScan']);
        $r->post('/admin/ai/chat', [AIChatController::class, 'send']);
        $r->post('/admin/ai/chat/apply', [AIChatController::class, 'apply']);
        $r->post('/admin/ai/chat/models', [AIChatController::class, 'models']);
        $r->post('/admin/ai/chat/undo', [AIChatController::class, 'undo']);
        $r->post('/admin/ai/theme-generate', [AIToolsController::class, 'themeGenerate']);
        $r->post('/admin/ai/package-review', [AIToolsController::class, 'packageReview']);
        $r->post('/admin/settings/media', [SettingsController::class, 'saveMedia']);
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
            // Gửi vượt post_max_size: PHP bỏ trống cả $_POST (mất luôn token) — báo đúng lý do thay vì "phiên hết hạn".
            $limit = ini_parse_quantity((string) ini_get('post_max_size')); // 0 = không giới hạn
            if (!$_POST && !$_FILES && $limit > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
                $msg = 'Dữ liệu gửi lên quá lớn (máy chủ giới hạn ' . ini_get('post_max_size') . ').';
                if (wants_json()) {
                    json_response(['ok' => false, 'message' => $msg], 413);
                }
                flash('error', $msg);
                redirect_back('/admin');
            }
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
            ['label' => 'Giao diện', 'icon' => 'palette', 'url' => '/admin/themes', 'cap' => 'manage_themes', 'children' => [
                ['label' => 'Theme', 'url' => '/admin/themes', 'cap' => 'manage_themes', 'exact' => true],
                ...(\LQ\Core\ThemeFiles::editingAllowed() ? [['label' => 'Chỉnh sửa', 'url' => '/admin/themes/editor', 'cap' => 'edit_themes']] : []),
            ]],
            ['label' => 'Plugin', 'icon' => 'puzzle', 'url' => '/admin/plugins', 'cap' => 'manage_plugins', 'badge' => count(array_filter(\LQ\Core\Plugins::all(), fn ($p) => !$p['active'])) ?: null], // số plugin đã cài nhưng chưa bật
            ['label' => 'Cài đặt', 'icon' => 'settings', 'url' => '/admin/settings', 'cap' => 'manage_options', 'children' => [
                ['label' => 'Tổng quan', 'url' => '/admin/settings', 'cap' => 'manage_options', 'exact' => true],
                ['label' => 'Đường dẫn tĩnh', 'url' => '/admin/settings/permalinks', 'cap' => 'manage_options'],
                ['label' => 'Media', 'url' => '/admin/settings/media', 'cap' => 'manage_options'],
                ['label' => 'MCP', 'url' => '/admin/settings/ai', 'cap' => 'manage_options'],
            ]],
            ['label' => 'Hồ sơ', 'icon' => 'user-round', 'url' => '/admin/profile', 'cap' => 'access_admin'],
        ];

        $pages = PluginPages::menuItems();
        if ($pages) {
            // Nhóm "Plugin" đứng ngay trước nhóm "Hệ thống". Trước đây chèn cứng ở vị trí 2 (sau "Bài viết") nên
            // Trang và Media bị xếp nhầm dưới tiêu đề "Plugin".
            $at = array_key_first(array_filter($menu, fn ($m) => isset($m['section']))) ?? count($menu);
            array_splice($menu, $at, 0, [['section' => 'Plugin'], ...$pages]);
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
