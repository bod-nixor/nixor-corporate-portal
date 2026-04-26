<?php
function cors_origin_from_url(string $url): ?string {
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $origin .= ':' . (int)$parts['port'];
    }
    return $origin;
}

function cors_normalize_origin(?string $origin): string {
    $origin = rtrim(trim((string)$origin), '/');
    if ($origin === '') {
        return '';
    }
    return cors_origin_from_url($origin) ?? $origin;
}

function cors_allowed_origins(): array {
    $origins = [
        'https://ncp.nixorcorporate.com',
        'capacitor://localhost',
        'http://localhost',
    ];

    $baseUrl = env_value('BASE_URL', '');
    if ($baseUrl) {
        $baseOrigin = cors_origin_from_url($baseUrl);
        if ($baseOrigin) {
            $origins[] = $baseOrigin;
        }
    }

    $configured = env_value('CORS_ALLOWED_ORIGINS', '');
    if ($configured !== '') {
        foreach (explode(',', $configured) as $rawOrigin) {
            $rawOrigin = trim($rawOrigin);
            if ($rawOrigin === '') {
                continue;
            }
            $origin = cors_origin_from_url($rawOrigin);
            if ($origin) {
                $origins[] = $origin;
            }
        }
    }

    return array_values(array_unique(array_map('cors_normalize_origin', $origins)));
}

function cors_is_loopback_dev_origin(string $origin): bool {
    $parts = parse_url($origin);
    if (!$parts) {
        return false;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function cors_is_trusted_origin(?string $origin): bool {
    $origin = cors_normalize_origin($origin);
    if ($origin === '') {
        return false;
    }
    if (in_array($origin, cors_allowed_origins(), true)) {
        return true;
    }
    return cors_is_loopback_dev_origin($origin);
}

function cors_origin_requires_cross_site_session(?string $origin): bool {
    $origin = cors_normalize_origin($origin);
    if (!cors_is_trusted_origin($origin)) {
        return false;
    }

    $parts = parse_url($origin);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme === 'capacitor') {
        return true;
    }

    $originHost = strtolower((string)($parts['host'] ?? ''));
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?: $requestHost;

    return $originHost !== '' && $requestHost !== '' && $originHost !== $requestHost && is_https();
}

function apply_cors_headers(): void {
    $origin = cors_normalize_origin($_SERVER['HTTP_ORIGIN'] ?? '');
    if (!cors_is_trusted_origin($origin)) {
        if ($origin !== '') {
            error_log("CORS: Untrusted origin rejected: " . $origin);
        }
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

function cors_is_preflight_request(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS';
}
