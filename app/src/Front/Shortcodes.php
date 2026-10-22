<?php
declare(strict_types=1);

namespace LQ\Front;

use InvalidArgumentException;
use LQ\Core\ErrorLog;
use Throwable;

/**
 * Shortcode trong nội dung bài: [ten a="1" b='2' c=3 vi-tri], [ten]nội dung[/ten], [[ten]] = in nguyên văn "[ten]".
 * Chỉ xử lý tag đã đăng ký (lq_add_shortcode) — chữ trong ngoặc vuông khác giữ nguyên.
 *
 * Với the_content: kết quả shortcode được thay bằng ký hiệu giữ chỗ TRƯỚC lq_autop (ưu tiên 4) rồi trả lại SAU
 * (ưu tiên 6) — để autop không bọc <p> / chèn <br> vào HTML do shortcode sinh ra; shortcode đứng riêng một đoạn
 * cũng không bị bọc <p>.
 */
final class Shortcodes
{
    /** @var array<string, callable> */
    private static array $tags = [];
    /** @var list<array<int, string>> kết quả đang giữ chỗ (ngăn xếp: the_content có thể lồng nhau) */
    private static array $held = [];

    public static function add(string $tag, callable $callback): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $tag)) {
            throw new InvalidArgumentException("Tên shortcode không hợp lệ: \"$tag\" (chữ thường, số, _ -, tối đa 40 ký tự).");
        }
        self::$tags[$tag] = $callback;
    }

    public static function remove(string $tag): void
    {
        unset(self::$tags[$tag]);
    }

    public static function exists(string $tag): bool
    {
        return isset(self::$tags[$tag]);
    }

    /** Nội dung có dùng shortcode $tag không. */
    public static function has(string $content, string $tag): bool
    {
        if (!self::exists($tag) || !str_contains($content, '[')) {
            return false;
        }
        // Xét MỌI lần khớp: "[[ten]] … [ten]" vẫn là có dùng (lần đầu chỉ là dạng thoát).
        preg_match_all(self::regex([$tag]), self::protect($content), $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        foreach ($all as $m) {
            if (!($m[1] === '[' && $m[6] === ']')) {
                return true;
            }
        }
        return false;
    }

    /** Chạy mọi shortcode đã đăng ký trong $content. */
    public static function run(string $content): string
    {
        if (!self::$tags || !str_contains($content, '[')) {
            return $content;
        }
        return self::unprotect((string) preg_replace_callback(self::regex(array_keys(self::$tags)), [self::class, 'callMatch'], self::protect($content), -1, $n, PREG_UNMATCHED_AS_NULL));
    }

    /** Bỏ shortcode đã đăng ký (giữ lại nội dung bên trong dạng [ten]…[/ten]) — dùng cho tóm tắt. */
    public static function strip(string $content): string
    {
        if (!self::$tags || !str_contains($content, '[')) {
            return $content;
        }
        return self::unprotect((string) preg_replace_callback(self::regex(array_keys(self::$tags)), fn ($m) => $m[1] === '[' && $m[6] === ']' ? substr($m[0], 1, -1) : ($m[5] ?? ''), self::protect($content), -1, $n, PREG_UNMATCHED_AS_NULL));
    }

    /** the_content ưu tiên 4: chạy shortcode, kết quả thay bằng ký hiệu giữ chỗ \x02n\x02. */
    public static function hold(string $content): string
    {
        $held = [];
        // Ký tự giữ chỗ không được có sẵn trong nội dung (release() sẽ thay nhầm).
        $content = str_replace(["\x02", "\x03", "\x04"], '', $content);
        if (self::$tags && str_contains($content, '[')) {
            $content = self::unprotect((string) preg_replace_callback(self::regex(array_keys(self::$tags)), function ($m) use (&$held) {
                $held[] = self::callMatch($m);
                return "\x02" . (count($held) - 1) . "\x02";
            }, self::protect($content), -1, $n, PREG_UNMATCHED_AS_NULL));
        }
        self::$held[] = $held;
        return $content;
    }

    /** the_content ưu tiên 6: trả kết quả shortcode vào chỗ giữ. */
    public static function release(string $content): string
    {
        $held = array_pop(self::$held) ?? [];
        return $held ? (string) preg_replace_callback('#\x02(\d+)\x02#', fn ($m) => $held[(int) $m[1]] ?? '', $content) : $content;
    }

    /** Tham số: 'a="1" b=\'x\' c=3 vi-tri' → ['a' => '1', 'b' => 'x', 'c' => '3', 0 => 'vi-tri']. */
    public static function parseAtts(string $text): array
    {
        // Trình soạn thảo có thể lưu dấu nháy dạng &quot; / &#39;, khoảng trắng dạng &nbsp;.
        $text = trim(str_replace("\u{00A0}", ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $atts = [];
        preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\'"]+)|"([^"]*)"|\'([^\']*)\'|(\S+)/u', $text, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            if (($m[1] ?? '') !== '') {
                $atts[strtolower($m[1])] = $m[2];
            } elseif (($m[3] ?? '') !== '') {
                $atts[strtolower($m[3])] = $m[4];
            } elseif (($m[5] ?? '') !== '') {
                $atts[strtolower($m[5])] = $m[6];
            } else {
                $atts[] = ($m[7] ?? '') !== '' ? $m[7] : (($m[8] ?? '') !== '' ? $m[8] : ($m[9] ?? ''));
            }
        }
        return $atts;
    }

    /**
     * Dấu [ ] nằm TRONG thẻ HTML (thuộc tính alt, title, href…, chú thích <!-- -->) đổi tạm thành \x03 \x04 để regex không
     * khớp: shortcode trong thuộc tính sẽ chèn HTML vào giữa thẻ (vd. alt="[nut url='… onerror=…']" → thẻ <a> phá thuộc tính,
     * lọt onerror — XSS dù nội dung đã qua Html::sanitize). Như do_shortcodes_in_html_tags của WordPress.
     */
    private static function protect(string $content): string
    {
        if (!str_contains($content, '<')) {
            return $content;
        }
        return (string) preg_replace_callback('#<!--.*?-->|<[^<>]*>#s', fn ($m) => strtr($m[0], ['[' => "\x03", ']' => "\x04"]), $content);
    }

    private static function unprotect(string $content): string
    {
        return strtr($content, ["\x03" => '[', "\x04" => ']']);
    }

    /** Kết quả khớp trên nội dung đã protect(): trả lại [ ] trong các nhóm trước khi gọi callback. */
    private static function callMatch(array $m): string
    {
        return self::call(array_map(fn ($v) => $v === null ? null : self::unprotect($v), $m));
    }

    /** Một shortcode khớp được: gọi callback, lỗi của plugin thì ghi nhật ký và in rỗng (trang vẫn chạy). */
    private static function call(array $m): string
    {
        // Mọi nhóm luôn có mặt (PREG_UNMATCHED_AS_NULL), nhóm không khớp = null.
        if ($m[1] === '[' && $m[6] === ']') {
            return substr($m[0], 1, -1); // [[ten]] → in nguyên văn [ten]
        }
        $tag = $m[2];
        $content = $m[5] !== null && $m[4] !== '/' && str_contains($m[0], "[/$tag]") ? $m[5] : null;
        try {
            $out = (self::$tags[$tag])(self::parseAtts((string) $m[3]), $content, $tag);
            return $m[1] . (is_scalar($out) || $out instanceof \Stringable ? (string) $out : '') . ($m[6] ?? '');
        } catch (Throwable $e) {
            ErrorLog::add('error', "Shortcode [$tag] lỗi: " . $e->getMessage(), $e->getFile(), $e->getLine());
            return $m[1] . ($m[6] ?? '');
        }
    }

    /**
     * Khớp [ten …], [ten …/], [ten …]…[/ten] và dạng thoát [[ten]]. Nhóm: 1 "[" thoát, 2 tên, 3 tham số, 4 "/" tự đóng,
     * 5 nội dung, 6 "]" thoát.
     */
    private static function regex(array $tags): string
    {
        $names = implode('|', array_map(fn ($t) => preg_quote($t, '#'), $tags));
        return '#\[(\[?)(' . $names . ')(?![\w-])([^\]/]*(?:/(?!\])[^\]/]*)*?)(?:(/)\]|\](?:([^\[]*+(?:\[(?!/\2\])[^\[]*+)*+)\[/\2\])?)(\]?)#s';
    }
}
