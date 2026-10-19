<?php
/**
 * Chạy tác vụ định kỳ từ cron của hệ điều hành (khuyên dùng trên VPS):
 *   * * * * * php /var/www/lqcms/bin/cron.php >/dev/null 2>&1
 * Thêm --force để chạy mọi tác vụ ngay, bỏ qua lịch.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Chỉ chạy từ dòng lệnh.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use LQ\Core\App;
use LQ\Core\Cron;
use LQ\Core\Database;
use LQ\Core\Migrations;
use LQ\Core\Options;
use LQ\Core\Plugins;
use LQ\Core\Themes;

if (!is_file(LQ_CONFIG)) {
    fwrite(STDERR, "Chưa cài đặt LQCMS (thiếu config.php).\n");
    exit(1);
}
$config = require LQ_CONFIG;
App::setDb(new Database($config['db']));
Options::load();
Migrations::run();
// Nạp plugin + theme để tác vụ do chúng đăng ký cũng chạy.
Plugins::loadActive();
Themes::loadActiveFunctions();
do_action('init');

$ran = Cron::run('cli', in_array('--force', $argv, true));
if ($ran === null) {
    echo "Đang có tiến trình cron khác chạy, bỏ qua.\n";
    exit(0);
}
foreach ($ran as $name => $r) {
    printf("[%s] %s: %s (%d ms)\n", $r['ok'] ? 'OK' : 'LỖI', $name, $r['ok'] ? $r['result'] : $r['error'], $r['ms']);
}
if (!$ran) {
    echo "Không có tác vụ nào đến hạn.\n";
}
