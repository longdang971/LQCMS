<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Themes;
use LQ\Core\View;

final class ThemesController
{
    public static function index(): void
    {
        Kernel::require('manage_themes');
        View::render('admin/themes/index', [
            'title' => 'Giao diện',
            'themes' => Themes::all(),
            'active' => Themes::active(),
        ]);
    }

    public static function activate(): void
    {
        Kernel::require('manage_themes');
        $slug = input_str('theme');
        $theme = Themes::read($slug);
        if (!$theme) {
            flash('error', 'Theme không tồn tại hoặc bị lỗi.');
            redirect('/admin/themes');
        }
        $old = Themes::active();
        update_option('active_theme', $slug);
        do_action('theme_switched', $slug, $old);
        flash('success', 'Đã kích hoạt theme "' . $theme['name'] . '".');
        redirect('/admin/themes');
    }

    public static function destroy(): void
    {
        Kernel::require('manage_themes');
        $slug = input_str('theme');
        $theme = Themes::read($slug);
        if (!$theme) {
            flash('error', 'Theme không tồn tại.');
        } elseif ($slug === Themes::active()) {
            flash('error', 'Không thể xoá theme đang sử dụng.');
        } else {
            rrmdir(Themes::dir() . "/$slug");
            flash('success', 'Đã xoá theme "' . $theme['name'] . '".');
        }
        redirect('/admin/themes');
    }
}
