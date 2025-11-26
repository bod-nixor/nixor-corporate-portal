<?php

declare(strict_types=1);

namespace App\Lib;

use Throwable;

final class Logger
{
    private static string $logFile = __DIR__ . '/../../logs/app.log';

    public static function init(?string $filePath = null): void
    {
        if ($filePath) {
            self::$logFile = $filePath;
        }
        $dir = dirname(self::$logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function exception(Throwable $throwable, array $context = []): void
    {
        $context = array_merge($context, [
            'exception' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => $throwable->getTraceAsString(),
        ]);
        self::write('EXCEPTION', 'Unhandled exception', $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents(self::$logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
