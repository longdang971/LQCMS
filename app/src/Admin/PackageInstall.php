<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Core\Package;
use LQ\Core\Plugins;
use LQ\Core\Themes;
use LQ\Core\View;

/**
 * Cài plugin/theme từ .zip: tải lên → quét (Package::stage) → trang xem lại kết quả → người cài bấm Cài đặt hoặc Huỷ.
 * Dùng chung cho cả hai loại; $kind = 'plugin' | 'theme'.
 */
final class PackageInstall
{
    private static function cfg(string $kind): array
    {
        return $kind === 'plugin'
            ? ['cap' => 'manage_plugins', 'dir' => Plugins::dir(), 'base' => '/admin/plugins', 'label' => 'plugin',
               'validate' => [Plugins::class, 'validatePackage'], 'describe' => [Plugins::class, 'describePackage']]
            : ['cap' => 'manage_themes', 'dir' => Themes::dir(), 'base' => '/admin/themes', 'label' => 'theme',
               'validate' => [Themes::class, 'validatePackage'], 'describe' => [Themes::class, 'describePackage']];
    }

    public static function upload(string $kind): void
    {
        $c = self::cfg($kind);
        Kernel::require($c['cap']);
        [$token, $err] = Package::stage($_FILES['package'] ?? [], $kind, $c['dir'], $c['validate'], $c['describe']);
        if ($err) {
            flash('error', $err);
            redirect($c['base']);
        }
        redirect($c['base'] . '/review?token=' . $token);
    }

    public static function review(string $kind): void
    {
        $c = self::cfg($kind);
        Kernel::require($c['cap']);
        $pkg = Package::staged(input_str('token'), $kind);
        if (!$pkg) {
            flash('error', 'Gói cài đặt không còn (đã cài, đã huỷ hoặc quá 1 giờ). Hãy tải lên lại.');
            redirect($c['base']);
        }
        View::render('admin/packages/review', [
            'title' => 'Kiểm tra ' . $c['label'],
            'subject' => $pkg['name'],
            'pkg' => $pkg,
            'label' => $c['label'],
            'base' => $c['base'],
        ]);
    }

    public static function install(string $kind): void
    {
        $c = self::cfg($kind);
        Kernel::require($c['cap']);
        [$slug, $err] = Package::commit(input_str('token'), $kind, $c['dir']);
        if ($err) {
            flash('error', $err);
        } else {
            $name = ($kind === 'plugin' ? Plugins::read($slug) : Themes::read($slug))['name'] ?? $slug;
            flash('success', "Đã cài {$c['label']} \"$name\". Bấm Kích hoạt để sử dụng.");
        }
        redirect($c['base']);
    }

    public static function cancel(string $kind): void
    {
        $c = self::cfg($kind);
        Kernel::require($c['cap']);
        Package::discard(input_str('token'));
        flash('success', 'Đã huỷ, gói tải lên đã bị xoá.');
        redirect($c['base']);
    }
}
