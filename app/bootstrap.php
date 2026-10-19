<?php
declare(strict_types=1);

define('LQ_VERSION', '0.1.0');
define('LQ_ROOT', dirname(__DIR__));
define('LQ_APP', LQ_ROOT . '/app');
define('LQ_PUBLIC', LQ_ROOT . '/public');
define('LQ_CONTENT', LQ_PUBLIC . '/content');
define('LQ_CONFIG', LQ_ROOT . '/config.php');

// Mọi mốc thời gian trong DB lưu theo UTC; chỉ đổi sang múi giờ site khi hiển thị.
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'LQ\\')) {
        return;
    }
    $file = LQ_APP . '/src/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require LQ_APP . '/helpers.php';
