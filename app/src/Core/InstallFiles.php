<?php
declare(strict_types=1);

namespace LQ\Core;

/**
 * Tệp chỉ dùng lúc cài đặt: xoá ngay sau khi cài xong (Installer) và sau mỗi lần cập nhật / khôi phục lõi (Updater),
 * để site đang chạy không còn trình cài đặt, cấu trúc bảng hay cấu hình máy chủ mẫu nằm trong thư mục web.
 * Muốn cài lại từ đầu thì dùng gói cài mới (lqcms-<phiên bản>.zip).
 */
final class InstallFiles
{
    public const PATHS = ['database', 'nginx.conf.example', 'app/views/install', 'app/src/Core/Installer.php'];

    /** Xoá các tệp cài đặt còn lại. @return list<string> những mục KHÔNG xoá được (thiếu quyền ghi) */
    public static function remove(): array
    {
        // Bản checkout git (máy dev, deploy bằng git pull): đây là tệp của repo, xoá sẽ thành thay đổi chưa commit.
        if (is_dir(LQ_ROOT . '/.git')) {
            return [];
        }
        $left = [];
        foreach (self::PATHS as $p) {
            $abs = LQ_ROOT . "/$p";
            if (!file_exists($abs) && !is_link($abs)) {
                continue;
            }
            is_dir($abs) && !is_link($abs) ? rrmdir($abs) : @unlink($abs);
            clearstatcache(true, $abs);
            if (file_exists($abs) || is_link($abs)) {
                $left[] = $p;
            }
        }
        return $left;
    }
}
