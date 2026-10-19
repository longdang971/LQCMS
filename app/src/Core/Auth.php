<?php
declare(strict_types=1);

namespace LQ\Core;

final class Auth
{
    public const ROLES = [
        'admin' => 'Quản trị viên',
        'editor' => 'Biên tập viên',
        'author' => 'Tác giả',
        'subscriber' => 'Thành viên',
    ];

    private const CAPS = [
        'admin' => ['*'],
        'editor' => ['access_admin', 'edit_posts', 'edit_others_posts', 'publish_posts', 'edit_pages', 'manage_terms', 'upload_files', 'unfiltered_html'],
        'author' => ['access_admin', 'edit_posts', 'publish_posts', 'upload_files'],
        'subscriber' => ['access_admin'],
    ];

    private const REMEMBER_COOKIE = 'lq_remember';
    private const REMEMBER_DAYS = 30;
    private const MAX_FAILS = 5;
    private const LOCK_SECONDS = 900;

    private static ?array $user = null;
    private static bool $resolved = false;

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        if (!empty($_SESSION['uid'])) {
            self::$user = self::find((int) $_SESSION['uid']);
        }
        if (!self::$user) {
            self::$user = self::fromRememberCookie();
        }
        if (self::$user && self::$user['status'] !== 'active') {
            self::$user = null;
            unset($_SESSION['uid']);
        }
        return self::$user;
    }

    public static function find(int $id): ?array
    {
        return db()->row('SELECT * FROM {users} WHERE id = ?', [$id]);
    }

    public static function can(string $cap): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        $caps = apply_filters('role_caps', self::CAPS[$u['role']] ?? [], $u['role']);
        return in_array('*', $caps, true) || in_array($cap, $caps, true);
    }

    /** Trả về thông báo lỗi, hoặc null nếu đăng nhập thành công. */
    public static function attempt(string $login, string $password, bool $remember): ?string
    {
        $ip = client_ip();
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_SECONDS);
        $fails = (int) db()->value('SELECT COUNT(*) FROM {login_attempts} WHERE ip = ? AND attempted_at > ?', [$ip, $since]);
        if ($fails >= self::MAX_FAILS) {
            return 'Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.';
        }

        $u = db()->row('SELECT * FROM {users} WHERE username = ? OR email = ? LIMIT 1', [$login, $login]);
        // Vẫn chạy password_verify khi không có user để thời gian phản hồi như nhau (chống dò tên tài khoản).
        $hash = $u['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || !$u) {
            db()->insert('login_attempts', ['ip' => $ip, 'attempted_at' => now_utc()]);
            return 'Tên đăng nhập hoặc mật khẩu không đúng.';
        }
        if ($u['status'] !== 'active') {
            return 'Tài khoản của bạn đã bị khoá.';
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db()->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], ['id' => $u['id']]);
        }

        db()->delete('login_attempts', ['ip' => $ip]);
        self::login($u, $remember);
        return null;
    }

    public static function login(array $u, bool $remember = false): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        db()->update('users', ['last_login_at' => now_utc()], ['id' => $u['id']]);
        if ($remember) {
            self::issueRememberToken((int) $u['id']);
        }
        self::$user = $u;
        self::$resolved = true;
        do_action('user_login', $u);
    }

    public static function logout(): void
    {
        if ($u = self::user()) {
            do_action('user_logout', $u);
        }
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (is_string($cookie) && str_contains($cookie, ':')) {
            db()->delete('user_tokens', ['selector' => explode(':', $cookie, 2)[0]]);
        }
        self::setCookie('', time() - 3600);
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    /**
     * Đăng ký tài khoản mới. Trả về mảng lỗi theo field; rỗng nghĩa là thành công.
     * @return array{0: array<string,string>, 1: ?int}
     */
    public static function register(array $in, ?string $role = null): array
    {
        $username = trim((string) ($in['username'] ?? ''));
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        $confirm = (string) ($in['password_confirm'] ?? '');
        $errors = [];

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
            $errors['username'] = 'Tên đăng nhập 3–30 ký tự, chỉ gồm chữ không dấu, số, dấu chấm, gạch dưới, gạch ngang.';
        } elseif (db()->value('SELECT 1 FROM {users} WHERE username = ?', [$username])) {
            $errors['username'] = 'Tên đăng nhập đã được sử dụng.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email không hợp lệ.';
        } elseif (db()->value('SELECT 1 FROM {users} WHERE email = ?', [$email])) {
            $errors['email'] = 'Email đã được sử dụng.';
        }
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'Mật khẩu phải có ít nhất 8 ký tự.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'Mật khẩu nhập lại không khớp.';
        }
        if ($errors) {
            return [$errors, null];
        }

        $role ??= (string) option('default_role', 'subscriber');
        if (!isset(self::ROLES[$role])) {
            $role = 'subscriber';
        }
        $id = db()->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => trim((string) ($in['display_name'] ?? '')) ?: $username,
            'role' => $role,
            'status' => 'active',
            'created_at' => now_utc(),
        ]);
        do_action('user_registered', $id);
        return [[], $id];
    }

    /* ---------- Ghi nhớ đăng nhập: cookie selector:validator, DB chỉ giữ hash của validator ---------- */

    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::REMEMBER_DAYS * 86400;
        db()->insert('user_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
        ]);
        self::setCookie("$selector:$validator", $expires);
    }

    private static function fromRememberCookie(): ?array
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($cookie) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $cookie, $m)) {
            return null;
        }
        $t = db()->row('SELECT * FROM {user_tokens} WHERE selector = ? AND expires_at > ?', [$m[1], now_utc()]);
        if (!$t || !hash_equals($t['validator_hash'], hash('sha256', $m[2]))) {
            self::setCookie('', time() - 3600);
            return null;
        }
        $u = self::find((int) $t['user_id']);
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
        }
        return $u;
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
