<?php
declare(strict_types=1);

namespace LQ\Core;

/** Hệ thống action/filter kiểu WordPress. Số nhỏ chạy trước. */
final class Hooks
{
    /** @var array<string, array<int, callable[]>> */
    private static array $hooks = [];

    public static function add(string $name, callable $callback, int $priority = 10): void
    {
        self::$hooks[$name][$priority][] = $callback;
        ksort(self::$hooks[$name]);
    }

    public static function has(string $name): bool
    {
        return !empty(self::$hooks[$name]);
    }

    public static function run(string $name, mixed ...$args): void
    {
        foreach (self::$hooks[$name] ?? [] as $callbacks) {
            foreach ($callbacks as $cb) {
                $cb(...$args);
            }
        }
    }

    public static function apply(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::$hooks[$name] ?? [] as $callbacks) {
            foreach ($callbacks as $cb) {
                $value = $cb($value, ...$args);
            }
        }
        return $value;
    }
}
