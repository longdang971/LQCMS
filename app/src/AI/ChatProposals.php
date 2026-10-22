<?php
declare(strict_types=1);

namespace LQ\AI;

use LQ\Content\PostFields;
use LQ\Content\PostMeta;
use LQ\Content\Posts;
use LQ\Content\Revisions;
use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Core\App;
use LQ\Core\ThemeFiles;

/**
 * Đề xuất thay đổi do trợ lý chat tạo ra, chờ người dùng duyệt. Lưu trong session (theo người đăng nhập).
 * Không có gì được ghi cho tới khi người dùng bấm Áp dụng (apply); mỗi lần áp dụng đều giữ bản cũ để Hoàn tác (undo).
 *
 * Hai loại: sửa/tạo TỆP trong theme hoặc plugin; tạo/sửa BÀI VIẾT hoặc TRANG.
 */
final class ChatProposals
{
    public const FILE_EXT = ['php', 'css', 'js', 'json', 'html', 'htm', 'svg', 'txt', 'md'];
    public const MAX_FILE_BYTES = 300 * 1024;
    private const KEEP = 30;

    /* ---------- Lưu trữ trong session ---------- */

    public static function all(): array
    {
        return (array) ($_SESSION['ai_proposals'] ?? []);
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** Ghi lại (thêm/cập nhật) một đề xuất; chỉ giữ 30 đề xuất gần nhất. */
    public static function put(array $p): void
    {
        $all = self::all();
        $all[$p['id']] = $p;
        $_SESSION['ai_proposals'] = array_slice($all, -self::KEEP, null, true);
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /* ---------- Tệp theme / plugin ---------- */

    /**
     * Kiểm tra đường dẫn tệp được phép sửa: chỉ trong content/themes/<slug>/ hoặc content/plugins/<slug>/,
     * đúng phần mở rộng, không thoát ra ngoài. Trả [đường dẫn tuyệt đối, loại 'theme'|'plugin', lỗi].
     */
    public static function filePath(string $rel): array
    {
        $rel = trim(str_replace('\\', '/', $rel), '/ ');
        if (App::config('disallow_file_edit', false)) {
            return [null, null, 'Site đã tắt sửa tệp từ admin (disallow_file_edit trong config.php).'];
        }
        if (!preg_match('#^content/(themes|plugins)/([a-z0-9_-]+)/(.+)$#', $rel, $m)) {
            return [null, null, 'Chỉ được sửa tệp trong content/themes/<theme>/ hoặc content/plugins/<plugin>/.'];
        }
        [, $kind, $slug, $inner] = $m;
        if ($err = ThemeFiles::validateName($inner)) {
            return [null, null, $err];
        }
        if (!in_array(strtolower(pathinfo($inner, PATHINFO_EXTENSION)), self::FILE_EXT, true)) {
            return [null, null, 'Chỉ được sửa tệp ' . implode(', ', self::FILE_EXT) . '.'];
        }
        $base = LQ_CONTENT . "/$kind/$slug";
        if (!is_dir($base)) {
            return [null, null, "Không có " . ($kind === 'themes' ? 'theme' : 'plugin') . " \"$slug\"."];
        }
        $abs = "$base/$inner";
        $realBase = realpath($base);
        // Thư mục con chưa có (tạo mới khi áp dụng): kiểm thư mục cha gần nhất đang có — nó có thể là symlink trỏ ra ngoài.
        $dir = dirname($abs);
        while (!file_exists($dir) && strlen($dir) > strlen($base)) {
            $dir = dirname($dir);
        }
        $realDir = realpath($dir);
        if ($realDir === false || !str_starts_with($realDir . '/', $realBase . '/')) {
            return [null, null, 'Đường dẫn không hợp lệ.'];
        }
        if (is_link($abs)) {
            return [null, null, 'Không sửa tệp liên kết (symlink).'];
        }
        $cap = $kind === 'themes' ? 'edit_themes' : 'manage_plugins';
        if (!can($cap)) {
            return [null, null, 'Tài khoản của bạn không có quyền sửa tệp ' . ($kind === 'themes' ? 'theme' : 'plugin') . '.'];
        }
        return [$abs, $kind === 'themes' ? 'theme' : 'plugin', null];
    }

    /**
     * Tạo đề xuất sửa/tạo tệp. $content = toàn bộ nội dung mới; hoặc $edits = [[find, replace], …] áp lên bản hiện tại
     * (mỗi find phải xuất hiện đúng 1 lần). Trả [đề xuất, lỗi].
     */
    public static function proposeFile(string $rel, ?string $content, array $edits, string $summary): array
    {
        [$abs, $kind, $err] = self::filePath($rel);
        if ($err) {
            return [null, $err];
        }
        $old = is_file($abs) ? (string) file_get_contents($abs) : null;
        if ($content === null) {
            if ($old === null) {
                return [null, 'Tệp chưa tồn tại — muốn tạo mới thì gửi toàn bộ nội dung (content).'];
            }
            $content = $old;
            foreach ($edits as $i => $e) {
                $find = is_string($e['find'] ?? null) ? $e['find'] : '';
                $n = $find === '' ? 0 : substr_count($content, $find);
                if ($n !== 1) {
                    return [null, 'Sửa #' . ($i + 1) . ': đoạn "find" ' . ($n === 0 ? 'không có trong tệp' : "xuất hiện $n lần") . ' — phải khớp đúng một chỗ. Đọc lại tệp rồi thử lại.'];
                }
                if (isset($e['replace']) && !is_string($e['replace'])) {
                    return [null, 'Sửa #' . ($i + 1) . ': "replace" phải là chuỗi.'];
                }
                $content = str_replace($find, $e['replace'] ?? '', $content);
            }
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            return [null, 'Nội dung quá lớn (tối đa 300 KB).'];
        }
        if ($old === $content) {
            return [null, 'Nội dung mới giống hệt tệp hiện tại — không có gì để đổi.'];
        }
        if ($lint = ThemeFiles::lint($rel, $content)) {
            return [null, 'Nội dung mới có lỗi: ' . $lint['message'] . ' Sửa rồi đề xuất lại.'];
        }
        $p = [
            'id' => self::newId(),
            'type' => 'file',
            'kind' => $kind,
            'path' => trim(str_replace('\\', '/', $rel), '/ '),
            'summary' => mb_substr($summary, 0, 500),
            'old' => $old,
            'new' => $content,
            'status' => 'pending',
            'created' => time(),
        ];
        self::put($p);
        return [$p, null];
    }

    /** Áp dụng đề xuất tệp: tệp phải còn y như lúc đề xuất (không ai sửa xen giữa). */
    public static function applyFile(array $p): ?string
    {
        [$abs, , $err] = self::filePath($p['path']);
        if ($err) {
            return $err;
        }
        $current = is_file($abs) ? (string) file_get_contents($abs) : null;
        if ($current !== $p['old']) {
            return 'Tệp đã thay đổi kể từ lúc AI đề xuất — hãy nhờ AI đề xuất lại trên bản mới.';
        }
        if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
            return 'Không tạo được thư mục cho tệp.';
        }
        if ($current !== null) {
            self::backup($p['id'], $p['path'], $current);
        }
        return ThemeFiles::write($abs, $p['new']) ? null : 'Không ghi được tệp (kiểm tra quyền ghi thư mục).';
    }

    /** Hoàn tác đề xuất tệp đã áp dụng: trả bản cũ, hoặc xoá tệp nếu đề xuất đã tạo mới nó. */
    public static function undoFile(array $p): ?string
    {
        [$abs, , $err] = self::filePath($p['path']);
        if ($err) {
            return $err;
        }
        $current = is_file($abs) ? (string) file_get_contents($abs) : null;
        if ($current !== $p['new']) {
            return 'Tệp đã bị sửa tiếp sau khi áp dụng — không hoàn tác tự động được. Bản cũ vẫn còn trong storage/ai-backups/.';
        }
        if ($p['old'] === null) {
            return @unlink($abs) ? null : 'Không xoá được tệp.';
        }
        return ThemeFiles::write($abs, $p['old']) ? null : 'Không ghi được tệp.';
    }

    /* ---------- Việc của plugin (lq_add_ai_tool, write = true) ---------- */

    /** Đề xuất chạy một việc có thay đổi của plugin; chưa chạy gì cho tới khi người dùng bấm Chạy. */
    public static function proposeAction(array $tool, array $args): array
    {
        $p = [
            'id' => self::newId(),
            'type' => 'action',
            'tool' => $tool['name'],
            'label' => $tool['label'],
            'source' => $tool['source'],
            'summary' => mb_substr(trim((string) ($args['reason'] ?? '')) ?: $tool['description'], 0, 500),
            'args' => array_intersect_key($args, $tool['params']),
            'status' => 'pending',
            'created' => time(),
        ];
        self::put($p);
        return $p;
    }

    /** Chạy việc đã được đồng ý — kiểm lại công cụ còn và người dùng còn quyền (có thể đã tắt plugin / đổi tài khoản). */
    public static function applyAction(array &$p): ?string
    {
        $tool = PluginTools::get($p['tool']);
        if (!$tool) {
            return 'Việc này không còn (plugin đã tắt) hoặc bạn không có quyền.';
        }
        $r = PluginTools::call($tool, $p['args']);
        if (!$r['ok']) {
            return $r['text']; // thẻ giữ trạng thái chờ, hiện lỗi; người dùng có thể nhờ AI đề xuất lại
        }
        $p['result'] = $r['text'];
        return null;
    }

    /* ---------- Bài viết / trang ---------- */

    /**
     * Đề xuất tạo ($data['id'] rỗng) hoặc sửa bài/trang. $data: type (post|page), title, content (HTML), status (draft|publish|pending),
     * slug?, categories? (tên), tags? (tên), terms? {taxonomy tuỳ biến: [tên]}, fields? {key: giá trị}, parent_id?, template?.
     * Phần nào không gửi thì giữ nguyên khi sửa. Field được kiểm tra như form soạn thảo (PostFields::collectSome). Trả [đề xuất, lỗi].
     */
    public static function proposeContent(array $data, string $summary): array
    {
        // Trường đơn mà AI gửi dạng mảng/đối tượng: báo lại để AI sửa — ép kiểu thì thành tiêu đề "Array", id (int)[5] = 1 (sửa nhầm bài #1).
        foreach (['id', 'type', 'title', 'content', 'status', 'slug', 'parent_id', 'template'] as $k) {
            if (isset($data[$k]) && !is_scalar($data[$k])) {
                return [null, "\"$k\" phải là chuỗi/số, không phải mảng hay đối tượng."];
            }
        }
        $id = (int) ($data['id'] ?? 0);
        $existing = null;
        if ($id) {
            $existing = db()->row('SELECT * FROM {posts} WHERE id = ?', [$id]);
            if (!$existing || $existing['status'] === 'trash' || !Posts::canEdit($existing)) {
                return [null, "Không sửa được #$id (không tồn tại, đang trong thùng rác hoặc bạn không có quyền)."];
            }
        }
        $type = $existing['type'] ?? (($data['type'] ?? 'post') === 'page' ? 'page' : 'post');
        if (!$existing && !can(Posts::type($type)['cap'] ?? 'manage_options')) {
            return [null, 'Tài khoản của bạn không có quyền tạo ' . (Posts::type($type)['singular'] ?? $type) . '.'];
        }
        $title = trim((string) ($data['title'] ?? ($existing['title'] ?? '')));
        if ($title === '') {
            return [null, 'Cần có tiêu đề.'];
        }
        $template = (string) ($data['template'] ?? ($existing['template'] ?? ''));
        // Field: kiểm tra & làm sạch như khi lưu form (kiểu, lựa chọn, validate của theme/plugin…); field nhiều giá trị giữ dạng mảng.
        [$fieldErrors, $fields] = PostFields::collectSome(is_array($data['fields'] ?? null) ? $data['fields'] : [], $existing, $type, $type === 'page' ? $template : null);
        if ($fieldErrors) {
            return [null, 'Field không hợp lệ: ' . implode(' ', $fieldErrors) . ' (xem site_info để biết kiểu và lựa chọn của từng field).'];
        }
        // Taxonomy tuỳ biến (vd. actor): {key: [tên term]}; chỉ bài viết, chỉ taxonomy người dùng quản lý được.
        $terms = [];
        foreach ((array) ($data['terms'] ?? []) as $key => $names) {
            $tax = Taxonomies::get((string) $key);
            if (in_array($key, ['category', 'tag'], true)) {
                $data[$key === 'category' ? 'categories' : 'tags'] = (array) $names; // gửi nhầm vào terms vẫn nhận
                continue;
            }
            if ($type === 'page' || !$tax || !can($tax['cap'])) {
                return [null, "Không gán được taxonomy \"$key\" (không tồn tại, không dùng cho trang, hoặc bạn không có quyền)."];
            }
            $terms[$key] = array_values(array_filter(array_map(fn ($n) => trim((string) $n), array_filter((array) $names, 'is_scalar'))));
        }
        $snap = $existing ? self::snapshot($existing) : null;
        // Chỉ nhận tên dạng chuỗi/số: phần tử mảng lồng nhau thành thẻ "Array".
        $list = fn (string $k) => array_key_exists($k, $data) ? array_values(array_filter(array_map('strval', array_filter((array) $data[$k], 'is_scalar')))) : null;
        $new = [
            'type' => $type,
            'title' => mb_substr($title, 0, 500),
            'content' => (string) ($data['content'] ?? ($existing['content'] ?? '')),
            // Sửa bài có sẵn mà AI không gửi status: giữ trạng thái cũ (không thì bài đang đăng bị hạ thành nháp khi áp dụng).
            'status' => in_array($data['status'] ?? '', ['publish', 'pending', 'draft'], true) ? $data['status']
                : ($snap['status'] ?? 'draft'),
            'slug' => (string) ($data['slug'] ?? ($existing['slug'] ?? '')),
            'categories' => $list('categories'), // null = không đổi
            'tags' => $list('tags'),
            'terms' => $terms,
            'fields' => $fields,
            'parent_id' => (int) ($data['parent_id'] ?? ($existing['parent_id'] ?? 0)),
            'template' => $template,
        ];
        $p = [
            'id' => self::newId(),
            'type' => 'content',
            'post_id' => $id ?: null,
            'summary' => mb_substr($summary, 0, 500),
            'old' => $snap,
            'new' => $new,
            'status' => 'pending',
            'created' => time(),
        ];
        self::put($p);
        return [$p, null];
    }

    /** Áp dụng đề xuất bài/trang qua Posts::save (cùng đường lưu với form soạn thảo: kiểm tra quyền, slug, lịch sử phiên bản). */
    public static function applyContent(array &$p): ?string
    {
        $existing = $p['post_id'] ? db()->row('SELECT * FROM {posts} WHERE id = ?', [(int) $p['post_id']]) : null;
        if ($p['post_id'] && (!$existing || !Posts::canEdit($existing))) {
            return 'Bài/trang không còn hoặc bạn không có quyền sửa.';
        }
        if ($existing && $p['old'] && $existing['updated_at'] !== $p['old']['updated_at']) {
            return 'Bài/trang đã được sửa kể từ lúc AI đề xuất — hãy nhờ AI đề xuất lại.';
        }
        $err = self::save($p['new'], $existing, $savedId);
        if ($err) {
            return $err;
        }
        $p['post_id'] = $savedId;
        $p['applied_updated_at'] = (string) db()->value('SELECT updated_at FROM {posts} WHERE id = ?', [$savedId]);
        return null;
    }

    /** Hoàn tác: bài mới tạo → chuyển vào thùng rác; bài đã sửa → trả lại nội dung, trạng thái, phân loại, field cũ. */
    public static function undoContent(array $p): ?string
    {
        $post = db()->row('SELECT * FROM {posts} WHERE id = ?', [(int) $p['post_id']]);
        if (!$post || !Posts::canEdit($post)) {
            return 'Bài/trang không còn hoặc bạn không có quyền sửa.';
        }
        if ($post['updated_at'] !== ($p['applied_updated_at'] ?? '')) {
            return 'Bài/trang đã được sửa tiếp sau khi áp dụng — hãy dùng Lịch sử phiên bản trong trang soạn thảo.';
        }
        if (!$p['old']) {
            Posts::trash((int) $post['id']);
            return null;
        }
        return self::save($p['old'], $post, $id);
    }

    /** Bản chụp bài/trang hiện tại theo cùng dạng với 'new' của đề xuất (để so sánh và hoàn tác). */
    private static function snapshot(array $post): array
    {
        $id = (int) $post['id'];
        $fields = [];
        foreach (array_keys(PostFields::fields($post['type'])) as $k) {
            $fields[$k] = PostMeta::get($id, $k);
        }
        $terms = [];
        foreach ($post['type'] === 'post' ? array_keys(Taxonomies::custom()) : [] as $tax) {
            $terms[$tax] = array_column(Terms::forPost($id, $tax), 'name');
        }
        return [
            'type' => $post['type'],
            'title' => $post['title'],
            'content' => (string) $post['content'],
            'status' => match ($post['status']) { 'published', 'scheduled' => 'publish', 'pending' => 'pending', default => 'draft' },
            'slug' => $post['slug'],
            'categories' => array_column(Terms::forPost($id, 'category'), 'name'),
            'tags' => array_column(Terms::forPost($id, 'tag'), 'name'),
            'terms' => $terms,
            'fields' => $fields,
            'parent_id' => (int) ($post['parent_id'] ?? 0),
            'template' => (string) ($post['template'] ?? ''),
            'updated_at' => $post['updated_at'],
        ];
    }

    private static function save(array $d, ?array $existing, ?int &$savedId): ?string
    {
        $type = $d['type'];
        $in = [
            'title' => $d['title'],
            'content' => $d['content'],
            'slug' => $d['slug'],
            'action' => $d['status'] === 'publish' ? 'publish' : ($d['status'] === 'pending' ? 'pending' : 'draft'),
        ];
        if ($type === 'page') {
            $in += ['parent_id' => $d['parent_id'], 'template' => $d['template'], 'menu_order' => (int) ($existing['menu_order'] ?? 0)];
        } else {
            // Phân loại theo tên: tên chưa có thì tạo mới (chỉ khi người dùng đã đồng ý áp dụng). null/rỗng = giữ như cũ
            // (Posts::save luôn thay chuyên mục & thẻ, nên phải gửi lại giá trị hiện có để không bị xoá).
            $keep = fn (string $tax, string $col) => $existing ? array_column(Terms::forPost((int) $existing['id'], $tax), $col) : [];
            // Chuyên mục mới chỉ tạo khi có quyền quản lý chuyên mục (tác giả nhờ AI cũng chỉ gắn được chuyên mục đã có).
            $in['categories'] = $d['categories'] !== null ? Terms::ensureTerms('category', $d['categories'], can('manage_terms')) : $keep('category', 'id');
            $in['tags'] = implode(',', $d['tags'] ?? $keep('tag', 'name'));
            foreach ($d['terms'] ?? [] as $key => $names) {
                $tax = Taxonomies::get($key);
                if ($tax) {
                    $in['tax_input'][$key] = $tax['hierarchical'] ? Terms::ensureTerms($key, $names, can($tax['cap'])) : implode(',', $names);
                }
            }
        }
        [$errors, $savedId] = Posts::save($in, $existing, null, $type);
        if ($errors) {
            return reset($errors);
        }
        foreach ($d['fields'] as $k => $v) {
            PostFields::store($savedId, $k, $v);
        }
        Revisions::record($existing ?? [], db()->row('SELECT * FROM {posts} WHERE id = ?', [$savedId]), (int) current_user()['id']);
        return null;
    }

    private static function backup(string $id, string $rel, string $content): void
    {
        $path = LQ_ROOT . '/storage/ai-backups/' . date('Ymd-His') . "-$id/" . $rel;
        @mkdir(dirname($path), 0750, true);
        @file_put_contents($path, $content, LOCK_EX);
    }
}
