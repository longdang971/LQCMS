<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Content\Media;
use LQ\Content\Pages;
use LQ\Content\PostFields;
use LQ\Content\Posts;
use LQ\Content\Revisions;
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
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', input_str('month')) ? input_str('month') : '',
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
            'perPage' => self::PER_PAGE,
            'categories' => Terms::tree(Terms::all('category')),
            'months' => Posts::months(),
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
        $uid = (int) current_user()['id'];
        $lockedBy = null;
        if ($post) {
            // Mở bài: giữ khoá nếu không ai đang sửa; nếu có người khác thì báo để người dùng chọn.
            $lockedBy = Revisions::heartbeat((int) $post['id'], $uid);
        }
        $common = [
            'autosave' => $post ? Revisions::pendingAutosave($post, $uid) : null,
            'lockedBy' => $lockedBy,
            'revisionCount' => $post ? Revisions::count((int) $post['id']) : 0,
            'post' => $post,
            'type' => $type,
            'errors' => $errors,
            'permalink' => $post && $post['status'] === 'published' ? Posts::permalink($post) : null,
            // Bài chưa đăng: xem trước qua ?p=ID (frontend chỉ cho người có quyền sửa xem).
            'previewUrl' => $post && $post['status'] !== 'published' && $post['status'] !== 'trash' ? site_url('/?p=' . $post['id'] . '&preview=1') : null,
            'author' => $post ? Auth::find((int) $post['author_id']) : current_user(),
            'boxes' => PostFields::layout($type),
            'featuredMedia' => !empty($post['featured_image']) ? Media::findByPath($post['featured_image']) : null,
        ];

        if ($type === 'page') {
            $exclude = $post ? Pages::descendantIds((int) $post['id']) : [];
            $parentRow = $post && $post['parent_id'] ? Posts::find((int) $post['parent_id'], 'page') : null;
            $parents = array_values(array_filter(Pages::tree(), fn ($pg) => !in_array((int) $pg['id'], $exclude, true)));
            // Trang cha đang nằm trong thùng rác: Pages::tree() bỏ qua nên phải thêm vào, không thì lưu trang con
            // sẽ âm thầm đưa nó lên cấp gốc (khôi phục trang cha cũng không gắn lại được).
            if ($parentRow && $parentRow['status'] === 'trash') {
                $parents[] = $parentRow + ['depth' => 0];
            }
            View::render('admin/pages/form', $common + [
                'title' => $post ? 'Sửa trang' : 'Thêm trang mới',
                'subject' => $post ? ($post['title'] ?: '(Không tiêu đề)') : '',
                'parents' => $parents,
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
            'subject' => $post ? ($post['title'] ?: '(Không tiêu đề)') : '',
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

        // Field do theme/plugin khai báo: kiểm tra trước, lỗi thì không lưu gì cả.
        $metaInput = is_array($_POST['meta'] ?? null) ? $_POST['meta'] : [];
        $template = $type === 'page' ? trim((string) ($_POST['template'] ?? '')) : null;
        [$fieldErrors, $metaValues] = PostFields::collect($metaInput, $existing, $type, $template);

        // Bài đã được người khác lưu sau khi mình mở: chặn lần lưu này để không ghi đè âm thầm.
        // Lưu lần nữa (form đã được cập nhật mốc thời gian mới) mới ghi đè.
        $loadedAt = input_str('loaded_at');
        if ($existing && $loadedAt !== '' && $existing['updated_at'] > $loadedAt && !$fieldErrors) {
            $by = db()->value("SELECT u.display_name FROM {post_revisions} r JOIN {users} u ON u.id = r.author_id WHERE r.post_id = ? AND r.kind = 'revision' ORDER BY r.id DESC LIMIT 1", [$id]);
            $fieldErrors['_conflict'] = ($by ? "$by" : 'Người khác') . ' đã lưu ' . Posts::TYPES[$type]['singular'] . ' này lúc ' . format_date($existing['updated_at'], 'H:i') . ' sau khi bạn mở. Nội dung của bạn vẫn còn trong form: bấm lưu lần nữa để ghi đè, hoặc xem Lịch sử phiên bản trước.';
            $_POST['loaded_at'] = $existing['updated_at'];
        }

        [$errors, $savedId] = $fieldErrors ? [$fieldErrors, null] : Posts::save($_POST, $existing, $featured, $type);
        if ($errors) {
            Session::keepOld($_POST);
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect($id ? "$base/$id/edit" : "$base/new");
        }

        PostFields::save($savedId, $metaValues, $_POST, $type);
        // Ảnh đại diện tải lên khi bài chưa có slug (form đánh dấu featured_pending): giờ bài đã có slug thì đặt tên theo slug.
        if ($pending = (int) input('featured_pending', 0)) {
            Media::adoptPostSlug($pending, Posts::find($savedId, $type) ?? []);
        }

        $post = Posts::find($savedId, $type);
        $uid = (int) current_user()['id'];
        Revisions::record($existing ?? [], $post, $uid);
        Revisions::clearAutosave($savedId, $uid);
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
                // Ngày đăng ở tương lai thì thành "Lên lịch" (giống Posts::save), để cron đăng đúng giờ.
                'publish' => !$isTrash && ($type === 'page' || can('publish_posts')) && db()->query(
                    "UPDATE {posts} SET status = IF(COALESCE(published_at, ?) > ?, 'scheduled', 'published'), published_at = COALESCE(published_at, ?), updated_at = ? WHERE id = ?",
                    [now_utc(), now_utc(), now_utc(), now_utc(), $id]
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

    /* ---------- Chống mất bài ---------- */

    /** Tự lưu (JSON). Bài mới: tạo bản nháp ngay lần đầu. Bài đã có: cất bản tự lưu riêng, không đụng bài. */
    public static function autosave(array $p): void
    {
        $type = $p['type'] ?? 'post';
        if (!can(Posts::TYPES[$type]['cap'])) {
            json_response(['ok' => false, 'message' => 'Không có quyền.'], 403);
        }
        $title = (string) ($_POST['title'] ?? '');
        $content = (string) ($_POST['content'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $uid = (int) current_user()['id'];
        if ($id) {
            $post = Posts::find($id, $type);
            if (!$post || !Posts::canEdit($post)) {
                json_response(['ok' => false, 'message' => 'Không tìm thấy hoặc không có quyền sửa.'], 403);
            }
            // Lọc ngay khi ghi (như Posts::save): bản tự lưu được khôi phục/hiện lại sau này, có khi bởi người có quyền cao hơn.
            if (!can('unfiltered_html')) {
                $content = \LQ\Core\Html::sanitize($content);
            }
            $at = Revisions::autosave($id, $uid, $title, $content);
            json_response(['ok' => true, 'id' => $id, 'saved_at' => format_date($at, 'H:i:s')]);
        }
        if (trim($title) === '' && trim(strip_tags($content)) === '') {
            json_response(['ok' => true, 'skipped' => true]);
        }
        // Trang: gửi kèm trang cha đang chọn, nếu không slug bị tính như trang cấp gốc (tránh trùng bài/chuyên mục ở gốc → "-2" oan).
        $extra = $type === 'page' ? ['parent_id' => (int) ($_POST['parent_id'] ?? 0)] : [];
        [$errors, $newId] = Posts::save(['title' => $title, 'content' => $content, 'action' => 'draft'] + $extra, null, null, $type);
        if ($errors) {
            json_response(['ok' => false, 'message' => reset($errors)], 422);
        }
        $post = Posts::find($newId, $type);
        Revisions::heartbeat($newId, $uid);
        json_response([
            'ok' => true,
            'id' => $newId,
            'created' => true,
            'slug' => $post['slug'],
            'loaded_at' => $post['updated_at'],
            'edit_url' => self::base($type) . "/$newId/edit",
            'saved_at' => format_date(now_utc(), 'H:i:s'),
        ]);
    }

    /** Nhịp giữ khoá mỗi 30 giây; take=1 để giành quyền sửa. */
    public static function lock(array $p): void
    {
        $post = self::editable($p);
        $other = Revisions::heartbeat((int) $post['id'], (int) current_user()['id'], !empty($_POST['take']));
        json_response(['ok' => true, 'locked_by' => $other]);
    }

    public static function unlock(array $p): void
    {
        $post = self::editable($p);
        Revisions::release((int) $post['id'], (int) current_user()['id']);
        json_response(['ok' => true]);
    }

    /** Trang lịch sử phiên bản: chọn một bản để so với bản hiện tại. */
    public static function revisions(array $p): void
    {
        $post = self::editable($p);
        $list = Revisions::forPost((int) $post['id']);
        $revId = (int) input('rev', 0);
        // Mặc định so bản đã lưu ngay trước bản hiện tại.
        if (!$revId) {
            foreach ($list as $r) {
                if ($r['kind'] === 'revision' && !($r['title'] === $post['title'] && self::sameContent($r['id'], $post))) {
                    $revId = (int) $r['id'];
                    break;
                }
            }
        }
        $rev = $revId ? Revisions::find($revId, (int) $post['id']) : null;
        View::render('admin/posts/revisions', [
            'title' => 'Lịch sử phiên bản',
            'subject' => $post['title'] ?: '(Không tiêu đề)',
            'post' => $post,
            'type' => $post['type'],
            'base' => self::base($post['type']),
            'list' => $list,
            'rev' => $rev,
            'titleDiff' => $rev ? \LQ\Core\Diff::words($rev['title'], $post['title']) : null,
            'diff' => $rev ? \LQ\Core\Diff::lines(\LQ\Core\Diff::htmlLines($rev['content']), \LQ\Core\Diff::htmlLines($post['content'])) : [],
        ]);
    }

    public static function restoreRevision(array $p): void
    {
        $post = self::editable($p);
        $rev = Revisions::find((int) $p['rid'], (int) $post['id']) ?? Kernel::notFound();
        Revisions::restore($post, $rev, (int) current_user()['id']);
        flash('success', 'Đã khôi phục phiên bản lúc ' . format_date($rev['created_at']) . '.');
        redirect(self::base($post['type']) . '/' . $post['id'] . '/edit');
    }

    private static function sameContent(int $revId, array $post): bool
    {
        return (string) db()->value('SELECT content FROM {post_revisions} WHERE id = ?', [$revId]) === $post['content'];
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
