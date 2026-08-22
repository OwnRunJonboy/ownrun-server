<?php

declare(strict_types=1);

namespace OwnRun;

final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(string $message, int $status = 400, ?array $extra = null): void
    {
        $body = ['error' => $message];
        if ($extra !== null) {
            $body = array_merge($body, $extra);
        }
        self::json($body, $status);
    }
}
