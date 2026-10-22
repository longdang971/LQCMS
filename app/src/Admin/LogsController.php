<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\ErrorLog;
use LQ\Core\Plugins;
use LQ\Core\Themes;
use LQ\Core\View;

/** Cài đặt › Nhật ký: lỗi PHP của site + nhật ký theme/plugin ghi bằng lq_log(). */
final class LogsController
{
    public const LEVELS = ['error' => 'Lỗi', 'fatal' => 'Lỗi nghiêm trọng', 'warning' => 'Cảnh báo', 'notice' => 'Lưu ý', 'deprecated' => 'Lỗi thời', 'info' => 'Thông tin'];

    public static function index(): void
    {
        Kernel::require('manage_options');
        $all = ErrorLog::recent(500);
        $level = input_str('level');
        $source = input_str('source');
        $q = trim(input_str('q'));
        $rows = array_values(array_filter($all, fn ($r) => ($level === '' || ($r['level'] ?? '') === $level || ($level === 'error' && ($r['level'] ?? '') === 'fatal'))
            && ($source === '' || ($r['source'] ?? '') === ($source === 'core' ? '' : $source))
            && ($q === '' || mb_stripos(($r['msg'] ?? '') . ' ' . ($r['file'] ?? ''), $q) !== false)));
        $sources = [];
        foreach ($all as $r) {
            $s = (string) ($r['source'] ?? '');
            $sources[$s === '' ? 'core' : $s] = self::sourceLabel($s);
        }
        ksort($sources);
        View::render('admin/logs', [
            'title' => 'Nhật ký',
            'rows' => array_slice($rows, 0, 200),
            'total' => count($all),
            'sources' => $sources,
            'filters' => compact('level', 'source', 'q'),
        ]);
    }

    public static function clear(): void
    {
        Kernel::require('manage_options');
        ErrorLog::clear();
        flash('success', 'Đã xoá nhật ký.');
        redirect('/admin/logs');
    }

    /** "plugin:hello-lq" → "Plugin Hello LQ" (tên lấy từ header nếu còn cài). */
    public static function sourceLabel(string $source): string
    {
        if ($source === '') {
            return 'Lõi LQCMS';
        }
        [$type, $slug] = explode(':', $source, 2) + [1 => ''];
        $meta = $type === 'plugin' ? Plugins::read($slug) : Themes::read($slug);
        return ($type === 'plugin' ? 'Plugin ' : 'Theme ') . ($meta['name'] ?? $slug);
    }
}
