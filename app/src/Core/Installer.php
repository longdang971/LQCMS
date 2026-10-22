<?php
declare(strict_types=1);

namespace LQ\Core;

use PDOException;
use Throwable;

/** Trình cài đặt chạy khi chưa có config.php: tạo bảng, tài khoản quản trị, cài đặt mặc định. */
final class Installer
{
    public function handle(string $path): void
    {
        if ($path !== '/install') {
            redirect('/install');
        }
        $errors = [];
        $configText = null;

        if (request_method() === 'POST') {
            if (!Csrf::valid()) {
                $errors['_'] = 'Phiên làm việc đã hết hạn, vui lòng thử lại.';
            } else {
                [$errors, $configText] = $this->install(array_filter($_POST, 'is_string')); // form chỉ có ô chữ
                if (!$errors && $configText === null) {
                    flash('success', 'Cài đặt LQCMS thành công. Chào mừng bạn!');
                    redirect('/admin');
                }
            }
            // keepOld chỉ bỏ khoá chứa "password": đừng để mật khẩu DB nằm trong session. Form chỉ có ô chữ nên bỏ giá trị mảng.
            Session::keepOld(array_diff_key(array_filter($_POST, 'is_string'), ['db_pass' => 1]));
            // Render lại ngay trong request này nên chuyển thẳng sang "current".
            $_SESSION['_old_current'] = $_SESSION['_old_next'];
            unset($_SESSION['_old_next']);
        }

        View::render('install/index', [
            'title' => 'Cài đặt LQCMS',
            'errors' => $errors,
            'configText' => $configText,
            'requirements' => self::requirements(),
        ], 'admin/auth-layout');
    }

    public static function requirements(): array
    {
        return [
            ['PHP ≥ 8.2 (đang chạy ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.2', '>=')],
            ['Extension pdo_mysql', extension_loaded('pdo_mysql')],
            ['Extension mbstring', extension_loaded('mbstring')],
            ['Extension fileinfo', extension_loaded('fileinfo')],
            ['Extension zip (cài theme/plugin từ .zip)', extension_loaded('zip')],
            ['Ghi được thư mục content/uploads', is_writable(LQ_CONTENT . '/uploads')],
        ];
    }

    /** @return array{0: array<string,string>, 1: ?string} [lỗi, nội dung config nếu không ghi được file] */
    private function install(array $in): array
    {
        $db = [
            'host' => trim((string) ($in['db_host'] ?? 'localhost')) ?: 'localhost',
            'port' => (int) ($in['db_port'] ?? 3306) ?: 3306,
            'name' => trim((string) ($in['db_name'] ?? '')),
            'user' => trim((string) ($in['db_user'] ?? '')),
            'pass' => (string) ($in['db_pass'] ?? ''),
            'prefix' => trim((string) ($in['db_prefix'] ?? 'lq_')),
        ];
        $errors = [];
        if ($db['name'] === '' || !preg_match('/^[A-Za-z0-9_$-]+$/', $db['name'])) {
            $errors['db_name'] = 'Tên database không hợp lệ.';
        }
        if ($db['user'] === '') {
            $errors['db_user'] = 'Nhập tên người dùng database.';
        }
        if (!preg_match('/^[a-z0-9_]{0,20}$/', $db['prefix'])) {
            $errors['db_prefix'] = 'Prefix chỉ gồm chữ thường, số, "_" (tối đa 20 ký tự).';
        }
        $siteTitle = trim((string) ($in['site_title'] ?? ''));
        if ($siteTitle === '') {
            $errors['site_title'] = 'Nhập tên website.';
        }
        if ($errors) {
            return [$errors, null];
        }

        try {
            $database = new Database($db);
        } catch (PDOException $e) {
            return [['_' => 'Không kết nối được database: ' . $e->getMessage()], null];
        }
        App::setDb($database);

        // Bất kỳ bảng nào trùng tên (của bản cài cũ hay ứng dụng khác dùng chung database) đều chặn: schema dùng
        // CREATE TABLE IF NOT EXISTS nên sẽ lặng lẽ dùng bảng lạ, và khi cài lỗi dropTables() sẽ xoá mất bảng không phải của mình.
        $names = array_map(fn ($t) => $db['prefix'] . $t, $this->schemaTables());
        $taken = $database->pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_map([$database->pdo, 'quote'], $names)) . ')'
        )->fetchAll(\PDO::FETCH_COLUMN);
        if ($taken) {
            return [['_' => 'Database đã có bảng ' . implode(', ', $taken) . '. Hãy đổi prefix hoặc xoá bảng cũ.'], null];
        }

        try {
            foreach (array_filter(array_map('trim', explode(';', $this->schemaSql()))) as $sql) {
                $database->pdo->exec($database->sql($sql));
            }
            [$userErrors, $userId] = Auth::register([
                'username' => $in['admin_username'] ?? '',
                'email' => $in['admin_email'] ?? '',
                'password' => $in['admin_password'] ?? '',
                'password_confirm' => $in['admin_password_confirm'] ?? '',
                'display_name' => $in['admin_username'] ?? '',
            ], 'admin');
            if ($userErrors) {
                $this->dropTables($database);
                return [array_combine(array_map(fn ($k) => "admin_$k", array_keys($userErrors)), $userErrors), null];
            }
            $this->seed($database, $userId, $siteTitle, (string) $in['admin_email']);
        } catch (Throwable $e) {
            $this->dropTables($database);
            return [['_' => 'Cài đặt thất bại: ' . $e->getMessage()], null];
        }

        $config = "<?php\n// Được tạo bởi trình cài đặt LQCMS lúc " . gmdate('Y-m-d H:i:s') . " UTC\nreturn " . var_export([
            'db' => $db,
        ], true) . ";\n";

        Auth::login(Auth::find($userId));

        if (@file_put_contents(LQ_CONFIG, $config, LOCK_EX) === false) {
            // Không ghi được (thư mục gốc chỉ đọc): hiển thị để người dùng tự tạo file.
            return [[], $config];
        }
        @chmod(LQ_CONFIG, 0640);
        return [[], null];
    }

    private function schemaSql(): string
    {
        $sql = (string) file_get_contents(LQ_ROOT . '/database/schema.sql');
        return (string) preg_replace('/^--.*$/m', '', $sql);
    }

    /** Tên bảng (chưa có prefix) mà schema.sql tạo, theo thứ tự trong tệp. */
    private function schemaTables(): array
    {
        preg_match_all('/CREATE TABLE IF NOT EXISTS \\{([a-z_]+)\\}/i', $this->schemaSql(), $m);
        return $m[1];
    }

    /** Cài lỗi giữa chừng: xoá bảng vừa tạo. Trước khi tạo đã chắc không bảng nào trùng tên có sẵn, nên chỉ xoá bảng của mình. */
    private function dropTables(Database $db): void
    {
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse($this->schemaTables()) as $t) {
            $db->pdo->exec('DROP TABLE IF EXISTS ' . $db->table($t));
        }
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seed(Database $db, int $userId, string $siteTitle, string $email): void
    {
        $now = now_utc();
        $catId = $db->insert('terms', [
            'taxonomy' => 'category', 'name' => 'Chưa phân loại', 'slug' => 'chua-phan-loai', 'parent_id' => null, 'description' => '',
        ]);
        $scheme = is_https() ? 'https' : 'http';
        $defaults = [
            'site_title' => $siteTitle,
            'tagline' => 'Một website nữa dùng LQCMS',
            'site_url' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'admin_email' => mb_strtolower(trim($email)),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'date_format' => 'd/m/Y',
            'time_format' => 'H:i',
            'posts_per_page' => 10,
            'users_can_register' => false,
            'default_role' => 'subscriber',
            'default_category' => $catId,
            'permalink_structure' => '/%postname%/',
            'category_base' => 'chuyen-muc',
            'tag_base' => 'the',
            'active_theme' => 'lq-default',
            'active_plugins' => [],
            'upload_max_mb' => 8,
            'media_keep_original' => true,
            'media_max_width' => 2560,
            'media_max_height' => 2560,
            'media_quality' => 82,
            'db_version' => Migrations::LATEST,
        ];
        foreach ($defaults as $k => $v) {
            Options::set($k, $v);
        }

        $postId = $db->insert('posts', [
            'type' => 'post',
            'title' => 'Xin chào thế giới!',
            'slug' => 'xin-chao-the-gioi',
            'content' => '<p>Chào mừng bạn đến với LQCMS. Đây là bài viết đầu tiên — hãy sửa hoặc xoá nó rồi bắt đầu viết nhé!</p>',
            'status' => 'published',
            'author_id' => $userId,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->insert('post_terms', ['post_id' => $postId, 'term_id' => $catId]);
    }
}
