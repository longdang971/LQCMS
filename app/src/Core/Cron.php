<?php
declare(strict_types=1);

namespace LQ\Core;

use Throwable;

/**
 * Tác vụ định kỳ (đăng bài đã lên lịch, dọn dữ liệu cũ…). Hai cách chạy:
 *  - Cron hệ điều hành (khuyên dùng):  * * * * * php /đường-dẫn/lqcms/bin/cron.php
 *  - Dự phòng bằng lượt truy cập: sau khi trả trang xong, tối đa 1 lần/phút.
 * Dùng GET_LOCK của MariaDB để hai tiến trình không chạy chồng nhau.
 *
 * Plugin/theme thêm tác vụ: lq_add_cron_task('ten', 3600, fn () => ..., 'Mô tả');
 */
final class Cron
{
    /** Khoảng cách tối thiểu giữa hai lần chạy (giây). */
    private const TICK = 60;

    /** @var array<string, array{interval:int, callback:callable, label:string}> */
    private static array $tasks = [];

    public static function add(string $name, int $interval, callable $callback, string $label = ''): void
    {
        if (!preg_match('/^[a-z0-9_.-]{2,64}$/', $name)) {
            throw new \InvalidArgumentException("Tên tác vụ không hợp lệ: $name");
        }
        self::$tasks[$name] = ['interval' => max(self::TICK, $interval), 'callback' => $callback, 'label' => $label ?: $name];
    }

    public static function tasks(): array
    {
        self::registerCore();
        return self::$tasks;
    }

    private static function registerCore(): void
    {
        if (isset(self::$tasks['publish_scheduled'])) {
            return;
        }
        // Đặt lên đầu để các tác vụ của plugin chạy sau.
        $plugins = self::$tasks;
        self::$tasks = [];
        self::add('publish_scheduled', 60, [self::class, 'publishScheduled'], 'Đăng bài / trang đã lên lịch khi tới giờ');
        self::add('cleanup', 86400, [self::class, 'cleanup'], 'Dọn dữ liệu cũ (đăng nhập sai, phiên ghi nhớ hết hạn, bản tự lưu cũ)');
        self::$tasks += $plugins;
    }

    /** Gọi ở mỗi request: nếu đã quá 1 phút chưa chạy thì chạy sau khi trả trang. */
    public static function maybeRunOnRequest(): void
    {
        if (PHP_SAPI === 'cli' || !option('cron_web_fallback', true)) {
            return;
        }
        $last = (int) (((array) option('cron_last', []))['at'] ?? 0);
        if (time() - $last < self::TICK) {
            return;
        }
        register_shutdown_function(static function (): void {
            // Trả trang cho người dùng trước (PHP-FPM), rồi mới chạy tác vụ.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::run('web');
            } catch (Throwable $e) {
                error_log('[LQCMS] Cron (web) lỗi: ' . $e->getMessage());
            }
        });
    }

    /**
     * Chạy các tác vụ đến hạn. $force = chạy hết bất kể hạn (nút "Chạy ngay").
     * @return array<string, array> kết quả từng tác vụ đã chạy; null nếu đang có tiến trình khác chạy
     */
    public static function run(string $via, bool $force = false): ?array
    {
        $lock = 'lq_cron_' . db()->prefix;
        if ((int) db()->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return null;
        }
        try {
            update_option('cron_last', ['at' => time(), 'via' => $via]);
            $state = (array) option('cron_state', []);
            $ran = [];
            foreach (self::tasks() as $name => $task) {
                $last = (int) ($state[$name]['last'] ?? 0);
                if (!$force && time() - $last < $task['interval']) {
                    continue;
                }
                $start = microtime(true);
                try {
                    $result = ($task['callback'])();
                    $entry = ['last' => time(), 'ok' => true, 'result' => is_scalar($result) ? (string) $result : '', 'error' => null];
                } catch (Throwable $e) {
                    $entry = ['last' => time(), 'ok' => false, 'result' => '', 'error' => mb_substr($e->getMessage(), 0, 300)];
                    error_log("[LQCMS] Tác vụ $name lỗi: " . $e->getMessage());
                }
                $entry['ms'] = (int) round((microtime(true) - $start) * 1000);
                $state[$name] = $ran[$name] = $entry;
            }
            update_option('cron_state', $state, false);
            return $ran;
        } finally {
            db()->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Bài/trang "Lên lịch" đã tới giờ → "Đã đăng". */
    public static function publishScheduled(): string
    {
        $ids = db()->column("SELECT id FROM {posts} WHERE status = 'scheduled' AND published_at <= ?", [now_utc()]);
        foreach ($ids as $id) {
            db()->query("UPDATE {posts} SET status = 'published' WHERE id = ? AND status = 'scheduled'", [$id]);
            do_action('post_published', (int) $id, 'scheduled');
        }
        return $ids ? 'Đã đăng ' . count($ids) . ' bài' : 'Không có bài nào tới giờ';
    }

    public static function cleanup(): string
    {
        $n = db()->query('DELETE FROM {login_attempts} WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)])->rowCount();
        $n += db()->query('DELETE FROM {user_tokens} WHERE expires_at < ?', [now_utc()])->rowCount();
        if ((int) option('db_version', 1) >= 5) {
            $n += db()->query("DELETE FROM {post_revisions} WHERE kind = 'autosave' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)])->rowCount();
        }
        do_action('cron_cleanup');
        return "Đã dọn $n bản ghi";
    }

    /** Trạng thái để hiển thị trong Cài đặt. */
    public static function status(): array
    {
        $state = (array) option('cron_state', []);
        $last = (array) option('cron_last', []);
        $rows = [];
        foreach (self::tasks() as $name => $t) {
            $s = $state[$name] ?? [];
            $rows[$name] = $t + ['state' => $s, 'next' => isset($s['last']) ? (int) $s['last'] + $t['interval'] : null];
        }
        return [
            'tasks' => $rows,
            'last_at' => isset($last['at']) ? (int) $last['at'] : null,
            'last_via' => $last['via'] ?? null,
            // Cron hệ thống coi là đang chạy nếu lần chạy CLI gần nhất trong 5 phút.
            'system_ok' => ($last['via'] ?? '') === 'cli' && time() - (int) ($last['at'] ?? 0) < 300,
        ];
    }
}
