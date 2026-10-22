<?php
declare(strict_types=1);

namespace LQ\Core;

/** Hệ thống action/filter kiểu WordPress. Số nhỏ chạy trước. */
final class Hooks
{
    /** @var array<string, array<int, callable[]>> */
    private static array $hooks = [];

    public static function add(string $name, callable $callback, int $priority = 10): void
    {
        self::$hooks[$name][$priority][] = $callback;
        ksort(self::$hooks[$name]);
    }

    public static function run(string $name, mixed ...$args): void
    {
        foreach (self::$hooks[$name] ?? [] as $callbacks) {
            foreach ($callbacks as $cb) {
                try {
                    $cb(...$args);
                } catch (\Throwable $e) {
                    self::pluginFailed($name, $cb, $e);
                }
            }
        }
    }

    /** Như run() nhưng lỗi của callback (kể cả plugin) ném ra cho nơi gọi xử lý — vd. activate_{slug}: ném lỗi = không kích hoạt. */
    public static function runStrict(string $name, mixed ...$args): void
    {
        foreach (self::$hooks[$name] ?? [] as $callbacks) {
            foreach ($callbacks as $cb) {
                $cb(...$args);
            }
        }
    }

    public static function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::$hooks[$name] ?? [] as $callbacks) {
            foreach ($callbacks as $cb) {
                try {
                    $value = $cb($value, ...$args);
                } catch (\Throwable $e) {
                    self::pluginFailed($name, $cb, $e); // filter lỗi: giữ nguyên giá trị đang có
                }
            }
        }
        return $value;
    }

    /**
     * Callback của plugin ném lỗi: ghi nhật ký rồi bỏ qua để một plugin hỏng không làm sập cả admin/site.
     * Lỗi của lõi/theme thì ném tiếp như cũ (theme đã có trang cứu hộ riêng), lq_ajax_error() cũng vậy (đó là cách trả lỗi có chủ ý).
     */
    private static function pluginFailed(string $hook, callable $cb, \Throwable $e): void
    {
        $slug = $e instanceof \LQ\Front\AjaxError ? null : self::pluginOf($cb);
        if ($slug === null) {
            throw $e;
        }
        ErrorLog::add('error', "Plugin $slug lỗi ở hook \"$hook\" (đã bỏ qua): " . $e->getMessage(), $e->getFile(), $e->getLine());
    }

    /** Slug plugin chứa mã của callback, null nếu callback không thuộc plugin nào. */
    private static function pluginOf(callable $cb): ?string
    {
        try {
            $ref = match (true) {
                $cb instanceof \Closure, is_string($cb) && !str_contains($cb, '::') => new \ReflectionFunction($cb),
                is_string($cb) => new \ReflectionMethod($cb),
                is_array($cb) => new \ReflectionMethod($cb[0], $cb[1]),
                default => new \ReflectionMethod($cb, '__invoke'),
            };
        } catch (\ReflectionException) {
            return null;
        }
        $file = str_replace('\\', '/', (string) $ref->getFileName());
        // PHP lưu đường dẫn thật của tệp đã nạp: so với cả dạng thật lẫn dạng khai báo của thư mục plugins (symlink, /tmp ↔ /private/tmp).
        foreach (array_unique([Plugins::dir(), (string) realpath(Plugins::dir())]) as $dir) {
            $root = str_replace('\\', '/', $dir) . '/';
            if ($dir !== '' && str_starts_with($file, $root)) {
                break;
            }
            $root = null;
        }
        if ($root === null) {
            return null;
        }
        $slug = strstr(substr($file, strlen($root)), '/', true);
        return $slug === false || $slug === '' ? null : $slug;
    }
}
