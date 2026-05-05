<?php
function auth_password_token_lifetime_seconds(): int {
    $raw = env_value('PASSWORD_RESET_TOKEN_MINUTES', '30');
    $minutes = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['default' => 30, 'min_range' => 15, 'max_range' => 60],
    ]);
    return (is_int($minutes) ? $minutes : 30) * 60;
}

function auth_token_type_is_valid(string $type): bool {
    return in_array($type, ['password_reset', 'password_setup'], true);
}

function auth_password_token_format_is_valid(string $token): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{32,256}$/', $token);
}

function auth_password_token_hash(string $token): string {
    return hash('sha256', $token);
}

function auth_invalidate_active_password_tokens(int $userId, string $type): void {
    if (!auth_token_type_is_valid($type)) {
        throw new InvalidArgumentException('Invalid auth token type');
    }
    $stmt = db()->prepare(
        'UPDATE auth_tokens
         SET used_at = COALESCE(used_at, UTC_TIMESTAMP())
         WHERE user_id = ?
           AND token_type = ?
           AND used_at IS NULL
           AND expires_at > UTC_TIMESTAMP()'
    );
    $stmt->execute([$userId, $type]);
}

function auth_create_password_token(int $userId, string $type, ?int $createdBy = null): array {
    if (!auth_token_type_is_valid($type)) {
        throw new InvalidArgumentException('Invalid auth token type');
    }
    $token = auth_base64url_encode(random_bytes(32));
    $tokenHash = auth_password_token_hash($token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + auth_password_token_lifetime_seconds());
    $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $pdo = db();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        auth_invalidate_active_password_tokens($userId, $type);
        $stmt = $pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_type, token_hash, expires_at, created_ip, created_user_agent, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $tokenHash, $expiresAt, $ip, $userAgent, $createdBy]);
        if ($startedTransaction) {
            $pdo->commit();
        }
        return [
            'token' => $token,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function auth_public_base_url(): string {
    $baseUrl = trim((string)env_value('BASE_URL', ''));
    $appEnv = strtolower((string)env_value('APP_ENV', 'production'));
    if ($baseUrl === '') {
        if ($appEnv === 'production') {
            error_log('BASE_URL is required in production for password reset/setup links.');
            return '';
        }
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '' || !auth_public_host_allowed($host)) {
            error_log('BASE_URL or ALLOWED_HOSTS is required before using HTTP_HOST for password reset/setup links.');
            return '';
        }
        $scheme = function_exists('is_https') && is_https() ? 'https' : 'http';
        $baseUrl = "{$scheme}://{$host}";
    }
    $baseUrl = rtrim($baseUrl, '/');
    if ($appEnv === 'production' && str_starts_with(strtolower($baseUrl), 'http://')) {
        $baseUrl = 'https://' . substr($baseUrl, strlen('http://'));
    }
    return $baseUrl;
}

function auth_public_host_allowed(string $host): bool {
    $allowed = function_exists('allowed_hosts') ? allowed_hosts() : [];
    if (!$allowed) {
        return false;
    }
    $host = strtolower($host);
    $hostName = strtolower((string)(parse_url('http://' . $host, PHP_URL_HOST) ?: ''));
    foreach ($allowed as $allowedHost) {
        $allowedHost = strtolower(trim((string)$allowedHost));
        if ($allowedHost === '') {
            continue;
        }
        $allowedName = strtolower((string)(parse_url('http://' . $allowedHost, PHP_URL_HOST) ?: $allowedHost));
        if ($host === $allowedHost || $hostName === $allowedName) {
            return true;
        }
    }
    return false;
}

function auth_password_flow_url(string $token): string {
    $baseUrl = auth_public_base_url();
    $path = '/reset_password.html?token=' . rawurlencode($token);
    return $baseUrl !== '' ? $baseUrl . $path : $path;
}

function auth_mask_email(string $email): string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    $prefix = substr($local, 0, 1);
    return $prefix . str_repeat('*', max(2, min(6, strlen($local) - 1))) . '@' . $domain;
}

function auth_password_token_email_subject(string $type): string {
    return $type === 'password_setup' ? 'Set up your Nixor Portal password' : 'Reset your Nixor Portal password';
}

function auth_password_token_email_body(array $user, string $type, string $url, string $expiresAt): string {
    $name = htmlspecialchars((string)($user['full_name'] ?? 'Portal user'), ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $purpose = $type === 'password_setup' ? 'set up your password' : 'reset your password';
    $expires = htmlspecialchars($expiresAt . ' UTC', ENT_QUOTES, 'UTF-8');
    return <<<HTML
<p>Hello {$name},</p>
<p>Use the secure link below to {$purpose} for the Nixor Corporate Portal. The link expires at {$expires} and can only be used once.</p>
<p><a href="{$safeUrl}">Continue to Nixor Portal</a></p>
<p>If you did not request this, contact Nixor College/Nixor Corporate administration.</p>
HTML;
}

function auth_send_password_token_email(array $user, string $type, string $token, string $expiresAt): bool {
    $email = trim((string)($user['email'] ?? ''));

    file_put_contents(
        dirname(__DIR__, 2) . '/logs/reset-debug.log',
        '[' . date('c') . "] auth_send: email={$email}\n",
        FILE_APPEND
    );

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        file_put_contents(
            dirname(__DIR__, 2) . '/logs/reset-debug.log',
            '[' . date('c') . "] auth_send: invalid email\n",
            FILE_APPEND
        );
        return false;
    }

    $url = auth_password_flow_url($token);

    file_put_contents(
        dirname(__DIR__, 2) . '/logs/reset-debug.log',
        '[' . date('c') . "] auth_send: url={$url}\n",
        FILE_APPEND
    );

    $subject = auth_password_token_email_subject($type);
    $body = auth_password_token_email_body($user, $type, $url, $expiresAt);

    file_put_contents(
        dirname(__DIR__, 2) . '/logs/reset-debug.log',
        '[' . date('c') . "] auth_send: before send_email\n",
        FILE_APPEND
    );

    $result = send_email($email, $subject, $body, true);

    file_put_contents(
        dirname(__DIR__, 2) . '/logs/reset-debug.log',
        '[' . date('c') . '] auth_send: send_email returned ' . ($result ? 'true' : 'false') . "\n",
        FILE_APPEND
    );

    return $result;
}

function auth_fetch_password_token_row(PDO $pdo, string $token, bool $forUpdate = false): ?array {
    if (!auth_password_token_format_is_valid($token)) {
        return null;
    }
    $tokenHash = auth_password_token_hash($token);
    $stmt = $pdo->prepare(
        'SELECT
            at.*,
            u.email,
            u.full_name,
            u.status,
            (at.expires_at <= UTC_TIMESTAMP()) AS token_expired
         FROM auth_tokens at
         JOIN users u ON u.id = at.user_id
         WHERE at.token_hash = ?
         LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (!hash_equals((string)$row['token_hash'], $tokenHash)) {
        return null;
    }
    return $row;
}

function auth_password_token_row_is_usable(?array $row): bool {
    if (!$row || !auth_token_type_is_valid((string)($row['token_type'] ?? ''))) {
        return false;
    }
    if (!empty($row['used_at']) || (int)($row['token_expired'] ?? 0) === 1) {
        return false;
    }
    return ($row['status'] ?? '') === 'active';
}
