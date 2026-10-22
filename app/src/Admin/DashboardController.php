<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Content\Posts;
use LQ\Core\View;

final class DashboardController
{
    public static function index(): void
    {
        $user = current_user();
        $layout = Widgets::layout((int) $user['id']);
        $html = '';
        foreach ($layout as $item) {
            $html .= Widgets::render($item['id'], $item['size']);
        }
        View::render('admin/dashboard', [
            'title' => 'Bảng tin',
            'widgetsHtml' => $html,
            'layout' => $layout,
            'catalog' => Widgets::catalog(),
            'sizes' => Widgets::SIZES,
        ]);
    }

    /** Lưu bố cục (thứ tự + kích thước) từ chế độ Tuỳ chỉnh. Body JSON: {"layout":[{"id":..,"size":..}]} */
    public static function saveLayout(): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        // Body hỏng/thiếu "layout" (request cụt, gửi form thường…) thì từ chối — trước đây bị coi là bố cục rỗng và xoá sạch Bảng tin.
        if (!is_array($body) || !is_array($body['layout'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Dữ liệu bố cục không hợp lệ.'], 400);
        }
        $layout = $body['layout'];
        $saved = Widgets::saveLayout((int) current_user()['id'], $layout);
        json_response(['ok' => true, 'layout' => $saved]);
    }

    public static function resetLayout(): void
    {
        Widgets::resetLayout((int) current_user()['id']);
        flash('success', 'Đã khôi phục Bảng tin mặc định.');
        redirect('/admin');
    }

    /** HTML của một widget để chèn khi kéo từ ngăn "Thêm widget" vào. */
    public static function widget(): void
    {
        $id = input_str('id');
        $w = Widgets::get($id);
        if (!$w) {
            json_response(['ok' => false, 'message' => 'Widget không tồn tại.'], 404);
        }
        $size = (int) input('size', $w['size']);
        if (!in_array($size, $w['sizes'], true)) {
            $size = $w['size'];
        }
        json_response(['ok' => true, 'html' => Widgets::render($id, $size), 'size' => $size]);
    }

    public static function quickDraft(): void
    {
        Kernel::require('edit_posts');
        $title = input_str('title');
        if ($title === '') {
            flash('error', 'Nhập tiêu đề cho bản nháp.');
            redirect('/admin');
        }
        [$errors] = Posts::save([
            'title' => $title,
            'content' => nl2br(e(input_str('content'))),
            'action' => 'draft',
        ], null, null);
        if ($errors) {
            flash('error', reset($errors));
            redirect('/admin');
        }
        flash('success', 'Đã lưu bản nháp "' . $title . '".');
        redirect('/admin');
    }

    /** Tìm nhanh cho thanh lệnh ⌘K. */
    /**
     * Tìm cho thanh ⌘K: bài viết & trang (kể cả thùng rác), term mọi taxonomy, ảnh, plugin, theme, tệp của theme đang dùng.
     * Mỗi nhóm chỉ trả khi người dùng có quyền tương ứng.
     */
    public static function search(): void
    {
        $q = input_str('q');
        $out = ['posts' => [], 'terms' => [], 'media' => [], 'plugins' => [], 'themes' => [], 'files' => []];
        if ($q === '') {
            json_response(['ok' => true] + $out);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        // So thêm theo dạng slug không dấu: "duong deo" khớp "đường đèo" (MariaDB coi đ ≠ d).
        // Slug rỗng (q chỉ gồm ký tự đặc biệt như "%", "_") thì chỉ so theo $like đã escape — trước đây "%" thô lọt vào LIKE
        // và str_contains(…, '') luôn đúng nên khớp mọi bài/plugin/theme.
        $slug = \LQ\Core\Slug::make($q);
        $slugLike = $slug !== '' ? '%' . $slug . '%' : $like;
        $has = fn (string $text) => ($slug !== '' && str_contains(\LQ\Core\Slug::make($text), $slug)) || mb_stripos($text, $q) !== false;

        if (can('edit_posts')) {
            $own = can('edit_others_posts') ? '' : ' AND author_id = ' . (int) current_user()['id'];
            $out['posts'] = array_map(fn ($p) => [
                'id' => (int) $p['id'],
                'title' => $p['title'] ?: '(Không tiêu đề)',
                'status' => ($p['type'] === 'page' ? 'Trang · ' : '') . (Posts::STATUSES[$p['status']] ?? $p['status']),
                'statusKey' => $p['status'],
                'type' => $p['type'],
                'url' => '/admin/' . Posts::TYPES[$p['type']]['route'] . '/' . $p['id'] . '/edit',
            ], db()->all(
                // Bài viết (tác giả chỉ thấy bài của mình) + trang tĩnh nếu có quyền sửa trang; bài trong thùng rác xếp cuối.
                "SELECT id, title, status, type FROM {posts}
                 WHERE (title LIKE ? OR slug LIKE ?)
                   AND ((type = 'post'$own)" . (can('edit_pages') ? " OR type = 'page'" : '') . ")
                 ORDER BY status = 'trash', updated_at DESC LIMIT 8",
                [$like, $slugLike]
            ));
        }
        // Term của mọi taxonomy người dùng quản lý được, mở đúng trang sửa của taxonomy đó.
        $taxes = array_filter(\LQ\Content\Taxonomies::all(), fn ($t) => can($t['cap']));
        if ($taxes) {
            $out['terms'] = array_map(fn ($t) => [
                'name' => $t['name'],
                'type' => lq_ucfirst($taxes[$t['taxonomy']]['singular']),
                'taxonomy' => $t['taxonomy'],
                'url' => '/admin/' . $taxes[$t['taxonomy']]['route'] . '/' . $t['id'] . '/edit',
            ], db()->all(
                'SELECT id, name, taxonomy FROM {terms} WHERE taxonomy IN (' . \LQ\Core\Database::placeholders(array_keys($taxes)) . ')
                 AND (name LIKE ? OR slug LIKE ?) ORDER BY name LIMIT 6',
                [...array_keys($taxes), $like, $slugLike]
            ));
        }
        if (can('upload_files')) {
            $out['media'] = array_map(fn ($m) => [
                'id' => (int) $m['id'],
                'name' => basename($m['path']),
                'hint' => trim(($m['alt'] ?: '') . ' · ' . strtoupper(pathinfo($m['path'], PATHINFO_EXTENSION)), ' ·'),
                'url' => '/admin/media?open=' . $m['id'],
            ], db()->all('SELECT id, path, alt FROM {media} WHERE path LIKE ? OR file_name LIKE ? OR alt LIKE ? OR caption LIKE ? ORDER BY id DESC LIMIT 5',
                [$slugLike, $like, $like, $like]));
        }
        if (can('manage_plugins')) {
            foreach (\LQ\Core\Plugins::all() as $slugKey => $p) {
                if ($has($p['name']) || $has($slugKey)) {
                    $out['plugins'][] = ['name' => $p['name'], 'hint' => $p['active'] ? 'Plugin · Đang bật' : 'Plugin · Đang tắt', 'url' => '/admin/plugins'];
                }
            }
        }
        if (can('manage_themes')) {
            $active = \LQ\Core\Themes::active();
            foreach (\LQ\Core\Themes::all() as $slugKey => $t) {
                if ($has($t['name']) || $has($slugKey)) {
                    $out['themes'][] = ['name' => $t['name'], 'hint' => $slugKey === $active ? 'Theme · Đang dùng' : 'Theme', 'url' => '/admin/themes'];
                }
            }
        }
        if (can('edit_themes') && \LQ\Core\ThemeFiles::editingAllowed()) {
            foreach (array_keys(\LQ\Core\ThemeFiles::list(\LQ\Core\Themes::active())) as $file) {
                if (stripos((string) $file, $q) !== false && count($out['files']) < 5) {
                    $out['files'][] = ['name' => (string) $file, 'url' => '/admin/themes/editor?file=' . rawurlencode((string) $file)];
                }
            }
        }
        json_response(['ok' => true] + $out);
    }
}
