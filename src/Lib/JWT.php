<?php

declare(strict_types=1);

namespace App\Lib;

use DateInterval;
use DateTimeImmutable;
use Exception;

final class JWT
{
    private const ALG = 'HS256';

    public static function encode(array $claims, string $secret): string
    {
        $header = ['typ' => 'JWT', 'alg' => self::ALG];
        $segments = [
            self::urlsafeB64Encode(json_encode($header, JSON_THROW_ON_ERROR)),
            self::urlsafeB64Encode(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::urlsafeB64Encode($signature);
        return implode('.', $segments);
    }

    public static function decode(string $jwt, string $secret): array
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new Exception('Invalid token');
        }
        [$header64, $payload64, $signature64] = $segments;
        $header = json_decode(self::urlsafeB64Decode($header64), true, 512, JSON_THROW_ON_ERROR);
        if (($header['alg'] ?? null) !== self::ALG) {
            throw new Exception('Unsupported algorithm');
        }
        $expected = hash_hmac('sha256', sprintf('%s.%s', $header64, $payload64), $secret, true);
        if (!hash_equals($expected, self::urlsafeB64Decode($signature64))) {
            throw new Exception('Signature verification failed');
        }
        $payload = json_decode(self::urlsafeB64Decode($payload64), true, 512, JSON_THROW_ON_ERROR);
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            throw new Exception('Token expired');
        }
        return $payload;
    }

    public static function issueForUser(array $user, int $ttlSeconds, string $secret, string $issuer): string
    {
        $now = new DateTimeImmutable();
        $claims = [
            'iss' => $issuer,
            'sub' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'entityIds' => $user['entityIds'] ?? [],
            'iat' => $now->getTimestamp(),
            'exp' => $now->add(new DateInterval('PT' . $ttlSeconds . 'S'))->getTimestamp(),
        ];
        return self::encode($claims, $secret);
    }

    private static function urlsafeB64Encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function urlsafeB64Decode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
