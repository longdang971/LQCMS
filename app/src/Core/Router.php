<?php
declare(strict_types=1);

namespace LQ\Core;

final class Router
{
    private array $routes = [];
    private static array $patterns = [];

    /** Khai báo regex cho tham số {name}, vd. pattern('tax', 'categories|tags|dien-vien'). */
    public function pattern(string $name, string $regex): void
    {
        self::$patterns[$name] = $regex;
    }

    public function get(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /** {id} khớp số, {slug} khớp chuỗi slug, {tax} khớp category|tag. */
    private static function compile(string $pattern): string
    {
        $re = preg_replace_callback('/\{(\w+)\}/', static fn ($m) => match ($m[1]) {
            'id' => '(?<id>\d+)',
            'tax' => '(?<tax>' . (self::$patterns['tax'] ?? 'categories|tags') . ')',
            default => '(?<' . $m[1] . '>[a-z0-9_-]+)',
        }, $pattern);
        return '#^' . $re . '$#';
    }

    /** Trả về [handler, params] hoặc null; 'method_not_allowed' khi path khớp nhưng sai method. */
    public function match(string $method, string $path): array|string|null
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $pattern, $handler]) {
            if (!preg_match(self::compile($pattern), $path, $params)) {
                continue;
            }
            $pathMatched = true;
            if ($m === $method) {
                return [$handler, array_filter($params, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }
        return $pathMatched ? 'method_not_allowed' : null;
    }
}
