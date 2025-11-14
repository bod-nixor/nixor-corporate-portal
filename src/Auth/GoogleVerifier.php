<?php

declare(strict_types=1);

namespace App\Auth;

use App\Lib\Env;
use RuntimeException;

final class GoogleVerifier
{
    public function verifyIdToken(string $token): array
    {
        $clientId = Env::get('GOOGLE_CLIENT_ID');
        if (!$clientId) {
            throw new RuntimeException('Google client ID not configured');
        }

        $response = $this->callGoogle($token);
        if (($response['aud'] ?? null) !== $clientId) {
            throw new RuntimeException('Invalid audience');
        }

        $allowedDomain = Env::get('ALLOWED_EMAIL_DOMAIN', 'nixorcollege.edu.pk');
        if (!str_ends_with(strtolower($response['email']), '@' . strtolower($allowedDomain))) {
            throw new RuntimeException('Email domain not allowed');
        }

        if (!($response['email_verified'] ?? false)) {
            throw new RuntimeException('Email not verified');
        }

        return $response;
    }

    private function callGoogle(string $token): array
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
            ],
        ]);
        $json = file_get_contents($url, false, $context);
        if (!$json) {
            throw new RuntimeException('Failed to verify token');
        }
        $response = json_decode($json, true);
        if (!is_array($response)) {
            throw new RuntimeException('Invalid token response');
        }
        return $response;
    }
}
