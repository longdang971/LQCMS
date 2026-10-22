<?php
declare(strict_types=1);

namespace LQ\Front;

use LQ\Content\Pages;
use LQ\Core\Themes;

/**
 * Điểm vào frontend: tìm nội dung theo URL (Resolver), chọn template theo thứ bậc rồi nạp tệp của theme.
 * Giữ trạng thái dùng chung cho các hàm template: truy vấn chính và bài đang xét trong vòng lặp.
 */
final class Front
{
    private static ?Query $main = null;
    private static ?array $post = null;
    private static string $template = '';

    public static function run(string $path): void
    {
        if (!is_file(self::themeDir() . '/index.php')) {
            http_response_code(500);
            echo 'Theme đang kích hoạt không tồn tại. Vào /admin/themes để chọn theme khác.';
            return;
        }
        // Route riêng của theme/plugin (lq_add_route) xét trước nội dung.
        self::$main = Query::fromPosts([]);
        self::$main->is = ['route' => true];
        $routed = Routes::dispatch($path, [AdminBar::class, 'boot']);
        if ($routed === true) {
            return;
        }
        if ($routed === false) {
            // Route báo "không có" (vd. /su-kien/{slug} với slug không tồn tại): trang 404 của theme.
            self::$main = Query::fromPosts([]);
            self::$main->is = ['404' => true];
        } else {
            self::$main = Resolver::resolve($path);
        }
        if (self::$main->posts && (self::$main->is['singular'] ?? false)) {
            self::$post = self::$main->posts[0];
        }
        do_action('template_redirect', self::$main);
        if ($routed === null) {
            AdminBar::boot();
        }

        if (self::$main->is['404'] ?? false) {
            http_response_code(404);
        }
        header('Content-Type: text/html; charset=utf-8');
        $file = (string) apply_filters('template_include', self::locate(self::hierarchy(self::$main)), self::$main);
        self::$template = $file;
        self::load($file);
    }

    public static function main(): Query
    {
        return self::$main ??= Query::fromPosts([]);
    }

    public static function setPost(?array $post): void
    {
        self::$post = $post;
    }

    public static function post(): ?array
    {
        return self::$post;
    }

    /** Trả bài hiện tại về bài của truy vấn chính (sau khi chạy một lq_query() riêng). */
    public static function resetPost(): void
    {
        $m = self::main();
        self::$post = $m->post ?? (($m->is['singular'] ?? false) ? ($m->posts[0] ?? null) : null);
    }

    /** Tệp template đang dùng, tính từ thư mục theme (vd. "single.php"). */
    public static function template(): string
    {
        $dir = self::themeDir() . '/';
        return str_starts_with(self::$template, $dir) ? substr(self::$template, strlen($dir)) : self::$template;
    }

    public static function is(string $flag): bool
    {
        return (bool) (self::main()->is[$flag] ?? false);
    }

    public static function themeDir(): string
    {
        return Themes::dir() . '/' . Themes::active();
    }

    /** Thứ bậc template: tệp cụ thể nhất trước, cuối cùng luôn là index.php. */
    public static function hierarchy(Query $q): array
    {
        $is = $q->is;
        $o = $q->queried ?? [];
        $list = [];
        if ($is['404'] ?? false) {
            $list = ['404.php'];
        } elseif ($is['search'] ?? false) {
            $list = ['search.php'];
        } elseif ($is['page'] ?? false) {
            if ($is['front_page'] ?? false) {
                $list[] = 'front-page.php';
            }
            $tpl = Pages::template($o['template'] ?? null);
            if ($tpl && $tpl['file_exists']) {
                $list[] = $tpl['file'];
            }
            array_push($list, "page-{$o['slug']}.php", "page-{$o['id']}.php", 'page.php', 'singular.php');
        } elseif ($is['single'] ?? false) {
            // Loại nội dung tuỳ biến: single-<loại>-<slug>.php → single-<loại>.php → single.php (như WordPress).
            $pt = (string) ($o['type'] ?? 'post');
            $list = $pt === 'post' ? ["single-{$o['slug']}.php"] : ["single-$pt-{$o['slug']}.php", "single-$pt.php"];
            array_push($list, 'single.php', 'singular.php');
        } elseif ($is['post_type_archive'] ?? false) {
            $list = ["archive-{$o['key']}.php", 'archive.php'];
        } elseif ($is['home'] ?? false) {
            $list = ($is['front_page'] ?? false) ? ['front-page.php', 'home.php'] : ['home.php'];
        } elseif ($is['category'] ?? false) {
            $list = ["category-{$o['slug']}.php", "category-{$o['id']}.php", 'category.php', 'archive.php'];
        } elseif ($is['tag'] ?? false) {
            $list = ["tag-{$o['slug']}.php", "tag-{$o['id']}.php", 'tag.php', 'archive.php'];
        } elseif ($is['tax'] ?? false) {
            $list = ["taxonomy-{$o['taxonomy']}-{$o['slug']}.php", "taxonomy-{$o['taxonomy']}.php", 'taxonomy.php', 'archive.php'];
        } elseif ($is['author'] ?? false) {
            $list = ["author-{$o['username']}.php", "author-{$o['id']}.php", 'author.php', 'archive.php'];
        } elseif ($is['date'] ?? false) {
            $list = ['date.php', 'archive.php'];
        }
        $list[] = 'index.php';
        return apply_filters('template_hierarchy', array_values(array_unique($list)), $q);
    }

    /** Tệp đầu tiên có trong theme; không có thì index.php. */
    public static function locate(array $names): string
    {
        $dir = self::themeDir();
        foreach ($names as $n) {
            $n = ltrim((string) $n, '/');
            if ($n !== '' && !str_contains($n, '..') && is_file("$dir/$n")) {
                return "$dir/$n";
            }
        }
        return "$dir/index.php";
    }

    /**
     * Nạp tệp template trong phạm vi riêng. $args dùng được trong tệp qua biến $args.
     * $lq giữ lại cho theme viết trước khi có API template.
     */
    public static function load(string $__file, array $args = []): void
    {
        $theme = Themes::active();
        $lq = [
            'path' => request_path(),
            'site_title' => option('site_title', 'LQCMS'),
            'tagline' => option('tagline', ''),
            'theme_url' => "/content/themes/$theme",
        ];
        (static function () use ($__file, $args, $lq): void {
            require $__file;
        })();
    }
}
