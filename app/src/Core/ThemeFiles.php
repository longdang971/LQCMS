<?php
declare(strict_types=1);

namespace LQ\Core;

use ParseError;

/**
 * Đọc/ghi tệp của theme cho trình sửa mã trong admin.
 * - Chỉ các đuôi văn bản an toàn, không tệp/thư mục ẩn, không ra ngoài thư mục theme (kiểm tra bằng realpath).
 * - PHP và JSON được kiểm tra cú pháp trước khi ghi; ghi kiểu "tệp tạm rồi đổi tên" để không bao giờ để lại tệp ghi dở.
 * - Mỗi tệp giữ một bản sao lưu (bản ngay trước lần lưu gần nhất) ở storage/theme-backups (bị chặn truy cập từ web).
 */
final class ThemeFiles
{
    public const EXTENSIONS = ['php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml'];
    public const MAX_BYTES = 1048576;
    private const SKIP_DIRS = ['node_modules', 'vendor'];

    /** Mô tả các tệp quen thuộc, hiện cạnh tên tệp trong cây thư mục. */
    public const DESCRIPTIONS = [
        'style.css' => 'Giao diện (CSS)',
        'functions.php' => 'Hàm của theme',
        'theme.json' => 'Thông tin theme',
        'index.php' => 'Template dự phòng',
        'header.php' => 'Đầu trang',
        'footer.php' => 'Chân trang',
        'sidebar.php' => 'Cột bên',
        'searchform.php' => 'Form tìm kiếm',
        'front-page.php' => 'Trang chủ',
        'home.php' => 'Danh sách bài mới',
        'single.php' => 'Bài viết đơn',
        'singular.php' => 'Bài / trang đơn',
        'page.php' => 'Trang tĩnh',
        'archive.php' => 'Lưu trữ',
        'category.php' => 'Chuyên mục',
        'tag.php' => 'Thẻ',
        'taxonomy.php' => 'Taxonomy',
        'author.php' => 'Tác giả',
        'date.php' => 'Lưu trữ theo ngày',
        'search.php' => 'Kết quả tìm kiếm',
        '404.php' => 'Không tìm thấy (404)',
    ];

    public static function editingAllowed(): bool
    {
        return !App::config('disallow_file_edit', false);
    }

    /**
     * Tệp sửa được của theme, xếp: tệp gốc trước (style.css, functions.php lên đầu), rồi tới thư mục con.
     * @return array<string, array{path:string, size:int, desc:string, dir:string}>
     */
    public static function list(string $theme): array
    {
        $root = Themes::dir() . "/$theme";
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $f) => !str_starts_with($f->getFilename(), '.')
                    && !($f->isDir() && in_array($f->getFilename(), self::SKIP_DIRS, true))
                    && !$f->isLink()
            )
        );
        foreach ($it as $f) {
            if (!$f->isFile() || !in_array(strtolower($f->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $dir = str_contains($rel, '/') ? dirname($rel) : '';
            $out[$rel] = ['path' => $rel, 'size' => $f->getSize(), 'desc' => self::describe($rel), 'dir' => $dir];
        }
        $rank = fn (string $p) => match ($p) {
            'style.css' => 0,
            'functions.php' => 1,
            default => str_contains($p, '/') ? 3 : 2,
        };
        uksort($out, fn ($a, $b) => [$rank($a), $a] <=> [$rank($b), $b]);
        return $out;
    }

    /**
     * Cây thư mục cho trình sửa mã: thư mục luôn đứng trước tệp, cả hai xếp theo tên.
     * @return array{dirs: array<string, array>, files: array<int, array>, path: string}
     */
    public static function tree(string $theme): array
    {
        $root = ['dirs' => [], 'files' => [], 'path' => ''];
        foreach (self::list($theme) as $f) {
            $node = &$root;
            $parts = explode('/', $f['path']);
            $name = array_pop($parts);
            $acc = '';
            foreach ($parts as $dir) {
                $acc = $acc === '' ? $dir : "$acc/$dir";
                $node['dirs'][$dir] ??= ['dirs' => [], 'files' => [], 'path' => $acc];
                $node = &$node['dirs'][$dir];
            }
            $node['files'][] = $f + ['name' => $name];
            unset($node);
        }
        $sort = function (array &$n) use (&$sort): void {
            uksort($n['dirs'], 'strnatcasecmp');
            usort($n['files'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            foreach ($n['dirs'] as &$d) {
                $sort($d);
            }
        };
        $sort($root);
        return $root;
    }

    /** Icon (Lucide) + nhóm màu theo loại tệp. @return array{0:string,1:string} */
    public static function icon(string $rel): array
    {
        return match (strtolower(pathinfo($rel, PATHINFO_EXTENSION))) {
            'php' => ['file-code-2', 'php'],
            'css' => ['hash', 'css'],
            'js' => ['file-code', 'js'],
            'json' => ['braces', 'json'],
            'svg' => ['image', 'svg'],
            'html', 'htm', 'xml' => ['code-xml', 'html'],
            'md' => ['file-text', 'md'],
            default => ['file', 'txt'],
        };
    }

    public static function describe(string $rel): string
    {
        $base = basename($rel);
        if (isset(self::DESCRIPTIONS[$rel])) {
            return self::DESCRIPTIONS[$rel];
        }
        return match (true) {
            (bool) preg_match('/^single-(.+)\.php$/', $base, $m) => 'Bài viết: ' . $m[1],
            (bool) preg_match('/^page-(.+)\.php$/', $base, $m) => 'Trang: ' . $m[1],
            (bool) preg_match('/^category-(.+)\.php$/', $base, $m) => 'Chuyên mục: ' . $m[1],
            (bool) preg_match('/^tag-(.+)\.php$/', $base, $m) => 'Thẻ: ' . $m[1],
            (bool) preg_match('/^taxonomy-(.+)\.php$/', $base, $m) => 'Taxonomy: ' . $m[1],
            (bool) preg_match('/^author-(.+)\.php$/', $base, $m) => 'Tác giả: ' . $m[1],
            str_starts_with($rel, 'templates/') => 'Mẫu trang',
            str_starts_with($rel, 'parts/') => 'Phần dùng lại',
            default => '',
        };
    }

    /** Đường dẫn tuyệt đối của tệp nếu hợp lệ và nằm trong theme; ngược lại null. */
    public static function resolve(string $theme, string $rel): ?string
    {
        if (self::validateName($rel) !== null || !preg_match('/^[a-z0-9_-]+$/', $theme)) {
            return null;
        }
        $root = realpath(Themes::dir() . "/$theme");
        $abs = realpath(Themes::dir() . "/$theme/$rel");
        if (!$root || !$abs || !is_file($abs) || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return filesize($abs) <= self::MAX_BYTES ? $abs : null;
    }

    /** Kiểm tra tên tệp tương đối; trả thông báo lỗi hoặc null. */
    public static function validateName(string $rel): ?string
    {
        if ($rel === '' || strlen($rel) > 200) {
            return 'Tên tệp không hợp lệ.';
        }
        if (!preg_match('#^[a-zA-Z0-9_-][a-zA-Z0-9_.-]*(/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*$#', $rel) || str_contains($rel, '..')) {
            return 'Tên tệp chỉ gồm chữ không dấu, số, "-", "_", "." và "/" cho thư mục con (không bắt đầu bằng dấu chấm).';
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONS, true)) {
            return 'Chỉ sửa được tệp .' . implode(', .', self::EXTENSIONS) . '.';
        }
        if (array_intersect(explode('/', $rel), self::SKIP_DIRS)) {
            return 'Không sửa tệp trong ' . implode(', ', self::SKIP_DIRS) . '.';
        }
        return null;
    }

    /** Kiểm tra cú pháp. @return ?array{message:string, line:int} */
    public static function lint(string $rel, string $content): ?array
    {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            try {
                token_get_all($content, TOKEN_PARSE);
            } catch (ParseError $e) {
                return ['message' => 'Lỗi cú pháp PHP dòng ' . $e->getLine() . ': ' . $e->getMessage() . '. Chưa lưu.', 'line' => $e->getLine()];
            }
        }
        if ($ext === 'json' && trim($content) !== '') {
            json_decode($content);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['message' => 'JSON không hợp lệ: ' . json_last_error_msg() . '. Chưa lưu.', 'line' => 0];
            }
        }
        return null;
    }

    /** Ghi nguyên tử: tệp tạm cùng thư mục rồi rename, giữ quyền cũ, xoá cache opcode. */
    public static function write(string $abs, string $content): bool
    {
        $tmp = dirname($abs) . '/.' . basename($abs) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        if (is_file($abs)) {
            @chmod($tmp, fileperms($abs) & 0777);
        } else {
            @chmod($tmp, 0644);
        }
        if (!@rename($tmp, $abs)) {
            @unlink($tmp);
            return false;
        }
        clearstatcache(true, $abs);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($abs, true);
        }
        return true;
    }

    private static function backupDir(string $theme): string
    {
        return LQ_ROOT . '/storage/theme-backups/' . $theme;
    }

    public static function backup(string $theme, string $rel, string $content): void
    {
        $path = self::backupDir($theme) . '/' . $rel;
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0750, true);
        }
        @file_put_contents($path, $content, LOCK_EX);
    }

    public static function backupPath(string $theme, string $rel): ?string
    {
        $path = self::backupDir($theme) . '/' . $rel;
        return self::validateName($rel) === null && is_file($path) ? $path : null;
    }

    /** Ngôn ngữ cho trình soạn thảo Ace. */
    public static function mode(string $rel): string
    {
        return match (strtolower(pathinfo($rel, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'css' => 'css',
            'js' => 'javascript',
            'json' => 'json',
            'html', 'htm' => 'html',
            'md' => 'markdown',
            'svg', 'xml' => 'xml',
            default => 'text',
        };
    }

    /** Nội dung mở đầu cho tệp mới. */
    public static function stub(string $rel): string
    {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            $desc = self::describe($rel);
            return "<?php\n/** " . ($desc !== '' ? $desc : basename($rel)) . " */\ndefined('LQ_ROOT') || exit;\n?>\n";
        }
        return match ($ext) {
            'json' => "{\n}\n",
            default => '',
        };
    }
}
