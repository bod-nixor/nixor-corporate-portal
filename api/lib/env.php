<?php
function env_value($key, $default = null) {
    static $env;
    if ($env === null) {
        $env = [];
        $root = dirname(__DIR__, 2);
        $defaultPath = $root . '/.env';
        $configPath = $root . '/config/.env';

        // Prioritize $_SERVER/$_ENV because they contain the variables passed explicitly via proc_open/php-S
        $path = $_SERVER['ENV_FILE_PATH'] ?? $_ENV['ENV_FILE_PATH'] ?? getenv('ENV_FILE_PATH')
            ?: (file_exists($configPath) ? $configPath : $defaultPath);

        // Handle relative paths by resolving against project root
        if ($path && !str_starts_with($path, '/')) {
            $path = $root . '/' . $path;
        }

        if (file_exists($path)) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                error_log("Warning: Failed to read .env file at {$path}");
                $lines = [];
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $k = trim($parts[0]);
                $v = trim($parts[1]);
                if (($pos = strpos($v, ' #')) !== false) {
                    $v = trim(substr($v, 0, $pos));
                }
                if ((str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                    (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                    $v = substr($v, 1, -1);
                }
                $env[$k] = $v;
            }
        }
    }
    return $env[$key] ?? $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?? $default;
}
