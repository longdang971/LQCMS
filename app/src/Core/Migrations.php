<?php
declare(strict_types=1);

namespace LQ\Core;

/**
 * Nâng cấp database theo phiên bản (option db_version). Mỗi bước chỉ chạy một lần,
 * nên site đã cài từ trước vẫn có bảng mới mà không phải cài lại.
 */
final class Migrations
{
    public const LATEST = 5;

    public static function run(): void
    {
        $current = (int) option('db_version', 1);
        if ($current >= self::LATEST) {
            return;
        }
        for ($v = $current + 1; $v <= self::LATEST; $v++) {
            $method = "v$v";
            self::$method(db());
            update_option('db_version', $v);
        }
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
}
