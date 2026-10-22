<?php
declare(strict_types=1);

namespace LQ\Content;

use InvalidArgumentException;
use LQ\Core\Auth;
use Throwable;

/**
 * Field tuỳ biến cho form bài viết, do theme/plugin khai báo:
 *   lq_add_post_field('gdrive_link', ['label' => 'Link Google Drive', 'type' => 'url', 'box' => 'Tải về']);
 * Hệ thống tự vẽ ô nhập, kiểm tra, làm sạch và lưu vào post_meta.
 *
 * Khung tự vẽ (meta box) cho trường hợp phức tạp: lq_add_meta_box($id, $title, $render, ['save' => fn ($postId, $input) => ...]).
 */
final class PostFields
{
    public const TYPES = ['text', 'textarea', 'number', 'url', 'email', 'select', 'radio', 'checkbox', 'date', 'color', 'image'];
    private const REPEATABLE = ['text', 'number', 'url', 'email', 'date', 'image'];

    /** @var array<string, array> */
    private static array $fields = [];
    /** @var array<string, array> */
    private static array $boxes = [];

    public static function register(string $key, array $args): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key)) {
            throw new InvalidArgumentException("Tên field không hợp lệ: \"$key\" (chữ thường, số, gạch dưới).");
        }
        $type = (string) ($args['type'] ?? 'text');
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Kiểu field \"$type\" không được hỗ trợ. Dùng: " . implode(', ', self::TYPES));
        }
        if (!empty($args['repeat']) && !in_array($type, self::REPEATABLE, true)) {
            throw new InvalidArgumentException("Kiểu \"$type\" không hỗ trợ nhập nhiều giá trị (repeat).");
        }
        if (in_array($type, ['select', 'radio'], true) && empty($args['options'])) {
            throw new InvalidArgumentException("Field $key kiểu $type cần 'options' => ['giá trị' => 'Nhãn'].");
        }
        $box = (string) ($args['box'] ?? 'Thông tin thêm');
        self::$fields[$key] = [
            'key' => $key,
            'type' => $type,
            'label' => (string) ($args['label'] ?? $key),
            'box' => $box,
            'box_id' => 'box-' . (\LQ\Core\Slug::make($box) ?: 'thong-tin-them'),
            'context' => ($args['context'] ?? 'main') === 'side' ? 'side' : 'main',
            'help' => (string) ($args['help'] ?? ''),
            'placeholder' => (string) ($args['placeholder'] ?? ''),
            'default' => $args['default'] ?? null,
            'options' => (array) ($args['options'] ?? []),
            'required' => (bool) ($args['required'] ?? false),
            'min' => $args['min'] ?? null,
            'max' => $args['max'] ?? null,
            'step' => $args['step'] ?? null,
            'repeat' => (bool) ($args['repeat'] ?? false),
            'max_items' => (int) ($args['max_items'] ?? 20),
            'add_label' => (string) ($args['add_label'] ?? 'Thêm dòng'),
            'cap' => (string) ($args['cap'] ?? 'edit_posts'),
            'priority' => (int) ($args['priority'] ?? 10),
            'width' => in_array($args['width'] ?? 'full', ['full', 'half'], true) ? ($args['width'] ?? 'full') : 'full',
            'post_type' => array_values((array) ($args['post_type'] ?? ['post'])), // 'post', 'page' hoặc cả hai
            'template' => isset($args['template']) ? array_values((array) $args['template']) : null, // chỉ hiện với mẫu trang này
            'validate' => $args['validate'] ?? null,   // fn ($value, ?array $post): ?string  — trả thông báo lỗi
            'sanitize' => $args['sanitize'] ?? null,   // fn ($value): mixed
            // Trợ lý AI điền gợi ý vào field có vai trò này: 'sapo' (đoạn dẫn) hoặc 'seo' (mô tả cho công cụ tìm kiếm).
            'ai_role' => in_array($args['ai_role'] ?? null, ['sapo', 'seo'], true) ? $args['ai_role'] : null,
        ];
    }

    public static function addBox(string $id, string $title, callable $render, array $opts = []): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            throw new InvalidArgumentException("ID meta box không hợp lệ: $id");
        }
        self::$boxes[$id] = [
            'id' => $id,
            'title' => $title,
            'render' => $render,
            'save' => $opts['save'] ?? null,
            'context' => ($opts['context'] ?? 'main') === 'side' ? 'side' : 'main',
            'cap' => (string) ($opts['cap'] ?? 'edit_posts'),
            'priority' => (int) ($opts['priority'] ?? 10),
            'description' => (string) ($opts['description'] ?? ''),
            'post_type' => array_values((array) ($opts['post_type'] ?? ['post'])),
        ];
    }

    /** Field người dùng hiện tại được sửa, cho một kiểu nội dung. */
    public static function fields(string $type = 'post'): array
    {
        return array_filter(self::$fields, fn ($f) => Auth::can($f['cap']) && in_array($type, $f['post_type'], true));
    }

    /** Field có áp dụng cho mẫu trang đang chọn không (field không gắn mẫu thì luôn áp dụng). */
    private static function matchesTemplate(array $f, ?string $template): bool
    {
        return $f['template'] === null || in_array((string) $template, $f['template'], true);
    }

    /**
     * Khung hiển thị theo vị trí: ['main' => [...], 'side' => [...]], mỗi khung có 'fields' hoặc 'render'.
     */
    public static function layout(string $type = 'post'): array
    {
        $out = ['main' => [], 'side' => []];
        foreach (self::fields($type) as $f) {
            // Field của mẫu trang: gom theo mẫu để cả khung ẩn/hiện khi đổi mẫu.
            $tpl = $f['template'] ? implode(' ', $f['template']) : '';
            $id = $f['box_id'] . '-' . $f['context'] . ($tpl !== '' ? '-' . \LQ\Core\Slug::make($tpl) : '');
            $out[$f['context']][$id] ??= ['id' => $id, 'title' => $f['box'], 'fields' => [], 'priority' => $f['priority'], 'render' => null, 'description' => '', 'template' => $tpl];
            $out[$f['context']][$id]['fields'][] = $f;
            $out[$f['context']][$id]['priority'] = min($out[$f['context']][$id]['priority'], $f['priority']);
        }
        foreach (self::$boxes as $b) {
            if (Auth::can($b['cap']) && in_array($type, $b['post_type'], true)) {
                $out[$b['context']]['mb-' . $b['id']] = $b + ['fields' => [], 'template' => ''];
            }
        }
        foreach ($out as &$list) {
            foreach ($list as &$box) {
                usort($box['fields'], fn ($a, $b) => $a['priority'] <=> $b['priority']);
            }
            uasort($list, fn ($a, $b) => $a['priority'] <=> $b['priority']);
        }
        return $out;
    }

    /** Giá trị hiện tại để đổ vào form (ưu tiên dữ liệu vừa nhập khi validate lỗi). */
    public static function value(array $field, ?array $post, ?array $old): mixed
    {
        if (is_array($old)) {
            if (array_key_exists($field['key'], $old)) {
                return $old[$field['key']];
            }
            // Checkbox bỏ chọn thì trình duyệt không gửi gì: form vừa gửi lại (lỗi/xung đột) phải hiện đúng là đã bỏ chọn,
            // không lấy giá trị đã lưu — nếu không người dùng lưu lần nữa sẽ bật lại nó mà không hay.
            if ($field['type'] === 'checkbox') {
                return false;
            }
        }
        if ($post) {
            return PostMeta::get((int) $post['id'], $field['key'], $field['default']);
        }
        return $field['default'];
    }

    /**
     * Kiểm tra và làm sạch dữ liệu từ form.
     * @return array{0: array<string,string>, 1: array<string,mixed>} [lỗi theo "meta.key", giá trị đã làm sạch]
     */
    public static function collect(array $input, ?array $post, string $type = 'post', ?string $template = null): array
    {
        $errors = [];
        $values = [];
        foreach (self::fields($type) as $key => $f) {
            if (!self::matchesTemplate($f, $template)) {
                continue; // field của mẫu khác: bỏ qua, giữ nguyên dữ liệu cũ
            }
            $raw = $input[$key] ?? null;
            if ($f['repeat']) {
                $items = array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) $raw), fn ($v) => $v !== '' && $v !== null));
                if (count($items) > $f['max_items']) {
                    $errors["meta.$key"] = "{$f['label']}: tối đa {$f['max_items']} giá trị.";
                    continue;
                }
                $clean = [];
                foreach ($items as $i => $item) {
                    [$v, $err] = self::clean($f, $item);
                    if ($err) {
                        $errors["meta.$key"] = "{$f['label']} (dòng " . ($i + 1) . "): $err";
                        break;
                    }
                    $clean[] = $v;
                }
                $value = $clean;
                $empty = !$clean;
            } else {
                [$value, $err] = self::clean($f, $raw);
                if ($err) {
                    $errors["meta.$key"] = "{$f['label']}: $err";
                    continue;
                }
                $empty = $value === null || $value === '' || $value === false;
            }
            if (isset($errors["meta.$key"])) {
                continue;
            }
            if ($f['required'] && $empty) {
                $errors["meta.$key"] = "{$f['label']} là bắt buộc.";
                continue;
            }
            if (is_callable($f['sanitize'])) {
                $value = ($f['sanitize'])($value);
            }
            $custom = null;
            if (is_callable($f['validate']) && !$empty) {
                try {
                    $custom = ($f['validate'])($value, $post);
                } catch (Throwable $e) {
                    $custom = $e->getMessage();
                }
            }
            $custom = apply_filters('post_field_validate', $custom, $key, $value, $post);
            if (is_string($custom) && $custom !== '') {
                $errors["meta.$key"] = $custom;
                continue;
            }
            $values[$key] = $value;
        }
        return [$errors, $values];
    }

    /**
     * Kiểm tra & làm sạch MỘT PHẦN field (trợ lý AI, API…) bằng đúng quy trình của form: kiểu, lựa chọn, bắt buộc,
     * sanitize/validate của theme/plugin, filter post_field_validate. Field không gửi giữ nguyên — giá trị hiện có
     * của bài chỉ được đưa vào để collect() chạy đủ, không trả về và không báo lỗi thay cho chúng.
     * @param array<string, mixed> $given {key: giá trị thô — mảng cho field repeat, true/false cho checkbox, khoá lựa chọn cho select/radio}
     * @return array{0: list<string>, 1: array<string, mixed>} [lỗi, giá trị đã làm sạch của các field trong $given]
     */
    public static function collectSome(array $given, ?array $post, string $type, ?string $template = null): array
    {
        $all = self::fields($type);
        $errors = [];
        foreach (array_keys($given) as $k) {
            if (!isset($all[$k])) {
                $errors[] = "Field \"$k\" chưa được đăng ký cho loại nội dung này (hoặc bạn không có quyền sửa).";
            } elseif (!self::matchesTemplate($all[$k], $template)) {
                $errors[] = "Field \"$k\" chỉ dùng cho mẫu trang: " . implode(', ', $all[$k]['template']) . '.';
            }
        }
        if ($errors) {
            return [$errors, []];
        }
        $current = $post ? PostMeta::all((int) $post['id']) : [];
        [$errs, $values] = self::collect($given + $current, $post, $type, $template);
        $mine = array_flip(array_map(fn ($k) => "meta.$k", array_keys($given)));
        return [array_values(array_intersect_key($errs, $mine)), array_intersect_key($values, $given)];
    }

    /** Làm sạch một giá trị theo kiểu. @return array{0: mixed, 1: ?string} */
    private static function clean(array $f, mixed $raw): array
    {
        if ($f['type'] === 'checkbox') {
            return [!empty($raw), null];
        }
        if (is_array($raw)) {
            return [null, 'giá trị không hợp lệ.'];
        }
        $v = trim((string) ($raw ?? ''));
        if ($v === '') {
            return ['', null];
        }
        switch ($f['type']) {
            case 'text':
                $max = (int) ($f['max'] ?? 500);
                return mb_strlen($v) > $max ? [null, "tối đa $max ký tự."] : [$v, null];
            case 'textarea':
                $max = (int) ($f['max'] ?? 10000);
                return mb_strlen($v) > $max ? [null, "tối đa $max ký tự."] : [$v, null];
            case 'number':
                if (!is_numeric($v)) {
                    return [null, 'phải là số.'];
                }
                $n = str_contains($v, '.') || ($f['step'] !== null && (float) $f['step'] != (int) $f['step']) ? (float) $v : (int) $v;
                if ($f['min'] !== null && $n < $f['min']) {
                    return [null, "nhỏ nhất là {$f['min']}."];
                }
                if ($f['max'] !== null && $n > $f['max']) {
                    return [null, "lớn nhất là {$f['max']}."];
                }
                return [$n, null];
            case 'url':
                return filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v)
                    ? [$v, null] : [null, 'đường dẫn phải bắt đầu bằng http:// hoặc https://'];
            case 'email':
                return filter_var($v, FILTER_VALIDATE_EMAIL) ? [mb_strtolower($v), null] : [null, 'email không hợp lệ.'];
            case 'select':
            case 'radio':
                return array_key_exists($v, $f['options']) ? [$v, null] : [null, 'lựa chọn không hợp lệ.'];
            case 'date':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                return $d && $d->format('Y-m-d') === $v ? [$v, null] : [null, 'ngày không hợp lệ.'];
            case 'color':
                return preg_match('/^#[0-9a-f]{6}$/i', $v) ? [strtolower($v), null] : [null, 'màu phải có dạng #rrggbb.'];
            case 'image':
                return preg_match('#^(/content/uploads/[\w./-]+|https?://\S+)$#', $v) ? [$v, null] : [null, 'ảnh không hợp lệ.'];
        }
        return [$v, null];
    }

    /**
     * Ghi một field. Field có 'default' mà giá trị rỗng: lưu hẳn giá trị rỗng — xoá dòng thì lần mở form sau lại hiện
     * giá trị mặc định (value()) và lần lưu kế tiếp ghi nó vào, tức là không bao giờ xoá trắng được.
     */
    public static function store(int $postId, string $key, mixed $value): void
    {
        $default = self::$fields[$key]['default'] ?? null;
        PostMeta::set($postId, $key, $value, $default !== null && $default !== '' && $default !== []);
    }

    /** Lưu giá trị đã làm sạch và gọi hàm lưu của các meta box tự vẽ. */
    public static function save(int $postId, array $values, array $input, string $type = 'post'): void
    {
        foreach ($values as $key => $value) {
            self::store($postId, $key, $value);
        }
        foreach (self::$boxes as $b) {
            if (is_callable($b['save']) && Auth::can($b['cap']) && in_array($type, $b['post_type'], true)) {
                ($b['save'])($postId, $input);
            }
        }
        do_action('post_meta_saved', $postId, $values);
    }
}
