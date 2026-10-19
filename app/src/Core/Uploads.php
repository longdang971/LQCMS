<?php
declare(strict_types=1);

namespace LQ\Core;

use LQ\Content\Media;

/** Giữ API cũ: mọi ảnh upload giờ đều đi qua thư viện Media (có bản ghi + các cỡ ảnh). */
final class Uploads
{
    /** @return array{0: ?string, 1: ?string} [url, lỗi] */
    public static function storeImage(array $file): array
    {
        [$media, $err] = Media::upload($file);
        return [$media['url'] ?? null, $err];
    }
}
