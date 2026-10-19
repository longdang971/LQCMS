<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Auth;
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
        $errors = [];
        $data = [];

        $name = trim((string) ($in['display_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) {
            $errors['display_name'] = 'Tên hiển thị không được để trống.';
        }
        $data['display_name'] = $name;

        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $newPass = (string) ($in['new_password'] ?? '');
        $sensitive = $email !== $u['email'] || $newPass !== '';

        // Đổi email hoặc mật khẩu phải xác nhận mật khẩu hiện tại.
        if ($sensitive && !password_verify((string) ($in['current_password'] ?? ''), $u['password_hash'])) {
            $errors['current_password'] = 'Mật khẩu hiện tại không đúng.';
        }
        if ($email !== $u['email']) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Email không hợp lệ.';
            } elseif (db()->value('SELECT 1 FROM {users} WHERE email = ? AND id <> ?', [$email, $u['id']])) {
                $errors['email'] = 'Email đã được tài khoản khác sử dụng.';
            }
            $data['email'] = $email;
        }
        if ($newPass !== '') {
            if (mb_strlen($newPass) < 8) {
                $errors['new_password'] = 'Mật khẩu mới phải có ít nhất 8 ký tự.';
            } elseif ($newPass !== (string) ($in['new_password_confirm'] ?? '')) {
                $errors['new_password_confirm'] = 'Mật khẩu nhập lại không khớp.';
            }
            $data['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
        }

        if ($errors) {
            Session::keepOld($in);
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect('/admin/profile');
        }

        db()->update('users', $data, ['id' => $u['id']]);
        if (isset($data['password_hash'])) {
            // Đổi mật khẩu: huỷ mọi phiên "ghi nhớ" trên thiết bị khác.
            db()->delete('user_tokens', ['user_id' => $u['id']]);
            session_regenerate_id(true);
        }
        flash('success', 'Đã cập nhật hồ sơ.');
        redirect('/admin/profile');
    }

    public static function roleLabel(string $role): string
    {
        return Auth::ROLES[$role] ?? $role;
    }
}
