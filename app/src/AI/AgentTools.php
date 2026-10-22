<?php
declare(strict_types=1);

namespace LQ\AI;

use LQ\Content\PostFields;
use LQ\Content\PostMeta;
use LQ\Content\Posts;
use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Core\ErrorLog;
use LQ\Core\Plugins;
use LQ\Core\Themes;

/**
 * Công cụ của trợ lý chat trong admin: các công cụ đọc mã của CodeTools, cộng thêm
 * tổng quan site, nhật ký lỗi và nội dung (bài viết/trang/chuyên mục).
 * Hai công cụ propose_* chỉ TẠO ĐỀ XUẤT (ChatProposals) — người dùng bấm Áp dụng thì mới ghi.
 */
final class AgentTools
{
    public CodeTools $code;
    /** @var list<array> đề xuất tạo trong lượt chat này */
    public array $proposals = [];

    public function __construct()
    {
        $this->code = new CodeTools();
    }

    public function log(): array
    {
        return $this->code->log;
    }

    /** Công cụ can thiệp sâu (đọc mã, sửa tệp theme/plugin, nhật ký lỗi): chỉ quản trị viên. Việc nội dung thì ai vào admin cũng dùng được. */
    public const CODE_TOOLS = ['search_code', 'read_file', 'list_files', 'propose_file_change', 'read_error_log'];

    public static function codeAccess(): bool
    {
        return can('manage_options');
    }

    public static function definitions(): array
    {
        $all = self::allDefinitions();
        return self::codeAccess() ? $all : array_values(array_filter($all, fn ($d) => !in_array($d['name'], self::CODE_TOOLS, true)));
    }

    private static function allDefinitions(): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
        return [
            ...CodeTools::definitions(),
            [
                'name' => 'site_info',
                'description' => 'Tổng quan website: phiên bản, theme đang dùng, plugin, taxonomy, field tuỳ biến, mẫu trang.',
                'parameters' => $none,
            ],
            [
                'name' => 'read_error_log',
                'description' => 'Nhật ký lỗi PHP gần nhất của site (mức độ, thông báo, tệp, dòng, URL, số lần lặp).',
                'parameters' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'Số mục, mặc định 20.']], 'required' => []],
            ],
            [
                'name' => 'search_content',
                'description' => 'Tìm bài viết hoặc trang theo từ khoá (tiêu đề/nội dung/giá trị field) và/hoặc theo một field tuỳ biến. Trả id, loại, trạng thái, tiêu đề, link.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Từ khoá; bỏ trống = mới nhất.'],
                    'field' => ['type' => 'string', 'description' => 'Key field tuỳ biến để lọc (xem site_info).'],
                    'field_filter' => ['type' => 'string', 'description' => 'Với field: "missing" = chưa điền/trống/false (mặc định), "present" = đã điền, hoặc một chuỗi = giá trị field chứa chuỗi đó.'],
                    'type' => ['type' => 'string', 'description' => 'post, page, khoá loại nội dung tuỳ biến (xem site_info) hoặc any (mặc định any).'],
                    'limit' => ['type' => 'integer', 'description' => 'Tối đa 30, mặc định 10.'],
                ], 'required' => []],
            ],
            [
                'name' => 'get_content',
                'description' => 'Đọc đầy đủ một bài viết/trang theo id: tiêu đề, trạng thái, nội dung HTML, chuyên mục, thẻ, field tuỳ biến.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'ID bài/trang.']], 'required' => ['id']],
            ],
            [
                'name' => 'propose_file_change',
                'description' => 'Đề xuất sửa hoặc tạo một tệp trong theme/plugin (content/themes/<theme>/… hoặc content/plugins/<plugin>/…). '
                    . 'Sửa tệp có sẵn: dùng edits (mỗi find là đoạn nguyên văn đang có trong tệp, khớp đúng 1 chỗ, đủ dài để không trùng). '
                    . 'Tạo tệp mới hoặc viết lại hẳn: dùng content (toàn bộ nội dung). Đề xuất KHÔNG được áp dụng ngay — người dùng xem so sánh rồi bấm Áp dụng.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Đường dẫn từ gốc site, vd. content/themes/lq-default/single.php'],
                    'summary' => ['type' => 'string', 'description' => 'Một câu: sửa gì và vì sao.'],
                    'edits' => ['type' => 'array', 'description' => 'Các chỗ thay thế trong tệp có sẵn.', 'items' => ['type' => 'object', 'properties' => [
                        'find' => ['type' => 'string'], 'replace' => ['type' => 'string'],
                    ], 'required' => ['find', 'replace']]],
                    'content' => ['type' => 'string', 'description' => 'Toàn bộ nội dung tệp (tạo mới / viết lại).'],
                ], 'required' => ['path', 'summary']],
            ],
            [
                'name' => 'propose_content',
                'description' => 'Đề xuất tạo bài viết/trang mới (bỏ trống id) hoặc sửa bài/trang có sẵn (truyền id; trường nào không gửi thì giữ nguyên). '
                    . 'Nội dung là HTML (<p>, <h2>, <ul>…). Mặc định lưu nháp. Đề xuất KHÔNG được áp dụng ngay — người dùng bấm Áp dụng mới lưu.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'ID bài/trang cần sửa; bỏ trống để tạo mới.'],
                    'type' => ['type' => 'string', 'description' => 'post hoặc page (khi tạo mới).'],
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'Nội dung HTML.'],
                    'status' => ['type' => 'string', 'description' => 'draft (mặc định), publish hoặc pending.'],
                    'slug' => ['type' => 'string'],
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tên chuyên mục (bài viết). Không gửi = giữ nguyên.'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tên thẻ (bài viết). Không gửi = giữ nguyên.'],
                    'terms' => ['type' => 'object', 'description' => 'Taxonomy tuỳ biến của bài viết: {"<taxonomy>": ["tên term", …]}, vd. {"actor": ["Trấn Thành"]}. Term chưa có sẽ được tạo. Không gửi = giữ nguyên.'],
                    'fields' => ['type' => 'object', 'description' => 'Chỉ các field cần đổi (field khác giữ nguyên), theo kiểu ghi trong site_info: chuỗi; mảng cho field nhiều giá trị; true/false cho checkbox; KHOÁ lựa chọn cho select/radio. Giá trị được kiểm tra như form — sai sẽ báo lỗi để bạn sửa.'],
                    'parent_id' => ['type' => 'integer', 'description' => 'Trang cha (trang).'],
                    'template' => ['type' => 'string', 'description' => 'Mẫu trang (trang).'],
                    'summary' => ['type' => 'string', 'description' => 'Một câu: tạo/sửa gì.'],
                ], 'required' => ['summary']],
            ],
            [
                'name' => 'list_terms',
                'description' => 'Danh sách term của một taxonomy (category, tag hoặc taxonomy tuỳ biến) kèm số bài.',
                'parameters' => ['type' => 'object', 'properties' => ['taxonomy' => ['type' => 'string', 'description' => 'Vd. category, tag.']], 'required' => ['taxonomy']],
            ],
            ...PluginTools::definitions(), // việc plugin/theme khai báo bằng lq_add_ai_tool
        ];
    }

    public function run(string $name, array $args): string
    {
        // Model có thể gọi tên công cụ không có trong danh sách được cấp: chặn lại ở đây chứ không chỉ dựa vào definitions().
        if (in_array($name, self::CODE_TOOLS, true) && !self::codeAccess()) {
            return 'Chỉ quản trị viên mới dùng được công cụ này.';
        }
        if (in_array($name, ['search_code', 'read_file', 'list_files'], true)) {
            return $this->code->run($name, $args);
        }
        // Model gửi sai kiểu (mảng/đối tượng thay cho chuỗi/số): (string) ra "Array" kèm cảnh báo PHP rơi vào nhật ký lỗi, (int) ra 1.
        $str = fn (string $k, string $d = '') => is_scalar($args[$k] ?? null) ? (string) $args[$k] : $d;
        $int = fn (string $k, int $d) => is_scalar($args[$k] ?? null) ? (int) $args[$k] : $d;
        try {
            $out = match ($name) {
                'site_info' => $this->siteInfo(),
                'read_error_log' => $this->errors($int('limit', 20)),
                'search_content' => $this->search($str('query'), $str('type', 'any'), $int('limit', 10), $str('field'), $str('field_filter', 'missing')),
                'get_content' => $this->content($int('id', 0)),
                'list_terms' => $this->terms($str('taxonomy', 'category')),
                'propose_file_change' => $this->proposeFile($args),
                'propose_content' => $this->proposeContent($args),
                default => $this->pluginTool($name, $args),
            };
        } catch (\Throwable $e) {
            $out = 'Lỗi: ' . $e->getMessage();
        }
        $this->code->log[] = $name . ($args ? ' ' . mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE), 0, 80) : '');
        return mb_strcut(mb_scrub($out, 'UTF-8'), 0, 60000);
    }

    /** Việc plugin khai báo bằng lq_add_ai_tool: chỉ đọc thì chạy ngay; có thay đổi thì gửi đề xuất để người dùng bấm Chạy. */
    private function pluginTool(string $name, array $args): string
    {
        $tool = PluginTools::get($name);
        if (!$tool) {
            return "Không có công cụ \"$name\" (hoặc bạn không có quyền dùng).";
        }
        if (!$tool['write']) {
            return PluginTools::call($tool, $args)['text'];
        }
        if ($err = PluginTools::missing($tool, $args)) {
            return "Chưa gửi được đề xuất: $err";
        }
        $p = ChatProposals::proposeAction($tool, $args);
        $this->proposals[] = $p;
        return "Đã gửi đề xuất #{$p['id']} ({$tool['label']}) cho người dùng duyệt. Chưa chạy gì: nhắc người dùng bấm Chạy nếu đồng ý.";
    }

    private function proposeFile(array $a): string
    {
        $edits = array_values(array_filter((array) ($a['edits'] ?? []), 'is_array'));
        $content = isset($a['content']) && is_string($a['content']) ? $a['content'] : null;
        if ($content === null && !$edits) {
            return 'Lỗi: cần edits (sửa tệp có sẵn) hoặc content (toàn bộ nội dung).';
        }
        [$p, $err] = ChatProposals::proposeFile(is_string($a['path'] ?? null) ? $a['path'] : '', $content, $edits, is_scalar($a['summary'] ?? null) ? (string) $a['summary'] : '');
        if ($err) {
            return 'Không tạo được đề xuất: ' . $err;
        }
        $this->proposals[] = $p;
        return "Đã gửi đề xuất #{$p['id']} (" . ($p['old'] === null ? 'tạo tệp mới' : 'sửa tệp') . " {$p['path']}) cho người dùng duyệt. "
            . 'Chưa có gì được ghi: hãy nói ngắn gọn với người dùng đề xuất này làm gì và nhắc họ bấm Áp dụng nếu muốn thay đổi.';
    }

    private function proposeContent(array $a): string
    {
        $summary = is_scalar($a['summary'] ?? null) ? (string) $a['summary'] : '';
        unset($a['summary']);
        [$p, $err] = ChatProposals::proposeContent($a, $summary);
        if ($err) {
            return 'Không tạo được đề xuất: ' . $err;
        }
        $this->proposals[] = $p;
        return "Đã gửi đề xuất #{$p['id']} (" . ($p['post_id'] ? "sửa #{$p['post_id']}" : 'tạo ' . ($p['new']['type'] === 'page' ? 'trang' : 'bài viết') . ' mới') . " \"{$p['new']['title']}\") cho người dùng duyệt. "
            . 'Chưa có gì được lưu: hãy nói ngắn gọn với người dùng và nhắc họ bấm Áp dụng nếu muốn thay đổi.';
    }

    private function siteInfo(): string
    {
        $plugins = array_map(fn ($p) => $p['slug'] . ($p['active'] ? ' (bật)' : ' (tắt)'), Plugins::all());
        // Mỗi field ghi đủ để AI gửi đúng dạng giá trị: kiểu, nhiều giá trị (mảng), lựa chọn hợp lệ (gửi KHOÁ), bắt buộc, mẫu trang.
        $fields = [];
        foreach (array_keys(Posts::types()) as $t) {
            foreach (PostFields::fields($t) as $k => $f) {
                $fields[$k] ??= '  - ' . $k . ' "' . $f['label'] . '": ' . $f['type']
                    . ($f['repeat'] ? ", nhiều giá trị (gửi mảng, tối đa {$f['max_items']})" : '')
                    . ($f['options'] ? ', lựa chọn ' . json_encode(array_map('strval', array_keys($f['options'])), JSON_UNESCAPED_UNICODE) : '')
                    . ($f['type'] === 'checkbox' ? ' (true/false)' : '')
                    . ($f['required'] ? ', bắt buộc' : '')
                    . ' · dùng cho ' . implode('/', $f['post_type'])
                    . ($f['template'] ? ' mẫu ' . implode('/', $f['template']) : '')
                    . ($f['help'] !== '' ? ' — ' . $f['help'] : '');
            }
        }
        return implode("\n", [
            'Tên site: ' . option('site_title', '') . ' · URL: ' . site_url('/'),
            'LQCMS ' . LQ_VERSION . ' · PHP ' . PHP_VERSION,
            'Theme đang dùng: ' . Themes::active() . ' (thư mục content/themes/' . Themes::active() . '/)',
            'Plugin: ' . (implode(', ', $plugins) ?: '(không có)'),
            'Loại nội dung: ' . implode(', ', array_map(fn ($k, $t) => "$k ({$t['label']})", array_keys(Posts::types()), Posts::types())),
            'Taxonomy: ' . implode(', ', array_map(fn ($k, $t) => "$k ({$t['label']})", array_keys(Taxonomies::all()), Taxonomies::all())),
            "Field tuỳ biến (đọc/ghi qua get_content, propose_content.fields, lọc bằng search_content.field):\n" . (implode("\n", $fields) ?: '  (không có)'),
            'Mẫu trang: ' . (implode(', ', array_map(fn ($k, $t) => "$k ({$t['label']})", array_keys(\LQ\Content\Pages::templates()), \LQ\Content\Pages::templates())) ?: '(không có)'),
            'Cấu trúc permalink: ' . option('permalink_structure', ''),
        ]);
    }

    private function errors(int $limit): string
    {
        $rows = ErrorLog::recent(max(1, min(50, $limit)));
        if (!$rows) {
            return 'Nhật ký lỗi trống — chưa ghi nhận lỗi PHP nào.';
        }
        return implode("\n", array_map(fn ($e) => "[{$e['t']} UTC] {$e['level']} ×{$e['count']}: {$e['msg']} — {$e['file']}:{$e['line']} (URL: {$e['url']})", $rows));
    }

    private function search(string $query, string $type, int $limit, string $field = '', string $filter = 'missing'): string
    {
        $types = $type !== 'any' && Posts::type($type) ? [$type] : array_keys(Posts::types());
        if ($field !== '') {
            // Chỉ xét loại nội dung có field này — trang không có field "subtitle" của bài viết thì không tính là "thiếu".
            $types = array_values(array_filter($types, fn ($t) => isset(PostFields::fields($t)[$field])));
            if (!$types) {
                return "Không có field \"$field\" cho loại nội dung này (xem site_info).";
            }
        }
        $limit = max(1, min(30, $limit));
        $where = "type IN ('" . implode("','", $types) . "') AND status <> 'trash'";
        $params = [];
        if (trim($query) !== '') {
            // Giá trị field lưu dạng JSON ("…", […]) — LIKE vẫn khớp được chữ bên trong.
            $where .= ' AND (title LIKE ? OR content LIKE ? OR EXISTS (SELECT 1 FROM {post_meta} m WHERE m.post_id = {posts}.id AND m.meta_key NOT LIKE ? AND m.meta_value LIKE ?))';
            $like = '%' . addcslashes(trim($query), '%_\\') . '%';
            $params = [$like, $like, '\\_%', $like];
        }
        if ($field !== '') {
            $filled = "EXISTS (SELECT 1 FROM {post_meta} m WHERE m.post_id = {posts}.id AND m.meta_key = ? AND m.meta_value NOT IN ('\"\"', '[]', 'null', 'false', '{}'))";
            if ($filter === 'missing' || $filter === '') {
                $where .= " AND NOT $filled";
                $params[] = $field;
            } elseif ($filter === 'present') {
                $where .= " AND $filled";
                $params[] = $field;
            } else {
                $where .= ' AND EXISTS (SELECT 1 FROM {post_meta} m WHERE m.post_id = {posts}.id AND m.meta_key = ? AND m.meta_value LIKE ?)';
                array_push($params, $field, '%' . addcslashes($filter, '%_\\') . '%');
            }
        }
        // Bài chưa đăng (nháp, chờ duyệt, hẹn giờ) của người khác: chỉ hiện khi người đang chat sửa được — như get_content.
        // Vai trò thấp (author, subscriber) cũng mở được khung chat.
        $rows = array_slice(array_values(array_filter(
            db()->all("SELECT * FROM {posts} WHERE $where ORDER BY updated_at DESC LIMIT " . ($limit * 3), $params),
            fn ($p) => $p['status'] === 'published' || Posts::canEdit($p),
        )), 0, $limit);
        if (!$rows) {
            return 'Không tìm thấy bài/trang nào.';
        }
        return implode("\n", array_map(fn ($p) => "#{$p['id']} [{$p['type']}, {$p['status']}] {$p['title']} — " . Posts::permalink($p) . ($p['featured_image'] ? '' : ' · chưa có ảnh đại diện'), $rows));
    }

    private function content(int $id): string
    {
        $p = db()->row('SELECT * FROM {posts} WHERE id = ?', [$id]);
        if (!$p || !Posts::canEdit($p)) {
            return "Không có bài/trang #$id (hoặc bạn không có quyền xem).";
        }
        $terms = [];
        foreach (array_keys(Taxonomies::all()) as $tax) {
            if ($ts = Terms::forPost($id, $tax)) {
                $terms[] = "$tax: " . implode(', ', array_column($ts, 'name'));
            }
        }
        $meta = PostMeta::all($id);
        return implode("\n", [
            "#{$p['id']} [{$p['type']}, {$p['status']}] {$p['title']}",
            'Slug: ' . $p['slug'] . ' · Link: ' . Posts::permalink($p) . ' · Sửa: ' . (Posts::editUrl($p['type'], (int) $p['id']) ?: '(loại nội dung không còn đăng ký)'),
            'Cập nhật: ' . format_date($p['updated_at']) . ($p['published_at'] ? ' · Ngày đăng: ' . format_date($p['published_at']) : ''),
            'Ảnh đại diện: ' . ($p['featured_image'] ? $p['featured_image'] : '(chưa có)'),
            $terms ? 'Phân loại: ' . implode(' · ', $terms) : 'Phân loại: (không có)',
            $meta ? 'Field: ' . json_encode($meta, JSON_UNESCAPED_UNICODE) : 'Field: (không có)',
            "Nội dung HTML:\n" . mb_substr((string) $p['content'], 0, 20000),
        ]);
    }

    private function terms(string $taxonomy): string
    {
        if (!Taxonomies::get($taxonomy)) {
            return "Không có taxonomy \"$taxonomy\".";
        }
        $rows = get_terms($taxonomy, ['hide_empty' => false]);
        return $rows ? implode("\n", array_map(fn ($t) => "#{$t['id']} {$t['name']} (slug {$t['slug']}, {$t['count']} bài)", $rows)) : '(chưa có term nào)';
    }
}
