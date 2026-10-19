<?php
declare(strict_types=1);

namespace LQ\Content;

use finfo;
use LQ\Core\Database;
use LQ\Core\Slug;

/**
 * Thư viện media: lưu ảnh vào public/content/uploads/YYYY/MM/ và ghi vào bảng media.
 * Chỉ lưu đúng tệp gốc người dùng tải lên: không tạo cỡ ảnh phụ, không nén hay xoay lại.
 */
final class Media
{
    public const MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    /* ---------- Tải lên ---------- */

    /** @return array{0: ?array, 1: ?string} [media, lỗi] */
    public static function upload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [null, match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Tệp vượt quá dung lượng cho phép của máy chủ (' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_NO_FILE => 'Chưa chọn tệp.',
                default => 'Tải tệp lên thất bại (mã ' . ($file['error'] ?? '?') . ').',
            }];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return [null, 'Tệp không hợp lệ.'];
        }
        $maxMb = (int) option('upload_max_mb', 8);
        if ($file['size'] > $maxMb * 1024 * 1024) {
            return [null, "Ảnh vượt quá {$maxMb} MB."];
        }
        // Xác định loại theo nội dung thật, không tin phần mở rộng hay MIME trình duyệt gửi.
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::MIME[$mime]) || @getimagesize($file['tmp_name']) === false) {
            return [null, 'Chỉ hỗ trợ ảnh JPG, PNG, GIF, WEBP, AVIF.'];
        }

        // Thư mục năm/tháng theo múi giờ của website (không theo UTC), vd. uploads/2026/11/.
        $sub = (new \DateTimeImmutable('now', site_timezone()))->format('Y/m');
        $dir = LQ_CONTENT . '/uploads/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [null, 'Không tạo được thư mục uploads (kiểm tra quyền ghi).'];
        }
        $ext = self::MIME[$mime];
        $base = Slug::make(pathinfo((string) $file['name'], PATHINFO_FILENAME), 80) ?: 'anh';
        $name = "$base.$ext";
        for ($i = 2; is_file("$dir/$name"); $i++) {
            $name = "$base-$i.$ext";
        }
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            return [null, 'Không lưu được tệp.'];
        }
        @chmod("$dir/$name", 0644);

        $media = self::register("/content/uploads/$sub/$name", (string) $file['name'], (int) ($_SESSION['uid'] ?? 0) ?: null);
        do_action('media_uploaded', $media);
        do_action('image_uploaded', $media['url']);
        return [$media, null];
    }

    /** Ghi một tệp đã nằm trong uploads vào bảng media. */
    public static function register(string $path, string $originalName = '', ?int $authorId = null, ?string $createdAt = null): array
    {
        $abs = LQ_PUBLIC . $path;
        $info = @getimagesize($abs) ?: [null, null];
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs);
        $id = db()->insert('media', [
            'path' => $path,
            'file_name' => mb_substr($originalName !== '' ? $originalName : basename($path), 0, 191),
            'mime' => $mime,
            'size' => (int) filesize($abs),
            'width' => $info[0],
            'height' => $info[1],
            'alt' => '',
            'caption' => '',
            'author_id' => $authorId,
            'created_at' => $createdAt ?? now_utc(),
        ]);
        return self::find($id);
    }

    /** Nhập các ảnh đã có trong uploads mà chưa nằm trong thư viện (dùng khi nâng cấp). */
    public static function importExisting(): int
    {
        $root = LQ_CONTENT . '/uploads';
        if (!is_dir($root)) {
            return 0;
        }
        $known = array_flip(db()->column('SELECT path FROM {media}'));
        $count = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = '/content/uploads/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            $mime = $f->isFile() ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($f->getPathname()) : '';
            if (!isset(self::MIME[$mime]) || isset($known[$path])) {
                continue;
            }
            self::register($path, basename($path), null, gmdate('Y-m-d H:i:s', $f->getMTime()));
            $count++;
        }
        return $count;
    }

    /* ---------- Đọc ---------- */

    public static function find(int $id): ?array
    {
        $r = db()->row('SELECT m.*, u.display_name AS author_name FROM {media} m LEFT JOIN {users} u ON u.id = m.author_id WHERE m.id = ?', [$id]);
        return $r ? self::present($r) : null;
    }

    public static function findByPath(string $path): ?array
    {
        $r = db()->row('SELECT m.*, NULL AS author_name FROM {media} m WHERE m.path = ?', [$path]);
        return $r ? self::present($r) : null;
    }

    /** Chuẩn hoá bản ghi cho UI (chỉ có ảnh gốc, không tạo cỡ ảnh phụ). */
    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'url' => $r['path'],
            'file_name' => $r['file_name'],
            'mime' => $r['mime'],
            'ext' => strtoupper(self::MIME[$r['mime']] ?? pathinfo($r['path'], PATHINFO_EXTENSION)),
            'size' => (int) $r['size'],
            'size_label' => self::bytes((int) $r['size']),
            'width' => $r['width'] !== null ? (int) $r['width'] : null,
            'height' => $r['height'] !== null ? (int) $r['height'] : null,
            'alt' => $r['alt'],
            'caption' => $r['caption'],
            'author_id' => $r['author_id'] !== null ? (int) $r['author_id'] : null,
            'author_name' => $r['author_name'] ?? null,
            'created_at' => $r['created_at'],
            'date_label' => format_date($r['created_at']),
        ];
    }

    /** @return array{items: array, total: int} */
    public static function paginate(string $q, string $month, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(m.file_name LIKE ? OR m.path LIKE ? OR m.alt LIKE ? OR m.caption LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, '%' . (Slug::make($q) ?: $like) . '%', $like, $like);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $where[] = "DATE_FORMAT(CONVERT_TZ(m.created_at, '+00:00', ?), '%Y-%m') = ?";
            $params[] = self::tzOffset();
            $params[] = $month;
        }
        $w = implode(' AND ', $where);
        $total = (int) db()->value("SELECT COUNT(*) FROM {media} m WHERE $w", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = db()->all(
            "SELECT m.*, u.display_name AS author_name FROM {media} m LEFT JOIN {users} u ON u.id = m.author_id
             WHERE $w ORDER BY m.created_at DESC, m.id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return ['items' => array_map([self::class, 'present'], $rows), 'total' => $total];
    }

    /** Các tháng có ảnh, mới nhất trước: ['2026-10' => 'Tháng 10/2026']. */
    public static function months(): array
    {
        $out = [];
        foreach (db()->column("SELECT DISTINCT DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', ?), '%Y-%m') FROM {media} ORDER BY 1 DESC", [self::tzOffset()]) as $m) {
            [$y, $mo] = explode('-', $m);
            $out[$m] = 'Tháng ' . (int) $mo . '/' . $y;
        }
        return $out;
    }

    /** Bài viết / trang đang dùng ảnh (ảnh đại diện, trong nội dung hoặc trong field). */
    public static function usage(array $media): array
    {
        $like = '%' . addcslashes($media['url'], '%_\\') . '%';
        return db()->all(
            "SELECT p.id, p.title, p.type, p.status FROM {posts} p
             WHERE p.status <> 'trash' AND (p.featured_image = ? OR p.content LIKE ?
                   OR EXISTS (SELECT 1 FROM {post_meta} pm WHERE pm.post_id = p.id AND pm.meta_value LIKE ?))
             ORDER BY p.updated_at DESC LIMIT 20",
            [$media['url'], $like, $like] // post_meta lưu JSON không escape "/" nên so khớp trực tiếp được
        );
    }

    /* ---------- Sửa / xoá ---------- */

    public static function update(int $id, array $in): void
    {
        db()->update('media', [
            'alt' => mb_substr(trim((string) ($in['alt'] ?? '')), 0, 255),
            'caption' => mb_substr(trim((string) ($in['caption'] ?? '')), 0, 2000),
        ], ['id' => $id]);
    }

    /** Xoá bản ghi và tệp ảnh. Ảnh đại diện đang trỏ tới ảnh này được gỡ. */
    public static function delete(int $id): bool
    {
        $m = self::find($id);
        if (!$m) {
            return false;
        }
        do_action('media_deleting', $m);
        $abs = realpath(LQ_PUBLIC . $m['url']);
        if ($abs && str_starts_with($abs, realpath(LQ_CONTENT . '/uploads') . DIRECTORY_SEPARATOR)) {
            @unlink($abs);
        }
        db()->transaction(function (Database $db) use ($m, $id): void {
            $db->update('posts', ['featured_image' => null], ['featured_image' => $m['url']]);
            $db->delete('media', ['id' => $id]);
        });
        return true;
    }

    public static function canEdit(array $m): bool
    {
        return can('edit_others_posts') || ($m['author_id'] !== null && $m['author_id'] === (int) (current_user()['id'] ?? 0));
    }

    /** Độ lệch múi giờ của website dạng "+07:00" (CONVERT_TZ dùng được mà không cần bảng múi giờ của MariaDB). */
    private static function tzOffset(): string
    {
        return (new \DateTimeImmutable('now', site_timezone()))->format('P');
    }

    public static function bytes(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : ($b >= 1024 ? round($b / 1024) . ' KB' : "$b B");
    }
}
