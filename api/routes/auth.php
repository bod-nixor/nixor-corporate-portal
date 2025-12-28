<?php
function handle_auth(string $method, array $segments): void {
    $action = $segments[1] ?? '';
    if ($action === 'login' && $method === 'POST') {
        if (!rate_limit('login', 5, 900)) {
            respond(['ok' => false, 'error' => 'Too many attempts'], 429);
        }
        require_csrf();
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
        complete_login($user);
    }

    if ($action === 'logout' && $method === 'POST') {
        require_csrf();
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['user_id'])) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
            }
            session_destroy();
        }
        respond(['ok' => true, 'data' => ['message' => 'Logged out']]);
    }

    if ($action === 'me' && $method === 'GET') {
        $user = current_user();
        respond(['ok' => true, 'data' => ['user' => $user ? sanitize_user($user) : null]]);
    }

    if ($action === 'csrf' && $method === 'GET') {
        respond(['ok' => true, 'data' => ['token' => $_SESSION['csrf_token'] ?? null]]);
    }

    if ($action === 'google_callback' && $method === 'POST') {
        if (!rate_limit('google_callback', 5, 900)) {
            respond(['ok' => false, 'error' => 'Too many attempts'], 429);
        }
        require_csrf();
        $data = read_json();
        $idToken = $data['id_token'] ?? '';
        if (!$idToken) {
            respond(['ok' => false, 'error' => 'id_token required'], 400);
        }
        $tokenInfo = verify_google_id_token($idToken);
        $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ?');
        $stmt->execute([$tokenInfo['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND (google_id IS NULL OR google_id = "")');
            $stmt->execute([$tokenInfo['email']]);
            $user = $stmt->fetch();
        }
        if (!$user) {
            respond(['ok' => false, 'error' => 'Google account not found'], 404);
        }
        if (($user['status'] ?? 'active') !== 'active') {
            respond(['ok' => false, 'error' => 'Account inactive'], 403);
        }
        complete_login($user);
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

function sanitize_user(array $user): array {
    unset($user['password_hash']);
    return $user;
}

function verify_google_id_token(string $idToken): array {
    $clientId = env_value('GOOGLE_CLIENT_ID');
    if (!$clientId) {
        respond(['ok' => false, 'error' => 'Google OAuth not configured'], 500);
    }
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        respond(['ok' => false, 'error' => 'Google auth library missing'], 500);
    }
    require_once $autoload;
    $client = new Google_Client(['client_id' => $clientId]);
    $payload = $client->verifyIdToken($idToken);
    if (!$payload) {
        respond(['ok' => false, 'error' => 'Invalid token'], 401);
    }
    return $payload;
}

function complete_login(array $user): void {
    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    respond(['ok' => true, 'data' => ['user' => sanitize_user($user)]]);
}
