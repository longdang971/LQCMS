<?php
declare(strict_types=1);

namespace LQ\Core;

use FilesystemIterator;
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Quét theme/plugin đã giải nén (ở thư mục tạm, chưa chạy) trước khi cài. Chỉ BÁO CÁO để người cài quyết định —
 * mã độc viết khéo vẫn lọt, còn plugin hợp lệ có thể dính cảnh báo (vd. gọi exec có chủ đích).
 *   - Tệp nguy hiểm: cấu hình máy chủ (.htaccess, .user.ini), đuôi PHP lạ (.phtml, .phar…), tên giả dạng ảnh,
 *     PHP giấu trong tệp không phải .php, tệp thực thi, symlink.
 *   - Cú pháp PHP: token_get_all(TOKEN_PARSE) — phân tích, không thực thi, không cần exec().
 *   - Mẫu mã đáng ngờ: eval, giải mã chuỗi, chạy lệnh hệ thống, gọi hàm từ dữ liệu người dùng, chuỗi mã hoá dài…
 */
final class PackageScanner
{
    public const DANGER = 'danger';
    public const WARNING = 'warning';

    private const MAX_FINDINGS = 300;
    private const MAX_PER_FILE = 15;
    private const MAX_SCAN_BYTES = 2 * 1024 * 1024; // tệp lớn hơn chỉ kiểm tra tên/đuôi, không đọc nội dung

    private const CONFIG_FILES = ['.htaccess', '.user.ini', 'php.ini', 'web.config', '.htpasswd'];
    private const ODD_PHP_EXT = ['phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'pgif', 'shtml'];
    private const EXEC_EXT = ['so', 'dll', 'exe', 'bin', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'jar', 'bat', 'cmd', 'com', 'msi', 'dylib'];
    private const MEDIA_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'txt', 'css', 'js', 'json', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'pdf', 'zip'];

    /**
     * Mẫu theo dòng trong mã PHP: [mức, mô tả, regex]. Thứ tự = ưu tiên; mỗi dòng chỉ báo mẫu đầu tiên khớp.
     * (?<![\w>:$]) để không bắt nhầm curl_exec(), $pdo->exec(), Foo::system()…
     */
    private const RULES = [
        [self::DANGER, 'Chạy mã từ chuỗi (eval)', '/(?<![\w>:$])eval\s*\(/i'],
        [self::DANGER, 'assert() với biến — cách chạy mã tuỳ ý cổ điển', '/(?<![\w>:$])assert\s*\(\s*\$/i'],
        [self::DANGER, 'create_function() — chạy mã từ chuỗi', '/(?<![\w>:$])create_function\s*\(/i'],
        [self::DANGER, 'preg_replace với cờ /e — chạy mã từ chuỗi', '/preg_replace\s*\(\s*([\'"])([^\w\s\\\\]).*?\2([a-zA-Z]*e[a-zA-Z]*)\1/'],
        [self::DANGER, 'Chạy lệnh hệ thống', '/(?<![\w>:$])(shell_exec|exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/i'],
        [self::DANGER, 'Chạy lệnh hệ thống bằng dấu backtick', '/`[^`]*\$_(GET|POST|REQUEST|COOKIE)[^`]*`/'],
        [self::DANGER, 'Gọi hàm theo tên lấy từ dữ liệu người dùng gửi lên', '/\$_(GET|POST|REQUEST|COOKIE|SERVER)\s*\[[^\]]*\]\s*\(/i'],
        [self::DANGER, 'include/require tệp theo dữ liệu người dùng gửi lên', '/(include|require)(_once)?\s*\(?\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
        [self::WARNING, 'Giải mã/giải nén chuỗi ẩn (hay dùng để giấu mã)', '/(?<![\w>:$])(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|convert_uudecode)\s*\(/i'],
        [self::WARNING, 'Chuỗi mã hoá rất dài', '/[\'"][A-Za-z0-9+\/=]{200,}[\'"]/'],
        [self::WARNING, 'Chuỗi viết toàn mã \\x.. (làm rối mã)', '/(\\\\x[0-9a-fA-F]{2}){15,}/'],
        [self::WARNING, 'Tắt báo lỗi của PHP', '/error_reporting\s*\(\s*0\s*\)|ini_set\s*\(\s*[\'"]display_errors[\'"]\s*,\s*[\'"]?(0|off)/i'],
        [self::WARNING, 'Gọi tới máy chủ bên ngoài', '/(?<![\w>:$])(fsockopen|curl_exec)\s*\(|file_get_contents\s*\(\s*[\'"]https?:\/\//i'],
        [self::WARNING, 'Ghi tệp vào thư mục tuỳ ý', '/(?<![\w>:$])(file_put_contents|move_uploaded_file)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
    ];

    /**
     * @param string[] $symlinks đường dẫn (tương đối trong gói) được đánh dấu symlink trong zip
     * @return array{files:int, bytes:int, php:int, findings:list<array{file:string, line:int, severity:string, message:string, snippet:string}>}
     */
    public static function scan(string $dir, array $symlinks = []): array
    {
        $out = ['files' => 0, 'bytes' => 0, 'php' => 0, 'findings' => []];
        $add = function (string $file, int $line, string $sev, string $msg, string $snippet = '') use (&$out): void {
            if (count($out['findings']) < self::MAX_FINDINGS) {
                $out['findings'][] = ['file' => $file, 'line' => $line, 'severity' => $sev, 'message' => $msg, 'snippet' => mb_strimwidth(trim($snippet), 0, 220, '…')];
            }
        };
        foreach ($symlinks as $link) {
            $add($link, 0, self::DANGER, 'Liên kết tượng trưng (symlink) — có thể trỏ tới tệp hệ thống như mật khẩu, cấu hình');
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1);
            if ($f->isLink()) {
                $add($rel, 0, self::DANGER, 'Liên kết tượng trưng (symlink)');
                continue;
            }
            if ($f->isDir()) {
                continue;
            }
            $out['files']++;
            $out['bytes'] += $f->getSize();
            $name = strtolower($f->getFilename());
            $parts = explode('.', $name);
            $ext = count($parts) > 1 ? end($parts) : '';

            if (in_array($name, self::CONFIG_FILES, true)) {
                $add($rel, 0, self::DANGER, 'Tệp cấu hình máy chủ/PHP — có thể đổi cách máy chủ chạy tệp (vd. cho ảnh chạy như PHP)');
            } elseif (in_array($ext, self::ODD_PHP_EXT, true)) {
                $add($rel, 0, self::DANGER, "Đuôi .$ext — máy chủ có thể chạy như PHP, hay dùng để lách bộ lọc");
            } elseif ($ext === 'php' && count($parts) > 2 && in_array($parts[count($parts) - 2], self::MEDIA_EXT, true)) {
                $add($rel, 0, self::DANGER, 'Tệp PHP giả dạng ảnh/tài nguyên (đuôi kép)');
            } elseif (in_array($ext, self::EXEC_EXT, true)) {
                $add($rel, 0, self::WARNING, "Tệp thực thi/script .$ext — theme/plugin PHP thường không cần");
            } elseif ($name[0] === '.' && !in_array($name, ['.gitignore', '.gitkeep', '.editorconfig'], true)) {
                $add($rel, 0, self::WARNING, 'Tệp ẩn');
            }

            if ($f->getSize() > self::MAX_SCAN_BYTES) {
                continue;
            }
            $code = (string) file_get_contents($f->getPathname());
            $isPhp = $ext === 'php' || in_array($ext, self::ODD_PHP_EXT, true);
            if (!$isPhp) {
                // Thẻ mở PHP trong ảnh/css/js… là dấu hiệu giấu web shell (ăn theo lỗ hổng include).
                if ($ext !== 'md' && preg_match('/<\?php|<\?=/i', $code)) {
                    $add($rel, self::lineOf($code, '/<\?php|<\?=/i'), self::DANGER, 'Có mã PHP giấu trong tệp không phải .php');
                    self::matchRules($rel, $code, $add);
                }
                continue;
            }
            $out['php']++;
            try {
                token_get_all($code, TOKEN_PARSE);
            } catch (ParseError $e) {
                $add($rel, $e->getLine(), self::WARNING, 'Lỗi cú pháp PHP: ' . $e->getMessage() . ' — kích hoạt sẽ báo lỗi', self::lineText($code, $e->getLine()));
            }
            self::matchRules($rel, $code, $add);
        }
        // Nguy hiểm lên trước, rồi theo tệp và dòng.
        usort($out['findings'], fn ($a, $b) => [$a['severity'] !== self::DANGER, $a['file'], $a['line']] <=> [$b['severity'] !== self::DANGER, $b['file'], $b['line']]);
        return $out;
    }

    private static function matchRules(string $rel, string $code, callable $add): void
    {
        $count = 0;
        foreach (preg_split('/\r\n|\r|\n/', $code) as $i => $line) {
            if (strlen($line) > 5000) {
                $add($rel, $i + 1, self::WARNING, 'Dòng rất dài (' . number_format(strlen($line)) . ' ký tự) — mã bị nén hoặc làm rối', $line);
                if (++$count >= self::MAX_PER_FILE) {
                    return;
                }
                continue;
            }
            foreach (self::RULES as [$sev, $msg, $re]) {
                if (preg_match($re, $line)) {
                    $add($rel, $i + 1, $sev, $msg, $line);
                    if (++$count >= self::MAX_PER_FILE) {
                        return;
                    }
                    break;
                }
            }
        }
    }

    private static function lineOf(string $code, string $re): int
    {
        return preg_match($re, $code, $m, PREG_OFFSET_CAPTURE) ? substr_count($code, "\n", 0, $m[0][1]) + 1 : 0;
    }

    private static function lineText(string $code, int $line): string
    {
        return (string) (preg_split('/\r\n|\r|\n/', $code)[$line - 1] ?? '');
    }
}
