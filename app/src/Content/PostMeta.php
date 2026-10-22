<?php
declare(strict_types=1);

namespace LQ\Content;

/** Đọc/ghi dữ liệu riêng của bài viết. Giá trị lưu dạng JSON nên giữ nguyên kiểu (chuỗi, số, mảng, bool). */
final class PostMeta
{
    private static array $cache = [];

    public static function all(int $postId): array
    {
        if (!isset(self::$cache[$postId])) {
            self::$cache[$postId] = [];
            foreach (db()->all('SELECT meta_key, meta_value FROM {post_meta} WHERE post_id = ?', [$postId]) as $r) {
                self::$cache[$postId][$r['meta_key']] = json_decode($r['meta_value'], true);
            }
        }
        return self::$cache[$postId];
    }

    public static function get(int $postId, string $key, mixed $default = null): mixed
    {
        return self::all($postId)[$key] ?? $default;
    }

    /** $keepEmpty: lưu cả giá trị rỗng thay vì xoá dòng (field có giá trị mặc định mà người dùng chủ động để trống). */
    public static function set(int $postId, string $key, mixed $value, bool $keepEmpty = false): void
    {
        // null luôn xoá dòng (vd. hoàn tác về lúc bài chưa từng có field này); '' / [] chỉ xoá khi không cần giữ.
        if ($value === null || (!$keepEmpty && ($value === '' || $value === []))) {
            self::delete($postId, $key);
            return;
        }
        db()->query(
            'INSERT INTO {post_meta} (post_id, meta_key, meta_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)',
            [$postId, $key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        unset(self::$cache[$postId]);
    }

    public static function delete(int $postId, string $key): void
    {
        db()->delete('post_meta', ['post_id' => $postId, 'meta_key' => $key]);
        unset(self::$cache[$postId]);
    }
}
