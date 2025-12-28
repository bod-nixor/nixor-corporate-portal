<?php
function env_value($key, $default = null) {
    static $env;
    if ($env === null) {
        $env = [];
        $path = dirname(__DIR__, 2) . '/.env';
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
    return $env[$key] ?? getenv($key) ?? $default;
}
