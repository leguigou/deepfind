<?php

declare(strict_types=1);

namespace DeepFind\Http;

use DeepFind\Text;

final class Response
{
    public static function json(array $data, int $status = 200, array $headers = []): void
    {
        $body = Text::json($data);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    public static function noContent(array $headers = []): void
    {
        http_response_code(204);
        foreach ($headers as $k => $v) {
            header($k . ': ' . $v);
        }
    }

    /** Termine la réponse HTTP côté client et poursuit le traitement (journaux) en arrière-plan. */
    public static function finish(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    public static function redirect(string $url, int $status = 302): void
    {
        header('Location: ' . $url, true, $status);
    }
}
