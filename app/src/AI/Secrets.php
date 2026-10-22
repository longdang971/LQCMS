<?php
declare(strict_types=1);

namespace LQ\AI;

/**
 * Mã hoá API key trước khi lưu vào bảng options (sodium secretbox). Khoá nằm ở storage/secret.php, tách khỏi database:
 * lộ bản sao DB chưa đủ để lấy được key. Tệp là PHP mở đầu bằng exit nên lỡ máy chủ cho gọi thẳng qua web cũng không in ra khoá.
 * Mất tệp khoá thì phải nhập lại key.
 */
final class Secrets
{
    private static function key(): string
    {
        $file = LQ_ROOT . '/storage/secret.php';
        if (!is_file($file)) {
            @mkdir(dirname($file), 0700, true);
            // Ghi ra tệp tạm rồi link() (nguyên tử, thất bại nếu đã có): hai request cùng tạo khoá lần đầu không đè khoá
            // của nhau (đè thì key vừa mã hoá bằng khoá kia không giải mã được nữa), và không ai đọc phải tệp đang ghi dở.
            $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            file_put_contents($tmp, "<?php exit; ?>\n" . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . "\n");
            @chmod($tmp, 0600);
            @link($tmp, $file) || is_file($file) || @rename($tmp, $file);
            @unlink($tmp);
        }
        $lines = preg_split('/\r\n|\r|\n/', trim((string) file_get_contents($file)));
        return base64_decode((string) end($lines));
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return '';
        }
        $raw = (string) base64_decode(substr($stored, 3));
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        } catch (\SodiumException) {
            // Giá trị cụt (nonce thiếu byte) hoặc tệp khoá hỏng: coi như chưa có key thay vì làm sập mọi trang gọi AI::enabled().
            return '';
        }
        return $plain === false ? '' : $plain;
    }
}
