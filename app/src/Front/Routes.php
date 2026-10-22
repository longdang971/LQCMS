<?php
declare(strict_types=1);

namespace LQ\Front;

use InvalidArgumentException;
use LQ\Core\ErrorLog;
use LQ\Core\Permalink;
use Throwable;

/**
 * Đường dẫn riêng của theme/plugin ngoài website (lq_add_route): /sitemap.xml, /feed, /su-kien/{slug}…
 * Xét TRƯỚC nội dung (bài, trang, chuyên mục). Đoạn đầu cố định của route được tính là "đã có chủ" để trang/chuyên mục
 * mới không lấy trùng slug (Permalink::takenRootSegments).
 */
final class Routes
{
    /** @var array<string, array> */
    private static array $routes = [];
    private static ?array $current = null;

    public static function add(string $pattern, callable $callback, array $opts = []): void
    {
        $pattern = '/' . trim($pattern, '/');
        if (!preg_match('#^(/[^/{}]+|/\{[a-z_][a-z0-9_]*(?::[^/{}]+)?\})+$#i', $pattern)) {
            throw new InvalidArgumentException("Route không hợp lệ: \"$pattern\" (vd. /sitemap.xml, /su-kien/{slug}, /don/{id:\\d+}).");
        }
        $first = explode('/', $pattern)[1];
        if (str_starts_with($first, '{')) {
            throw new InvalidArgumentException("Route \"$pattern\" phải bắt đầu bằng một đoạn cố định (vd. /su-kien/{slug}).");
        }
        if (in_array(strtolower(pathinfo($first, PATHINFO_FILENAME)), Permalink::RESERVED_ROOT, true) || in_array(strtolower($first), Permalink::RESERVED_ROOT, true)) {
            throw new InvalidArgumentException("Route \"$pattern\" trùng đường dẫn hệ thống (/$first).");
        }
        $regex = '#^' . preg_replace_callback('#\{([a-z_][a-z0-9_]*)(?::([^/{}]+))?\}|[^{}]+#i', fn ($m) => isset($m[1]) && $m[1] !== ''
            ? '(?P<' . $m[1] . '>' . (($m[2] ?? '') !== '' ? $m[2] : '[^/]+') . ')'
            : preg_quote($m[0], '#'), $pattern) . '$#Du';
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException("Route \"$pattern\": biểu thức của tham số không hợp lệ.");
        }
        self::$routes[$pattern] = [
            'pattern' => $pattern,
            'regex' => $regex,
            'first' => $first,
            'callback' => $callback,
            'methods' => array_map('strtoupper', (array) ($opts['methods'] ?? ['GET'])),
            'content_type' => (string) ($opts['content_type'] ?? 'text/html; charset=utf-8'),
        ];
    }

    public static function remove(string $pattern): void
    {
        unset(self::$routes['/' . trim($pattern, '/')]);
    }

    /** Đoạn đầu URL các route đang giữ (vd. "su-kien", "sitemap.xml"). */
    public static function rootSegments(): array
    {
        return array_values(array_unique(array_map(fn ($r) => strtolower($r['first']), self::$routes)));
    }

    /** Route đang chạy ({pattern, params}) — cho is_route() trong template. */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * Chạy route khớp $path (nếu có). @return bool|null true = đã trả trang, false = route báo không có (404 của theme),
     * null = không route nào khớp. $onMatch chạy khi có route khớp, trước callback (Front khởi động admin bar).
     */
    public static function dispatch(string $path, ?callable $onMatch = null): ?bool
    {
        foreach (self::$routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            $method = request_method();
            if (!in_array($method, $r['methods'], true) && !($method === 'HEAD' && in_array('GET', $r['methods'], true))) {
                header('Allow: ' . implode(', ', $r['methods']));
                http_response_code(405);
                echo 'Method Not Allowed';
                return true;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            self::$current = ['pattern' => $r['pattern'], 'params' => $params];
            if ($onMatch) {
                $onMatch();
            }
            header('Content-Type: ' . $r['content_type']);
            $level = ob_get_level();
            ob_start();
            try {
                $out = ($r['callback'])($params);
            } catch (Throwable $e) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                ErrorLog::add('error', "Route {$r['pattern']} lỗi: " . $e->getMessage(), $e->getFile(), $e->getLine());
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Lỗi máy chủ.';
                return true;
            }
            $echoed = (string) ob_get_clean();
            if ($out === false) {
                self::$current = null;
                return false;
            }
            if (is_array($out) || $out instanceof \JsonSerializable) {
                json_response($out);
            }
            // true / null = callback tự echo (vd. get_header() … get_footer()); chuỗi / số = nội dung trả về.
            echo $echoed, is_string($out) || is_int($out) || is_float($out) || $out instanceof \Stringable ? (string) $out : '';
            return true;
        }
        return null;
    }
}
