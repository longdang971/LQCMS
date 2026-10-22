<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\ThemeFiles;
use LQ\Core\Themes;
use LQ\Core\View;

/**
 * Sửa mã theme ngay trong admin (giống Giao diện › Trình sửa theme của WordPress).
 * Chỉ người có quyền edit_themes; tắt hẳn bằng 'disallow_file_edit' => true trong config.php.
 * Route này không nạp functions.php của theme (xem App::run) nên vẫn vào được để sửa khi theme bị lỗi nặng.
 */
final class ThemeEditorController
{
    public static function index(): void
    {
        self::guard();
        $themes = Themes::all();
        $slug = input_str('theme') ?: Themes::active();
        if (!isset($themes[$slug])) {
            flash('error', 'Theme không tồn tại.');
            redirect('/admin/themes/editor');
        }
        $files = ThemeFiles::list($slug);
        $file = input_str('file') ?: (isset($files['style.css']) ? 'style.css' : (string) array_key_first($files));
        if (!$files) {
            flash('error', 'Theme này không có tệp nào sửa được.');
            redirect('/admin/themes');
        }
        $abs = ThemeFiles::resolve($slug, $file);
        if (!$abs || !isset($files[$file])) {
            flash('error', 'Không mở được tệp "' . $file . '" (không tồn tại hoặc không được phép sửa).');
            redirect('/admin/themes/editor?theme=' . rawurlencode($slug));
        }
        View::render('admin/themes/editor', [
            'title' => 'Chỉnh sửa',
            'subject' => $file . ' (' . $themes[$slug]['name'] . ')',
            'themes' => $themes,
            'theme' => $themes[$slug],
            'isActive' => $slug === Themes::active(),
            'tree' => ThemeFiles::tree($slug),
            'fileCount' => count($files),
            'file' => $file,
            'current' => self::fileData($slug, $file, $abs),
            'line' => max(0, (int) input('line', 0)),
        ]);
    }

    /** Nội dung một tệp dạng JSON — trình sửa mở tab mới không cần tải lại trang. */
    public static function file(): void
    {
        self::guard();
        [$slug, $file, $abs] = self::target();
        json_response(['ok' => true] + self::fileData($slug, $file, $abs));
    }

    private static function fileData(string $slug, string $file, string $abs): array
    {
        $content = (string) file_get_contents($abs);
        [$icon, $kind] = ThemeFiles::icon($file);
        // Tệp không phải UTF-8 (Latin-1, nhị phân đổi đuôi…): JSON thay byte hỏng bằng U+FFFD nên lưu lại là hỏng tệp — chỉ cho xem.
        $utf8 = mb_check_encoding($content, 'UTF-8');
        return [
            'file' => $file,
            'content' => $utf8 ? $content : mb_scrub($content, 'UTF-8'),
            'hash' => sha1($content),
            'mode' => ThemeFiles::mode($file),
            // Ghi = tệp tạm cùng thư mục rồi đổi tên (ThemeFiles::write) nên thư mục cũng phải ghi được.
            'writable' => $utf8 && is_writable($abs) && is_writable(dirname($abs)),
            'binary' => !$utf8,
            'hasBackup' => ThemeFiles::backupPath($slug, $file) !== null,
            'desc' => ThemeFiles::describe($file),
            'icon' => $icon,
            'kind' => $kind,
            'modified' => filemtime($abs) ?: null,
        ];
    }

    /** Lưu (JSON). Từ chối khi lỗi cú pháp, hoặc khi tệp đã bị sửa ở nơi khác sau lúc mở (trừ khi force). */
    public static function save(): void
    {
        self::guard();
        [$slug, $file, $abs] = self::target();
        $content = (string) ($_POST['content'] ?? '');
        $content = str_replace("\r\n", "\n", $content);

        if (strlen($content) > ThemeFiles::MAX_BYTES) {
            json_response(['ok' => false, 'message' => 'Tệp quá lớn để sửa trên trình duyệt (tối đa 1 MB).'], 422);
        }
        if ($err = ThemeFiles::lint($file, $content)) {
            json_response(['ok' => false, 'message' => $err['message'], 'line' => $err['line']], 422);
        }
        $current = (string) file_get_contents($abs);
        if (empty($_POST['force']) && sha1($current) !== input_str('hash')) {
            json_response(['ok' => false, 'conflict' => true, 'message' => 'Tệp vừa bị thay đổi ở nơi khác sau khi bạn mở. Lưu đè sẽ mất thay đổi đó.'], 409);
        }
        if (!mb_check_encoding($current, 'UTF-8')) {
            json_response(['ok' => false, 'message' => 'Tệp không phải văn bản UTF-8 — sửa trên trình duyệt sẽ làm hỏng các byte không đọc được.'], 422);
        }
        if (!is_writable($abs) || !is_writable(dirname($abs))) {
            json_response(['ok' => false, 'message' => 'Máy chủ không cho ghi tệp này (kiểm tra quyền ghi của thư mục theme).'], 422);
        }
        if ($current !== $content) {
            ThemeFiles::backup($slug, $file, $current);
            if (!ThemeFiles::write($abs, $content)) {
                json_response(['ok' => false, 'message' => 'Ghi tệp thất bại.'], 500);
            }
            do_action('theme_file_saved', $slug, $file);
        }
        json_response(['ok' => true, 'message' => 'Đã lưu ' . $file . '.', 'hash' => sha1($content), 'hasBackup' => true]);
    }

    /** Trả tệp về bản ngay trước lần lưu gần nhất (bản hiện tại thành bản sao lưu, nên bấm lại là "làm lại"). */
    public static function restore(): void
    {
        self::guard();
        [$slug, $file, $abs] = self::target();
        $bak = ThemeFiles::backupPath($slug, $file);
        if (!$bak) {
            json_response(['ok' => false, 'message' => 'Chưa có bản sao lưu cho tệp này.'], 404);
        }
        $old = (string) file_get_contents($bak);
        $current = (string) file_get_contents($abs);
        ThemeFiles::backup($slug, $file, $current);
        if (!ThemeFiles::write($abs, $old)) {
            json_response(['ok' => false, 'message' => 'Ghi tệp thất bại.'], 500);
        }
        json_response(['ok' => true, 'message' => 'Đã khôi phục bản trước của ' . $file . '.', 'content' => $old, 'hash' => sha1($old)]);
    }

    /** Tạo tệp mới trong theme, vd. single-review.php, parts/banner.php. */
    public static function create(): void
    {
        self::guard();
        $slug = input_str('theme');
        if (!Themes::read($slug)) {
            json_response(['ok' => false, 'message' => 'Theme không tồn tại.'], 404);
        }
        $file = trim(str_replace('\\', '/', input_str('file')), '/');
        if ($err = ThemeFiles::validateName($file)) {
            json_response(['ok' => false, 'message' => $err], 422);
        }
        $abs = Themes::dir() . "/$slug/$file";
        if (file_exists($abs) || is_link($abs)) { // is_link: symlink hỏng thì file_exists false nhưng ghi vẫn đi theo nó ra ngoài
            json_response(['ok' => false, 'message' => 'Tệp "' . $file . '" đã có.'], 422);
        }
        // Thư mục cha gần nhất đang có phải nằm thật trong theme: nó có thể là symlink trỏ ra ngoài (mkdir/ghi sẽ đi theo).
        $root = realpath(Themes::dir() . "/$slug");
        $near = dirname($abs);
        while (!file_exists($near) && strlen($near) > strlen(Themes::dir() . "/$slug")) {
            $near = dirname($near);
        }
        $real = realpath($near);
        if ($root === false || $real === false || !str_starts_with($real . '/', $root . '/')) {
            json_response(['ok' => false, 'message' => 'Đường dẫn không hợp lệ.'], 422);
        }
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            json_response(['ok' => false, 'message' => 'Không tạo được thư mục (kiểm tra quyền ghi).'], 500);
        }
        // Trợ lý AI tạo tệp kèm nội dung sẵn; nội dung lỗi cú pháp thì không tạo.
        $content = isset($_POST['content']) ? (string) $_POST['content'] : ThemeFiles::stub($file);
        if (strlen($content) > ThemeFiles::MAX_BYTES) {
            json_response(['ok' => false, 'message' => 'Nội dung quá lớn.'], 422);
        }
        if (isset($_POST['content']) && ($err = ThemeFiles::lint($file, $content))) {
            json_response(['ok' => false, 'message' => 'Nội dung có lỗi: ' . $err['message'], 'line' => $err['line']], 422);
        }
        if (!ThemeFiles::write($abs, $content)) {
            json_response(['ok' => false, 'message' => 'Không tạo được tệp (kiểm tra quyền ghi).'], 500);
        }
        json_response(['ok' => true, 'url' => '/admin/themes/editor?theme=' . rawurlencode($slug) . '&file=' . rawurlencode($file)]);
    }

    public static function allowed(): bool
    {
        return ThemeFiles::editingAllowed() && can('edit_themes');
    }

    private static function guard(): void
    {
        if (!ThemeFiles::editingAllowed()) {
            if (wants_json()) {
                json_response(['ok' => false, 'message' => 'Sửa mã đã bị tắt trong config.php.'], 403);
            }
            flash('error', 'Trình sửa mã đã bị tắt (disallow_file_edit trong config.php).');
            redirect('/admin/themes');
        }
        Kernel::require('edit_themes');
    }

    /** @return array{0:string,1:string,2:string} [theme, tệp, đường dẫn tuyệt đối] */
    private static function target(): array
    {
        $slug = input_str('theme');
        $file = input_str('file');
        $abs = Themes::read($slug) ? ThemeFiles::resolve($slug, $file) : null;
        if (!$abs) {
            json_response(['ok' => false, 'message' => 'Tệp không tồn tại hoặc không được phép sửa.'], 404);
        }
        return [$slug, $file, $abs];
    }
}
