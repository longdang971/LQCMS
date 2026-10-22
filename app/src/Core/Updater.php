<?php
declare(strict_types=1);

namespace LQ\Core;

use Throwable;
use ZipArchive;

/**
 * Kiểm tra & cập nhật lõi LQCMS từ GitHub Releases.
 *
 * Mỗi bản phát hành đính kèm "lqcms-<phiên bản>-update.zip": chỉ mã lõi (PATHS), không có database/ (site đã cài nâng cấp
 * DB bằng Migrations), docs/, theme/plugin, storage/ hay config. Cập nhật = tải zip → kiểm tra (đúng phiên bản, PHP không
 * lỗi cú pháp) → ĐỔI TÊN bản lõi đang chạy vào storage/updates/backup-… (cùng ổ đĩa nên gần như tức thì) rồi đổi tên bản
 * mới vào chỗ; lỗi giữa chừng thì trả lại như cũ. Bản sao lưu dùng cho "Khôi phục bản trước" (rollback()).
 */
final class Updater
{
    public const REPO = 'longdang971/LQCMS';
    /** Đường dẫn lõi được thay — zip cập nhật chứa đúng những thứ này (thiếu cái nào thì giữ bản đang có). */
    public const PATHS = ['app', 'assets', 'index.php', '.htaccess', 'README.md', 'content/.htaccess'];
    private const REQUIRED = ['app/bootstrap.php', 'app/src/Core/App.php', 'index.php'];
    private const CHECK_TTL = 43200; // 12 giờ
    private const MAX_ZIP = 50 * 1024 * 1024;
    private const KEEP_BACKUPS = 2;

    public static function repo(): string
    {
        return (string) App::config('update_repo', self::REPO);
    }

    /** Cấu hình 'disallow_updates' => true trong config.php: chỉ kiểm tra, không cho cập nhật từ trang quản trị. */
    public static function allowed(): bool
    {
        return !App::config('disallow_updates', false);
    }

    public static function dir(): string
    {
        return LQ_ROOT . '/storage/updates';
    }

    /** Kết quả lần kiểm tra gần nhất: {checked_at, latest: {version, tag, name, notes, url, published_at, asset_url, asset_size}|null, error}. */
    public static function status(): array
    {
        $s = option('update_check', []);
        return is_array($s) ? $s : [];
    }

    /** Bản mới hơn bản đang chạy (theo lần kiểm tra gần nhất), hoặc null. */
    public static function available(): ?array
    {
        $l = self::status()['latest'] ?? null;
        return is_array($l) && version_compare((string) $l['version'], LQ_VERSION, '>') ? $l : null;
    }

    /**
     * Hỏi GitHub các bản phát hành (dùng kết quả đã lưu nếu chưa quá 12 giờ, trừ khi $force). Lưu bản mới nhất ('latest')
     * và vài bản gần nhất ('releases', phiên bản giảm dần) để trang Cập nhật hiện ghi chú của bản đang dùng + lịch sử.
     */
    public static function check(bool $force = false): array
    {
        $s = self::status();
        if (!$force && ($s['checked_at'] ?? 0) > time() - self::CHECK_TTL) {
            return $s;
        }
        $api = rtrim((string) App::config('update_api', 'https://api.github.com'), '/');
        [$code, $body, $err] = self::http("$api/repos/" . self::repo() . '/releases?per_page=20');
        $s['checked_at'] = time();
        $s['error'] = null;
        if ($code !== 200 || !is_array($list = json_decode((string) $body, true))) {
            // Giữ kết quả cũ, chỉ ghi lỗi: GitHub giới hạn 60 lượt/giờ mỗi IP khi không đăng nhập.
            $s['error'] = $err ?: match ($code) {
                403, 429 => 'GitHub tạm chặn vì gọi quá nhiều lần, thử lại sau ít phút.',
                404 => 'Không tìm thấy repo ' . self::repo() . ' trên GitHub.',
                default => "GitHub trả về mã $code.",
            };
        } else {
            $releases = [];
            foreach ($list as $r) {
                // Bản nháp, pre-release và tag sai dạng không tính là bản phát hành.
                $version = is_array($r) ? ltrim((string) ($r['tag_name'] ?? ''), 'vV') : '';
                if (!empty($r['draft']) || !empty($r['prerelease']) || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
                    continue;
                }
                $asset = null;
                foreach ((array) ($r['assets'] ?? []) as $a) {
                    if (is_array($a) && ($a['name'] ?? '') === "lqcms-$version-update.zip") {
                        $asset = $a;
                        break;
                    }
                }
                $releases[] = [
                    'version' => $version,
                    'tag' => (string) $r['tag_name'],
                    'name' => mb_substr((string) ($r['name'] ?? '') ?: $version, 0, 200),
                    'notes' => mb_substr((string) ($r['body'] ?? ''), 0, 20000),
                    'url' => (string) ($r['html_url'] ?? ''),
                    'published_at' => (string) ($r['published_at'] ?? ''),
                    'asset_url' => $asset ? (string) $asset['browser_download_url'] : null,
                    'asset_size' => $asset ? (int) $asset['size'] : 0,
                ];
            }
            // GitHub xếp theo ngày tạo, không theo số phiên bản (vá lỗi cho nhánh cũ có thể ra sau): tự xếp lại.
            usort($releases, fn ($a, $b) => version_compare($b['version'], $a['version']));
            $s['latest'] = $releases[0] ?? null;
            $s['releases'] = array_slice($releases, 0, 8);
        }
        update_option('update_check', $s, false);
        return $s;
    }

    /** Thông tin bản phát hành của phiên bản đang chạy (ghi chú, ngày phát hành), hoặc null nếu không có trên GitHub. */
    public static function current(): ?array
    {
        foreach ((array) (self::status()['releases'] ?? []) as $r) {
            if (is_array($r) && ($r['version'] ?? '') === LQ_VERSION) {
                return $r;
            }
        }
        return null;
    }

    /** Tác vụ định kỳ (Cron): kiểm tra 12 giờ một lần để menu báo có bản mới. */
    public static function cronCheck(): string
    {
        $s = self::check(true);
        if ($s['error']) {
            throw new \RuntimeException($s['error']);
        }
        return ($l = self::available()) ? "Có bản {$l['version']}" : 'Đang dùng bản mới nhất';
    }

    /**
     * Danh sách điều kiện để cập nhật từ trang quản trị (hiện dạng checklist).
     * @return list<array{label: string, ok: bool, hint: string, problem: string}>
     */
    public static function readiness(): array
    {
        // Đổi tên một mục cần quyền ghi thư mục chứa nó; đổi tên THƯ MỤC sang chỗ khác còn cần ghi được chính nó (cập nhật "..").
        $need = [LQ_ROOT, LQ_ROOT . '/content', LQ_ROOT . '/storage'];
        foreach (self::PATHS as $p) {
            if (is_dir(LQ_ROOT . "/$p")) {
                $need[] = LQ_ROOT . "/$p";
            }
        }
        $denied = array_map(fn ($d) => $d === LQ_ROOT ? '/' : substr($d, strlen(LQ_ROOT)), array_filter($need, fn ($d) => is_dir($d) && !is_writable($d)));
        $net = function_exists('curl_init') || (bool) ini_get('allow_url_fopen');
        return [
            ['label' => 'Cho phép cập nhật từ trang quản trị', 'ok' => self::allowed(),
                'hint' => self::allowed() ? 'Tắt bằng disallow_updates trong tệp cấu hình' : 'Đang tắt bằng disallow_updates',
                'problem' => 'Cập nhật từ trang quản trị đang bị tắt (disallow_updates trong tệp cấu hình).'],
            ['label' => 'Tải được tệp từ GitHub', 'ok' => $net,
                'hint' => function_exists('curl_init') ? 'Qua cURL' : ($net ? 'Qua allow_url_fopen' : 'Thiếu cURL và allow_url_fopen đang tắt'),
                'problem' => 'Máy chủ không tải được tệp từ Internet (thiếu curl và allow_url_fopen đang tắt).'],
            ['label' => 'Giải nén gói zip', 'ok' => class_exists(ZipArchive::class),
                'hint' => class_exists(ZipArchive::class) ? 'Extension zip đã bật' : 'Chưa bật extension zip của PHP',
                'problem' => 'Máy chủ chưa bật extension zip của PHP.'],
            ['label' => 'Quyền ghi mã lõi', 'ok' => !$denied,
                'hint' => $denied ? 'Thiếu quyền ghi: ' . implode(', ', $denied) : 'Thư mục gốc, app/, assets/, content/, storage/',
                'problem' => 'Máy chủ (PHP) không có quyền ghi: ' . implode(', ', $denied) . '. Cấp quyền ghi cho người dùng chạy PHP, hoặc cập nhật thủ công bằng zip/git.'],
            ['label' => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'ok' => version_compare(PHP_VERSION, '8.2', '>='),
                'hint' => 'LQCMS cần PHP 8.2 trở lên', 'problem' => 'LQCMS cần PHP 8.2 trở lên.'],
        ];
    }

    /** Lý do không cập nhật được trên máy chủ này, mảng rỗng = được. */
    public static function problems(): array
    {
        return array_values(array_map(fn ($r) => $r['problem'], array_filter(self::readiness(), fn ($r) => !$r['ok'])));
    }

    /**
     * Tải và cài bản mới nhất. @return array{0: bool, 1: string} [thành công, thông báo]
     */
    public static function apply(): array
    {
        if ($p = self::problems()) {
            return [false, $p[0]];
        }
        self::check(true);
        $l = self::available();
        if (!$l) {
            return [false, self::status()['error'] ?: 'Bạn đang dùng bản mới nhất.'];
        }
        if (!$l['asset_url']) {
            return [false, "Bản {$l['version']} chưa có gói cập nhật (lqcms-{$l['version']}-update.zip) — hãy cập nhật thủ công."];
        }
        // Một lượt cập nhật mỗi lúc (bấm 2 lần, 2 quản trị viên cùng bấm).
        $lock = (string) db()->value("SELECT CONCAT('lq_update_', MD5(CONCAT(DATABASE(), '.', ?)))", [db()->prefix]);
        if ((int) db()->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return [false, 'Đang có một lượt cập nhật khác chạy, thử lại sau ít phút.'];
        }
        @set_time_limit(600);
        ignore_user_abort(true); // người dùng đóng tab giữa chừng thì vẫn làm cho xong (hoặc trả lại như cũ)
        $work = self::dir() . '/work-' . bin2hex(random_bytes(6));
        try {
            if (!@mkdir($work, 0700, true)) {
                return [false, 'Không tạo được thư mục tạm trong storage/updates (kiểm tra quyền ghi).'];
            }
            $zipFile = "$work/update.zip";
            if ($err = self::download($l['asset_url'], $zipFile)) {
                return [false, "Không tải được gói cập nhật: $err"];
            }
            [$root, $err] = self::unpack($zipFile, "$work/src");
            if ($err) {
                return [false, $err];
            }
            if ($err = self::verify($root, $l['version'])) {
                return [false, "Gói cập nhật không hợp lệ, chưa thay gì: $err"];
            }
            $from = LQ_VERSION;
            $backup = self::dir() . '/backup-' . $from . '-' . gmdate('YmdHis');
            if ($err = self::swap($root, $backup)) {
                return [false, "Cập nhật thất bại, đã trả lại bản $from: $err"];
            }
            file_put_contents("$backup/backup.json", json_encode(['version' => $from, 'replaced_by' => $l['version'], 'at' => time()]));
            self::afterSwap();
            update_option('update_last', ['from' => $from, 'to' => $l['version'], 'at' => time(), 'user' => (int) (current_user()['id'] ?? 0)], false);
            self::prune();
            do_action('lq_updated', $from, $l['version']);
            return [true, "Đã cập nhật LQCMS $from → {$l['version']}."];
        } catch (Throwable $e) {
            ErrorLog::add('error', 'Cập nhật LQCMS lỗi: ' . $e->getMessage(), $e->getFile(), $e->getLine());
            return [false, 'Cập nhật thất bại: ' . $e->getMessage()];
        } finally {
            rrmdir($work);
            db()->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Bản sao lưu gần nhất (để khôi phục), hoặc null. */
    public static function lastBackup(): ?array
    {
        $list = [];
        foreach (glob(self::dir() . '/backup-*', GLOB_ONLYDIR) ?: [] as $d) {
            $meta = json_decode((string) @file_get_contents("$d/backup.json"), true);
            if (is_array($meta) && is_file("$d/app/bootstrap.php")) {
                $list[] = $meta + ['dir' => $d];
            }
        }
        usort($list, fn ($a, $b) => $b['at'] <=> $a['at']);
        return $list[0] ?? null;
    }

    /** Trả lõi về bản sao lưu gần nhất; bản đang chạy lại được sao lưu (nên khôi phục cũng đảo ngược được). */
    public static function rollback(): array
    {
        if (!self::allowed()) {
            return [false, 'Cập nhật từ trang quản trị đang bị tắt (disallow_updates trong config.php).'];
        }
        $b = self::lastBackup();
        if (!$b) {
            return [false, 'Không có bản sao lưu nào để khôi phục.'];
        }
        $lock = (string) db()->value("SELECT CONCAT('lq_update_', MD5(CONCAT(DATABASE(), '.', ?)))", [db()->prefix]);
        if ((int) db()->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return [false, 'Đang có một lượt cập nhật khác chạy, thử lại sau ít phút.'];
        }
        try {
            $from = LQ_VERSION;
            $backup = self::dir() . '/backup-' . $from . '-' . gmdate('YmdHis');
            if ($err = self::swap($b['dir'], $backup)) {
                return [false, "Khôi phục thất bại, vẫn giữ bản $from: $err"];
            }
            file_put_contents("$backup/backup.json", json_encode(['version' => $from, 'replaced_by' => $b['version'], 'at' => time()]));
            rrmdir($b['dir']); // các mục đã được chuyển về gốc site; còn lại backup.json
            self::afterSwap();
            update_option('update_last', ['from' => $from, 'to' => $b['version'], 'at' => time(), 'user' => (int) (current_user()['id'] ?? 0), 'rollback' => true], false);
            return [true, "Đã khôi phục LQCMS $from → {$b['version']}."];
        } finally {
            db()->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Ghi chú phát hành (Markdown của GitHub) → HTML an toàn: escape trước, rồi mới đổi tiêu đề, danh sách, **đậm**, `mã`, link http(s). */
    public static function notesHtml(string $md): string
    {
        $out = [];
        $list = false;
        foreach (preg_split('/\r\n|\r|\n/', $md) as $line) {
            $t = trim($line);
            $inline = fn (string $s) => preg_replace(
                ['/\*\*(.+?)\*\*/', '/`([^`]+)`/', '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/'],
                ['<strong>$1</strong>', '<code>$1</code>', '<a href="$2" target="_blank" rel="noopener">$1</a>'],
                e($s)
            );
            if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
                $out[] = ($list ? '' : '<ul>') . '<li>' . $inline($m[1]) . '</li>';
                $list = true;
                continue;
            }
            if ($list) {
                $out[] = '</ul>';
                $list = false;
            }
            if ($t === '') {
                continue;
            }
            $out[] = preg_match('/^#{1,6}\s+(.*)$/', $t, $m) ? '<h4>' . $inline($m[1]) . '</h4>' : '<p>' . $inline($t) . '</p>';
        }
        return implode('', $out) . ($list ? '</ul>' : '');
    }

    /* ---------- Bên trong ---------- */

    /** GET một URL. @return array{0: int, 1: ?string, 2: ?string} [mã HTTP, nội dung, lỗi mạng] */
    private static function http(string $url): array
    {
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: LQCMS/' . LQ_VERSION];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $body === false ? curl_error($ch) : null;
            return [$code, $body === false ? null : (string) $body, $err ? "Không kết nối được GitHub ($err)." : null];
        }
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 15, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = preg_match('#^HTTP/\S+\s+(\d+)#', (string) ($http_response_header[0] ?? ''), $m) ? (int) $m[1] : 0;
        return [$code, $body === false ? null : $body, $body === false ? 'Không kết nối được GitHub.' : null];
    }

    /** Tải tệp về $dest (tối đa MAX_ZIP). @return ?string lỗi */
    private static function download(string $url, string $dest): ?string
    {
        if (!preg_match('#^https://#i', $url)) {
            return 'địa chỉ tải không phải https.';
        }
        $fh = @fopen($dest, 'wb');
        if (!$fh) {
            return 'không ghi được tệp tạm.';
        }
        $size = 0;
        $tooBig = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_HTTPHEADER => ['User-Agent: LQCMS/' . LQ_VERSION],
                CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($fh, &$size, &$tooBig): int {
                    $size += strlen($data);
                    if ($size > self::MAX_ZIP) {
                        $tooBig = true;
                        return 0; // trả khác độ dài dữ liệu = huỷ tải
                    }
                    return (int) fwrite($fh, $data);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            fclose($fh);
            if ($tooBig) {
                return 'tệp lớn hơn ' . (self::MAX_ZIP >> 20) . ' MB.';
            }
            return $ok && $code === 200 ? null : ($code ? "máy chủ trả về mã $code." : $err);
        }
        $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['header' => 'User-Agent: LQCMS/' . LQ_VERSION, 'timeout' => 300]]));
        if (!$in) {
            fclose($fh);
            return 'không kết nối được.';
        }
        $n = stream_copy_to_stream($in, $fh, self::MAX_ZIP + 1);
        fclose($in);
        fclose($fh);
        return $n === false ? 'tải bị gián đoạn.' : ($n > self::MAX_ZIP ? 'tệp lớn hơn ' . (self::MAX_ZIP >> 20) . ' MB.' : null);
    }

    /** Giải nén (chặn zip slip, đúng một thư mục gốc). @return array{0: ?string, 1: ?string} [thư mục gốc đã giải nén, lỗi] */
    private static function unpack(string $zipFile, string $dir): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return [null, 'Gói tải về không phải tệp zip hợp lệ.'];
        }
        $root = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || $name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name) || str_contains($name, ':') || str_contains($name, "\0")) {
                $zip->close();
                return [null, 'Gói cập nhật chứa đường dẫn không an toàn.'];
            }
            if (str_starts_with($name, '__MACOSX/')) {
                continue;
            }
            $first = explode('/', $name, 2)[0];
            if (!str_contains($name, '/') || ($root !== null && $first !== $root)) {
                $zip->close();
                return [null, 'Gói cập nhật phải chứa đúng một thư mục gốc.'];
            }
            $root = $first;
        }
        if ($root === null || !@mkdir($dir, 0700, true)) {
            $zip->close();
            return [null, $root === null ? 'Gói cập nhật rỗng.' : 'Không tạo được thư mục tạm.'];
        }
        $ok = Package::extract($zip, $dir);
        $zip->close();
        return $ok === true ? ["$dir/$root", null] : [null, 'Giải nén thất bại' . ($ok ? ": $ok" : '.')];
    }

    /** Gói đúng phiên bản đã báo, đủ tệp bắt buộc, chỉ chứa đường dẫn lõi, mọi tệp PHP không lỗi cú pháp. @return ?string lỗi */
    private static function verify(string $root, string $version): ?string
    {
        foreach (self::REQUIRED as $f) {
            if (!is_file("$root/$f")) {
                return "thiếu $f.";
            }
        }
        if (!preg_match("/define\\('LQ_VERSION',\\s*'([^']+)'\\)/", (string) file_get_contents("$root/app/bootstrap.php"), $m) || $m[1] !== $version) {
            return 'phiên bản trong gói (' . ($m[1] ?? '?') . ") khác bản phát hành ($version).";
        }
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'content' && !in_array($entry, self::PATHS, true)) {
                return "có \"$entry\" nằm ngoài phần lõi được phép cập nhật.";
            }
        }
        if (is_dir("$root/content") && array_diff(scandir("$root/content") ?: [], ['.', '..', '.htaccess'])) {
            return 'gói cập nhật không được chứa theme/plugin/tệp tải lên (content/).';
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            try {
                token_get_all((string) file_get_contents($f->getPathname()), TOKEN_PARSE);
            } catch (\ParseError $e) {
                return 'lỗi cú pháp ở ' . substr($f->getPathname(), strlen($root) + 1) . ':' . $e->getLine() . '.';
            }
        }
        return null;
    }

    /**
     * Thay các mục lõi đang chạy bằng các mục trong $source: mục cũ đổi tên vào $backup, mục mới đổi tên vào chỗ.
     * Lỗi ở bước nào thì đảo ngược mọi bước đã làm. @return ?string lỗi
     */
    private static function swap(string $source, string $backup): ?string
    {
        if (!@mkdir("$backup/content", 0700, true)) {
            return 'không tạo được thư mục sao lưu trong storage/updates.';
        }
        $done = []; // [đường dẫn, đã chuyển bản cũ vào backup?, đã đưa bản mới vào?]
        $err = null;
        foreach (self::PATHS as $p) {
            if (!file_exists("$source/$p")) {
                continue; // gói không có mục này: giữ bản đang chạy
            }
            $cur = LQ_ROOT . "/$p";
            $step = [$p, false, false];
            if (file_exists($cur)) {
                if (!@rename($cur, "$backup/$p")) {
                    $err = "không chuyển được $p sang bản sao lưu (" . (error_get_last()['message'] ?? '?') . ')';
                    break;
                }
                $step[1] = true;
            }
            if (!@rename("$source/$p", $cur)) {
                $done[] = $step;
                $err = "không đưa được $p bản mới vào (" . (error_get_last()['message'] ?? '?') . ')';
                break;
            }
            $step[2] = true;
            $done[] = $step;
        }
        if ($err === null) {
            return null;
        }
        // Đảo ngược: đưa bản mới về lại $source, bản cũ từ backup về chỗ.
        foreach (array_reverse($done) as [$p, $movedOld, $placedNew]) {
            if ($placedNew) {
                @rename(LQ_ROOT . "/$p", "$source/$p");
            }
            if ($movedOld) {
                @rename("$backup/$p", LQ_ROOT . "/$p");
            }
        }
        // Chỉ xoá thư mục sao lưu khi mọi mục đã về chỗ cũ — trả về mà lỗi thì bản lõi cũ vẫn nằm trong đó, xoá là mất.
        $left = array_diff(array_merge(scandir($backup) ?: [], array_map(fn ($x) => "content/$x", scandir("$backup/content") ?: [])),
            ['.', '..', 'content', 'content/.', 'content/..']);
        if (!$left) {
            rrmdir($backup);
            return $err . '.';
        }
        return $err . '. Không trả lại được hết — bản cũ còn trong ' . substr($backup, strlen(LQ_ROOT) + 1) . ', hãy chép lại thủ công.';
    }

    /** Mã PHP vừa thay: xoá bộ nhớ đệm opcode (và cache stat) để request sau chạy đúng mã mới. */
    private static function afterSwap(): void
    {
        InstallFiles::remove(); // bản lõi cũ (khôi phục) hay site cài từ bản cũ có thể còn trình cài đặt, database/…
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
    }

    /** Chỉ giữ KEEP_BACKUPS bản sao lưu mới nhất. */
    private static function prune(): void
    {
        $dirs = glob(self::dir() . '/backup-*', GLOB_ONLYDIR) ?: [];
        usort($dirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($dirs, self::KEEP_BACKUPS) as $d) {
            rrmdir($d);
        }
    }
}
