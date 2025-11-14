<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use App\Lib\Response;

final class Router
{
    /** @var array<string, array<string, Closure>> */
    private array $routes = [];

    public function add(string $method, string $pattern, Closure $handler): void
    {
        $method = strtoupper($method);
        $this->routes[$method][$pattern] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $method = strtoupper($request->method);
        $path = $request->path;

        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $regex = '#^' . preg_replace('#\{([^/]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, '\is_string', ARRAY_FILTER_USE_KEY);
                $handler($request, $params);
                return;
            }
        }

        Response::json(['message' => 'Not Found'], 404);
    }
}
