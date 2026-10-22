<?php
declare(strict_types=1);

namespace LQ\Core;

/** Bảng options dạng key/value, giá trị lưu JSON để giữ nguyên kiểu (int, bool, mảng). */
final class Options
{
    private static array $cache = [];

    public static function load(): void
    {
        foreach (db()->all('SELECT name, value FROM {options} WHERE autoload = 1') as $r) {
            self::$cache[$r['name']] = json_decode($r['value'], true);
        }
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, self::$cache)) {
            $v = db()->value('SELECT value FROM {options} WHERE name = ?', [$name]);
            self::$cache[$name] = $v === null ? null : json_decode($v, true);
        }
        return self::$cache[$name] ?? $default;
    }

    public static function set(string $name, mixed $value, bool $autoload = true): void
    {
        // INVALID_UTF8_SUBSTITUTE: chuỗi UTF-8 hỏng làm json_encode trả false → lưu chuỗi rỗng, mất cả giá trị (vd. tên website).
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        db()->query(
            'INSERT INTO {options} (name, value, autoload) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), autoload = VALUES(autoload)',
            [$name, $json, (int) $autoload]
        );
        // Cache đúng giá trị request sau sẽ đọc lại (object → mảng…), không phải giá trị gốc truyền vào.
        self::$cache[$name] = is_string($json) ? json_decode($json, true) : null;
    }

    public static function delete(string $name): void
    {
        db()->delete('options', ['name' => $name]);
        unset(self::$cache[$name]);
    }
}
