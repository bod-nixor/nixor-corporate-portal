<?php
function trusted_proxies(): array {
    $raw = env_value('TRUSTED_PROXIES', '');
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function is_trusted_proxy(): bool {
    $proxies = trusted_proxies();
    if (!$proxies) {
        return false;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($remote, $proxies, true);
}

function request_scheme(): string {
    $httpsFlag = $_SERVER['HTTPS'] ?? '';
    if ($httpsFlag && strtolower($httpsFlag) !== 'off') {
        return 'https';
    }
    if (is_trusted_proxy()) {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwarded) {
            $parts = explode(',', $forwarded);
            $candidate = strtolower(trim($parts[0] ?? ''));
            if (in_array($candidate, ['http', 'https'], true)) {
                return $candidate;
            }
        }
        $cfVisitor = $_SERVER['HTTP_CF_VISITOR'] ?? '';
        if ($cfVisitor) {
            $decoded = json_decode($cfVisitor, true);
            if (is_array($decoded) && isset($decoded['scheme'])) {
                $scheme = strtolower((string)$decoded['scheme']);
                if (in_array($scheme, ['http', 'https'], true)) {
                    return $scheme;
                }
            }
        }
    }
    return 'http';
}

function is_https(): bool {
    return request_scheme() === 'https';
}

function base_url(): string {
    $base = env_value('BASE_URL', '');
    if ($base) {
        return rtrim($base, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return request_scheme() . '://' . $host;
}
