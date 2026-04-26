<?php
function handle_auth(string $method, array $segments): void {
    $action = $segments[1] ?? '';
    if ($action === 'login' && $method === 'POST') {
        try {
            if (!rate_limit('login', 5, 900)) {
                respond(['ok' => false, 'error' => 'Too many attempts'], 429);
            }
            require_csrf();
            $data = read_json();
            $email = strtolower(trim((string)($data['email'] ?? '')));
            $password = (string)($data['password'] ?? '');
            if ($email === '' || $password === '') {
                respond(['ok' => false, 'error' => 'Email and password are required'], 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond(['ok' => false, 'error' => 'Invalid credentials'], 401);
            }
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user || !verify_password($password, $user['password_hash'] ?? null)) {
                respond(['ok' => false, 'error' => 'Invalid credentials'], 401);
            }
            if (($user['status'] ?? 'active') !== 'active') {
                respond(['ok' => false, 'error' => 'Account inactive'], 403);
            }
            complete_login($user);
        } catch (Throwable $e) {
            error_log('Password login failed unexpectedly: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            respond(['ok' => false, 'error' => 'Internal server error'], 500);
        }
    }

    if ($action === 'logout' && $method === 'POST') {
        require_csrf();
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['user_id'])) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', [
                        'expires' => time() - 42000,
                        'path' => $params['path'] ?? '/',
                        'domain' => $params['domain'] ?? '',
                        'secure' => (bool)($params['secure'] ?? false),
                        'httponly' => (bool)($params['httponly'] ?? true),
                        'samesite' => $params['samesite'] ?? 'Lax',
                    ]);
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
            if (in_array($user['global_role'], ['admin', 'board', 'student_affairs'], true)) {
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
        respond([
            'ok' => true,
            'data' => [
                'csrfToken' => $_SESSION['csrf_token'] ?? null,
                'expiresIn' => (int)ini_get('session.gc_maxlifetime'),
                'sessionName' => session_name()
            ]
        ]);
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

    if ($action === 'google' && ($segments[2] ?? '') === 'start' && $method === 'GET') {
        handle_google_oauth_start();
    }

    if ($action === 'google' && ($segments[2] ?? '') === 'callback' && $method === 'GET') {
        handle_google_oauth_callback();
    }

    if ($action === 'mobile' && ($segments[2] ?? '') === 'exchange' && $method === 'POST') {
        handle_mobile_auth_exchange();
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
        try {
            $tokenInfo = verify_google_id_token_or_fail($idToken);
            $user = google_user_from_token_info($tokenInfo);
            complete_login($user);
        } catch (AuthRouteException $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
        }
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

class AuthRouteException extends RuntimeException {
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400) {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function status(): int {
        return $this->httpStatus;
    }
}

function handle_google_oauth_start(): void {
    if (!rate_limit('google_oauth_start', 20, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }

    try {
        $platform = (($_GET['platform'] ?? '') === 'mobile') ? 'mobile' : 'web';
        $client = google_oauth_client_or_fail();
        $client->addScope('openid');
        $client->addScope('email');
        $client->addScope('profile');
        $client->setAccessType('online');
        $client->setIncludeGrantedScopes(true);
        $client->setState(create_google_oauth_state($platform));

        redirect_to($client->createAuthUrl());
    } catch (AuthRouteException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
    } catch (Throwable $e) {
        error_log('Google OAuth start failed: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Google OAuth could not be started'], 500);
    }
}

function handle_google_oauth_callback(): void {
    $stateRaw = (string)($_GET['state'] ?? '');
    $platform = google_oauth_state_platform_hint($stateRaw);

    try {
        if (isset($_GET['error'])) {
            throw new AuthRouteException('Google sign-in was cancelled or denied', 401);
        }

        $state = validate_google_oauth_state($stateRaw);
        $platform = $state['platform'];

        $authCode = trim((string)($_GET['code'] ?? ''));
        if ($authCode === '') {
            throw new AuthRouteException('Google authorization code missing', 400);
        }

        $client = google_oauth_client_or_fail();
        $token = $client->fetchAccessTokenWithAuthCode($authCode);
        if (!is_array($token) || isset($token['error'])) {
            throw new AuthRouteException('Google authorization failed', 401);
        }

        $idToken = (string)($token['id_token'] ?? '');
        if ($idToken === '') {
            throw new AuthRouteException('Google identity token missing', 401);
        }

        $tokenInfo = verify_google_id_token_or_fail($idToken);
        $user = google_user_from_token_info($tokenInfo);

        if ($platform === 'mobile') {
            $mobileCode = create_mobile_auth_code((int)$user['id']);
            redirect_to(build_redirect_url(mobile_auth_callback_url(), ['code' => $mobileCode]));
            return;
        }

        establish_login_session($user);
        redirect_to('/dashboard.html');
    } catch (AuthRouteException $e) {
        error_log('Google OAuth callback failed: ' . $e->getMessage());
        if ($platform === 'mobile') {
            if (ob_get_length()) ob_clean();
            redirect_to(build_redirect_url(mobile_auth_callback_url(), [
                'error' => 'google_auth_failed',
                'message' => $e->getMessage()
            ]));
            return;
        }
        redirect_to('/login.html?error=google_auth_failed');
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        error_log('Google OAuth callback failed unexpectedly: ' . $msg . "\n" . $e->getTraceAsString());
        if ($platform === 'mobile') {
            // Include a sanitized version of the error message for debugging
            $debugMsg = 'Internal server error: ' . substr($msg, 0, 100);
            if (ob_get_length()) ob_clean();
            redirect_to(build_redirect_url(mobile_auth_callback_url(), [
                'error' => 'google_auth_failed',
                'message' => $debugMsg
            ]));
            return;
        }
        redirect_to('/login.html?error=google_auth_failed');
    }
}

function handle_mobile_auth_exchange(): void {
    if (!rate_limit('mobile_auth_exchange', 10, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    
    // We do NOT require CSRF for the initial mobile code exchange because:
    // 1. The mobile code itself is a high-entropy one-time secret.
    // 2. The user may not have a stable session yet in the WebView.
    // 3. The segments check in index.php already allows 'auth' requests without global CSRF.

    $data = read_json();
    $code = trim((string)($data['code'] ?? ''));
    if ($code === '') {
        respond(['ok' => false, 'error' => 'Mobile auth code required'], 400);
    }
    if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/', $code)) {
        respond(['ok' => false, 'error' => 'Invalid mobile auth code'], 400);
    }

    try {
        $user = consume_mobile_auth_code($code);
        complete_login($user);
    } catch (AuthRouteException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
    } catch (Throwable $e) {
        error_log('Mobile auth exchange failed unexpectedly: ' . $e->getMessage());
        respond(['ok' => false, 'error' => 'Mobile sign-in failed'], 500);
    }
}

function sanitize_user(array $user): array {
    unset($user['password_hash']);
    return $user;
}

function verify_google_id_token(string $idToken): array {
    try {
        return verify_google_id_token_or_fail($idToken);
    } catch (AuthRouteException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
    }
}

function verify_google_id_token_or_fail(string $idToken): array {
    $clientId = env_value('GOOGLE_CLIENT_ID');
    if (!$clientId) {
        error_log('Google OAuth is not configured. Missing variable: GOOGLE_CLIENT_ID');
        throw new AuthRouteException('Google OAuth is not configured', 500);
    }
    require_google_autoload_or_fail();
    $client = new Google\Client(['client_id' => $clientId]);
    $payload = $client->verifyIdToken($idToken);
    if (!$payload) {
        throw new AuthRouteException('Invalid token', 401);
    }
    return $payload;
}

function require_google_autoload_or_fail(): void {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new AuthRouteException('Google auth library missing', 500);
    }
    require_once $autoload;
}

function google_oauth_client_or_fail() {
    $clientId = env_value('GOOGLE_CLIENT_ID');
    $clientSecret = env_value('GOOGLE_CLIENT_SECRET');
    $redirectUri = google_oauth_redirect_uri();
    
    $missing = [];
    if (!$clientId) $missing[] = 'GOOGLE_CLIENT_ID';
    if (!$clientSecret) $missing[] = 'GOOGLE_CLIENT_SECRET';
    if (!$redirectUri) $missing[] = 'GOOGLE_REDIRECT_URI';
    
    if (!empty($missing)) {
        error_log('Google OAuth is not configured. Missing variables: ' . implode(', ', $missing));
        throw new AuthRouteException('Google OAuth is not configured', 500);
    }

    require_google_autoload_or_fail();
    $client = new Google\Client();
    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri($redirectUri);
    return $client;
}

function google_oauth_redirect_uri(): string {
    $configured = trim((string)env_value('GOOGLE_REDIRECT_URI', ''));
    if ($configured !== '') {
        return $configured;
    }

    $baseUrl = trim((string)env_value('BASE_URL', ''));
    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/') . '/api/auth/google/callback';
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return '';
    }
    $scheme = is_https() ? 'https' : 'http';
    return $scheme . '://' . $host . '/api/auth/google/callback';
}

function google_state_secret_or_fail(): string {
    // Prefer a dedicated state-signing key so OAuth state HMACs do not reuse the Google client secret.
    $secret = (string)(env_value('OAUTH_STATE_SECRET')
        ?: env_value('APP_KEY')
        ?: env_value('GOOGLE_CLIENT_SECRET')
        ?: '');
    if ($secret === '') {
        error_log('No state secret configured: none of OAUTH_STATE_SECRET, APP_KEY, or GOOGLE_CLIENT_SECRET are set');
        throw new AuthRouteException('Google OAuth is not configured', 500);
    }
    return $secret;
}

function base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode(string $value): string|false {
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function create_google_oauth_state(string $platform): string {
    $platform = $platform === 'mobile' ? 'mobile' : 'web';
    $nonce = bin2hex(random_bytes(16));
    $payload = [
        'nonce' => $nonce,
        'platform' => $platform,
        'iat' => time(),
    ];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        throw new AuthRouteException('Google OAuth state could not be created', 500);
    }

    if (!isset($_SESSION['google_oauth_states']) || !is_array($_SESSION['google_oauth_states'])) {
        $_SESSION['google_oauth_states'] = [];
    }
    cleanup_google_oauth_states();
    $_SESSION['google_oauth_states'][$nonce] = [
        'platform' => $platform,
        'iat' => $payload['iat'],
    ];

    $payloadPart = base64url_encode($payloadJson);
    $signature = base64url_encode(hash_hmac('sha256', $payloadPart, google_state_secret_or_fail(), true));
    return $payloadPart . '.' . $signature;
}

function cleanup_google_oauth_states(): void {
    if (!isset($_SESSION['google_oauth_states']) || !is_array($_SESSION['google_oauth_states'])) {
        return;
    }
    $cutoff = time() - 900;
    foreach ($_SESSION['google_oauth_states'] as $nonce => $state) {
        $issuedAt = (int)($state['iat'] ?? 0);
        if ($issuedAt <= $cutoff) {
            unset($_SESSION['google_oauth_states'][$nonce]);
        }
    }
}

function validate_google_oauth_state(string $state): array {
    $parts = explode('.', $state, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        throw new AuthRouteException('Invalid Google OAuth state', 401);
    }

    [$payloadPart, $signaturePart] = $parts;
    $expectedSignature = base64url_encode(hash_hmac('sha256', $payloadPart, google_state_secret_or_fail(), true));
    if (!hash_equals($expectedSignature, $signaturePart)) {
        throw new AuthRouteException('Invalid Google OAuth state', 401);
    }

    $payloadJson = base64url_decode($payloadPart);
    $payload = is_string($payloadJson) ? json_decode($payloadJson, true) : null;
    if (!is_array($payload)) {
        throw new AuthRouteException('Invalid Google OAuth state', 401);
    }

    $nonce = (string)($payload['nonce'] ?? '');
    $platform = (string)($payload['platform'] ?? 'web');
    $issuedAt = (int)($payload['iat'] ?? 0);
    if ($nonce === '' || !in_array($platform, ['web', 'mobile'], true) || $issuedAt <= 0) {
        throw new AuthRouteException('Invalid Google OAuth state', 401);
    }
    if ($issuedAt < time() - 600) {
        throw new AuthRouteException('Google OAuth state expired', 401);
    }

    // For non-mobile platforms, we strictly require the state to be in the session.
    // For mobile, we can be more lenient if the HMAC signature is valid, because
    // external browser sessions can sometimes be unstable during redirects.
    if ($platform !== 'mobile') {
        cleanup_google_oauth_states();
        $sessionState = $_SESSION['google_oauth_states'][$nonce] ?? null;
        if (!is_array($sessionState) || ($sessionState['platform'] ?? '') !== $platform) {
            error_log("Google OAuth state session mismatch for {$platform}: nonce={$nonce}. " . (empty($_SESSION) ? "Session is empty." : "Session exists."));
            throw new AuthRouteException('Invalid Google OAuth state (session mismatch)', 401);
        }
        unset($_SESSION['google_oauth_states'][$nonce]);
    }

    return [
        'nonce' => $nonce,
        'platform' => $platform,
        'iat' => $issuedAt,
    ];
}

function google_oauth_state_platform_hint(string $state): string {
    $parts = explode('.', $state, 2);
    if (count($parts) !== 2) {
        return 'web';
    }
    $payloadJson = base64url_decode($parts[0]);
    $payload = is_string($payloadJson) ? json_decode($payloadJson, true) : null;
    return is_array($payload) && ($payload['platform'] ?? '') === 'mobile' ? 'mobile' : 'web';
}

function google_user_from_token_info(array $tokenInfo): array {
    $googleId = trim((string)($tokenInfo['sub'] ?? ''));
    $email = strtolower(trim((string)($tokenInfo['email'] ?? '')));
    if ($googleId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new AuthRouteException('Invalid Google account', 401);
    }

    $allowedDomains = allowed_google_domains();
    if ($allowedDomains && !email_domain_allowed($email, $allowedDomains)) {
        throw new AuthRouteException('Google account not in allowed domain', 403);
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE google_id = ?');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();
    if (!$user) {
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND (google_id IS NULL OR google_id = "")');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $link = db()->prepare('UPDATE users SET google_id = ? WHERE id = ?');
            $link->execute([$googleId, $user['id']]);
            $user['google_id'] = $googleId;
        }
    }
    if (!$user) {
        throw new AuthRouteException('Google account not found', 404);
    }
    if (($user['status'] ?? 'active') !== 'active') {
        throw new AuthRouteException('Account inactive', 403);
    }
    return $user;
}

function create_mobile_auth_code(int $userId): string {
    $code = base64url_encode(random_bytes(32));
    $codeHash = hash('sha256', $code);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 300);

    try {
        $stmt = db()->prepare(
            'INSERT INTO mobile_auth_codes (user_id, code_hash, expires_at, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$userId, $codeHash, $expiresAt]);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') { // Table not found
            throw new AuthRouteException('Mobile auth system is not fully initialized (missing table). Please run migrations.', 500);
        }
        throw $e;
    }

    return $code;
}

function consume_mobile_auth_code(string $code): array {
    $codeHash = hash('sha256', $code);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT
                mac.id AS auth_code_id,
                mac.user_id AS auth_code_user_id,
                mac.expires_at AS auth_code_expires_at,
                mac.used_at AS auth_code_used_at,
                (mac.expires_at <= UTC_TIMESTAMP()) AS auth_code_expired
             FROM mobile_auth_codes mac
             WHERE mac.code_hash = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$codeHash]);
        $authCode = $stmt->fetch();

        if (!$authCode) {
            $pdo->commit();
            throw new AuthRouteException('Invalid mobile auth code', 401);
        }
        if (!empty($authCode['auth_code_used_at'])) {
            $pdo->commit();
            throw new AuthRouteException('Mobile auth code already used', 401);
        }
        if ((int)($authCode['auth_code_expired'] ?? 0) === 1) {
            $pdo->commit();
            throw new AuthRouteException('Mobile auth code expired', 401);
        }

        $markUsed = $pdo->prepare('UPDATE mobile_auth_codes SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL');
        $markUsed->execute([$authCode['auth_code_id']]);
        if ($markUsed->rowCount() !== 1) {
            $pdo->commit();
            throw new AuthRouteException('Mobile auth code already used', 401);
        }

        $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $userStmt->execute([$authCode['auth_code_user_id']]);
        $user = $userStmt->fetch();
        if (!$user) {
            $pdo->commit();
            throw new AuthRouteException('Google account not found', 404);
        }
        if (($user['status'] ?? 'active') !== 'active') {
            $pdo->commit();
            throw new AuthRouteException('Account inactive', 403);
        }

        $pdo->commit();
        return $user;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function mobile_auth_callback_url(): string {
    $configured = trim((string)env_value('MOBILE_AUTH_REDIRECT_URI', ''));
    return $configured !== '' ? $configured : 'ncp://auth/callback';
}

function build_redirect_url(string $baseUrl, array $params): string {
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    return $baseUrl . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function redirect_to(string $url): void {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    header_remove('Content-Type');
    header('Location: ' . $url, true, 302);
    exit;
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

function establish_login_session(array $user): void {
    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
}

function complete_login(array $user): void {
    establish_login_session($user);
    respond(['ok' => true, 'data' => ['user' => sanitize_user($user)]]);
}
