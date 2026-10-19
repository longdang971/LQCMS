<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Content\Media;
use LQ\Content\Pages;
use LQ\Content\PostFields;
use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Core\Auth;
use LQ\Core\Permalink;
use LQ\Core\Session;
use LQ\Core\Uploads;
use LQ\Core\View;

/**
 * Bài viết và trang tĩnh dùng chung controller; route truyền 'type' => 'post' | 'page'.
 */
final class PostsController
{
    private const PER_PAGE = 20;

    /** Kiểu nội dung của request + kiểm tra quyền. */
    private static function type(array $p): string
    {
        $type = $p['type'] ?? 'post';
        Kernel::require(Posts::TYPES[$type]['cap']);
        return $type;
    }

    private static function base(string $type): string
    {
        return '/admin/' . Posts::TYPES[$type]['route'];
    }

    public static function index(array $p = []): void
    {
        $type = self::type($p);
        $filters = [
            'status' => input_str('status'),
            'q' => input_str('q'),
            'category' => (int) input('category', 0),
            'tag' => (int) input('tag', 0),
            'term' => (int) input('term', 0),
        ];

        if ($type === 'page') {
            $counts = array_fill_keys(array_keys(Posts::STATUSES), 0);
            foreach (db()->all("SELECT status, COUNT(*) c FROM {posts} WHERE type = 'page' GROUP BY status") as $r) {
                $counts[$r['status']] = (int) $r['c'];
            }
            $counts['all'] = array_sum($counts) - $counts['trash'];
            $status = isset(Posts::STATUSES[$filters['status']]) ? $filters['status'] : null;
            View::render('admin/pages/index', [
                'title' => 'Trang',
                'filters' => $filters,
                'items' => Pages::tree($status, $filters['q']),
                'counts' => $counts,
                'templates' => Pages::templates(),
            ]);
            return;
        }

        $page = max(1, (int) input('page', 1));
        $result = Posts::paginate($filters, $page, self::PER_PAGE);
        View::render('admin/posts/index', [
            'title' => 'Bài viết',
            'filters' => $filters,
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
            'categories' => Terms::tree(Terms::all('category')),
        ] + $result);
    }

    public static function create(array $p = []): void
    {
        self::form(null, self::type($p));
    }

    public static function edit(array $p): void
    {
        $type = self::type($p);
        $post = Posts::find((int) $p['id'], $type) ?? Kernel::notFound();
        if (!Posts::canEdit($post)) {
            Kernel::forbidden();
        }
        self::form($post, $type);
    }

    private static function form(?array $post, string $type): void
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        $common = [
            'post' => $post,
            'type' => $type,
            'errors' => $errors,
            'permalink' => $post && $post['status'] === 'published' ? Posts::permalink($post) : null,
            'author' => $post ? Auth::find((int) $post['author_id']) : current_user(),
            'boxes' => PostFields::layout($type),
            'featuredMedia' => !empty($post['featured_image']) ? Media::findByPath($post['featured_image']) : null,
        ];

        if ($type === 'page') {
            $exclude = $post ? Pages::descendantIds((int) $post['id']) : [];
            $parentRow = $post && $post['parent_id'] ? Posts::find((int) $post['parent_id'], 'page') : null;
            View::render('admin/pages/form', $common + [
                'title' => $post ? 'Sửa trang' : 'Thêm trang mới',
                'parents' => array_values(array_filter(Pages::tree(), fn ($pg) => !in_array((int) $pg['id'], $exclude, true))),
                'templates' => Pages::templates(),
                // Khung URL: site/[đường dẫn trang cha/]slug/
                'pathPrefix' => site_url('/' . ($parentRow ? Pages::path($parentRow) . '/' : '')),
            ]);
            return;
        }

        $structure = (string) option('permalink_structure', '/%postname%/');
        // Khung permalink để JS ghép slug vào khi gõ tiêu đề, vd. "https://site.vn/2026/10/" + slug + "/"
        $sample = Permalink::build($structure, [
            'id' => $post['id'] ?? 0,
            'slug' => '__SLUG__',
            'published_at' => $post['published_at'] ?? null,
            'created_at' => $post['created_at'] ?? null,
        ]);
        View::render('admin/posts/form', $common + [
            'title' => $post ? 'Sửa bài viết' : 'Viết bài mới',
            'categories' => Terms::tree(Terms::all('category')),
            'selectedCats' => $post ? array_map('intval', array_column(Terms::forPost((int) $post['id'], 'category'), 'id')) : [(int) option('default_category')],
            'tags' => $post ? array_column(Terms::forPost((int) $post['id'], 'tag'), 'name') : [],
            'popularTags' => db()->all(
                "SELECT t.name, COUNT(pt.post_id) c FROM {terms} t LEFT JOIN {post_terms} pt ON pt.term_id = t.id
                 WHERE t.taxonomy = 'tag' GROUP BY t.id ORDER BY c DESC, t.name LIMIT 8"
            ),
            'permalinkPattern' => $structure === '' ? null : site_url($sample),
            'customTax' => array_map(fn ($t) => $t + [
                'selected' => $post ? Terms::forPost((int) $post['id'], $t['key']) : [],
                'tree' => $t['hierarchical'] ? Terms::tree(Terms::all($t['key'])) : [],
            ], Taxonomies::custom()),
        ]);
    }

    public static function save(array $p = []): void
    {
        $type = self::type($p);
        $base = self::base($type);
        $id = (int) input('id', 0);
        $existing = null;
        if ($id) {
            $existing = Posts::find($id, $type) ?? Kernel::notFound();
            if (!Posts::canEdit($existing)) {
                Kernel::forbidden();
            }
        }

        // Ảnh đại diện: null = giữ nguyên, '' = gỡ, chuỗi = ảnh mới (chọn từ thư viện hoặc tải lên).
        $featured = null;
        if (array_key_exists('featured_url', $_POST)) {
            $url = trim((string) $_POST['featured_url']);
            if ($url === '') {
                $featured = '';
            } elseif (Media::findByPath($url)) {
                $featured = $url;
            }
        }
        if (!empty($_FILES['featured_image']['name']) && can('upload_files')) {
            [$url, $err] = Uploads::storeImage($_FILES['featured_image']);
            $err ? flash('error', 'Ảnh đại diện: ' . $err) : ($featured = $url);
        }

        // Field do theme/plugin khai báo: kiểm tra trước, lỗi thì không lưu gì cả.
        $metaInput = is_array($_POST['meta'] ?? null) ? $_POST['meta'] : [];
        $template = $type === 'page' ? trim((string) ($_POST['template'] ?? '')) : null;
        [$fieldErrors, $metaValues] = PostFields::collect($metaInput, $existing, $type, $template);
        [$errors, $savedId] = $fieldErrors ? [$fieldErrors, null] : Posts::save($_POST, $existing, $featured, $type);
        if ($errors) {
            Session::keepOld($_POST);
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect($id ? "$base/$id/edit" : "$base/new");
        }

        PostFields::save($savedId, $metaValues, $_POST, $type);

        $post = Posts::find($savedId, $type);
        $noun = Posts::TYPES[$type]['singular'];
        flash('success', match ($post['status']) {
            'published' => $existing && $existing['status'] === 'published' ? "Đã cập nhật $noun." : "Đã đăng $noun.",
            'scheduled' => 'Đã lên lịch đăng lúc ' . format_date($post['published_at']) . '.',
            'pending' => 'Đã gửi bài chờ duyệt.',
            default => 'Đã lưu bản nháp.',
        });
        redirect("$base/$savedId/edit");
    }

    public static function trash(array $p): void
    {
        $post = self::editable($p);
        Posts::trash((int) $post['id']);
        flash('success', 'Đã chuyển "' . ($post['title'] ?: 'Không tiêu đề') . '" vào thùng rác.');
        redirect_back(self::base($post['type']));
    }

    public static function restore(array $p): void
    {
        $post = self::editable($p);
        Posts::restore((int) $post['id']);
        flash('success', 'Đã khôi phục ' . Posts::TYPES[$post['type']]['singular'] . '.');
        redirect_back(self::base($post['type']) . '?status=trash');
    }

    public static function destroy(array $p): void
    {
        $post = self::editable($p);
        Posts::destroy((int) $post['id']);
        flash('success', 'Đã xoá vĩnh viễn ' . Posts::TYPES[$post['type']]['singular'] . '.');
        redirect(self::base($post['type']) . '?status=trash');
    }

    public static function emptyTrash(array $p = []): void
    {
        $type = self::type($p);
        if ($type === 'post') {
            Kernel::require('edit_others_posts');
        }
        $ids = db()->column("SELECT id FROM {posts} WHERE type = ? AND status = 'trash'", [$type]);
        foreach ($ids as $id) {
            Posts::destroy((int) $id);
        }
        flash('success', 'Đã dọn sạch thùng rác (' . count($ids) . ' ' . Posts::TYPES[$type]['singular'] . ').');
        redirect(self::base($type));
    }

    public static function bulk(array $p = []): void
    {
        $type = self::type($p);
        $noun = Posts::TYPES[$type]['singular'];
        $action = input_str('bulk_action');
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        if (!$ids || !in_array($action, ['trash', 'restore', 'delete', 'publish', 'draft'], true)) {
            flash('warning', "Hãy chọn $noun và thao tác cần thực hiện.");
            redirect_back(self::base($type));
        }
        $done = 0;
        foreach ($ids as $id) {
            $post = Posts::find($id, $type);
            if (!$post || !Posts::canEdit($post)) {
                continue;
            }
            $isTrash = $post['status'] === 'trash';
            $ok = match ($action) {
                'trash' => !$isTrash && (Posts::trash($id) || true),
                'restore' => $isTrash && (Posts::restore($id) || true),
                'delete' => $isTrash && (Posts::destroy($id) || true),
                'publish' => !$isTrash && ($type === 'page' || can('publish_posts')) && db()->query(
                    "UPDATE {posts} SET status = 'published', published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ?",
                    [now_utc(), now_utc(), $id]
                ),
                'draft' => !$isTrash && db()->update('posts', ['status' => 'draft', 'updated_at' => now_utc()], ['id' => $id]) >= 0,
            };
            $done += $ok ? 1 : 0;
        }
        $labels = ['trash' => 'chuyển vào thùng rác', 'restore' => 'khôi phục', 'delete' => 'xoá vĩnh viễn', 'publish' => 'đăng', 'draft' => 'chuyển về nháp'];
        flash('success', "Đã {$labels[$action]} $done $noun.");
        redirect_back(self::base($type));
    }

    /** Upload ảnh từ trình soạn thảo (TinyMCE), trả JSON {location}. Ảnh vào thẳng thư viện media. */
    public static function upload(): void
    {
        if (!can('upload_files')) {
            json_response(['error' => ['message' => 'Bạn không có quyền tải tệp lên.']], 403);
        }
        [$url, $err] = Uploads::storeImage($_FILES['file'] ?? []);
        if ($err) {
            json_response(['error' => ['message' => $err]], 422);
        }
        json_response(['location' => $url]);
    }

    private static function editable(array $p): array
    {
        $type = self::type($p);
        $post = Posts::find((int) $p['id'], $type) ?? Kernel::notFound();
        if (!Posts::canEdit($post)) {
            Kernel::forbidden();
        }
        return $post;
    }
}
