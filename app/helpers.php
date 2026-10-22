<?php
declare(strict_types=1);

use LQ\Core\App;
use LQ\Core\Auth;
use LQ\Core\Csrf;
use LQ\Core\Database;
use LQ\Core\Hooks;
use LQ\Core\Options;
use LQ\Core\Session;

/* ---------- Escape & định dạng ---------- */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function site_timezone(): DateTimeZone
{
    try {
        return new DateTimeZone((string) option('timezone', 'Asia/Ho_Chi_Minh'));
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

/** Đổi chuỗi UTC trong DB sang giờ site theo định dạng cho trước (mặc định lấy từ cài đặt). */
function format_date(?string $utc, ?string $format = null): string
{
    if (!$utc) {
        return '—';
    }
    $format ??= option('date_format', 'd/m/Y') . ' ' . option('time_format', 'H:i');
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(site_timezone())->format($format);
}

/** Giờ site (từ input datetime-local) -> UTC để lưu DB. */
function local_to_utc(string $local): ?string
{
    try {
        return (new DateTimeImmutable($local, site_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Exception) {
        return null;
    }
}

/* ---------- Truy cập dịch vụ lõi ---------- */

function db(): Database
{
    return App::db();
}

function option(string $name, mixed $default = null): mixed
{
    return Options::get($name, $default);
}

function update_option(string $name, mixed $value, bool $autoload = true): void
{
    Options::set($name, $value, $autoload);
}

function current_user(): ?array
{
    return Auth::user();
}

function can(string $cap): bool
{
    return Auth::can($cap);
}

/* ---------- Hooks cho plugin/theme ---------- */

function add_action(string $hook, callable $callback, int $priority = 10): void
{
    Hooks::add($hook, $callback, $priority);
}

function do_action(string $hook, mixed ...$args): void
{
    Hooks::run($hook, ...$args);
}

function add_filter(string $hook, callable $callback, int $priority = 10): void
{
    Hooks::add($hook, $callback, $priority);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return Hooks::apply($hook, $value, ...$args);
}

/**
 * Plugin đăng ký một trang riêng trong admin: /admin/plugin/{slug}.
 * $callback in ra HTML của trang (đã nằm trong layout admin).
 * $keywords: nội dung chính của trang (vd. "thời gian giữ cache, loại trừ URL") — để thanh ⌘K và trợ lý AI tìm đúng trang.
 */
function lq_add_admin_page(string $slug, string $title, callable $callback, string $icon = 'puzzle', string $cap = 'manage_options', string $keywords = ''): void
{
    \LQ\Admin\PluginPages::add($slug, $title, $callback, $icon, $cap, $keywords);
}

/**
 * Cho trợ lý AI làm một việc của plugin/theme. AI chỉ dùng được những việc khai báo ở đây.
 * $args: description (bắt buộc), run (callable(array $args): mixed, bắt buộc), params ['tên' => 'integer'|['type' => …, 'description' => …]],
 * required [tên tham số], cap (mặc định manage_options), write (true = có thay đổi → người dùng bấm Chạy mới thực hiện), label.
 * Xem LQ\AI\PluginTools.
 */
function lq_add_ai_tool(string $name, array $args): void
{
    \LQ\AI\PluginTools::register($name, $args);
}

/**
 * Plugin thêm widget cho Bảng tin. $render nhận độ rộng hiện tại (3/4/6/8/12 cột) và in ra HTML,
 * nên bọc nội dung trong <section class="tile">...</section> để cùng giao diện.
 * $opts: description, icon (tên icon Lucide), size, sizes, cap, default (true = có sẵn trên bảng tin mới).
 */
function lq_add_dashboard_widget(string $id, string $title, callable $render, array $opts = []): void
{
    \LQ\Admin\Widgets::register($id, ['title' => $title, 'render' => $render] + $opts);
}

/**
 * Thêm một cách phân loại bài viết, vd. "Diễn viên". Term dùng chung giữa các bài, có trang quản lý riêng
 * trong menu Bài viết và ô chọn trong form bài viết (dạng chip như Thẻ, hoặc checklist nếu hierarchical).
 */
function lq_register_taxonomy(string $key, array $args): void
{
    \LQ\Content\Taxonomies::register($key, $args);
}

/** Thêm field nhập liệu vào form bài viết. Xem LQ\Content\PostFields::register() cho các tuỳ chọn. */
function lq_add_post_field(string $key, array $args): void
{
    \LQ\Content\PostFields::register($key, $args);
}

/**
 * Theme thêm mẫu trang riêng (chọn trong khung "Thuộc tính trang"), kèm field chỉ dành cho mẫu đó.
 * Xem LQ\Content\Pages::registerTemplate().
 */
function lq_register_page_template(string $key, array $args): void
{
    \LQ\Content\Pages::registerTemplate($key, $args);
}

/** Khung tự vẽ trong form bài viết. $render(?array $post) in HTML; $opts['save'](int $postId, array $input) để lưu. */
function lq_add_meta_box(string $id, string $title, callable $render, array $opts = []): void
{
    \LQ\Content\PostFields::addBox($id, $title, $render, $opts);
}

function post_meta(int $postId, string $key, mixed $default = null): mixed
{
    return \LQ\Content\PostMeta::get($postId, $key, $default);
}

function update_post_meta(int $postId, string $key, mixed $value): void
{
    \LQ\Content\PostMeta::set($postId, $key, $value);
}

/** Term của bài theo taxonomy, vd. post_terms($id, 'actor'). */
function post_terms(int $postId, string $taxonomy): array
{
    return \LQ\Content\Terms::forPost($postId, $taxonomy);
}

/** Tác vụ định kỳ của plugin/theme, vd. lq_add_cron_task('dong-bo', 3600, fn () => ..., 'Đồng bộ dữ liệu'). */
function lq_add_cron_task(string $name, int $intervalSeconds, callable $callback, string $label = ''): void
{
    \LQ\Core\Cron::add($name, $intervalSeconds, $callback, $label);
}

/**
 * Endpoint AJAX /ajax/{action} cho theme/plugin. $callback(array $input) trả mảng (JSON), chuỗi (HTML) hoặc null.
 * $opts: public, method (POST|GET|ANY), cap, csrf, rate — xem LQ\Front\Ajax.
 */
function lq_add_ajax(string $action, callable $callback, array $opts = []): void
{
    \LQ\Front\Ajax::register($action, $callback, $opts);
}

/**
 * Thêm mục vào thanh quản trị trên trang web (chỉ người đã đăng nhập thấy).
 * $args: label, url, parent (id mục cha), side ('left' | 'right'), priority (mục có sẵn: site 10, new 20, edit 30,
 * preview 40, template 50; account 10 bên phải; không ghi = cuối), icon (tên có sẵn hoặc chuỗi <svg>), meta, title,
 * class, cap, method ('post'), html (chuỗi hoặc callable — in nguyên, tự escape dữ liệu bên trong).
 */
function lq_add_admin_bar_item(string $id, array $args): void
{
    \LQ\Front\AdminBar::add($id, $args);
}

/** Bỏ một mục khỏi thanh quản trị (kèm các mục con), vd. lq_remove_admin_bar_item('template'). */
function lq_remove_admin_bar_item(string $id): void
{
    \LQ\Front\AdminBar::remove($id);
}

/** Dừng callback AJAX và trả {ok: false, message} với mã HTTP $status. */
function lq_ajax_error(string $message, int $status = 400, array $extra = []): never
{
    throw new \LQ\Front\AjaxError($message, $status, $extra);
}

/* ---------- URL & request ---------- */

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return rtrim('/admin/' . ltrim($path, '/'), '/');
}

function site_url(string $path = ''): string
{
    return rtrim((string) option('site_url', ''), '/') . url($path);
}

function asset(string $path): string
{
    $file = LQ_ROOT . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : LQ_VERSION;
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function request_path(): string
{
    // Không dùng parse_url: nó trả false với "/foo:1/" (→ trang chủ thay vì 404) và coi "//admin" là tên host.
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    // Request dạng tuyệt đối ("GET http://host/path", HTTP/1.1 cho phép): bỏ scheme + host như parse_url từng làm.
    $uri = (string) preg_replace('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', '', $uri);
    $path = rawurldecode(substr($uri, 0, strcspn($uri, '?#')));
    $path = '/' . trim($path, '/');
    return $path;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function input(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function input_str(string $key, string $default = ''): string
{
    $v = input($key, $default);
    return is_string($v) ? trim($v) : $default;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function redirect(string $to, int $status = 302): never
{
    header('Location: ' . $to, true, $status);
    exit;
}

/** Quay về trang trước (chỉ chấp nhận URL cùng host để tránh open redirect). */
function redirect_back(string $fallback): never
{
    $p = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $refHost = isset($p['host']) ? $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : '';
    if ($refHost !== '' && strcasecmp($refHost, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0) {
        // Referer "http://site//evil.com" có path "//evil.com" — trình duyệt hiểu là URL khác host: gộp dấu / và \ ở đầu.
        redirect('/' . ltrim($p['path'] ?? '', '/\\') . (isset($p['query']) ? '?' . $p['query'] : ''));
    }
    redirect($fallback);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // INVALID_UTF8_SUBSTITUTE: một chuỗi UTF-8 hỏng không được làm json_encode trả false (body rỗng).
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- Session, flash, CSRF ---------- */

function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

/** Giá trị form cũ sau khi validate lỗi. */
function old(string $key, mixed $default = ''): mixed
{
    return Session::old($key, $default);
}

/* ---------- Tiện ích ---------- */

function icon(string $name, string $class = ''): string
{
    return '<i data-lucide="' . e($name) . '"' . ($class ? ' class="' . e($class) . '"' : '') . '></i>';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $a, mixed $b = true): string
{
    return (string) $a === (string) $b ? ' checked' : '';
}

/** Xoá thư mục đệ quy (dùng khi xoá theme/plugin). */
function rrmdir(string $dir): void
{
    // Thư mục là symlink (dev hay link plugin/theme từ nơi khác vào): chỉ gỡ link — đi theo link sẽ xoá sạch mã nguồn gốc.
    if (is_link($dir)) {
        unlink($dir);
        return;
    }
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Dòng báo lỗi dưới ô nhập. */
function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<p class="field-error">' . e($errors[$key]) . '</p>' : '';
}

/** Class "is-invalid" cho ô nhập có lỗi. */
function invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

/* ---------- Phím tắt ---------- */

/** Máy Mac/iPhone/iPad (đoán theo User-Agent) — để in ⌘ thay cho Ctrl. JS sẽ sửa lại nếu đoán sai. */
function is_mac_client(): bool
{
    return (bool) preg_match('/Macintosh|Mac OS X|iPhone|iPad/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/** Nhãn phím tắt có phím bổ trợ: kbd('S') -> <kbd>⌘S</kbd> trên Mac, <kbd>Ctrl S</kbd> trên Windows/Linux. */
function kbd(string $key, string $class = ''): string
{
    $label = (is_mac_client() ? '⌘' : 'Ctrl ') . $key;
    return '<kbd data-kbd="' . e($key) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . '>' . e($label) . '</kbd>';
}

/* ---------- Avatar, thumbnail, màu nhận diện ---------- */

/** Màu nhận diện cố định theo khoá (id/tên): cùng khoá luôn ra cùng màu. */
function identity_color(int|string $key): string
{
    // Thứ tự cố định, không tím/cam làm chủ đạo; đã kiểm tra tách biệt màu cho người mù màu.
    $palette = ['#65a30d', '#2a78d6', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#e34948', '#0e7490'];
    $n = is_int($key) ? $key : crc32($key);
    return $palette[abs($n) % count($palette)];
}

function avatar(string $name, string $size = '', ?string $key = null): string
{
    $name = trim($name) ?: '?';
    $parts = preg_split('/\s+/u', $name);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    return '<span class="avatar' . ($size ? ' ' . e($size) : '') . '" style="--c:' . identity_color($key ?? $name) . '" title="' . e($name) . '">' . e($initials) . '</span>';
}

/** Ảnh bài viết hoặc ô chữ cái thay thế cùng kích thước. */
function post_thumb(?string $image, string $title, string $class = 'thumb'): string
{
    if ($image) {
        return '<img class="' . e($class) . '" src="' . e($image) . '" alt="" loading="lazy">';
    }
    $letter = mb_strtoupper(mb_substr(trim($title) ?: '?', 0, 1));
    return '<span class="' . e($class) . ' thumb-letter" style="--c:' . identity_color($title ?: 'x') . '" aria-hidden="true">' . e($letter) . '</span>';
}

function cat_dot(int $id): string
{
    return '<span class="cat-dot" style="--c:' . identity_color($id) . '"></span>';
}
