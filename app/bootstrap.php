<?php
declare(strict_types=1);

define('LQ_VERSION', '0.2.0');
define('LQ_ROOT', dirname(__DIR__));
define('LQ_APP', LQ_ROOT . '/app');
define('LQ_CONTENT', LQ_ROOT . '/content');
/**
 * config.php (mật khẩu DB) nằm NGOÀI web root: ở thư mục cha, đặt tên theo thư mục project để nhiều site chung
 * một thư mục cha không đè nhau — vd. /var/www/example.com/ → /var/www/example.com-config.php.
 * Nhờ vậy dù máy chủ cấu hình sai (trả tệp .php như văn bản) cũng không ai tải được.
 * Không ghi được thư mục cha lúc cài (quyền, open_basedir của panel) thì trình cài đặt ghi tạm config.php trong
 * thư mục gốc và admin hiện cảnh báo; khi thư mục cha ghi được trở lại, request kế tiếp tự chuyển tệp ra ngoài.
 */
define('LQ_CONFIG_OUTSIDE', dirname(LQ_ROOT) . '/' . basename(LQ_ROOT) . '-config.php');
define('LQ_CONFIG', (static function (): string {
    $inside = LQ_ROOT . '/config.php';
    // @: open_basedir chặn đọc thư mục cha thì PHP báo warning — coi như không có.
    if (@is_file(LQ_CONFIG_OUTSIDE)) {
        return LQ_CONFIG_OUTSIDE;
    }
    $parentWritable = @is_writable(dirname(LQ_ROOT));
    if (is_file($inside)) {
        // rename thất bại vì request song song vừa chuyển xong thì tệp đã ở ngoài — không được trả $inside (đã mất) kẻo hiện trình cài đặt.
        return $parentWritable && (@rename($inside, LQ_CONFIG_OUTSIDE) || @is_file(LQ_CONFIG_OUTSIDE)) ? LQ_CONFIG_OUTSIDE : $inside;
    }
    return $parentWritable ? LQ_CONFIG_OUTSIDE : $inside; // cài mới: trình cài đặt ghi vào đây
})());

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

// Ghi lỗi PHP vào storage/logs/errors.php (trợ lý AI đọc được) — PHP vẫn xử lý lỗi như bình thường.
LQ\Core\ErrorLog::register();

require LQ_APP . '/helpers.php';
require LQ_APP . '/template.php';
