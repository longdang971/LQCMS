<?php
declare(strict_types=1);

namespace LQ\Core;

use ZipArchive;

/**
 * Cài theme/plugin từ file .zip upload, qua 2 bước:
 *   1. stage(): giải nén vào storage/packages/<token>/ (storage/ bị chặn truy cập từ web — xem nginx.conf.example, storage/.htaccess), kiểm tra cấu trúc
 *      (lỗi thì từ chối luôn) rồi quét bằng PackageScanner và lưu kết quả vào manifest.json.
 *   2. Người cài xem kết quả ở trang xem lại → commit() chuyển vào thư mục đích, hoặc discard() xoá bỏ.
 * Bản đã giải nén bị bỏ quá 1 giờ được dọn ở lần stage() kế tiếp.
 */
final class Package
{
    private const TTL = 3600;
    /** Giới hạn sau giải nén — chặn zip bomb (zip 30 MB toàn số 0 bung ra hàng chục GB, đầy ổ đĩa). */
    private const MAX_FILES = 5000;
    private const MAX_BYTES = 256 * 1024 * 1024;

    public static function stagingDir(): string
    {
        return LQ_ROOT . '/storage/packages';
    }

    /**
     * @param string $kind 'plugin' | 'theme'
     * @param callable $validate nhận thư mục đã giải nén, trả lỗi (string) hoặc null
     * @param callable $describe nhận thư mục đã giải nén, trả ['name' => …, 'version' => …] để hiện ở trang xem lại
     * @return array{0: ?string, 1: ?string} [token, lỗi]
     */
    public static function stage(array $file, string $kind, string $targetRoot, callable $validate, callable $describe): array
    {
        if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            // Lỗi hay gặp nhất (zip theme có ảnh > 2 MB mặc định của PHP): nói rõ thay vì "thất bại" chung chung.
            return [null, 'Tệp lớn hơn giới hạn tải lên của máy chủ (upload_max_filesize = ' . ini_get('upload_max_filesize') . ').'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return [null, 'Tải tệp lên thất bại.'];
        }
        if (!class_exists(ZipArchive::class)) {
            return [null, 'Máy chủ chưa bật extension zip của PHP.'];
        }
        self::cleanup();
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            return [null, 'Tệp không phải định dạng .zip hợp lệ.'];
        }

        // Mọi file phải nằm trong đúng một thư mục gốc, không có đường dẫn thoát ra ngoài (zip slip).
        $root = null;
        $symlinks = [];
        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            // Kiểm tra trước khi bỏ qua __MACOSX/.DS_Store: "__MACOSX/../ten-goc/x.php" vẫn được giải nén vào thư mục gốc.
            if ($name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, ':') || str_contains($name, "\0")) {
                $zip->close();
                return [null, 'Tệp zip chứa đường dẫn không an toàn.'];
            }
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }
            $first = explode('/', $name, 2)[0];
            if (!str_contains($name, '/')) {
                $zip->close();
                return [null, 'Tệp zip phải chứa đúng một thư mục gốc (vd. ten-theme/...).'];
            }
            if (++$files > self::MAX_FILES) {
                $zip->close();
                return [null, 'Gói có quá nhiều tệp (tối đa ' . self::MAX_FILES . ').'];
            }
            $root ??= $first;
            if ($first !== $root) {
                $zip->close();
                return [null, 'Tệp zip phải chứa đúng một thư mục gốc.'];
            }
            // Symlink trong zip (Unix): bit loại tệp 0120000 ở 16 bit cao của thuộc tính ngoài.
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                $symlinks[] = substr($name, strlen($root) + 1);
            }
        }
        if ($root === null || !preg_match('/^[a-z0-9_-]+$/', $root)) {
            $zip->close();
            return [null, 'Tên thư mục gốc trong zip không hợp lệ (chỉ chữ thường không dấu, số, "-", "_").'];
        }
        if (is_dir("$targetRoot/$root")) {
            $zip->close();
            return [null, "Đã tồn tại \"$root\". Hãy xoá bản cũ trước khi cài lại."];
        }

        $token = bin2hex(random_bytes(12));
        $dir = self::stagingDir() . "/$token";
        if (!@mkdir($dir, 0700, true)) {
            $zip->close();
            return [null, 'Không tạo được thư mục tạm trong storage/ (kiểm tra quyền ghi).'];
        }
        $ok = self::extract($zip, $dir);
        $zip->close();
        rrmdir("$dir/__MACOSX");
        if ($ok !== true) {
            rrmdir($dir);
            return [null, $ok ?: 'Giải nén thất bại.'];
        }
        if ($error = $validate("$dir/$root")) {
            rrmdir($dir);
            return [null, $error];
        }

        $report = PackageScanner::scan("$dir/$root", $symlinks);
        $info = $describe("$dir/$root");
        file_put_contents("$dir/manifest.json", json_encode([
            'kind' => $kind,
            'slug' => $root,
            'name' => (string) ($info['name'] ?? $root),
            'version' => (string) ($info['version'] ?? ''),
            'file_name' => (string) ($file['name'] ?? ''),
            'zip_size' => (int) ($file['size'] ?? 0),
            'created' => time(),
            'user_id' => (int) ($_SESSION['uid'] ?? 0),
        ] + $report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); // tên tệp không phải UTF-8 (zip từ Windows) làm json_encode trả false → manifest rỗng
        return [$token, null];
    }

    /**
     * Giải nén từng tệp, đếm số byte THỰC ghi ra (kích thước khai trong zip có thể bị sửa cho nhỏ, extractTo() vẫn bung hết).
     * Bỏ qua __MACOSX/ và .DS_Store. Không kiểm tra tên tệp — nơi gọi phải chặn zip slip trước (như stage(), Updater).
     * @return true|string true hoặc thông báo lỗi ('' = lỗi chung)
     */
    public static function extract(ZipArchive $zip, string $dir): true|string
    {
        $budget = self::MAX_BYTES;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }
            $path = "$dir/" . rtrim($name, '/');
            if (str_ends_with($name, '/')) {
                if (!is_dir($path) && !@mkdir($path, 0755, true)) {
                    return '';
                }
                continue;
            }
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
                return '';
            }
            $in = $zip->getStreamIndex($i);
            $out = $in ? @fopen($path, 'wb') : false;
            if (!$in || !$out) {
                $in && fclose($in);
                return '';
            }
            $n = stream_copy_to_stream($in, $out, $budget + 1);
            fclose($in);
            fclose($out);
            if ($n === false) {
                return '';
            }
            if (($budget -= $n) < 0) {
                return 'Gói giải nén ra quá lớn (tối đa ' . (self::MAX_BYTES >> 20) . ' MB).';
            }
        }
        return true;
    }

    /** Gói đang chờ xác nhận (đúng loại), hoặc null nếu token sai/hết hạn. */
    public static function staged(string $token, string $kind): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token) || !is_file($f = self::stagingDir() . "/$token/manifest.json")) {
            return null;
        }
        $m = json_decode((string) file_get_contents($f), true);
        if (!is_array($m) || ($m['kind'] ?? '') !== $kind || time() - (int) $m['created'] > self::TTL) {
            return null;
        }
        return $m + ['token' => $token];
    }

    /** @return array{0: ?string, 1: ?string} [slug, lỗi] */
    public static function commit(string $token, string $kind, string $targetRoot): array
    {
        $m = self::staged($token, $kind);
        if (!$m) {
            return [null, 'Gói cài đặt không còn (đã cài, đã huỷ hoặc quá 1 giờ). Hãy tải lên lại.'];
        }
        $slug = $m['slug'];
        if (is_dir("$targetRoot/$slug")) {
            self::discard($token);
            return [null, "Đã tồn tại \"$slug\". Hãy xoá bản cũ trước khi cài lại."];
        }
        if (!@rename(self::stagingDir() . "/$token/$slug", "$targetRoot/$slug")) {
            return [null, 'Không chép được vào thư mục đích (kiểm tra quyền ghi).'];
        }
        self::discard($token);
        return [$slug, null];
    }

    public static function discard(string $token): void
    {
        if (preg_match('/^[a-f0-9]{24}$/', $token)) {
            rrmdir(self::stagingDir() . "/$token");
        }
    }

    /** Xoá các gói giải nén tạm bị bỏ dở quá TTL. */
    private static function cleanup(): void
    {
        foreach (glob(self::stagingDir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (time() - (int) @filemtime($d) > self::TTL) {
                rrmdir($d);
            }
        }
    }
}
