<?php
declare(strict_types=1);

namespace LQ\Content;

/**
 * Chống mất bài:
 *  - Phiên bản (kind = revision): tạo mỗi lần bấm lưu nếu tiêu đề/nội dung đổi; giữ N bản gần nhất.
 *  - Bản tự lưu (kind = autosave): mỗi người một bản cho mỗi bài, ghi đè liên tục, không đụng bài đang hiển thị.
 *  - Khoá chỉnh sửa (post_meta "_edit_lock"): biết ai đang mở bài để cảnh báo khi hai người cùng sửa.
 */
final class Revisions
{
    /** Khoá coi là còn hiệu lực trong khoảng này (trình duyệt gửi nhịp mỗi 30 giây). */
    public const LOCK_TTL = 90;

    public static function keep(): int
    {
        return max(0, (int) option('revisions_keep', 25));
    }

    /** Ghi phiên bản sau khi lưu bài. Lần đầu (bài cũ chưa có lịch sử) lưu luôn trạng thái trước đó. */
    public static function record(array $before, array $after, int $userId): void
    {
        if (self::keep() === 0) {
            return;
        }
        $postId = (int) $after['id'];
        $last = db()->row("SELECT title, content FROM {post_revisions} WHERE post_id = ? AND kind = 'revision' ORDER BY id DESC LIMIT 1", [$postId]);
        if (!$last && $before && ($before['title'] !== '' || $before['content'] !== '')) {
            self::insert($postId, 'revision', $before['title'], $before['content'], $before['author_id'] ? (int) $before['author_id'] : null, $before['updated_at']);
            $last = $before;
        }
        if ($last && $last['title'] === $after['title'] && $last['content'] === $after['content']) {
            return;
        }
        self::insert($postId, 'revision', $after['title'], $after['content'], $userId);
        self::prune($postId);
    }

    private static function insert(int $postId, string $kind, string $title, string $content, ?int $userId, ?string $at = null): int
    {
        return db()->insert('post_revisions', [
            'post_id' => $postId,
            'kind' => $kind,
            'title' => $title,
            'content' => $content,
            'author_id' => $userId,
            'created_at' => $at ?? now_utc(),
        ]);
    }

    private static function prune(int $postId): void
    {
        $ids = db()->column("SELECT id FROM {post_revisions} WHERE post_id = ? AND kind = 'revision' ORDER BY id DESC LIMIT 1000 OFFSET " . self::keep(), [$postId]);
        if ($ids) {
            db()->query('DELETE FROM {post_revisions} WHERE id IN (' . \LQ\Core\Database::placeholders($ids) . ')', $ids);
        }
    }

    /* ---------- Bản tự lưu ---------- */

    public static function autosave(int $postId, int $userId, string $title, string $content): string
    {
        db()->query("DELETE FROM {post_revisions} WHERE post_id = ? AND author_id = ? AND kind = 'autosave'", [$postId, $userId]);
        self::insert($postId, 'autosave', $title, $content, $userId);
        return now_utc();
    }

    /** Bản tự lưu của người dùng nếu mới hơn và khác bản đã lưu của bài. */
    public static function pendingAutosave(array $post, int $userId): ?array
    {
        $a = db()->row("SELECT * FROM {post_revisions} WHERE post_id = ? AND author_id = ? AND kind = 'autosave' ORDER BY id DESC LIMIT 1", [$post['id'], $userId]);
        if (!$a || $a['created_at'] <= $post['updated_at'] || ($a['title'] === $post['title'] && $a['content'] === $post['content'])) {
            return null;
        }
        return $a;
    }

    public static function clearAutosave(int $postId, int $userId): void
    {
        db()->query("DELETE FROM {post_revisions} WHERE post_id = ? AND author_id = ? AND kind = 'autosave'", [$postId, $userId]);
    }

    /* ---------- Danh sách & khôi phục ---------- */

    public static function forPost(int $postId): array
    {
        return db()->all(
            "SELECT r.id, r.kind, r.title, r.author_id, r.created_at, u.display_name AS author_name, CHAR_LENGTH(r.content) AS length
             FROM {post_revisions} r LEFT JOIN {users} u ON u.id = r.author_id
             WHERE r.post_id = ? ORDER BY r.created_at DESC, r.id DESC",
            [$postId]
        );
    }

    public static function count(int $postId): int
    {
        return (int) db()->value("SELECT COUNT(*) FROM {post_revisions} WHERE post_id = ? AND kind = 'revision'", [$postId]);
    }

    public static function find(int $id, int $postId): ?array
    {
        return db()->row('SELECT r.*, u.display_name AS author_name FROM {post_revisions} r LEFT JOIN {users} u ON u.id = r.author_id WHERE r.id = ? AND r.post_id = ?', [$id, $postId]);
    }

    /** Đưa tiêu đề/nội dung của phiên bản về bài (tạo phiên bản mới ghi lại việc khôi phục). */
    public static function restore(array $post, array $rev, int $userId): void
    {
        db()->update('posts', ['title' => $rev['title'], 'content' => $rev['content'], 'updated_at' => now_utc()], ['id' => $post['id']]);
        self::record($post, ['id' => $post['id'], 'title' => $rev['title'], 'content' => $rev['content']], $userId);
        do_action('post_revision_restored', (int) $post['id'], (int) $rev['id']);
    }

    /* ---------- Khoá chỉnh sửa ---------- */

    /** Người đang giữ bài (khác $userId) nếu khoá còn hiệu lực. */
    public static function lockedBy(int $postId, int $userId): ?array
    {
        $lock = PostMeta::get($postId, '_edit_lock');
        if (!is_array($lock) || (int) ($lock['user'] ?? 0) === $userId || time() - (int) ($lock['at'] ?? 0) > self::LOCK_TTL) {
            return null;
        }
        $u = \LQ\Core\Auth::find((int) $lock['user']);
        return $u ? ['id' => (int) $u['id'], 'name' => $u['display_name'] ?: $u['username'], 'since' => (int) $lock['at']] : null;
    }

    /**
     * Nhịp giữ khoá. Trả về người đang giữ nếu là người khác (khi đó không giành, trừ khi $take = true).
     */
    public static function heartbeat(int $postId, int $userId, bool $take = false): ?array
    {
        $other = self::lockedBy($postId, $userId);
        if ($other && !$take) {
            return $other;
        }
        PostMeta::set($postId, '_edit_lock', ['user' => $userId, 'at' => time()]);
        return null;
    }

    public static function release(int $postId, int $userId): void
    {
        $lock = PostMeta::get($postId, '_edit_lock');
        if (is_array($lock) && (int) ($lock['user'] ?? 0) === $userId) {
            PostMeta::delete($postId, '_edit_lock');
        }
    }
}
