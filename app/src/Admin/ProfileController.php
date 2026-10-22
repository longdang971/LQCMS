<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Session;
use LQ\Core\View;

final class ProfileController
{
    public static function edit(): void
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        View::render('admin/profile', [
            'title' => 'Hồ sơ của bạn',
            'user' => current_user(),
            'errors' => $errors,
        ]);
    }

    public static function save(): void
    {
        $u = current_user();
        $in = $_POST;
        // Trường gửi dạng mảng (display_name[]=x) coi như rỗng, tránh lưu chữ "Array".
        $str = fn (string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';
        $errors = [];
        $data = [];

        $name = trim($str('display_name'));
        if ($name === '') {
            $errors['display_name'] = 'Tên hiển thị không được để trống.';
        } elseif (mb_strlen($name) > 191) {
            // Trước đây tên quá dài cũng báo "không được để trống".
            $errors['display_name'] = 'Tên hiển thị tối đa 191 ký tự.';
        }
        $data['display_name'] = $name;

        $email = mb_strtolower(trim($str('email')));
        $newPass = $str('new_password');
        $sensitive = $email !== $u['email'] || $newPass !== '';

        // Đổi email hoặc mật khẩu phải xác nhận mật khẩu hiện tại.
        if ($sensitive && !password_verify($str('current_password'), $u['password_hash'])) {
            $errors['current_password'] = 'Mật khẩu hiện tại không đúng.';
        }
        if ($email !== $u['email']) {
            // Cột users.email chỉ 191 ký tự: email dài hơn (filter_var vẫn nhận tới ~254) từng làm lỗi SQL "Data too long" → trang 500.
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
                $errors['email'] = 'Email không hợp lệ.';
            } elseif (db()->value('SELECT 1 FROM {users} WHERE email = ? AND id <> ?', [$email, $u['id']])) {
                $errors['email'] = 'Email đã được tài khoản khác sử dụng.';
            }
            $data['email'] = $email;
        }
        if ($newPass !== '') {
            if (mb_strlen($newPass) < 8) {
                $errors['new_password'] = 'Mật khẩu mới phải có ít nhất 8 ký tự.';
            } elseif (str_contains($newPass, "\0")) {
                // bcrypt (PHP 8.4+) ném ValueError khi gặp byte null.
                $errors['new_password'] = 'Mật khẩu chứa ký tự không hợp lệ.';
            } elseif ($newPass !== $str('new_password_confirm')) {
                $errors['new_password_confirm'] = 'Mật khẩu nhập lại không khớp.';
            }
            if (!$errors) {
                $data['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
            }
        }

        if ($errors) {
            Session::keepOld(array_filter($in, 'is_string'));
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect('/admin/profile');
        }

        db()->update('users', $data, ['id' => $u['id']]);
        update_option('admin_bar_' . $u['id'], !empty($in['admin_bar']), false);
        if (isset($data['password_hash'])) {
            // Đổi mật khẩu: huỷ mọi phiên "ghi nhớ" trên thiết bị khác; phiên đang mở ở nơi khác mất hiệu lực nhờ dấu _pw
            // (Auth::user), còn phiên này gắn dấu mới để không bị đăng xuất.
            db()->delete('user_tokens', ['user_id' => $u['id']]);
            session_regenerate_id(true);
            \LQ\Core\Auth::refreshStamp((int) $u['id']);
        }
        flash('success', 'Đã cập nhật hồ sơ.');
        redirect('/admin/profile');
    }

}
