<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Content\Media;
use LQ\Core\View;

final class MediaController
{
    private const PER_PAGE = 40;

    public static function index(): void
    {
        Kernel::require('upload_files');
        View::render('admin/media/index', [
            'title' => 'Media',
            'months' => Media::months(),
            'total' => (int) db()->value('SELECT COUNT(*) FROM {media}'),
            'totalSize' => Media::bytes((int) db()->value('SELECT COALESCE(SUM(size), 0) FROM {media}')),
            'maxMb' => min((int) option('upload_max_mb', 8), self::serverLimitMb()),
        ]);
    }

    /** JSON danh sách cho lưới media và hộp chọn ảnh. */
    public static function list(): void
    {
        Kernel::require('upload_files');
        $page = max(1, (int) input('page', 1));
        $r = Media::paginate(input_str('q'), input_str('month'), $page, self::PER_PAGE);
        json_response([
            'ok' => true,
            'items' => $r['items'],
            'total' => $r['total'],
            'page' => $page,
            'more' => $page * self::PER_PAGE < $r['total'],
        ]);
    }

    public static function show(array $p): void
    {
        Kernel::require('upload_files');
        $m = Media::find((int) $p['id']) ?? json_response(['ok' => false, 'message' => 'Không tìm thấy ảnh.'], 404);
        // Bài chưa đăng mà người xem không sửa được (nháp của người khác): vẫn đếm (để cảnh báo khi xoá ảnh) nhưng không lộ tiêu đề.
        $usage = array_map(function (array $u): array {
            $editable = \LQ\Content\Posts::canEdit($u);
            $hidden = !$editable && $u['status'] !== 'published';
            return ['id' => $hidden ? 0 : (int) $u['id'], 'title' => $hidden ? '' : $u['title'], 'type' => $u['type'], 'status' => $hidden ? '' : $u['status'],
                'editable' => $editable, 'hidden' => $hidden,
                'edit_url' => $hidden ? '' : \LQ\Content\Posts::editUrl($u['type'], (int) $u['id']),
                'kind' => lq_ucfirst(\LQ\Content\Posts::type($u['type'])['singular'] ?? $u['type'])];
        }, Media::usage($m));
        json_response(['ok' => true, 'item' => $m + ['usage' => $usage, 'can_edit' => Media::canEdit($m)]]);
    }

    /** Tải lên một ảnh (JS gửi từng ảnh để có tiến trình riêng). */
    public static function upload(): void
    {
        if (!can('upload_files')) {
            json_response(['ok' => false, 'message' => 'Bạn không có quyền tải tệp lên.'], 403);
        }
        [$m, $err] = Media::upload($_FILES['file'] ?? [], (string) ($_POST['name'] ?? ''));
        $err ? json_response(['ok' => false, 'message' => $err], 422) : json_response(['ok' => true, 'item' => $m]);
    }

    public static function update(array $p): void
    {
        Kernel::require('upload_files');
        $m = Media::find((int) $p['id']) ?? json_response(['ok' => false, 'message' => 'Không tìm thấy ảnh.'], 404);
        if (!Media::canEdit($m)) {
            json_response(['ok' => false, 'message' => 'Bạn chỉ sửa được ảnh do mình tải lên.'], 403);
        }
        Media::update($m['id'], $_POST);
        json_response(['ok' => true, 'item' => Media::find($m['id'])]);
    }

    public static function destroy(array $p): void
    {
        Kernel::require('upload_files');
        $m = Media::find((int) $p['id']) ?? json_response(['ok' => false, 'message' => 'Không tìm thấy ảnh.'], 404);
        if (!Media::canEdit($m)) {
            json_response(['ok' => false, 'message' => 'Bạn chỉ xoá được ảnh do mình tải lên.'], 403);
        }
        Media::delete($m['id']);
        json_response(['ok' => true]);
    }

    public static function bulkDelete(): void
    {
        Kernel::require('upload_files');
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        $done = $skipped = 0;
        $deletedIds = [];
        foreach ($ids as $id) {
            $m = Media::find($id);
            if ($m && Media::canEdit($m) && Media::delete($id)) {
                $done++;
                $deletedIds[] = $id;
            } else {
                $skipped++;
            }
        }
        // ids: ảnh đã xoá thật — JS chỉ gỡ các ô này khỏi lưới (ảnh bị bỏ qua vì không có quyền vẫn còn).
        json_response(['ok' => true, 'deleted' => $done, 'skipped' => $skipped, 'ids' => $deletedIds]);
    }

    /** Dọn dẹp, bước 1: danh sách ảnh không dùng đến để người dùng xem trước. */
    public static function unused(): void
    {
        Kernel::require('manage_options');
        $items = Media::unused();
        json_response(['ok' => true, 'items' => $items, 'total' => array_sum(array_column($items, 'size'))]);
    }

    /** Dọn dẹp, bước 2: xoá các ảnh đã xem trước — kiểm tra lại từng ảnh vẫn chưa được dùng (có thể vừa được chèn vào bài). */
    public static function cleanup(): void
    {
        Kernel::require('manage_options');
        $want = array_flip(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $done = 0;
        $deletedIds = [];
        foreach (Media::unused() as $m) {
            if (isset($want[$m['id']]) && Media::delete($m['id'])) {
                $done++;
                $deletedIds[] = $m['id'];
            }
        }
        // ids: ảnh đã xoá thật — ảnh vừa được dùng trở lại thì giữ, JS không được gỡ khỏi lưới.
        json_response(['ok' => true, 'deleted' => $done, 'skipped' => count($want) - $done, 'ids' => $deletedIds]);
    }

    private static function serverLimitMb(): int
    {
        $toMb = function (string $v): int {
            $n = (int) $v;
            return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1024, 'k' => max(1, intdiv($n, 1024)), 'm' => $n, default => max(1, intdiv($n, 1048576)) };
        };
        return max(1, min($toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size'))));
    }
}
