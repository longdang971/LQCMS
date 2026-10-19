<?php
declare(strict_types=1);

namespace LQ\Core;

/**
 * Cấu trúc đường dẫn tĩnh kiểu WordPress: "/%year%/%monthnum%/%postname%/".
 * - compile(): cấu trúc -> regex để router frontend khớp request.
 * - build():   bài viết -> URL.
 * Toàn bộ rewrite nằm trong PHP; nginx chỉ cần `try_files $uri $uri/ /index.php?$args;` một lần.
 */
final class Permalink
{
    public const TAGS = [
        '%year%' => ['(?<year>\d{4})', 'Năm, 4 chữ số (2026)'],
        '%monthnum%' => ['(?<monthnum>\d{2})', 'Tháng, 2 chữ số (05)'],
        '%day%' => ['(?<day>\d{2})', 'Ngày, 2 chữ số (28)'],
        '%hour%' => ['(?<hour>\d{2})', 'Giờ (15)'],
        '%minute%' => ['(?<minute>\d{2})', 'Phút (43)'],
        '%second%' => ['(?<second>\d{2})', 'Giây (33)'],
        '%post_id%' => ['(?<post_id>\d+)', 'ID bài viết (423)'],
        '%postname%' => ['(?<postname>[a-z0-9-]+)', 'Slug bài viết (bai-viet-mau)'],
        '%category%' => ['(?<category>[a-z0-9-]+(?:/[a-z0-9-]+)*)', 'Slug chuyên mục, gồm cả cha (tin-tuc/cong-nghe)'],
        '%author%' => ['(?<author>[a-zA-Z0-9_.-]+)', 'Tên đăng nhập tác giả'],
    ];

    public const PRESETS = [
        'plain' => ['', 'Thuần', '/?p=123'],
        'day' => ['/%year%/%monthnum%/%day%/%postname%/', 'Ngày và tên', null],
        'month' => ['/%year%/%monthnum%/%postname%/', 'Tháng và tên', null],
        'numeric' => ['/archives/%post_id%', 'Dạng số', null],
        'postname' => ['/%postname%/', 'Tên bài viết', null],
    ];

    public static function compile(string $structure): string
    {
        $parts = preg_split('/(%[a-z_]+%)/', trim($structure, '/'), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $re = '';
        foreach ($parts as $p) {
            $re .= isset(self::TAGS[$p]) ? self::TAGS[$p][0] : preg_quote($p, '#');
        }
        return '#^/' . $re . '/?$#';
    }

    /**
     * @param array{id:int|string, slug:string, published_at?:?string, created_at?:string, category_path?:string, author?:string} $post
     */
    public static function build(string $structure, array $post): string
    {
        if ($structure === '') {
            return '/?p=' . $post['id'];
        }
        $utc = ($post['published_at'] ?? null) ?: ($post['created_at'] ?? now_utc());
        $d = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(site_timezone());
        $path = strtr($structure, [
            '%year%' => $d->format('Y'),
            '%monthnum%' => $d->format('m'),
            '%day%' => $d->format('d'),
            '%hour%' => $d->format('H'),
            '%minute%' => $d->format('i'),
            '%second%' => $d->format('s'),
            '%post_id%' => (string) $post['id'],
            '%postname%' => $post['slug'],
            '%category%' => $post['category_path'] ?? 'chua-phan-loai',
            '%author%' => $post['author'] ?? 'admin',
        ]);
        return '/' . ltrim($path, '/');
    }

    /** Trả về thông báo lỗi hoặc null nếu cấu trúc hợp lệ. */
    public static function validate(string $structure): ?string
    {
        if ($structure === '') {
            return null;
        }
        if ($structure[0] !== '/') {
            return 'Cấu trúc phải bắt đầu bằng dấu "/".';
        }
        if (!preg_match('#^[a-z0-9/_.%-]+$#', $structure)) {
            return 'Cấu trúc chỉ được chứa chữ thường không dấu, số, "/", "-", "_", "." và các thẻ %...%.';
        }
        preg_match_all('/%[a-z_]+%/', $structure, $m);
        foreach ($m[0] as $tag) {
            if (!isset(self::TAGS[$tag])) {
                return "Thẻ $tag không được hỗ trợ.";
            }
        }
        if (count($m[0]) !== count(array_unique($m[0]))) {
            return 'Mỗi thẻ chỉ được dùng một lần.';
        }
        if (!in_array('%postname%', $m[0], true) && !in_array('%post_id%', $m[0], true)) {
            return 'Cấu trúc phải chứa %postname% hoặc %post_id% để xác định được bài viết.';
        }
        if (preg_match('#^/admin(/|$)#', $structure) || preg_match('#//#', $structure)) {
            return 'Cấu trúc không hợp lệ (trùng với /admin hoặc có "//").';
        }
        return null;
    }

    /** Đường dẫn gốc hệ thống dùng — không slug/tiền tố nào được trùng. */
    public const RESERVED_ROOT = ['admin', 'assets', 'content', 'install'];

    /**
     * Các "đoạn đầu URL" đã có chủ ở gốc site: đường dẫn hệ thống + tiền tố của các taxonomy có tiền tố.
     * Dùng để chặn slug chuyên mục/thẻ đụng nhau khi taxonomy đó bỏ tiền tố (URL dạng /the-loai/).
     */
    public static function takenRootSegments(?string $exceptTaxonomy = null): array
    {
        $taken = self::RESERVED_ROOT;
        foreach (\LQ\Content\Taxonomies::all() as $key => $t) {
            if ($key !== $exceptTaxonomy && $t['base'] !== '') {
                $taken[] = explode('/', $t['base'])[0];
            }
        }
        return array_values(array_unique($taken));
    }

    /** Tiền tố cho trang chuyên mục / thẻ, vd. "chuyen-muc". Để trống = không tiền tố. */
    public static function validateBase(string $base): ?string
    {
        if ($base === '') {
            return null;
        }
        if (!preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $base)) {
            return 'Tiền tố chỉ gồm chữ thường không dấu, số, "-" và "/".';
        }
        if (in_array(explode('/', $base)[0], self::RESERVED_ROOT, true)) {
            return 'Tiền tố "' . $base . '" trùng với đường dẫn hệ thống.';
        }
        return null;
    }
}
