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
        db()->query(
            'INSERT INTO {options} (name, value, autoload) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), autoload = VALUES(autoload)',
            [$name, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $autoload]
        );
        self::$cache[$name] = $value;
    }

    public static function delete(string $name): void
    {
        db()->delete('options', ['name' => $name]);
        unset(self::$cache[$name]);
    }
}
