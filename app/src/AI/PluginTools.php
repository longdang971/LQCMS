<?php
declare(strict_types=1);

namespace LQ\AI;

use InvalidArgumentException;
use Throwable;

/**
 * Việc plugin/theme cho phép trợ lý AI làm, khai báo bằng lq_add_ai_tool(). AI KHÔNG tự gọi hàm nào của plugin —
 * chỉ những việc được khai báo ở đây, và chỉ khi người đang chat có quyền 'cap'.
 *
 *   lq_add_ai_tool('views_top', [
 *       'description' => 'Bài nhiều lượt xem nhất',
 *       'params' => ['limit' => ['type' => 'integer', 'description' => 'Số bài, mặc định 10']],
 *       'run' => fn (array $a) => my_views_top((int) ($a['limit'] ?? 10)),
 *   ]);
 *   lq_add_ai_tool('cache_clear', ['description' => 'Xoá toàn bộ cache trang', 'write' => true, 'run' => fn () => my_cache_clear()]);
 *
 * write = false (mặc định): chỉ đọc — AI gọi và nhận kết quả ngay.
 * write = true: có thay đổi — AI chỉ gửi đề xuất, người dùng bấm "Chạy" trên thẻ đề xuất mới thực hiện; không hoàn tác được.
 */
final class PluginTools
{
    /** Tên công cụ có sẵn của lõi — plugin không được trùng. */
    private const RESERVED = ['site_info', 'read_error_log', 'search_content', 'get_content', 'list_terms', 'propose_file_change',
        'propose_content', 'search_code', 'read_file', 'list_files'];
    private const MAX_OUTPUT = 20000;

    /** @var array<string, array> */
    private static array $tools = [];

    public static function register(string $name, array $args): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,47}$/', $name) || in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("Tên công cụ AI không hợp lệ hoặc trùng công cụ của lõi: \"$name\" (chữ thường, số, gạch dưới).");
        }
        if (!is_callable($args['run'] ?? null) || trim((string) ($args['description'] ?? '')) === '') {
            throw new InvalidArgumentException("Công cụ AI \"$name\" cần 'description' và 'run' (callable).");
        }
        // Tham số: ['limit' => 'integer'] hoặc ['limit' => ['type' => 'integer', 'description' => '…', 'enum' => […]]].
        $props = [];
        foreach ((array) ($args['params'] ?? []) as $p => $def) {
            $def = is_string($def) ? ['type' => $def] : (array) $def;
            if (!in_array($def['type'] ?? 'string', ['string', 'integer', 'number', 'boolean', 'array', 'object'], true)) {
                throw new InvalidArgumentException("Công cụ AI \"$name\": tham số \"$p\" có kiểu không hỗ trợ.");
            }
            $props[(string) $p] = $def + ['type' => 'string'];
        }
        self::$tools[$name] = [
            'name' => $name,
            'label' => (string) ($args['label'] ?? $args['description']),
            'description' => trim((string) $args['description']),
            'params' => $props,
            'required' => array_values(array_intersect((array) ($args['required'] ?? []), array_keys($props))),
            'cap' => (string) ($args['cap'] ?? 'manage_options'),
            'write' => (bool) ($args['write'] ?? false),
            'run' => $args['run'],
            'source' => self::source(),
        ];
    }

    /** Công cụ người dùng hiện tại được dùng. */
    public static function available(): array
    {
        return array_filter(self::$tools, fn ($t) => can($t['cap']));
    }

    public static function get(string $name): ?array
    {
        return self::available()[$name] ?? null;
    }

    /** Định nghĩa gửi cho AI (cùng dạng với AgentTools::definitions). */
    public static function definitions(): array
    {
        return array_values(array_map(fn ($t) => [
            'name' => $t['name'],
            'description' => "[{$t['source']}] {$t['description']}"
                . ($t['write'] ? ' — CÓ THAY ĐỔI: gọi công cụ này chỉ gửi đề xuất, người dùng bấm Chạy mới thực hiện. Nhớ điền "reason".' : ' — chỉ đọc.'),
            'parameters' => [
                'type' => 'object',
                'properties' => $t['params'] + ($t['write'] ? ['reason' => ['type' => 'string', 'description' => 'Một câu cho người dùng: vì sao cần chạy việc này.']] : []),
                'required' => $t['required'],
            ],
        ], self::available()));
    }

    /** Tham số bắt buộc còn thiếu → thông báo lỗi; đủ → null. Kiểm cả lúc AI đề xuất, để AI sửa ngay. */
    public static function missing(array $tool, array $args): ?string
    {
        foreach ($tool['required'] as $r) {
            if (!array_key_exists($r, $args) || $args[$r] === '' || $args[$r] === null) {
                return "Thiếu tham số bắt buộc \"$r\".";
            }
        }
        return null;
    }

    /**
     * Chạy công cụ (đã kiểm quyền ở get()). Kết quả mảng → JSON. Lỗi (thiếu tham số, plugin ném exception)
     * trả ok = false để thẻ đề xuất không báo "đã chạy" nhầm, và không làm hỏng cả lượt chat.
     * @return array{ok: bool, text: string}
     */
    public static function call(array $tool, array $args): array
    {
        unset($args['reason']);
        $args = array_intersect_key($args, $tool['params']); // chỉ tham số đã khai báo
        if ($err = self::missing($tool, $args)) {
            return ['ok' => false, 'text' => $err];
        }
        try {
            $out = ($tool['run'])($args);
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => 'Plugin báo lỗi: ' . $e->getMessage()];
        }
        $text = match (true) {
            $out === null, $out === true => 'Xong.',
            $out === false => 'Không thực hiện được.',
            is_scalar($out) => (string) $out,
            default => (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
        return ['ok' => $out !== false, 'text' => mb_strcut(mb_scrub($text, 'UTF-8'), 0, self::MAX_OUTPUT)];
    }

    /** Tên plugin/theme đã khai báo (dò tệp gọi lq_add_ai_tool), để AI và người dùng biết việc này của ai. */
    private static function source(): string
    {
        $roots = ['plugin' => \LQ\Core\Plugins::dir() . '/', 'theme' => \LQ\Core\Themes::dir() . '/'];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            foreach ($roots as $type => $root) {
                if (str_starts_with($file, $root) && ($slug = strstr(substr($file, strlen($root)), '/', true))) {
                    $meta = $type === 'plugin' ? \LQ\Core\Plugins::read($slug) : \LQ\Core\Themes::read($slug);
                    return ($type === 'plugin' ? 'Plugin ' : 'Theme ') . ($meta['name'] ?? $slug);
                }
            }
        }
        return 'Lõi';
    }
}
