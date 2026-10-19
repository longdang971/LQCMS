<?php
declare(strict_types=1);

namespace LQ\Core;

use LQ\Admin\Kernel;
use RuntimeException;

final class App
{
    private static ?Database $db = null;

    public static function run(): void
    {
        Session::start();
        $path = request_path();

        if (!is_file(LQ_CONFIG)) {
            (new Installer())->handle($path);
            return;
        }

        $config = require LQ_CONFIG;
        self::$db = new Database($config['db']);
        Options::load();
        Migrations::run();

        Plugins::loadActive();
        Themes::loadActiveFunctions();
        do_action('init');
        Cron::maybeRunOnRequest();

        if ($path === '/install') {
            redirect(admin_url());
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            Kernel::handle($path);
            return;
        }

        Themes::renderFront($path);
    }

    public static function db(): Database
    {
        return self::$db ?? throw new RuntimeException('Chưa kết nối cơ sở dữ liệu.');
    }

    public static function setDb(Database $db): void
    {
        self::$db = $db;
    }
}
