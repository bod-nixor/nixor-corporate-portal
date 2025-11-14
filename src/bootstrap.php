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

Env::load(__DIR__ . '/../.env');
Session::start();
