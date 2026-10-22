<?php
declare(strict_types=1);

namespace LQ\Core;

/**
 * Nâng cấp database theo phiên bản (option db_version). Mỗi bước chỉ chạy một lần,
 * nên site đã cài từ trước vẫn có bảng mới mà không phải cài lại.
 */
final class Migrations
{
    public const LATEST = 6;

    public static function run(): void
    {
        if ((int) option('db_version', 1) >= self::LATEST) {
            return;
        }
        // Ngay sau khi cập nhật mã, nhiều request cùng lúc thấy db_version cũ: chỉ một request được nâng cấp,
        // các request khác chờ nó xong (tối đa 10 giây — phải dưới max_execution_time 30 giây mặc định, macOS tính cả lúc chờ)
        // — chạy song song thì bước ALTER/CREATE trùng nhau gây lỗi 500.
        $lock = (string) db()->value("SELECT CONCAT('lq_migrate_', MD5(CONCAT(DATABASE(), '.', ?)))", [db()->prefix]);
        if ((int) db()->value('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
            self::unavailable('Website đang nâng cấp cơ sở dữ liệu, vui lòng tải lại sau ít giây.');
        }
        try {
            // Đọc lại từ DB (không lấy cache của request này): request giữ khoá trước có thể đã nâng cấp xong.
            $current = (int) json_decode((string) db()->value("SELECT value FROM {options} WHERE name = 'db_version'"), true) ?: 1;
            for ($v = $current + 1; $v <= self::LATEST; $v++) {
                $method = "v$v";
                try {
                    self::$method(db());
                } catch (\Throwable $e) {
                    // Bước lỗi: ghi nhật ký và báo bảo trì (503) thay vì trang lỗi 500 trần; db_version giữ ở bước trước
                    // nên lần truy cập sau chạy lại đúng bước này.
                    ErrorLog::add('error', "Nâng cấp CSDL bước v$v lỗi: " . $e->getMessage(), $e->getFile(), $e->getLine());
                    db()->value('SELECT RELEASE_LOCK(?)', [$lock]); // exit trong unavailable() bỏ qua finally
                    self::unavailable('Nâng cấp cơ sở dữ liệu gặp lỗi. Quản trị viên xem chi tiết trong storage/logs/errors.php.');
                }
                update_option('db_version', $v);
            }
        } finally {
            db()->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Trang bảo trì 503 (không dùng view/theme — chúng có thể cần đúng bảng đang nâng cấp). */
    private static function unavailable(string $message): never
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(503);
            header('Retry-After: 10');
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><title>Đang bảo trì</title>'
            . '<p style="font:16px/1.6 system-ui,sans-serif;max-width:520px;margin:15vh auto;padding:0 16px">' . htmlspecialchars($message) . '</p>';
        exit;
    }

    /** v2: bảng post_meta cho field tuỳ biến. */
    private static function v2(Database $db): void
    {
        $db->pdo->exec($db->sql("CREATE TABLE IF NOT EXISTS {post_meta} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(191) NOT NULL,
            meta_value LONGTEXT NOT NULL,
            UNIQUE KEY uniq_post_key (post_id, meta_key),
            KEY idx_key (meta_key),
            CONSTRAINT {$db->prefix}fk_meta_post FOREIGN KEY (post_id) REFERENCES {posts} (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
    }

    /** v3: bỏ tính năng tóm tắt bài viết — xoá cột posts.excerpt. */
    private static function v3(Database $db): void
    {
        $db->pdo->exec($db->sql('ALTER TABLE {posts} DROP COLUMN IF EXISTS excerpt'));
    }

    /**
     * v4: trang tĩnh (trang cha, thứ tự, mẫu trang) + thư viện media.
     * Ảnh đã upload trước đó được nhập vào thư viện để không bị "mồ côi".
     */
    private static function v4(Database $db): void
    {
        $cols = $db->column('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefix . 'posts']);
        if (!in_array('parent_id', $cols, true)) {
            $db->pdo->exec($db->sql('ALTER TABLE {posts}
                ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER type,
                ADD COLUMN menu_order INT NOT NULL DEFAULT 0 AFTER parent_id,
                ADD COLUMN template VARCHAR(64) NULL AFTER menu_order,
                ADD KEY idx_type_parent (type, parent_id)'));
        }
        $db->pdo->exec($db->sql("CREATE TABLE IF NOT EXISTS {media} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(255) NOT NULL,
            file_name VARCHAR(191) NOT NULL,
            mime VARCHAR(64) NOT NULL,
            size INT UNSIGNED NOT NULL DEFAULT 0,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            alt VARCHAR(255) NOT NULL DEFAULT '',
            caption TEXT NOT NULL,
            author_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_path (path),
            KEY idx_created (created_at),
            CONSTRAINT {$db->prefix}fk_media_author FOREIGN KEY (author_id) REFERENCES {users} (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
        \LQ\Content\Media::importExisting();
    }

    /** v5: lịch sử phiên bản + bản tự lưu của bài viết/trang. */
    private static function v5(Database $db): void
    {
        $db->pdo->exec($db->sql("CREATE TABLE IF NOT EXISTS {post_revisions} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(16) NOT NULL DEFAULT 'revision',
            title TEXT NOT NULL,
            content LONGTEXT NOT NULL,
            author_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_post_kind (post_id, kind, created_at),
            CONSTRAINT {$db->prefix}fk_rev_post FOREIGN KEY (post_id) REFERENCES {posts} (id) ON DELETE CASCADE,
            CONSTRAINT {$db->prefix}fk_rev_author FOREIGN KEY (author_id) REFERENCES {users} (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
    }

    /** v6: đếm lượt gọi theo IP để giới hạn tần suất action AJAX công khai. */
    private static function v6(Database $db): void
    {
        $db->pdo->exec($db->sql('CREATE TABLE IF NOT EXISTS {rate_limits} (
            bucket VARCHAR(191) NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, window_start),
            KEY idx_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
    }
}
