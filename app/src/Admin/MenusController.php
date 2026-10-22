<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Content\Menus;
use LQ\Content\Pages;
use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Core\View;

/** Giao diện › Menu: dựng menu (kéo thả lồng cấp) và gán vào vị trí theme khai báo (lq_register_nav_menu). */
final class MenusController
{
    public static function index(): void
    {
        Kernel::require('manage_themes');
        $menus = Menus::all();
        $want = input_str('menu');
        $current = $want === 'new' ? null : (Menus::find((int) $want) ?? ($want === '' && $menus ? reset($menus) : null));
        if ($want !== '' && $want !== 'new' && !$current) {
            Kernel::notFound();
        }
        View::render('admin/menus', [
            'title' => 'Menu',
            'menus' => $menus,
            'current' => $current,
            // Cây mục cho trình dựng menu (giữ cả mục hỏng, đánh dấu missing để người dùng gỡ).
            'items' => $current ? self::forEditor(Menus::resolve($current['items'], true)) : [],
            'locations' => Menus::locations(),
            'assigned' => Menus::assigned(),
            'sources' => self::sources(),
        ]);
    }

    /** POST /admin/menus/save — body JSON {id, name, items, locations}. */
    public static function save(): void
    {
        Kernel::require('manage_themes');
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) {
            json_response(['ok' => false, 'message' => 'Dữ liệu menu không hợp lệ.'], 400);
        }
        [$err, $id] = Menus::save(
            (int) ($in['id'] ?? 0),
            is_string($in['name'] ?? null) ? $in['name'] : '',
            is_array($in['items'] ?? null) ? $in['items'] : [],
            array_values(array_filter((array) ($in['locations'] ?? []), 'is_string'))
        );
        if ($err) {
            json_response(['ok' => false, 'message' => $err], 422);
        }
        json_response(['ok' => true, 'id' => $id, 'message' => 'Đã lưu menu.', 'url' => '/admin/menus?menu=' . $id]);
    }

    public static function delete(array $p): void
    {
        Kernel::require('manage_themes');
        $menu = Menus::find((int) $p['id']) ?? Kernel::notFound();
        Menus::delete((int) $menu['id']);
        flash('success', 'Đã xoá menu "' . $menu['name'] . '".');
        redirect('/admin/menus');
    }

    /** Mục đã resolve → dữ liệu gọn cho menus.js. */
    private static function forEditor(array $items): array
    {
        return array_map(fn ($it) => [
            'id' => $it['id'], 'type' => $it['type'], 'object' => $it['object'], 'label' => $it['label'], 'url' => $it['url'],
            'new_tab' => $it['new_tab'], 'classes' => $it['classes'], 'auto' => $it['auto_label'], 'kind' => $it['kind'],
            'missing' => $it['missing'], 'children' => self::forEditor($it['children']),
        ], $items);
    }

    /** Nguồn để thêm mục: trang, bài mới, chuyên mục, thẻ, taxonomy tuỳ biến, loại nội dung tuỳ biến. */
    private static function sources(): array
    {
        $out = [];
        $pages = array_values(array_filter(Pages::tree('published')));
        $out[] = ['title' => 'Trang', 'items' => [
            ['type' => 'home', 'object' => null, 'label' => 'Trang chủ', 'kind' => 'Trang chủ', 'depth' => 0],
            ...array_map(fn ($p) => ['type' => 'post', 'object' => (int) $p['id'], 'label' => $p['title'] ?: '(Không tiêu đề)', 'kind' => 'Trang', 'depth' => (int) $p['depth']], $pages),
        ]];
        $postsOf = fn (string $type) => db()->all(
            "SELECT id, title FROM {posts} WHERE type = ? AND status = 'published' ORDER BY published_at DESC LIMIT 40", [$type]
        );
        $out[] = ['title' => 'Bài viết', 'items' => array_map(fn ($p) => ['type' => 'post', 'object' => (int) $p['id'], 'label' => $p['title'] ?: '(Không tiêu đề)', 'kind' => 'Bài viết', 'depth' => 0], $postsOf('post'))];
        foreach (Posts::customTypes() as $key => $t) {
            if (!$t['public']) {
                continue;
            }
            $out[] = ['title' => $t['label'], 'items' => [
                ...($t['has_archive'] ? [['type' => 'archive', 'object' => $key, 'label' => $t['label'], 'kind' => 'Danh sách', 'depth' => 0, 'hint' => 'Trang danh sách']] : []),
                ...array_map(fn ($p) => ['type' => 'post', 'object' => (int) $p['id'], 'label' => $p['title'] ?: '(Không tiêu đề)', 'kind' => lq_ucfirst($t['singular']), 'depth' => 0], $postsOf($key)),
            ]];
        }
        foreach (Taxonomies::all() as $key => $tax) {
            $terms = $tax['hierarchical'] ? Terms::tree(Terms::all($key)) : array_slice(Terms::all($key), 0, 60);
            if ($terms) {
                $out[] = ['title' => $tax['label'], 'items' => array_map(fn ($t) => ['type' => 'term', 'object' => (int) $t['id'], 'label' => $t['name'], 'kind' => lq_ucfirst($tax['singular']), 'depth' => (int) ($t['depth'] ?? 0)], $terms)];
            }
        }
        return array_values(array_filter($out, fn ($s) => $s['items']));
    }
}
