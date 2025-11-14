<?php

declare(strict_types=1);

namespace App\Validation;

use InvalidArgumentException;

final class Validator
{
    public static function string(array $input, string $key, int $min = 0, int $max = 255, bool $required = true): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            if ($required) {
                throw new InvalidArgumentException(sprintf('%s is required', $key));
            }
            return null;
        }
        $value = trim((string) $input[$key]);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d characters', $key, $min, $max));
        }
        return $value;
    }

    public static function email(array $input, string $key): string
    {
        $value = self::string($input, $key, 1, 191);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address');
        }
        return $value;
    }

    public static function int(array $input, string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX, bool $required = true): ?int
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            if ($required) {
                throw new InvalidArgumentException(sprintf('%s is required', $key));
            }
            return null;
        }
        if (!is_numeric($input[$key])) {
            throw new InvalidArgumentException(sprintf('%s must be numeric', $key));
        }
        $value = (int) $input[$key];
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d', $key, $min, $max));
        }
        return $value;
    }

    public static function bool(array $input, string $key, bool $required = true): ?bool
    {
        if (!array_key_exists($key, $input)) {
            if ($required) {
                throw new InvalidArgumentException(sprintf('%s is required', $key));
            }
            return null;
        }
        return filter_var($input[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
