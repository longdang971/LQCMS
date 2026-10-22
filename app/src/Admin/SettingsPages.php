<?php
declare(strict_types=1);

namespace LQ\Admin;

use InvalidArgumentException;
use LQ\AI\Secrets;
use LQ\Content\PostFields;
use LQ\Core\Session;
use LQ\Core\View;

/**
 * Trang cài đặt cho plugin/theme (lq_add_settings_page): plugin chỉ khai báo các ô, lõi vẽ form, kiểm tra
 * (cùng quy tắc với field bài viết — PostFields::check), báo lỗi từng ô và lưu vào option "settings_<slug>".
 * Đọc bằng lq_get_setting(). Kiểu thêm 'secret' (API key…): ô mật khẩu, lưu mã hoá, để trống = giữ nguyên.
 */
final class SettingsPages
{
    /** @var array<string, array> */
    private static array $pages = [];

    public static function add(string $slug, array $args): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
            throw new InvalidArgumentException("Slug trang cài đặt không hợp lệ: \"$slug\".");
        }
        // 'fields' ở cấp ngoài = một nhóm duy nhất không tiêu đề.
        $sections = $args['sections'] ?? [['fields' => $args['fields'] ?? []]];
        $fields = [];
        $norm = [];
        foreach ((array) $sections as $sec) {
            $keys = [];
            foreach ((array) ($sec['fields'] ?? []) as $key => $f) {
                $secret = ($f['type'] ?? '') === 'secret';
                $def = PostFields::normalize((string) $key, ['type' => $secret ? 'text' : ($f['type'] ?? 'text')] + (array) $f);
                $def['secret'] = $secret;
                $fields[$key] = $def;
                $keys[] = $key;
            }
            $norm[] = ['title' => (string) ($sec['title'] ?? ''), 'description' => (string) ($sec['description'] ?? ''), 'keys' => $keys];
        }
        $title = (string) ($args['title'] ?? $slug);
        self::$pages[$slug] = [
            'slug' => $slug,
            'title' => $title,
            'description' => (string) ($args['description'] ?? ''),
            'cap' => (string) ($args['cap'] ?? 'manage_options'),
            'sections' => $norm,
            'fields' => $fields,
        ];
        PluginPages::add($slug, (string) ($args['menu_title'] ?? $title), fn () => self::render($slug), (string) ($args['icon'] ?? 'settings'),
            self::$pages[$slug]['cap'], (string) ($args['keywords'] ?? ''));
    }

    public static function slugs(): array
    {
        return array_keys(self::$pages);
    }

    /** Giá trị đã lưu (secret đã giải mã); chưa lưu thì 'default' của ô, rồi $default. */
    public static function get(string $slug, string $key, mixed $default = null): mixed
    {
        $saved = option("settings_$slug", []);
        $f = self::$pages[$slug]['fields'][$key] ?? null;
        if (is_array($saved) && array_key_exists($key, $saved)) {
            return !empty($f['secret']) && is_string($saved[$key]) ? Secrets::decrypt($saved[$key]) : $saved[$key];
        }
        return $f['default'] ?? $default;
    }

    private static function render(string $slug): void
    {
        $page = self::$pages[$slug];
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        $old = Session::old('settings', null);
        $values = [];
        foreach ($page['fields'] as $key => $f) {
            // Form vừa lỗi: hiện lại chữ người dùng gõ (secret thì không). Checkbox bỏ chọn không được gửi → false.
            $values[$key] = is_array($old) && !$f['secret']
                ? ($old[$key] ?? ($f['type'] === 'checkbox' ? false : ''))
                : self::get($slug, $key);
        }
        echo View::capture('admin/settings-page', ['page' => $page, 'values' => $values, 'errors' => $errors]);
    }

    /** POST /admin/plugin/{slug}: kiểm tra từng ô, lưu nếu không lỗi. */
    public static function save(array $params): void
    {
        $page = self::$pages[$params['slug']] ?? null;
        if (!$page) {
            Kernel::notFound();
        }
        Kernel::require($page['cap']);
        $input = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
        $stored = option('settings_' . $page['slug'], []);
        $stored = is_array($stored) ? $stored : [];
        $values = [];
        $errors = [];
        foreach ($page['fields'] as $key => $f) {
            if ($f['secret']) {
                $raw = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
                if ($raw === '') {
                    if ($f['required'] && empty($stored[$key])) {
                        $errors["settings.$key"] = "{$f['label']} là bắt buộc.";
                    } elseif (array_key_exists($key, $stored)) {
                        $values[$key] = $stored[$key]; // để trống = giữ giá trị đã lưu
                    }
                    continue;
                }
                [$v, $err] = PostFields::check($f, $raw, null, false);
                if ($err !== null) {
                    $errors["settings.$key"] = $err;
                } else {
                    $values[$key] = Secrets::encrypt((string) $v);
                }
                continue;
            }
            [$v, $err] = PostFields::check($f, $input[$key] ?? null, null, false);
            if ($err !== null) {
                $errors["settings.$key"] = $err;
            } else {
                $values[$key] = $v;
            }
        }
        $url = '/admin/plugin/' . $page['slug'];
        if ($errors) {
            // Giữ chữ người dùng gõ để hiện lại — trừ ô secret (không để API key nằm trong session).
            $secretKeys = array_keys(array_filter($page['fields'], fn ($f) => $f['secret']));
            Session::keepOld(['settings' => array_diff_key(array_filter($input, fn ($v) => is_string($v) || is_array($v)), array_flip($secretKeys))]);
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect($url);
        }
        update_option('settings_' . $page['slug'], $values);
        do_action('settings_saved', 'plugin:' . $page['slug'], $values);
        flash('success', 'Đã lưu cài đặt.');
        redirect($url);
    }
}
