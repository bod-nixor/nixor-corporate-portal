<?php
function env_value($key, $default = null) {
    static $env;
    if ($env === null) {
        $env = [];
        $path = dirname(__DIR__, 2) . '/.env';
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, null);
                $env[trim($k)] = trim($v ?? '');
            }
        }
    }
    return $env[$key] ?? getenv($key) ?? $default;
}
