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
        $layout = is_array($body['layout'] ?? null) ? $body['layout'] : [];
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
    public static function search(): void
    {
        $q = input_str('q');
        $out = ['posts' => [], 'terms' => []];
        if ($q !== '' && can('edit_posts')) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            // So thêm theo dạng slug không dấu: "duong deo" khớp "đường đèo" (MariaDB coi đ ≠ d).
            $slugLike = '%' . (\LQ\Core\Slug::make($q) ?: $q) . '%';
            $own = can('edit_others_posts') ? '' : ' AND author_id = ' . (int) current_user()['id'];
            $out['posts'] = array_map(fn ($p) => [
                'id' => (int) $p['id'],
                'title' => $p['title'] ?: '(Không tiêu đề)',
                'status' => ($p['type'] === 'page' ? 'Trang · ' : '') . (Posts::STATUSES[$p['status']] ?? $p['status']),
                'statusKey' => $p['status'],
                'type' => $p['type'],
                'url' => '/admin/' . Posts::TYPES[$p['type']]['route'] . '/' . $p['id'] . '/edit',
            ], db()->all(
                // Bài viết (tác giả chỉ thấy bài của mình) + trang tĩnh nếu có quyền sửa trang.
                "SELECT id, title, status, type FROM {posts}
                 WHERE status <> 'trash' AND (title LIKE ? OR slug LIKE ?)
                   AND ((type = 'post'$own)" . (can('edit_pages') ? " OR type = 'page'" : '') . ')
                 ORDER BY updated_at DESC LIMIT 8',
                [$like, $slugLike]
            ));
            if (can('manage_terms')) {
                $out['terms'] = array_map(fn ($t) => [
                    'name' => $t['name'],
                    'type' => $t['taxonomy'] === 'category' ? 'Chuyên mục' : 'Thẻ',
                    'url' => '/admin/' . ($t['taxonomy'] === 'category' ? 'categories' : 'tags') . '/' . $t['id'] . '/edit',
                ], db()->all('SELECT id, name, taxonomy FROM {terms} WHERE name LIKE ? OR slug LIKE ? ORDER BY name LIMIT 4', [$like, $slugLike]));
            }
        }
        json_response(['ok' => true] + $out);
    }
}
