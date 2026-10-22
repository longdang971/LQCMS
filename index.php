<?php
declare(strict_types=1);

// Khi chạy bằng `php -S` (dev): trả thẳng file tĩnh; chặn thư mục chỉ dành cho máy chủ và mọi tệp PHP khác index.php
// (giống luật trong nginx.conf.example).
if (PHP_SAPI === 'cli-server') {
    $path = (string) $_SERVER['REQUEST_URI'];
    $path = rawurldecode(substr($path, 0, strcspn($path, '?#'))); // như request_path(): parse_url hỏng với "/a:1/"
    // Tệp ẩn (.htaccess, .DS_Store, .gitignore…) cũng chặn như nginx — trừ .well-known (xác minh SSL/domain).
    // Byte NUL (%00) làm realpath() ném ValueError → chặn luôn.
    if (str_contains($path, "\0")
        || preg_match('#^/(app|storage|database)(/|$)|^/(config\.php|[^/]+\.md|nginx\.conf\.example)$|/\.(?!well-known(/|$))#i', $path)) {
        http_response_code(404);
        exit('Not Found');
    }
    $file = realpath(__DIR__ . $path);
    if ($file !== false && str_starts_with($file, __DIR__ . '/') && is_file($file) && $file !== __FILE__) {
        // Không phân biệt hoa thường: trên ổ đĩa macOS/Windows "/content/x.PHP" vẫn trỏ tới x.php và php -S chạy nó như PHP.
        if (preg_match('/\.(php|phtml|phar)$/i', $file)) {
            http_response_code(403);
            exit('Forbidden');
        }
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

LQ\Core\App::run();
