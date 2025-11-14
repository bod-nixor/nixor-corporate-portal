<?php

declare(strict_types=1);

namespace App\Lib;

final class Session
{
    private const COOKIE_NAME = 'nixor_session';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        self::start();
        return $_SESSION['csrf_token'];
    }

    public static function requireCsrf(?string $token): void
    {
        if (!$token || !hash_equals(self::csrfToken(), $token)) {
            Response::json(['message' => 'CSRF token mismatch'], 419);
            exit;
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(self::COOKIE_NAME, '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
