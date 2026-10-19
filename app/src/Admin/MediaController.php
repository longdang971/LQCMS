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
        json_response(['ok' => true, 'item' => $m + ['usage' => Media::usage($m), 'can_edit' => Media::canEdit($m)]]);
    }

    /** Tải lên một ảnh (JS gửi từng ảnh để có tiến trình riêng). */
    public static function upload(): void
    {
        if (!can('upload_files')) {
            json_response(['ok' => false, 'message' => 'Bạn không có quyền tải tệp lên.'], 403);
        }
        [$m, $err] = Media::upload($_FILES['file'] ?? []);
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
        foreach ($ids as $id) {
            $m = Media::find($id);
            if ($m && Media::canEdit($m) && Media::delete($id)) {
                $done++;
            } else {
                $skipped++;
            }
        }
        json_response(['ok' => true, 'deleted' => $done, 'skipped' => $skipped]);
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
