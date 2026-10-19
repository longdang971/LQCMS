<?php
declare(strict_types=1);

// Khi chạy bằng `php -S` (dev): trả thẳng file tĩnh, chặn chạy trực tiếp file PHP trong content/.
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $file = realpath(__DIR__ . $path);
    if ($file !== false && str_starts_with($file, __DIR__ . '/') && is_file($file) && $file !== __FILE__) {
        if (str_ends_with($file, '.php')) {
            http_response_code(403);
            exit('Forbidden');
        }
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

LQ\Core\App::run();
