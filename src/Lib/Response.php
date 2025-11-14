<?php

declare(strict_types=1);

namespace App\Lib;

final class Response
{
    public static function json(array $body, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        foreach ($headers as $key => $value) {
            header(sprintf('%s: %s', $key, $value));
        }
        echo json_encode($body);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }
}
