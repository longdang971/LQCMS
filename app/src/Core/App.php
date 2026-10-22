<?php
declare(strict_types=1);

namespace LQ\Core;

use LQ\Admin\Kernel;
use RuntimeException;

final class App
{
    private static ?Database $db = null;
    private static array $config = [];

    public static function run(): void
    {
        Session::start();
        $path = request_path();

        if (!is_file(LQ_CONFIG)) {
            if (class_exists(Installer::class)) {
                (new Installer())->handle($path);
                return;
            }
            // Trình cài đặt đã được gỡ sau khi cài (InstallFiles) mà tệp cấu hình lại mất.
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Thiếu tệp cấu hình</title>'
                . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 16px">'
                . '<h1 style="font-size:20px">Không tìm thấy tệp cấu hình</h1>'
                . '<p>Website cần tệp <code>' . htmlspecialchars(basename(LQ_CONFIG_OUTSIDE)) . '</code> ở thư mục cha (ngoài thư mục web). '
                . 'Hãy khôi phục tệp này từ bản sao lưu. Muốn cài lại từ đầu, tải gói cài mới (lqcms-&lt;phiên bản&gt;.zip) từ '
                . '<a href="https://github.com/' . Updater::REPO . '/releases">GitHub Releases</a>.</p></div>';
            return;
        }

        $config = require LQ_CONFIG;
        self::$config = is_array($config) ? $config : [];
        self::$db = new Database($config['db']);
        Options::load();
        Migrations::run();
        // Cài xong mà phải tạo tệp cấu hình bằng tay: lần truy cập đầu tiên sau đó dọn nốt tệp cài đặt.
        if (option('install_cleanup_pending')) {
            InstallFiles::remove();
            Options::delete('install_cleanup_pending');
        }

        // Field có sẵn của lõi: mô tả SEO cho thẻ meta description (lq_meta_description() dùng nếu có).
        lq_add_post_field('seo_description', [
            'label' => 'Mô tả SEO',
            'type' => 'textarea',
            'box' => 'SEO',
            'context' => 'side',
            'max' => 160,
            'post_type' => ['post', 'page'],
            'help' => 'Hiện dưới tiêu đề trên Google, khoảng 150–160 ký tự. Để trống thì lấy từ nội dung.',
            'ai_role' => 'seo',
            'priority' => 90,
        ]);
        // Action AJAX có sẵn đăng ký trước, plugin/theme đăng ký lại cùng tên để thay thế.
        \LQ\Front\Ajax::registerCore();
        Plugins::loadActive();
        // Trình sửa mã theme không nạp functions.php: theme có lỗi nặng vẫn vào sửa được.
        if (!str_starts_with($path, '/admin/themes/editor')) {
            self::catchThemeFatal();
            Themes::loadActiveFunctions();
        }
        do_action('init');
        Cron::maybeRunOnRequest();

        if ($path === '/install') {
            redirect(admin_url());
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            Kernel::handle($path);
            return;
        }
        if ($path === '/' . \LQ\Front\Ajax::BASE || str_starts_with($path, '/' . \LQ\Front\Ajax::BASE . '/')) {
            \LQ\Front\Ajax::handle(trim(substr($path, strlen(\LQ\Front\Ajax::BASE) + 1), '/'));
        }

        Themes::renderFront($path);
    }

    /** Giá trị trong config.php, vd. App::config('disallow_file_edit', false). */
    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * Lỗi nghiêm trọng xảy ra trong tệp của theme (vd. sửa hỏng functions.php): thay trang trắng bằng thông báo,
     * người có quyền sửa theme thấy chi tiết + link mở đúng dòng lỗi trong trình sửa mã.
     */
    private static function catchThemeFatal(): void
    {
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            $themeDir = realpath(Themes::dir()) ?: Themes::dir();
            if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)
                || !str_starts_with((string) realpath($err['file']), $themeDir . DIRECTORY_SEPARATOR)) {
                return;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (str_starts_with(request_path(), '/' . \LQ\Front\Ajax::BASE . '/')) {
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['ok' => false, 'message' => 'Theme đang gặp lỗi nghiêm trọng.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            $rel = substr((string) realpath($err['file']), strlen($themeDir) + 1);
            [$theme, $file] = explode(DIRECTORY_SEPARATOR, $rel, 2) + [1 => ''];
            $canFix = false;
            try {
                $canFix = Auth::can('edit_themes');
            } catch (\Throwable) {
            }
            $msg = strtok((string) $err['message'], "\n");
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Theme gặp lỗi</title>'
                . '<body style="font:16px/1.6 -apple-system,Segoe UI,sans-serif;max-width:640px;margin:12vh auto;padding:0 20px;color:#18181b">'
                . '<h1 style="font-size:28px;letter-spacing:-.02em">' . ($canFix ? 'Theme đang gặp lỗi nghiêm trọng' : 'Website đang bảo trì') . '</h1>';
            if ($canFix) {
                $url = '/admin/themes/editor?theme=' . rawurlencode($theme) . '&file=' . rawurlencode(str_replace(DIRECTORY_SEPARATOR, '/', $file)) . '&line=' . (int) $err['line']
                    . '&error=' . rawurlencode(mb_substr($msg, 0, 500)); // trình sửa mã đưa lỗi này cho trợ lý AI nếu đã bật
                echo '<p>Tệp <code>' . e($rel) . '</code> dòng <b>' . (int) $err['line'] . '</b>:</p>'
                    . '<pre style="white-space:pre-wrap;background:#f4f4f5;padding:14px;border-radius:10px">' . e($msg) . '</pre>'
                    . '<p><a href="' . e($url) . '" style="display:inline-block;background:#18181b;color:#fff;padding:10px 16px;border-radius:999px;text-decoration:none">Mở trình sửa mã tại dòng lỗi</a></p>'
                    . '<p style="color:#71717a;font-size:14px">Trình sửa mã không nạp theme nên vẫn mở được. Có thể bấm "Khôi phục bản trước" để quay về bản trước lần lưu gần nhất.</p>';
            } else {
                echo '<p style="color:#52525b">Vui lòng quay lại sau ít phút.</p>';
            }
            echo '</body>';
        });
    }

    public static function db(): Database
    {
        return self::$db ?? throw new RuntimeException('Chưa kết nối cơ sở dữ liệu.');
    }

    public static function setDb(Database $db): void
    {
        self::$db = $db;
    }
}
