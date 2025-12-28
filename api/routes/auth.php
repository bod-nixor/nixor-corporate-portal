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
        $entities = [];
        if ($user) {
            if (in_array($user['global_role'], ['admin', 'board'], true)) {
                $entities = db()->query('SELECT * FROM entities ORDER BY name')->fetchAll();
            } else {
                $stmt = db()->prepare('SELECT e.* FROM entities e JOIN entity_memberships em ON e.id = em.entity_id WHERE em.user_id = ? ORDER BY e.name');
                $stmt->execute([$user['id']]);
                $entities = $stmt->fetchAll();
            }
        }
        respond(['ok' => true, 'data' => ['user' => $user ? sanitize_user($user) : null, 'entities' => $entities]]);
    }

    if ($action === 'csrf' && $method === 'GET') {
        respond(['ok' => true, 'data' => ['token' => $_SESSION['csrf_token'] ?? null]]);
    }

    if ($action === 'config' && $method === 'GET') {
        respond([
            'ok' => true,
            'data' => [
                'google_client_id' => env_value('GOOGLE_CLIENT_ID'),
                'google_allowed_domains' => allowed_google_domains(),
            ]
        ]);
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
        $allowedDomains = allowed_google_domains();
        if ($allowedDomains) {
            $email = strtolower($tokenInfo['email'] ?? '');
            if (!$email || !email_domain_allowed($email, $allowedDomains)) {
                respond(['ok' => false, 'error' => 'Google account not in allowed domain'], 403);
            }
        }
        $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ?');
        $stmt->execute([$tokenInfo['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND (google_id IS NULL OR google_id = "")');
            $stmt->execute([$tokenInfo['email']]);
            $user = $stmt->fetch();
            if ($user) {
                $link = db()->prepare('UPDATE users SET google_id = ? WHERE id = ?');
                $link->execute([$tokenInfo['sub'], $user['id']]);
            }
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

function allowed_google_domains(): array {
    $raw = env_value('GOOGLE_ALLOWED_DOMAIN', '');
    if (!$raw) {
        return [];
    }
    $parts = array_map('trim', explode(',', $raw));
    $domains = [];
    foreach ($parts as $domain) {
        if ($domain === '') {
            continue;
        }
        $domains[] = ltrim(strtolower($domain), '@');
    }
    return array_values(array_unique($domains));
}

function email_domain_allowed(string $email, array $domains): bool {
    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return false;
    }
    $emailDomain = substr($email, $atPos + 1);
    if ($emailDomain === '') {
        return false;
    }
    foreach ($domains as $domain) {
        if ($emailDomain === $domain) {
            return true;
        }
    }
    return false;
}

function complete_login(array $user): void {
    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    respond(['ok' => true, 'data' => ['user' => sanitize_user($user)]]);
}
