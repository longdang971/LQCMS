<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\View;

/** Các widget có sẵn của Bảng tin. */
final class CoreWidgets
{
    public static function register(): void
    {
        $v = fn (string $view) => fn (int $size) => View::capture("admin/widgets/$view", ['size' => $size]);

        $defs = [
            'welcome' => ['Lời chào', 'Lời chào theo buổi và lối tắt tới các trang hay dùng.', 'hand', 12, [6, 8, 12], 'access_admin', false],
            'posts-published' => ['Bài đã đăng', 'Tổng số bài đã đăng, xu hướng 12 tuần và số bài trong 30 ngày.', 'file-check-2', 3, [3, 4, 6], 'edit_posts', true],
            'edits' => ['Lượt biên tập', 'Số bài được tạo hoặc sửa mỗi tuần trong 12 tuần.', 'activity', 3, [3, 4, 6], 'edit_posts', true],
            'pending' => ['Đang chờ xử lý', 'Bản nháp, bài chờ duyệt và bài lên lịch.', 'hourglass', 3, [3, 4, 6], 'edit_posts', true],
            'taxonomy' => ['Phân loại', 'Số chuyên mục và thẻ.', 'folder-tree', 3, [3, 4, 6], 'edit_posts', true],
            'monthly' => ['Bài đăng theo tháng', 'Biểu đồ cột số bài đăng trong 12 tháng.', 'chart-column', 8, [6, 8, 12], 'edit_posts', true],
            'status' => ['Trạng thái nội dung', 'Tỉ lệ bài theo trạng thái và các bài sắp đăng.', 'chart-bar-stacked', 4, [4, 6], 'edit_posts', true],
            'recent' => ['Cập nhật gần đây', 'Các bài vừa được tạo hoặc chỉnh sửa.', 'history', 8, [6, 8, 12], 'edit_posts', true],
            'top-categories' => ['Chuyên mục nổi bật', 'Chuyên mục có nhiều bài nhất.', 'folder-kanban', 4, [4, 6], 'edit_posts', true],
            'quick-draft' => ['Bản nháp nhanh', 'Ghi lại ý tưởng thành bản nháp chỉ với một cú bấm.', 'notebook-pen', 4, [4, 6, 8], 'edit_posts', true],
            'members' => ['Thành viên', 'Các tài khoản mới tham gia.', 'users', 4, [4, 6], 'manage_options', true],
            'system' => ['Hệ thống', 'Phiên bản PHP, database, theme, plugin.', 'server', 4, [4, 6], 'manage_options', true],
        ];
        foreach ($defs as $id => [$title, $desc, $icon, $size, $sizes, $cap, $default]) {
            Widgets::register($id, [
                'title' => $title,
                'description' => $desc,
                'icon' => $icon,
                'size' => $size,
                'sizes' => $sizes,
                'cap' => $cap,
                'default' => $default,
                'group' => 'Có sẵn',
                'render' => $v($id),
            ]);
        }
    }
}
