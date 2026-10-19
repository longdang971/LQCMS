<?php
declare(strict_types=1);

namespace LQ\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('lqsid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Dữ liệu "old input" chỉ sống qua đúng một request.
        $_SESSION['_old_current'] = $_SESSION['_old_next'] ?? [];
        unset($_SESSION['_old_next']);
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** Lưu input để form hiển thị lại sau redirect khi validate lỗi (không lưu mật khẩu). */
    public static function keepOld(array $data): void
    {
        foreach ($data as $k => $v) {
            if (str_contains((string) $k, 'password') || $k === '_token') {
                unset($data[$k]);
            }
        }
        $_SESSION['_old_next'] = $data;
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old_current'][$key] ?? $default;
    }
}
