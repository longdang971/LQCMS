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

    /**
     * Theme/plugin ghi nhật ký (lq_log): $level info | warning | error. Vị trí là nơi gọi lq_log, nguồn (plugin/theme nào)
     * dò theo đường dẫn tệp đó. $context (mảng) nối vào cuối dạng JSON ngắn.
     */
    public static function log(string $message, string $level = 'info', array $context = []): void
    {
        $level = in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info';
        $file = '';
        $line = 0;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $f) {
            $fp = (string) ($f['file'] ?? '');
            if ($fp !== '' && $fp !== __FILE__ && !str_ends_with($fp, '/app/helpers.php')) {
                [$file, $line] = [$fp, (int) ($f['line'] ?? 0)];
                break;
            }
        }
        if ($context) {
            // Nhật ký chỉ giữ dòng đầu của thông điệp: gắn context vào sau dòng đầu, không thì mất.
            $message = (strtok($message, "\n") ?: $message) . ' ' . mb_substr((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 500);
        }
        self::add($level, $message, $file, $line, self::sourceOf($file));
    }

    /** "plugin:<slug>" / "theme:<slug>" theo đường dẫn tệp, "" = lõi. */
    public static function sourceOf(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        foreach (self::sourceDirs() as $d => $source) {
            if (str_starts_with($file, "$d/")) {
                return $source;
            }
        }
        return '';
    }

    /** @var ?array<string,string> thư mục (đường dẫn thật) → "plugin:slug" / "theme:slug" */
    private static ?array $sourceDirs = null;

    /**
     * Thư mục từng plugin/theme, cả đường dẫn qua content/ lẫn đường dẫn thật: plugin cài bằng symlink (đang phát triển ở chỗ khác)
     * thì PHP báo tệp theo đường dẫn thật, không nằm dưới content/plugins/.
     */
    private static function sourceDirs(): array
    {
        if (self::$sourceDirs === null) {
            self::$sourceDirs = [];
            foreach (['plugin' => Plugins::dir(), 'theme' => Themes::dir()] as $type => $dir) {
                foreach (glob("$dir/*", GLOB_ONLYDIR) ?: [] as $sub) {
                    foreach (array_unique([str_replace('\\', '/', $sub), str_replace('\\', '/', (string) realpath($sub))]) as $d) {
                        if ($d !== '') {
                            self::$sourceDirs[$d] ??= "$type:" . basename($sub);
                        }
                    }
                }
            }
        }
        return self::$sourceDirs;
    }

    /** Xoá toàn bộ nhật ký (Cài đặt › Nhật ký). */
    public static function clear(): void
    {
        @file_put_contents(self::file(), self::HEADER, LOCK_EX);
    }

    public static function add(string $level, string $msg, string $file, int $line, ?string $source = null): void
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
            // Lỗi PHP thường cũng gắn nguồn theo tệp gây lỗi: biết ngay plugin/theme nào.
            'source' => $source ?? self::sourceOf($file),
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
