<?php
declare(strict_types=1);

namespace LQ\Core;

/**
 * Nhật ký lỗi PHP của site ở storage/logs/errors.php (dòng đầu là <?php exit; ?> để gọi thẳng qua web không đọc được, sau đó mỗi dòng một JSON) — để trợ lý AI và người quản trị
 * xem được lỗi thật mà không cần vào log của máy chủ. Chỉ GHI THÊM: PHP vẫn xử lý lỗi như bình thường
 * (handler trả false), nên display_errors / error_log của máy chủ không đổi.
 */
final class ErrorLog
{
    private const MAX_BYTES = 512 * 1024;
    private const HEADER = "<?php exit; ?>\n";
    /** Lỗi trùng (cùng thông báo + tệp + dòng) trong một request chỉ ghi một lần. */
    private static array $seen = [];

    public static function file(): string
    {
        return LQ_ROOT . '/storage/logs/errors.php';
    }

    public static function register(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
            if (error_reporting() & $no) { // bỏ qua lỗi bị chặn bằng @
                self::add(self::level($no), $msg, $file, $line);
            }
            return false;
        });
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                self::add('fatal', (string) $e['message'], (string) $e['file'], (int) $e['line']);
            }
        });
    }

    public static function add(string $level, string $msg, string $file, int $line): void
    {
        // Thư viện AI (storage/ai/*/vendor) hay báo deprecated — không phải lỗi của site.
        if (str_contains($file, '/storage/ai/') || isset(self::$seen[$key = md5("$msg|$file|$line")])) {
            return;
        }
        self::$seen[$key] = true;
        $path = self::file();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0750, true);
        }
        if (is_file($path) && filesize($path) > self::MAX_BYTES) {
            // Quá lớn: giữ nửa sau (mới hơn).
            $keep = (string) @file_get_contents($path, false, null, (int) (filesize($path) / 2));
            @file_put_contents($path, self::HEADER . substr($keep, (int) strpos($keep, "\n") + 1), LOCK_EX);
        }
        if (!is_file($path)) {
            @file_put_contents($path, self::HEADER, LOCK_EX);
        }
        $root = LQ_ROOT . '/';
        @file_put_contents($path, json_encode([
            't' => gmdate('Y-m-d H:i:s'),
            'level' => $level,
            'msg' => mb_substr(strtok($msg, "\n") ?: $msg, 0, 1000),
            'file' => str_starts_with($file, $root) ? substr($file, strlen($root)) : $file,
            'line' => $line,
            'url' => PHP_SAPI === 'cli' ? 'cli' : (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Lỗi gần nhất, gộp lỗi giống nhau (đếm số lần, giữ lần cuối). @return list<array>
     */
    public static function recent(int $limit = 30): array
    {
        $path = self::file();
        if (!is_file($path)) {
            return [];
        }
        $groups = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $e = json_decode($raw, true);
            if (!is_array($e)) {
                continue;
            }
            $k = $e['level'] . '|' . $e['msg'] . '|' . $e['file'] . '|' . $e['line'];
            $groups[$k] = ($groups[$k] ?? ['count' => 0]) + $e;
            $groups[$k]['count']++;
            $groups[$k]['t'] = $e['t'];
            $groups[$k]['url'] = $e['url'];
        }
        uasort($groups, fn ($a, $b) => strcmp($b['t'], $a['t']));
        return array_slice(array_values($groups), 0, $limit);
    }

    private static function level(int $no): string
    {
        return match ($no) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'deprecated',
            default => 'error',
        };
    }
}
