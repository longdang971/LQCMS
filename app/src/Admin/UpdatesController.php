<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Updater;
use LQ\Core\View;

/** Cài đặt › Cập nhật: kiểm tra bản LQCMS mới trên GitHub, cập nhật lõi, khôi phục bản trước. */
final class UpdatesController
{
    public static function index(): void
    {
        Kernel::require('manage_options');
        View::render('admin/updates', [
            'title' => 'Cập nhật',
            'status' => Updater::check(), // dùng kết quả đã lưu nếu chưa quá 12 giờ
            'latest' => Updater::available(),
            'problems' => Updater::problems(),
            'backup' => Updater::lastBackup(),
            'last' => option('update_last', null),
        ]);
    }

    public static function check(): void
    {
        Kernel::require('manage_options');
        $s = Updater::check(true);
        if ($s['error']) {
            flash('error', 'Không kiểm tra được: ' . $s['error']);
        } elseif ($l = Updater::available()) {
            flash('success', "Có bản mới: LQCMS {$l['version']}.");
        } else {
            flash('success', 'Bạn đang dùng bản mới nhất (' . LQ_VERSION . ').');
        }
        redirect('/admin/updates');
    }

    public static function apply(): void
    {
        Kernel::require('manage_options');
        session_write_close(); // tải + giải nén có thể mất cả phút: đừng giữ khoá session của người dùng
        [$ok, $message] = Updater::apply();
        session_start();
        flash($ok ? 'success' : 'error', $message);
        // Request kế tiếp chạy mã mới (kể cả Migrations nâng cấp DB nếu bản mới cần).
        redirect('/admin/updates');
    }

    public static function rollback(): void
    {
        Kernel::require('manage_options');
        [$ok, $message] = Updater::rollback();
        flash($ok ? 'success' : 'error', $message);
        redirect('/admin/updates');
    }
}
