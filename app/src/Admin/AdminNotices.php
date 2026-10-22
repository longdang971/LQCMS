<?php
declare(strict_types=1);

namespace LQ\Admin;

use InvalidArgumentException;

/**
 * Thông báo cố định đầu trang quản trị cho theme/plugin (lq_admin_notice) — vd. "Plugin X cần nhập API key".
 * Plugin đăng ký mỗi request khi điều kiện còn đúng (thường trong hook admin_init); hết điều kiện thì thôi đăng ký là
 * thông báo tự biến mất. Người dùng bấm ẩn: nhớ theo từng người, nội dung đổi thì hiện lại.
 */
final class AdminNotices
{
    private const TYPES = ['info' => 'info', 'success' => 'circle-check', 'warning' => 'triangle-alert', 'error' => 'circle-alert'];
    /** @var array<string, array> */
    private static array $notices = [];

    public static function add(string $id, string $message, array $opts = []): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/', $id)) {
            throw new InvalidArgumentException("Mã thông báo không hợp lệ: \"$id\" (chữ thường, số, _ . : -).");
        }
        $link = is_array($opts['link'] ?? null) ? $opts['link'] : null;
        self::$notices[$id] = [
            'id' => $id,
            'message' => $message,
            'title' => (string) ($opts['title'] ?? ''),
            'type' => isset(self::TYPES[$opts['type'] ?? '']) ? $opts['type'] : 'info',
            'dismissible' => (bool) ($opts['dismissible'] ?? true),
            'cap' => (string) ($opts['cap'] ?? 'manage_options'),
            'screens' => array_values(array_map('strval', (array) ($opts['screens'] ?? []))), // tiền tố URL admin; rỗng = mọi trang
            'link' => $link && isset($link['label'], $link['url']) ? ['label' => (string) $link['label'], 'url' => (string) $link['url']] : null,
        ];
    }

    public static function remove(string $id): void
    {
        unset(self::$notices[$id]);
    }

    /** Thông báo người dùng hiện tại thấy ở trang $path (đủ quyền, đúng trang, chưa ẩn). */
    public static function visible(string $path): array
    {
        $dismissed = self::dismissed();
        return array_values(array_filter(self::$notices, fn ($n) => can($n['cap'])
            && (!$n['screens'] || array_filter($n['screens'], fn ($s) => self::onScreen($path, $s)))
            && !($n['dismissible'] && ($dismissed[$n['id']] ?? null) === self::hash($n))));
    }

    /** '/admin/posts' khớp cả trang con (/admin/posts/new); riêng '/admin' chỉ là bảng tin, không phải mọi trang. */
    private static function onScreen(string $path, string $screen): bool
    {
        $screen = rtrim($screen, '/');
        return $path === $screen || ($screen !== '/admin' && $screen !== '' && str_starts_with($path, $screen . '/'));
    }

    public static function render(string $path): string
    {
        $html = '';
        foreach (self::visible($path) as $n) {
            $link = $n['link'] && preg_match('#^(https?://|/(?![/\\\\]))#i', $n['link']['url'])
                ? ' <a class="link" href="' . e($n['link']['url']) . '"' . (preg_match('#^https?://#i', $n['link']['url']) ? ' target="_blank" rel="noopener"' : '') . '>' . e($n['link']['label']) . '</a>'
                : '';
            $html .= '<div class="notice notice-' . e($n['type']) . ' admin-notice" data-notice="' . e($n['id']) . '">'
                . icon(self::TYPES[$n['type']])
                . '<div>' . ($n['title'] !== '' ? '<strong>' . e($n['title']) . '</strong> ' : '') . e($n['message']) . $link . '</div>'
                . ($n['dismissible'] ? '<button class="icon-btn sm admin-notice-close" type="button" data-notice-dismiss aria-label="Ẩn thông báo">' . icon('x') . '</button>' : '')
                . '</div>';
        }
        return $html;
    }

    /** POST /admin/notices/dismiss {id}: ẩn với người dùng hiện tại (theo nội dung hiện tại — đổi nội dung thì hiện lại). */
    public static function dismissRoute(): void
    {
        $id = input_str('id');
        $n = self::$notices[$id] ?? null;
        if (!$n || !$n['dismissible'] || !can($n['cap'])) {
            json_response(['ok' => false, 'message' => 'Không có thông báo này.'], 404);
        }
        $all = self::dismissed();
        $all[$id] = self::hash($n);
        update_option('notices_dismissed_' . (int) current_user()['id'], array_slice($all, -100, null, true), false); // chỉ đọc ở trang admin: không autoload
        json_response(['ok' => true]);
    }

    private static function dismissed(): array
    {
        $u = current_user();
        $v = $u ? option('notices_dismissed_' . (int) $u['id'], []) : [];
        return is_array($v) ? $v : [];
    }

    private static function hash(array $n): string
    {
        return substr(md5($n['title'] . '|' . $n['message']), 0, 12);
    }
}
