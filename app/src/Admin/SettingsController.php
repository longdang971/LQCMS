<?php
declare(strict_types=1);

namespace LQ\Admin;

use DateTimeZone;
use LQ\Content\Terms;
use LQ\Core\Auth;
use LQ\Core\Permalink;
use LQ\Core\Session;
use LQ\Core\View;

final class SettingsController
{
    public const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'j F, Y', 'F j, Y'];
    public const TIME_FORMATS = ['H:i', 'g:i A', 'H:i:s'];

    public static function general(): void
    {
        Kernel::require('manage_options');
        View::render('admin/settings/general', [
            'title' => 'Cài đặt chung',
            'timezones' => DateTimeZone::listIdentifiers(),
            'categories' => Terms::tree(Terms::all('category')),
            'cron' => \LQ\Core\Cron::status(),
            'errors' => self::pullErrors(),
        ]);
    }

    public static function saveGeneral(): void
    {
        Kernel::require('manage_options');
        $in = $_POST;
        $errors = [];

        $title = trim((string) ($in['site_title'] ?? ''));
        if ($title === '') {
            $errors['site_title'] = 'Tên website không được để trống.';
        }
        $siteUrl = rtrim(trim((string) ($in['site_url'] ?? '')), '/');
        if (!preg_match('#^https?://[^\s/]+(:\d+)?$#i', $siteUrl)) {
            $errors['site_url'] = 'Địa chỉ website phải có dạng https://ten-mien.vn (không có đường dẫn phía sau).';
        }
        $email = mb_strtolower(trim((string) ($in['admin_email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = 'Email quản trị không hợp lệ.';
        }
        $tz = (string) ($in['timezone'] ?? '');
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Múi giờ không hợp lệ.';
        }
        $dateFormat = ($in['date_format'] ?? '') === 'custom' ? trim((string) ($in['date_format_custom'] ?? '')) : (string) ($in['date_format'] ?? '');
        $timeFormat = ($in['time_format'] ?? '') === 'custom' ? trim((string) ($in['time_format_custom'] ?? '')) : (string) ($in['time_format'] ?? '');
        if ($dateFormat === '' || mb_strlen($dateFormat) > 30) {
            $errors['date_format'] = 'Định dạng ngày không hợp lệ.';
        }
        if ($timeFormat === '' || mb_strlen($timeFormat) > 30) {
            $errors['time_format'] = 'Định dạng giờ không hợp lệ.';
        }
        $perPage = (int) ($in['posts_per_page'] ?? 10);
        if ($perPage < 1 || $perPage > 100) {
            $errors['posts_per_page'] = 'Số bài mỗi trang từ 1 đến 100.';
        }
        $role = (string) ($in['default_role'] ?? 'subscriber');
        if (!isset(Auth::ROLES[$role]) || $role === 'admin') {
            $errors['default_role'] = 'Vai trò mặc định không hợp lệ.';
        }
        $defaultCat = (int) ($in['default_category'] ?? 0);
        if (!Terms::find($defaultCat, 'category')) {
            $errors['default_category'] = 'Chuyên mục mặc định không tồn tại.';
        }
        $uploadMax = (int) ($in['upload_max_mb'] ?? 8);
        if ($uploadMax < 1 || $uploadMax > 512) {
            $errors['upload_max_mb'] = 'Dung lượng upload từ 1 đến 512 MB.';
        }

        if ($errors) {
            Session::keepOld($in);
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect('/admin/settings');
        }

        foreach ([
            'site_title' => $title,
            'tagline' => trim((string) ($in['tagline'] ?? '')),
            'site_url' => $siteUrl,
            'admin_email' => $email,
            'timezone' => $tz,
            'date_format' => $dateFormat,
            'time_format' => $timeFormat,
            'posts_per_page' => $perPage,
            'users_can_register' => !empty($in['users_can_register']),
            'default_role' => $role,
            'default_category' => $defaultCat,
            'upload_max_mb' => $uploadMax,
            'cron_web_fallback' => !empty($in['cron_web_fallback']),
        ] as $k => $v) {
            update_option($k, $v);
        }
        do_action('settings_saved', 'general');
        flash('success', 'Đã lưu cài đặt.');
        redirect('/admin/settings');
    }

    public static function permalinks(): void
    {
        Kernel::require('manage_options');
        $structure = (string) option('permalink_structure', '/%postname%/');
        $sample = ['id' => 123, 'slug' => 'bai-viet-mau', 'published_at' => now_utc(), 'category_path' => 'tin-tuc', 'author' => current_user()['username']];
        $presets = [];
        $current = 'custom';
        foreach (Permalink::PRESETS as $key => [$value, $label]) {
            $presets[$key] = ['value' => $value, 'label' => $label, 'example' => site_url(Permalink::build($value, $sample))];
            if ($value === $structure) {
                $current = $key;
            }
        }
        View::render('admin/settings/permalinks', [
            'title' => 'Đường dẫn tĩnh',
            'structure' => $structure,
            'presets' => $presets,
            'current' => $current,
            'tags' => Permalink::TAGS,
            'categoryBase' => (string) option('category_base', 'chuyen-muc'),
            'tagBase' => (string) option('tag_base', 'the'),
            'sample' => $sample,
            'errors' => self::pullErrors(),
        ]);
    }

    public static function savePermalinks(): void
    {
        Kernel::require('manage_options');
        $choice = input_str('preset');
        $structure = $choice === 'custom'
            ? trim(input_str('custom_structure'))
            : (Permalink::PRESETS[$choice][0] ?? null);
        $errors = [];

        if ($structure === null) {
            $errors['structure'] = 'Hãy chọn một cấu trúc.';
        } else {
            // Chuẩn hoá: gộp "//", thêm "/" đầu nếu người dùng quên.
            if ($structure !== '' && $structure[0] !== '/') {
                $structure = '/' . $structure;
            }
            $structure = (string) preg_replace('#/+#', '/', $structure);
            if ($structure === '/') {
                $structure = '';
            }
            if ($err = Permalink::validate($structure)) {
                $errors['structure'] = $err;
            }
        }
        // Tick "Không dùng tiền tố" = chuyên mục nằm ở gốc (/the-loai/); bỏ tick mà ô trống thì dùng mặc định.
        $catBase = !empty($_POST['category_no_base']) ? '' : (trim(input_str('category_base'), '/') ?: 'chuyen-muc');
        $tagBase = trim(input_str('tag_base'), '/') ?: 'the';
        if ($err = Permalink::validateBase($catBase)) {
            $errors['category_base'] = 'Tiền tố chuyên mục: ' . $err;
        }
        if ($err = Permalink::validateBase($tagBase)) {
            $errors['tag_base'] = 'Tiền tố thẻ: ' . $err;
        }
        if (!$errors && ($catBase !== '' && $catBase === $tagBase)) {
            $errors['tag_base'] = 'Tiền tố chuyên mục và thẻ phải khác nhau.';
        }
        if (!$errors && $catBase === '' && $tagBase === '') {
            $errors['tag_base'] = 'Chuyên mục và thẻ không thể cùng bỏ tiền tố.';
        }
        // Bỏ tiền tố: slug ở gốc site không được trùng đường dẫn hệ thống hay tiền tố khác.
        foreach (['category' => [$catBase, $tagBase, 'category_base', 'chuyên mục'], 'tag' => [$tagBase, $catBase, 'tag_base', 'thẻ']] as $tax => [$base, $otherBase, $field, $label]) {
            if ($errors || $base !== '') {
                continue;
            }
            $taken = array_merge(Permalink::RESERVED_ROOT, $otherBase !== '' ? [explode('/', $otherBase)[0]] : []);
            foreach (\LQ\Content\Taxonomies::custom() as $t) {
                if ($t['base'] !== '') {
                    $taken[] = explode('/', $t['base'])[0];
                }
            }
            $clash = db()->column(
                'SELECT slug FROM {terms} WHERE taxonomy = ? AND parent_id IS NULL AND slug IN (' . \LQ\Core\Database::placeholders($taken) . ')',
                [$tax, ...$taken]
            );
            if ($clash) {
                $errors[$field] = "Không thể bỏ tiền tố: {$label} có slug trùng đường dẫn đã dùng (" . implode(', ', $clash) . '). Hãy đổi slug trước.';
            }
        }

        if ($errors) {
            Session::keepOld($_POST);
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect('/admin/settings/permalinks');
        }

        $old = (string) option('permalink_structure', '');
        if ($old !== $structure) {
            // Giữ lịch sử cấu trúc cũ để frontend chuyển hướng 301 link cũ.
            $history = array_values(array_unique([$old, ...(array) option('permalink_history', [])]));
            update_option('permalink_history', array_slice(array_diff($history, [$structure]), 0, 10), false);
        }
        update_option('permalink_structure', $structure);
        update_option('category_base', $catBase);
        update_option('tag_base', $tagBase);
        do_action('settings_saved', 'permalinks');
        flash('success', 'Đã lưu cấu trúc đường dẫn.');
        redirect('/admin/settings/permalinks');
    }

    /** Nút "Chạy ngay" trong Cài đặt › Tác vụ định kỳ: chạy mọi tác vụ bất kể lịch. */
    public static function runCron(): void
    {
        Kernel::require('manage_options');
        $ran = \LQ\Core\Cron::run('manual', true);
        if ($ran === null) {
            flash('warning', 'Tác vụ đang được chạy bởi tiến trình khác, thử lại sau ít giây.');
        } else {
            $failed = array_keys(array_filter($ran, fn ($r) => !$r['ok']));
            $failed
                ? flash('error', 'Có tác vụ lỗi: ' . implode(', ', $failed) . '. Xem chi tiết trong bảng.')
                : flash('success', 'Đã chạy ' . count($ran) . ' tác vụ. ' . ($ran['publish_scheduled']['result'] ?? ''));
        }
        redirect('/admin/settings#tac-vu');
    }

    private static function pullErrors(): array
    {
        $e = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return $e;
    }
}
