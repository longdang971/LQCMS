<?php
declare(strict_types=1);

namespace LQ\Core;

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'admin/layout'): void
    {
        $content = self::capture($view, $data);
        echo $layout ? self::capture($layout, $data + ['content' => $content]) : $content;
    }

    public static function capture(string $view, array $data = []): string
    {
        $__file = LQ_APP . '/views/' . $view . '.php';
        extract($data, EXTR_SKIP);
        ob_start();
        require $__file;
        return (string) ob_get_clean();
    }
}
