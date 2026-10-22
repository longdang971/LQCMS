<?php
declare(strict_types=1);

namespace LQ\AI;

/**
 * Thư viện của từng nhà cung cấp AI, cài khi người dùng bấm "Cài đặt" ở Cài đặt › AI — LQCMS không đóng gói sẵn.
 * Mỗi nhà cung cấp một thư mục storage/ai/<id>/ (vendor riêng, chỉ nạp autoload của nhà cung cấp đang dùng nên
 * thư viện trùng nhau như guzzle không đụng nhau). Cài bằng composer.phar tải về storage/ai/ (kiểm tra SHA-256),
 * chạy qua proc_open — máy chủ cần bật proc_open và có kết nối ra Internet.
 */
final class Connectors
{
    public const LIST = [
        'anthropic' => [
            'label' => 'Claude',
            'vendor' => 'Anthropic',
            'packages' => ['anthropic-ai/sdk'],
            'main' => 'anthropic-ai/sdk',
            'note' => 'SDK chính thức của Anthropic.',
            'key_url' => 'https://platform.claude.com/settings/keys',
        ],
        'openai' => [
            'label' => 'ChatGPT',
            'vendor' => 'OpenAI',
            'packages' => ['openai-php/client', 'guzzlehttp/guzzle'],
            'main' => 'openai-php/client',
            'note' => 'Thư viện cộng đồng openai-php/client (OpenAI chưa có SDK PHP chính thức).',
            'key_url' => 'https://platform.openai.com/api-keys',
        ],
        'gemini' => [
            'label' => 'Gemini',
            'vendor' => 'Google',
            'packages' => ['google-gemini-php/client', 'guzzlehttp/guzzle'],
            'main' => 'google-gemini-php/client',
            'note' => 'Thư viện cộng đồng google-gemini-php/client (Google chưa có SDK PHP chính thức).',
            'key_url' => 'https://aistudio.google.com/apikey',
        ],
    ];

    /** SVG logo nhà cung cấp (Lobe Icons, MIT) để nhúng thẳng vào trang — OpenAI dùng currentColor nên tự đổi màu theo sáng/tối. */
    public static function logo(string $id): string
    {
        $f = LQ_ROOT . "/assets/admin/ai/$id.svg";
        return isset(self::LIST[$id]) && is_file($f) ? (string) file_get_contents($f) : '';
    }

    public static function root(): string
    {
        return LQ_ROOT . '/storage/ai';
    }

    public static function dir(string $id): string
    {
        return self::root() . "/$id";
    }

    /** Phiên bản thư viện chính đã cài, hoặc null nếu chưa cài. */
    public static function version(string $id): ?string
    {
        $file = self::dir($id) . '/vendor/composer/installed.json';
        if (!isset(self::LIST[$id]) || !is_file($file) || !is_file(self::dir($id) . '/vendor/autoload.php')) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        foreach ($data['packages'] ?? $data ?? [] as $p) {
            if (($p['name'] ?? '') === self::LIST[$id]['main']) {
                return ltrim((string) ($p['version'] ?? '?'), 'v');
            }
        }
        return null;
    }

    public static function installed(string $id): bool
    {
        return self::version($id) !== null;
    }

    /** Nạp autoload của nhà cung cấp (mỗi request chỉ nạp một). */
    public static function load(string $id): void
    {
        static $loaded = [];
        if (!isset($loaded[$id]) && self::installed($id)) {
            require_once self::dir($id) . '/vendor/autoload.php';
            $loaded[$id] = true;
        }
    }

    /** Lệnh tương đương để cài tay khi máy chủ không chạy được composer từ PHP. */
    public static function manualCommand(string $id): string
    {
        return 'cd ' . self::dir($id) . ' && composer require ' . implode(' ', self::LIST[$id]['packages']);
    }

    /** Lý do không tự cài được (thiếu hàm, không ghi được thư mục…), hoặc null nếu cài được. */
    public static function cannotInstall(): ?string
    {
        if (!function_exists('proc_open')) {
            return 'Máy chủ đã tắt hàm proc_open của PHP nên không chạy được trình cài đặt.';
        }
        $bin = self::phpBinary();
        if ($bin === null) {
            return 'Không tìm thấy chương trình php (CLI) trên máy chủ.';
        }
        @mkdir(self::root(), 0755, true);
        return is_writable(self::root()) ? null : 'Thư mục storage/ai không ghi được (kiểm tra quyền).';
    }

    /** @return array{0: bool, 1: string} [thành công, nhật ký] */
    public static function install(string $id): array
    {
        if (!isset(self::LIST[$id])) {
            return [false, 'Nhà cung cấp không hợp lệ.'];
        }
        if ($why = self::cannotInstall()) {
            return [false, $why];
        }
        @set_time_limit(600);
        ignore_user_abort(true);
        [$phar, $err] = self::composerPhar();
        if ($err) {
            return [false, $err];
        }
        $dir = self::dir($id);
        @mkdir($dir, 0755, true);
        // composer.json dựng sẵn: php-http/discovery là plugin của composer, phải cho phép trước, không thì
        // chế độ không tương tác sẽ dừng lại hỏi.
        file_put_contents("$dir/composer.json", json_encode([
            'name' => "lqcms/ai-$id",
            'description' => 'Thư viện AI do LQCMS cài — xoá thư mục này tương đương bấm Gỡ.',
            'require' => new \stdClass(),
            'config' => ['allow-plugins' => ['php-http/discovery' => true], 'optimize-autoloader' => true],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        [$code, $log] = self::run([self::phpBinary(), $phar, 'require', ...self::LIST[$id]['packages'],
            '--no-interaction', '--no-progress', '--no-ansi', '--update-no-dev', '--working-dir=' . $dir]);
        if ($code !== 0 || !self::installed($id)) {
            return [false, trim($log) ?: "Composer thoát với mã $code."];
        }
        return [true, trim($log)];
    }

    public static function uninstall(string $id): void
    {
        if (isset(self::LIST[$id])) {
            rrmdir(self::dir($id));
        }
    }

    private static function phpBinary(): ?string
    {
        // Dưới php-fpm, PHP_BINARY là php-fpm (không chạy được composer) — tìm bản CLI cạnh nó.
        foreach ([PHP_BINARY, PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'] as $bin) {
            if ($bin && is_file($bin) && is_executable($bin) && !str_contains(basename($bin), 'fpm')) {
                return $bin;
            }
        }
        return null;
    }

    /** @return array{0: ?string, 1: ?string} [đường dẫn composer.phar, lỗi] */
    private static function composerPhar(): array
    {
        $phar = self::root() . '/composer.phar';
        if (is_file($phar) && time() - filemtime($phar) < 30 * 86400) {
            return [$phar, null];
        }
        $bin = self::fetch('https://getcomposer.org/download/latest-stable/composer.phar');
        $sum = trim((string) self::fetch('https://getcomposer.org/download/latest-stable/composer.phar.sha256'));
        if ($bin === null || $sum === '') {
            return [null, 'Không tải được Composer từ getcomposer.org (máy chủ có kết nối Internet không?).'];
        }
        if (!hash_equals(strtolower(substr($sum, 0, 64)), hash('sha256', $bin))) {
            return [null, 'Tệp Composer tải về không khớp mã kiểm tra SHA-256 — đã huỷ để an toàn.'];
        }
        file_put_contents($phar, $bin);
        return [$phar, null];
    }

    private static function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'LQCMS/' . LQ_VERSION]);
        $body = curl_exec($ch);
        $ok = $body !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        return $ok ? (string) $body : null;
    }

    /** @return array{0: int, 1: string} [mã thoát, stdout+stderr] */
    private static function run(array $cmd): array
    {
        $env = [
            'COMPOSER_HOME' => self::root() . '/.composer',
            'HOME' => self::root(),
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'COMPOSER_NO_INTERACTION' => '1',
        ];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, self::root(), $env);
        if (!is_resource($p)) {
            return [1, 'Không chạy được Composer.'];
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($p), (string) $out];
    }
}
