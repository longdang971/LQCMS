<?php
declare(strict_types=1);

namespace LQ\Core;

use ZipArchive;

/** Cài theme/plugin từ file .zip upload. */
final class Package
{
    /**
     * Giải nén zip vào $targetRoot/<thư mục gốc trong zip>.
     * $validate nhận đường dẫn thư mục đã giải nén tạm, trả lỗi (string) hoặc null.
     * @return array{0: ?string, 1: ?string} [slug, lỗi]
     */
    public static function installZip(array $file, string $targetRoot, callable $validate): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return [null, 'Tải tệp lên thất bại.'];
        }
        if (!class_exists(ZipArchive::class)) {
            return [null, 'Máy chủ chưa bật extension zip của PHP.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return [null, 'Tệp không phải định dạng .zip hợp lệ.'];
        }

        // Mọi file phải nằm trong đúng một thư mục gốc, không có đường dẫn thoát ra ngoài (zip slip).
        $root = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }
            if ($name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, ':')) {
                $zip->close();
                return [null, 'Tệp zip chứa đường dẫn không an toàn.'];
            }
            $first = explode('/', $name, 2)[0];
            if (!str_contains($name, '/')) {
                $zip->close();
                return [null, 'Tệp zip phải chứa đúng một thư mục gốc (vd. ten-theme/...).'];
            }
            $root ??= $first;
            if ($first !== $root) {
                $zip->close();
                return [null, 'Tệp zip phải chứa đúng một thư mục gốc.'];
            }
        }
        if ($root === null || !preg_match('/^[a-z0-9_-]+$/', $root)) {
            $zip->close();
            return [null, 'Tên thư mục gốc trong zip không hợp lệ (chỉ chữ thường không dấu, số, "-", "_").'];
        }
        $slug = $root;
        if (is_dir("$targetRoot/$slug")) {
            $zip->close();
            return [null, "Đã tồn tại \"$slug\". Hãy xoá bản cũ trước khi cài lại."];
        }

        $tmp = sys_get_temp_dir() . '/lq-pkg-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        $ok = $zip->extractTo($tmp);
        $zip->close();
        if (!$ok) {
            rrmdir($tmp);
            return [null, 'Giải nén thất bại.'];
        }
        rrmdir("$tmp/__MACOSX");

        $error = $validate("$tmp/$root");
        if ($error === null && !@rename("$tmp/$root", "$targetRoot/$slug")) {
            $error = 'Không chép được vào thư mục đích (kiểm tra quyền ghi).';
        }
        rrmdir($tmp);
        return $error ? [null, $error] : [$slug, null];
    }
}
