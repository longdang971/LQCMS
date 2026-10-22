<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Plugins;
use LQ\Core\View;

final class PluginsController
{
    public static function index(): void
    {
        Kernel::require('manage_plugins');
        $all = Plugins::all();
        $filter = input_str('filter');
        $list = match ($filter) {
            'active' => array_filter($all, fn ($p) => $p['active']),
            'inactive' => array_filter($all, fn ($p) => !$p['active']),
            default => $all,
        };
        View::render('admin/plugins/index', [
            'title' => 'Plugin',
            'plugins' => $list,
            'filter' => $filter,
            'counts' => [
                'all' => count($all),
                'active' => count(array_filter($all, fn ($p) => $p['active'])),
                'inactive' => count(array_filter($all, fn ($p) => !$p['active'])),
            ],
        ]);
    }

    public static function activate(): void
    {
        Kernel::require('manage_plugins');
        $slug = input_str('plugin');
        $err = Plugins::activate($slug);
        $err ? flash('error', $err) : flash('success', 'Đã kích hoạt plugin "' . Plugins::read($slug)['name'] . '".');
        redirect_back('/admin/plugins');
    }

    public static function deactivate(): void
    {
        Kernel::require('manage_plugins');
        $slug = input_str('plugin');
        $meta = Plugins::read($slug);
        // Slug lạ / plugin vốn đang tắt: trước đây vẫn báo "Đã tắt plugin …" như thành công.
        if (!Plugins::isActive($slug)) {
            flash('error', 'Plugin không tồn tại hoặc đang tắt.');
            redirect_back('/admin/plugins');
        }
        Plugins::deactivate($slug);
        flash('success', 'Đã tắt plugin "' . ($meta['name'] ?? $slug) . '".');
        redirect_back('/admin/plugins');
    }

    public static function destroy(): void
    {
        Kernel::require('manage_plugins');
        $slug = input_str('plugin');
        $meta = Plugins::read($slug);
        if (!$meta) {
            flash('error', 'Plugin không tồn tại.');
        } elseif (Plugins::isActive($slug)) {
            flash('error', 'Hãy tắt plugin trước khi xoá.');
        } else {
            $uninstall = Plugins::dir() . "/$slug/uninstall.php";
            if (is_file($uninstall)) {
                try {
                    // Chạy trong closure riêng: uninstall.php dùng chung phạm vi biến với hàm này thì một dòng như
                    // `$slug = ...` sẽ làm rrmdir() bên dưới xoá nhầm thư mục (slug rỗng = xoá cả thư mục plugins).
                    (static function (string $__file): void {
                        require $__file;
                    })($uninstall);
                } catch (\Throwable $e) {
                    error_log("[LQCMS] uninstall $slug lỗi: " . $e->getMessage());
                }
            }
            rrmdir(Plugins::dir() . "/$slug");
            flash('success', 'Đã xoá plugin "' . $meta['name'] . '".');
        }
        redirect('/admin/plugins');
    }
}
