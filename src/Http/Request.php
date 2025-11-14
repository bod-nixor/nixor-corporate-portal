<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $headers;
    public mixed $body;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $this->query = $_GET ?? [];
        $this->headers = $this->parseHeaders();
        $this->body = $this->parseBody();
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    private function parseBody(): mixed
    {
        $input = file_get_contents('php://input');
        $type = $this->headers['Content-Type'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            return json_decode($input ?: 'null', true, 512, JSON_THROW_ON_ERROR);
        }
        return $_POST ?: $input;
    }
}
