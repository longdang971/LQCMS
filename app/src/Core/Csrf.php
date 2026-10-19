<?php
declare(strict_types=1);

namespace LQ\Core;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function valid(): bool
    {
        $t = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($t) && $t !== '' && hash_equals(self::token(), $t);
    }
}
