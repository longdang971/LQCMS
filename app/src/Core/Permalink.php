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
        // %author% đứng đầu: bài của tài khoản "admin" thành /admin/… và rơi vào trang quản trị.
        if (str_starts_with($structure, '/%author%')) {
            return 'Cấu trúc không được bắt đầu bằng %author% (tên đăng nhập như "admin" trùng đường dẫn hệ thống).';
        }
        if (preg_match('#^/admin(/|$)#', $structure) || preg_match('#//#', $structure)) {
            return 'Cấu trúc không hợp lệ (trùng với /admin hoặc có "//").';
        }
        return null;
    }

    /** Đường dẫn gốc hệ thống dùng — không slug/tiền tố nào được trùng. */
    public const RESERVED_ROOT = ['admin', 'assets', 'content', 'install', 'page', 'tac-gia', 'ajax', 'app', 'storage', 'database', 'docs']; // page = phân trang, tac-gia = trang tác giả, ajax = endpoint AJAX; app/storage/database/docs = thư mục thật ở web root

    /**
     * Các "đoạn đầu URL" đã có chủ ở gốc site: đường dẫn hệ thống + tiền tố của các taxonomy có tiền tố.
     * Dùng để chặn slug chuyên mục/thẻ đụng nhau khi taxonomy đó bỏ tiền tố (URL dạng /the-loai/).
     */
    public static function takenRootSegments(?string $exceptTaxonomy = null): array
    {
        // Đoạn đầu của route riêng (lq_add_route, vd. "su-kien") cũng đã có chủ.
        // Tiền tố URL của loại nội dung tuỳ biến (lq_register_post_type, vd. "su-kien") cũng vậy.
        $taken = [...self::RESERVED_ROOT, ...\LQ\Front\Routes::rootSegments(),
            ...array_map(fn ($t) => explode('/', $t['base'])[0], \LQ\Content\Posts::customTypes())];
        foreach (\LQ\Content\Taxonomies::all() as $key => $t) {
            if ($key !== $exceptTaxonomy && $t['base'] !== '') {
                $taken[] = explode('/', $t['base'])[0];
            }
        }
        return array_values(array_unique($taken));
    }

    /**
     * Đoạn đầu URL mà route riêng / loại nội dung tuỳ biến (plugin, theme đăng ký sau) chiếm mất của nội dung đã có:
     * trang cấp gốc, term taxonomy không tiền tố, bài viết khi cấu trúc /%postname%/. Route/CPT thắng nên nội dung kia thành
     * không vào được — admin cần được báo để đổi slug. @return list<array{segment:string, owner:string, title:string}>
     */
    public static function collisions(): array
    {
        $owners = [];
        foreach (\LQ\Front\Routes::rootSegments() as $seg) {
            $owners[$seg] ??= 'đường dẫn riêng';
        }
        foreach (\LQ\Content\Posts::customTypes() as $t) {
            $owners[explode('/', $t['base'])[0]] ??= 'loại nội dung "' . $t['label'] . '"';
        }
        if (!$owners) {
            return [];
        }
        $postsAtRoot = trim((string) option('permalink_structure', '/%postname%/'), '/') === '%postname%';
        $out = [];
        foreach ($owners as $seg => $owner) {
            $seg = (string) $seg;
            $title = db()->value("SELECT title FROM {posts} WHERE type = 'page' AND parent_id IS NULL AND slug = ? LIMIT 1", [$seg]);
            $what = $title !== null ? 'trang' : null;
            if (!$what && $postsAtRoot && ($t = db()->value("SELECT title FROM {posts} WHERE type = 'post' AND slug = ? LIMIT 1", [$seg])) !== null) {
                [$what, $title] = ['bài viết', $t];
            }
            foreach ($what ? [] : \LQ\Content\Taxonomies::all() as $key => $tax) {
                if ($tax['base'] === '' && ($t = db()->value('SELECT name FROM {terms} WHERE taxonomy = ? AND parent_id IS NULL AND slug = ? LIMIT 1', [$key, $seg])) !== null) {
                    [$what, $title] = [$tax['singular'], $t];
                    break;
                }
            }
            if ($what) {
                $out[] = ['segment' => $seg, 'owner' => $owner, 'title' => lq_ucfirst($what) . ' "' . $title . '"'];
            }
        }
        return $out;
    }

    /**
     * Slug này đã có chủ ở đoạn đầu URL chưa — trang cấp gốc (/slug/), term của taxonomy không tiền tố (/slug/) và bài viết
     * khi cấu trúc là /%postname%/ cùng nằm ở gốc site. Resolver ưu tiên trang → term → bài, nên trùng là cái sau thành 404.
     * $kind: post | page | term (chính đối tượng đang lưu được bỏ qua: cùng loại đã có uniqueSlug riêng kiểm).
     */
    public static function rootSlugTaken(string $slug, string $kind, ?string $taxonomy = null): bool
    {
        $postsAtRoot = trim((string) option('permalink_structure', '/%postname%/'), '/') === '%postname%';
        if ($kind === 'post' && (!$postsAtRoot || in_array($slug, self::takenRootSegments(), true))) {
            return $postsAtRoot; // bài ở gốc mà slug là "admin", "chuyen-muc"… cũng không được
        }
        if ($kind !== 'page' && db()->value("SELECT 1 FROM {posts} WHERE type = 'page' AND parent_id IS NULL AND slug = ? LIMIT 1", [$slug])) {
            return true;
        }
        if ($kind !== 'post' && $postsAtRoot && db()->value("SELECT 1 FROM {posts} WHERE type = 'post' AND slug = ? LIMIT 1", [$slug])) {
            return true;
        }
        foreach (\LQ\Content\Taxonomies::all() as $key => $t) {
            if ($t['base'] === '' && !($kind === 'term' && $key === $taxonomy)
                && db()->value('SELECT 1 FROM {terms} WHERE taxonomy = ? AND parent_id IS NULL AND slug = ? LIMIT 1', [$key, $slug])) {
                return true;
            }
        }
        return false;
    }

    /** Tiền tố cho trang chuyên mục / thẻ, vd. "chuyen-muc". Để trống = không tiền tố. */
    /** $owned: cũng không được trùng đường dẫn riêng / tiền tố loại nội dung tuỳ biến (khi đổi tiền tố chuyên mục, thẻ trong Cài đặt). */
    public static function validateBase(string $base, bool $owned = false): ?string
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
        $first = explode('/', $base)[0];
        if ($owned && (in_array($first, \LQ\Front\Routes::rootSegments(), true)
            || in_array($first, array_map(fn ($t) => explode('/', $t['base'])[0], \LQ\Content\Posts::customTypes()), true))) {
            return 'Tiền tố "' . $base . '" đã được theme/plugin dùng (đường dẫn riêng hoặc loại nội dung tuỳ biến).';
        }
        return null;
    }
}
