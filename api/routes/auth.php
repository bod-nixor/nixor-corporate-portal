<?php
function handle_auth(string $method, array $segments): void {
    $action = $segments[1] ?? '';
    if ($action === 'login' && $method === 'POST') {
        $data = read_json();
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$data['email'] ?? '']);
        $user = $stmt->fetch();
        if (!$user || !verify_password($data['password'] ?? '', $user['password_hash'])) {
            respond(['ok' => false, 'error' => 'Invalid credentials'], 401);
        }
        if (($user['status'] ?? 'active') !== 'active') {
            respond(['ok' => false, 'error' => 'Account inactive'], 403);
        }
        $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $update->execute([$user['id']]);
        $_SESSION['user_id'] = $user['id'];
        respond(['ok' => true, 'data' => ['user' => sanitize_user($user)]]);
    }

    if ($action === 'logout' && $method === 'POST') {
        session_destroy();
        respond(['ok' => true, 'data' => ['message' => 'Logged out']]);
    }

    if ($action === 'me' && $method === 'GET') {
        $user = current_user();
        respond(['ok' => true, 'data' => ['user' => $user ? sanitize_user($user) : null]]);
    }

    if ($action === 'google_callback' && $method === 'POST') {
        $data = read_json();
        $idToken = $data['id_token'] ?? '';
        if (!$idToken) {
            respond(['ok' => false, 'error' => 'id_token required'], 400);
        }
        $tokenInfo = verify_google_id_token($idToken);
        $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ? OR email = ?');
        $stmt->execute([$tokenInfo['sub'], $tokenInfo['email']]);
        $user = $stmt->fetch();
        if (!$user) {
            respond(['ok' => false, 'error' => 'Google account not found'], 404);
        }
        if (($user['status'] ?? 'active') !== 'active') {
            respond(['ok' => false, 'error' => 'Account inactive'], 403);
        }
        $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        $update->execute([$user['id']]);
        $_SESSION['user_id'] = $user['id'];
        respond(['ok' => true, 'data' => ['user' => sanitize_user($user)]]);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function sanitize_user(array $user): array {
    unset($user['password_hash']);
    return $user;
}

function verify_google_id_token(string $idToken): array {
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        respond(['ok' => false, 'error' => 'Failed to verify token'], 401);
    }
    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        respond(['ok' => false, 'error' => 'Invalid token response'], 401);
    }
    $clientId = env_value('GOOGLE_CLIENT_ID');
    if (!$clientId) {
        respond(['ok' => false, 'error' => 'Google OAuth not configured'], 500);
    }
    if (($payload['aud'] ?? '') !== $clientId) {
        respond(['ok' => false, 'error' => 'Invalid token audience'], 401);
    }
    $issuer = $payload['iss'] ?? '';
    if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
        respond(['ok' => false, 'error' => 'Invalid token issuer'], 401);
    }
    if (!empty($payload['exp']) && time() > (int)$payload['exp']) {
        respond(['ok' => false, 'error' => 'Token expired'], 401);
    }
    if (empty($payload['email']) || empty($payload['sub'])) {
        respond(['ok' => false, 'error' => 'Token missing claims'], 401);
    }
    return $payload;
}
