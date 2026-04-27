<?php
function current_user(): ?array {
    static $cachedUser = null;
    static $checkedAuth = false;
    if ($checkedAuth) {
        return $cachedUser;
    }
    $checkedAuth = true;

    $sessionUser = user_from_session();
    if ($sessionUser) {
        $cachedUser = $sessionUser;
        return $cachedUser;
    }

    $cachedUser = user_from_bearer_token();
    return $cachedUser;
}

function user_from_session(): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = ?');
    $stmt->execute([$_SESSION['user_id'], 'active']);
    $user = $stmt->fetch();
    return $user ?: null;
}

function auth_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function mobile_session_ttl_days(): int {
    $raw = env_value('MOBILE_SESSION_TTL_DAYS', '30');
    $days = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['default' => 30, 'min_range' => 1, 'max_range' => 365],
    ]);
    return is_int($days) ? $days : 30;
}

function normalize_mobile_platform(string $platform): string {
    $platform = strtolower(trim($platform));
    return in_array($platform, ['ios', 'android'], true) ? $platform : 'unknown';
}

function create_mobile_session_token(int $userId, string $platform): array {
    $userId = max(0, $userId);
    if ($userId <= 0) {
        throw new RuntimeException('Invalid user id for mobile session');
    }

    $userCheck = db()->prepare('SELECT id FROM users WHERE id = ? AND status = ?');
    $userCheck->execute([$userId, 'active']);
    if (!$userCheck->fetch()) {
        throw new RuntimeException('Cannot create mobile session for inactive or missing user');
    }

    $token = auth_base64url_encode(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + mobile_session_ttl_days() * 86400);

    $stmt = db()->prepare(
        'INSERT INTO mobile_sessions (user_id, token_hash, platform, expires_at)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $tokenHash, normalize_mobile_platform($platform), $expiresAt]);

    $lastLogin = db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?');
    $lastLogin->execute([$userId]);

    return [
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function auth_authorization_header(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header !== '') {
        return trim((string)$header);
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower((string)$key) === 'authorization') {
                    return trim((string)$value);
                }
            }
        }
    }
    return '';
}

function mobile_bearer_token_format_is_valid(string $token): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{32,256}$/', $token);
}

function mobile_bearer_token_from_request(): ?string {
    $header = auth_authorization_header();
    if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return null;
    }
    $token = trim((string)$matches[1]);
    return mobile_bearer_token_format_is_valid($token) ? $token : null;
}

function user_from_bearer_token(): ?array {
    static $checkedBearer = false;
    static $cachedBearerUser = null;
    if ($checkedBearer) {
        return $cachedBearerUser;
    }
    $checkedBearer = true;

    $token = mobile_bearer_token_from_request();
    if (!$token) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT u.*, ms.id AS _mobile_session_id
             FROM mobile_sessions ms
             JOIN users u ON u.id = ms.user_id
             WHERE ms.token_hash = ?
               AND ms.revoked_at IS NULL
               AND ms.expires_at > UTC_TIMESTAMP()
               AND u.status = ?
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), 'active']);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }

        $mobileSessionId = (int)($user['_mobile_session_id'] ?? 0);
        unset($user['_mobile_session_id']);

        if ($mobileSessionId > 0) {
            $touch = db()->prepare(
                'UPDATE mobile_sessions
                 SET last_used_at = UTC_TIMESTAMP()
                 WHERE id = ?
                   AND (last_used_at IS NULL OR last_used_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE)'
            );
            $touch->execute([$mobileSessionId]);
        }

        $cachedBearerUser = $user;
        return $cachedBearerUser;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            error_log('Mobile bearer auth table is missing. Run migrations.');
            return null;
        }
        throw $e;
    }
}

function request_has_valid_bearer_token(): bool {
    return user_from_bearer_token() !== null;
}

function revoke_mobile_bearer_token(): bool {
    $token = mobile_bearer_token_from_request();
    if (!$token) {
        return false;
    }
    $tokenHash = hash('sha256', $token);
    $stmt = db()->prepare(
        'UPDATE mobile_sessions
         SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
         WHERE token_hash = ?'
    );
    $stmt->execute([$tokenHash]);

    $exists = db()->prepare('SELECT 1 FROM mobile_sessions WHERE token_hash = ? LIMIT 1');
    $exists->execute([$tokenHash]);
    return (bool)$exists->fetchColumn();
}

function require_auth(): array {
    $user = current_user();
    if (!$user) {
        respond(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    return $user;
}

function require_role(array $roles): array {
    $user = require_auth();
    if (!in_array($user['global_role'], $roles, true)) {
        respond(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    return $user;
}

function ensure_entity_access(int $entityId, array $roles = []): array {
    $user = require_auth();
    // Admin and Board roles can access all entities by design.
    if (in_array($user['global_role'], ['admin', 'board'], true)) {
        return $user;
    }
    $stmt = db()->prepare('SELECT department FROM entity_memberships WHERE entity_id = ? AND user_id = ?');
    $stmt->execute([$entityId, $user['id']]);
    $membership = $stmt->fetch();
    if (!$membership) {
        respond(['ok' => false, 'error' => 'Entity access denied'], 403);
    }
    if ($roles && !in_array($membership['department'], $roles, true)) {
        respond(['ok' => false, 'error' => 'Department access denied'], 403);
    }
    return $user;
}

function ensure_entity_role(int $entityId, array $roles): array {
    $user = require_auth();
    if ($user['global_role'] === 'admin') {
        return $user;
    }
    $stmt = db()->prepare('SELECT role FROM entity_memberships WHERE entity_id = ? AND user_id = ?');
    $stmt->execute([$entityId, $user['id']]);
    $membership = $stmt->fetch();
    if (!$membership) {
        respond(['ok' => false, 'error' => 'Entity access denied'], 403);
    }
    if ($user['global_role'] === 'ceo') {
        return $user;
    }
    if ($roles && !in_array($membership['role'], $roles, true)) {
        respond(['ok' => false, 'error' => 'Role access denied'], 403);
    }
    return $user;
}

function verify_password(string $password, ?string $hash): bool {
    $hash = trim((string)$hash);
    if ($hash === '') {
        return false;
    }
    $info = password_get_info($hash);
    if (($info['algoName'] ?? 'unknown') === 'unknown') {
        return false;
    }
    return password_verify($password, $hash);
}
