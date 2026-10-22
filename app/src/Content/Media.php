<?php
declare(strict_types=1);

namespace LQ\Content;

use finfo;
use LQ\Core\Database;
use LQ\Core\Slug;

/**
 * Thư viện media: lưu ảnh vào content/uploads/YYYY/MM/ và ghi vào bảng media.
 * Mỗi ảnh chỉ có MỘT tệp, không tạo cỡ ảnh phụ. Mặc định giữ nguyên tệp gốc (option media_keep_original);
 * khi tắt, ảnh lớn hơn media_max_width × media_max_height được thu nhỏ ngay lúc tải lên (xem fitImage).
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

    /**
     * $name: tên muốn đặt cho tệp (vd. slug bài khi tải ảnh đại diện) thay cho tên tệp gốc; trùng thì thêm -2, -3…
     * @return array{0: ?array, 1: ?string} [media, lỗi]
     */
    public static function upload(array $file, string $name = ''): array
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
        $base = Slug::make($name, 80) ?: Slug::make(pathinfo((string) $file['name'], PATHINFO_FILENAME), 80) ?: 'anh';
        $name = "$base.$ext";
        // Tên phải trống cả trên đĩa lẫn trong bảng media (bản ghi còn mà tệp đã mất → INSERT trùng uniq_path, lỗi 500).
        // Giữ chỗ bằng fopen 'x' (nguyên tử): hai lượt tải cùng tên chạy song song không ghi đè tệp của nhau.
        for ($i = 2; ; $i++) {
            if (!is_file("$dir/$name") && !self::findByPath("/content/uploads/$sub/$name")) {
                if ($h = @fopen("$dir/$name", 'x')) {
                    fclose($h);
                    break;
                }
                if (!is_file("$dir/$name")) {
                    return [null, 'Không lưu được tệp.'];
                }
            }
            $name = "$base-$i.$ext";
        }
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            @unlink("$dir/$name");
            return [null, 'Không lưu được tệp.'];
        }
        @chmod("$dir/$name", 0644);
        if (!option('media_keep_original', true)) {
            self::fitImage("$dir/$name", $mime);
        }

        $media = self::register("/content/uploads/$sub/$name", (string) $file['name'], (int) ($_SESSION['uid'] ?? 0) ?: null);
        do_action('media_uploaded', $media);
        do_action('image_uploaded', $media['url']);
        return [$media, null];
    }

    /**
     * Thu nhỏ ảnh (ghi đè tại chỗ) cho vừa khung tối đa trong Cài đặt › Media; ảnh nhỏ hơn khung thì để nguyên.
     * GIF bỏ qua vì GD làm mất ảnh động. JPEG có EXIF Orientation được xoay đúng chiều trước khi thu nhỏ —
     * GD không ghi lại EXIF, bỏ bước này thì ảnh chụp dọc từ điện thoại sẽ bị nằm ngang.
     */
    private static function fitImage(string $abs, string $mime): void
    {
        $maxW = max(0, (int) option('media_max_width', 2560));
        $maxH = max(0, (int) option('media_max_height', 2560));
        $quality = min(100, max(10, (int) option('media_quality', 82)));
        $load = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp', 'image/avif' => 'imagecreatefromavif'][$mime] ?? null;
        if (($maxW === 0 && $maxH === 0) || !$load || !function_exists($load) || !($size = @getimagesize($abs))) {
            return;
        }
        $orientation = $mime === 'image/jpeg' && function_exists('exif_read_data') ? (int) (@exif_read_data($abs)['Orientation'] ?? 1) : 1;
        [$w, $h] = in_array($orientation, [5, 6, 7, 8], true) ? [$size[1], $size[0]] : [$size[0], $size[1]];
        $scale = min($maxW ? $maxW / $w : 1, $maxH ? $maxH / $h : 1);
        if ($scale >= 1) {
            return;
        }
        $src = @$load($abs);
        if (!$src) {
            error_log("[LQCMS] Không đọc được ảnh để thu nhỏ: $abs");
            return;
        }
        $src = match ($orientation) {
            2 => self::flip($src, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($src, 180, 0),
            4 => self::flip($src, IMG_FLIP_VERTICAL),
            5 => self::flip(imagerotate($src, -90, 0), IMG_FLIP_HORIZONTAL),
            6 => imagerotate($src, -90, 0),
            7 => self::flip(imagerotate($src, 90, 0), IMG_FLIP_HORIZONTAL),
            8 => imagerotate($src, 90, 0),
            default => $src,
        };
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($mime !== 'image/jpeg') {
            // Giữ nền trong suốt của PNG/WEBP/AVIF.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, imagesx($src), imagesy($src));
        $tmp = $abs . '.tmp';
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($dst, $tmp, $quality),
            'image/png' => imagepng($dst, $tmp, 6),
            'image/webp' => imagewebp($dst, $tmp, $quality),
            'image/avif' => imageavif($dst, $tmp, $quality),
        };
        // Ghi ra tệp tạm rồi mới thay: lỗi giữa chừng không làm hỏng ảnh đã tải lên.
        if ($ok && @rename($tmp, $abs)) {
            @chmod($abs, 0644);
        } else {
            @unlink($tmp);
            error_log("[LQCMS] Thu nhỏ ảnh thất bại, giữ nguyên tệp gốc: $abs");
        }
    }

    private static function flip(\GdImage $im, int $mode): \GdImage
    {
        imageflip($im, $mode);
        return $im;
    }

    /** Ghi một tệp đã nằm trong uploads vào bảng media. */
    public static function register(string $path, string $originalName = '', ?int $authorId = null, ?string $createdAt = null): array
    {
        $abs = LQ_ROOT . $path;
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
            "SELECT p.id, p.title, p.type, p.status, p.author_id FROM {posts} p
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
        $abs = realpath(LQ_ROOT . $m['url']);
        if ($abs && str_starts_with($abs, realpath(LQ_CONTENT . '/uploads') . DIRECTORY_SEPARATOR)) {
            @unlink($abs);
        }
        db()->transaction(function (Database $db) use ($m, $id): void {
            $db->update('posts', ['featured_image' => null], ['featured_image' => $m['url']]);
            $db->delete('media', ['id' => $id]);
        });
        return true;
    }

    /**
     * Ảnh không còn được dùng ở đâu: đường dẫn không xuất hiện trong ảnh đại diện, nội dung mọi bài/trang (kể cả nháp,
     * thùng rác), field tuỳ biến (post_meta), cài đặt (options), mô tả chuyên mục/thẻ, hay mã nguồn theme/plugin.
     * Bỏ qua ảnh tải lên chưa tới $minAgeHours giờ — có thể đang nằm trong bài chưa lưu. Bản tự lưu có tính; bản sửa cũ
     * (post_revisions kind = revision) không tính: khôi phục một bản sửa cũ dùng ảnh đã xoá thì ảnh đó không hiện.
     * @return list<array> bản ghi đã present(), ảnh mới nhất trước
     */
    public static function unused(int $minAgeHours = 24): array
    {
        $parts = [
            ...db()->column('SELECT featured_image FROM {posts} WHERE featured_image IS NOT NULL'),
            ...db()->column('SELECT content FROM {posts}'),
            // Bản tự lưu là phần đang soạn chưa bấm lưu (vd. vừa chèn một ảnh cũ trong thư viện) — xoá ảnh đó thì
            // người viết khôi phục/lưu bài sẽ mất ảnh. Chỉ bản sửa cũ (kind = revision) mới không tính.
            ...db()->column("SELECT content FROM {post_revisions} WHERE kind = 'autosave'"),
            ...db()->column('SELECT meta_value FROM {post_meta}'),
            ...db()->column('SELECT value FROM {options}'),
            ...db()->column("SELECT description FROM {terms} WHERE description <> ''"),
        ];
        foreach ([LQ_CONTENT . '/themes', LQ_CONTENT . '/plugins'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(php|css|js|json|html?)$/i', $f->getFilename()) && $f->getSize() < 1048576) {
                    $parts[] = (string) file_get_contents($f->getPathname());
                }
            }
        }
        // JSON trong options/post_meta escape dấu "/" thành "\/" — gỡ ra để tìm được đường dẫn.
        $haystack = str_replace('\\/', '/', implode("\n", array_map('strval', $parts)));
        $rows = db()->all('SELECT m.*, u.display_name AS author_name FROM {media} m LEFT JOIN {users} u ON u.id = m.author_id
                           WHERE m.created_at < ? ORDER BY m.id DESC', [gmdate('Y-m-d H:i:s', time() - $minAgeHours * 3600)]);
        // Tệp tên có dấu cách/Unicode/& (nhập từ uploads cũ qua importExisting) thường nằm trong nội dung ở dạng %20… hoặc &amp;
        // — chỉ so dạng thô thì ảnh đang dùng bị coi là thừa và bị xoá.
        $used = function (string $path) use ($haystack): bool {
            foreach (array_unique([$path, implode('/', array_map('rawurlencode', explode('/', $path))), htmlspecialchars($path, ENT_QUOTES)]) as $v) {
                if (str_contains($haystack, $v)) {
                    return true;
                }
            }
            return false;
        };
        return array_values(array_map([self::class, 'present'], array_filter($rows, fn ($r) => !$used((string) $r['path']))));
    }

    /**
     * Đặt lại tên ảnh đại diện theo slug của bài khi ảnh được tải lên lúc bài chưa có slug/tiêu đề.
     * Chỉ đổi khi chắc không làm hỏng chỗ khác: ảnh là ảnh đại diện hiện tại của bài, do chính người này tải lên
     * trong 24 giờ qua, và chưa bài nào khác dùng (ảnh đại diện hay trong nội dung). Đường dẫn mới được cập nhật ở
     * thư viện media, ảnh đại diện và nội dung của bài. @return ?string đường dẫn mới, null nếu không đổi.
     */
    public static function adoptPostSlug(int $id, array $post): ?string
    {
        $m = db()->row('SELECT * FROM {media} WHERE id = ?', [$id]);
        $base = Slug::make((string) ($post['slug'] ?? ''), 80);
        if (!$m || $base === '' || $m['path'] !== ($post['featured_image'] ?? null)
            || (int) $m['author_id'] !== (int) (current_user()['id'] ?? 0)
            || $m['created_at'] < gmdate('Y-m-d H:i:s', time() - 86400)) {
            return null;
        }
        $old = (string) $m['path'];
        $dir = dirname($old);
        $ext = pathinfo($old, PATHINFO_EXTENSION);
        if (preg_match('/^' . preg_quote($base, '/') . '(-\d+)?$/', pathinfo($old, PATHINFO_FILENAME))) {
            return null; // đã mang tên theo slug
        }
        $like = '%' . addcslashes($old, '%_\\') . '%';
        if (db()->value('SELECT 1 FROM {posts} WHERE id <> ? AND (featured_image = ? OR content LIKE ?) LIMIT 1', [$post['id'], $old, $like])) {
            return null;
        }
        // Field ảnh (post_meta) — của bài khác hay chính bài này — cũng trỏ tới ảnh: đổi tên sẽ làm hỏng field đó.
        if (db()->value('SELECT 1 FROM {post_meta} WHERE meta_key <> ? AND meta_value LIKE ? LIMIT 1', ['_edit_lock', $like])) {
            return null;
        }
        $name = "$base.$ext";
        for ($i = 2; is_file(LQ_ROOT . "$dir/$name") || self::findByPath("$dir/$name"); $i++) {
            $name = "$base-$i.$ext";
        }
        $new = "$dir/$name";
        if (!@rename(LQ_ROOT . $old, LQ_ROOT . $new)) {
            return null;
        }
        db()->transaction(function (Database $db) use ($id, $old, $new, $post): void {
            $db->update('media', ['path' => $new], ['id' => $id]);
            $db->query('UPDATE {posts} SET featured_image = ? WHERE featured_image = ?', [$new, $old]);
            // Ảnh cũng đã được chèn vào nội dung bài (đường dẫn tương đối hoặc tuyệt đối đều chứa $old).
            $db->query('UPDATE {posts} SET content = REPLACE(content, ?, ?) WHERE id = ?', [$old, $new, $post['id']]);
        });
        do_action('media_renamed', $id, $old, $new);
        return $new;
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
