<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('App\\', '', $class) . '.php';
    $path = str_replace('\\', '/', $path);
    if (file_exists($path)) {
        require_once $path;
    }
});

use App\Lib\Env;
use App\Lib\Session;
use App\Lib\Logger;

Env::load(__DIR__ . '/../.env');
Session::start();
Logger::init(__DIR__ . '/../logs/app.log');
Logger::info('Incoming request', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'cli',
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);

set_error_handler(function (int $severity, string $message, string $file, int $line): void {
    Logger::error('PHP error', [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ]);
});

set_exception_handler(function (\Throwable $exception): void {
    Logger::exception($exception);
    http_response_code(500);
    echo json_encode(['message' => 'Internal server error']);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error) {
        Logger::error('Shutdown error', $error);
    }
});
