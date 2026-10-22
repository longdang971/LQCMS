<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Auth;
use LQ\Core\Session;
use LQ\Core\View;

final class AuthController
{
    public static function loginForm(): void
    {
        if (Auth::user()) {
            redirect('/admin');
        }
        View::render('admin/auth/login', [
            'title' => 'Đăng nhập',
            'redirect' => self::safeRedirect(is_string($_GET['redirect'] ?? null) ? $_GET['redirect'] : ''),
            'canRegister' => (bool) option('users_can_register'),
        ], 'admin/auth-layout');
    }

    public static function login(): void
    {
        $login = input_str('login');
        $password = $_POST['password'] ?? '';
        $error = Auth::attempt($login, is_string($password) ? $password : '', !empty($_POST['remember']));
        $to = self::safeRedirect(input_str('redirect')) ?: '/admin';
        if ($error) {
            Session::keepOld(['login' => $login]);
            flash('error', $error);
            redirect('/admin/login' . ($to !== '/admin' ? '?redirect=' . rawurlencode($to) : ''));
        }
        flash('success', 'Chào mừng trở lại, ' . current_user()['display_name'] . '!');
        redirect($to);
    }

    public static function registerForm(): void
    {
        if (Auth::user()) {
            redirect('/admin');
        }
        View::render('admin/auth/register', [
            'title' => 'Đăng ký',
            'enabled' => (bool) option('users_can_register'),
            'errors' => $_SESSION['_errors'] ?? [],
        ], 'admin/auth-layout');
        unset($_SESSION['_errors']);
    }

    public static function register(): void
    {
        if (!option('users_can_register')) {
            flash('error', 'Website hiện không cho phép đăng ký.');
            redirect('/admin/login');
        }
        // Bẫy bot đơn giản: ô ẩn phải để trống.
        if (input_str('website') !== '') {
            redirect('/admin/login');
        }
        [$errors, $id] = Auth::register($_POST);
        if ($errors) {
            Session::keepOld(array_filter($_POST, 'is_string')); // form chỉ có ô chữ; ô gửi dạng mảng làm e(old()) báo "Array to string"
            $_SESSION['_errors'] = $errors;
            flash('error', 'Vui lòng kiểm tra lại thông tin đăng ký.');
            redirect('/admin/register');
        }
        Auth::login(Auth::find($id));
        flash('success', 'Đăng ký thành công. Chào mừng bạn!');
        redirect('/admin');
    }

    public static function logout(): void
    {
        Auth::logout();
        flash('success', 'Bạn đã đăng xuất.');
        redirect('/admin/login');
    }

    /** Chỉ cho redirect về đường dẫn nội bộ trong /admin. */
    private static function safeRedirect(string $to): string
    {
        return preg_match('#^/admin(/[^\s]*)?$#', $to) && !str_starts_with($to, '//') ? $to : '';
    }
}
