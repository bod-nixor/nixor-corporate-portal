<?php

declare(strict_types=1);

namespace App\Services;

use App\Lib\Env;

final class SettingsService
{
    public function getVisibilityMode(): string
    {
        return Env::get('VISIBILITY_MODE', 'RESTRICTED') ?? 'RESTRICTED';
    }

    public function updateVisibilityMode(string $mode): void
    {
        $path = __DIR__ . '/../../.env';
        $env = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $set = false;
        foreach ($env as $index => $line) {
            if (str_starts_with($line, 'VISIBILITY_MODE=')) {
                $env[$index] = 'VISIBILITY_MODE=' . $mode;
                $set = true;
                break;
            }
        }
        if (!$set) {
            $env[] = 'VISIBILITY_MODE=' . $mode;
        }
        file_put_contents($path, implode(PHP_EOL, $env));
        putenv('VISIBILITY_MODE=' . $mode);
        $_ENV['VISIBILITY_MODE'] = $mode;
    }
}
