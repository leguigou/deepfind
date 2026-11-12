<?php

declare(strict_types=1);

namespace DeepFind\Admin;

final class View
{
    public static function render(string $template, array $vars = [], string $title = '', bool $layout = true): void
    {
        $file = APP_ROOT . '/templates/admin/' . $template . '.php';
        $vars['title'] = $title;
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        $content = (string) ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        if (!$layout) {
            echo $content;
            return;
        }
        $flash = Auth::takeFlash();
        $active = $vars['active'] ?? '';
        require APP_ROOT . '/templates/admin/_layout.php';
    }
}
