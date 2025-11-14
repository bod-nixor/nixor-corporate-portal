<?php

declare(strict_types=1);

namespace App\Lib;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $path = __DIR__ . '/../../.env'): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2) + ['', '']);
            if ($key === '') {
                continue;
            }
            $value = trim($value, "\"' ");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
            if (getenv($key) === false) {
                putenv(sprintf('%s=%s', $key, $value));
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}
