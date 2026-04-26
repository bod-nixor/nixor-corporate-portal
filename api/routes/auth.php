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
            $user = find_or_create_google_user($tokenInfo);
            complete_login($user);
        } catch (AuthRouteException $e) {
            respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
        } catch (PDOException $e) {
            error_log('Google Identity Services database error: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            respond(['ok' => false, 'error' => 'Google sign-in failed'], 500);
        } catch (Throwable $e) {
            error_log('Google Identity Services failed unexpectedly: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            respond(['ok' => false, 'error' => 'Google sign-in failed'], 500);
        }
    }

    respond(['ok' => false, 'error' => 'Not Found'], 404);
}

class AuthRouteException extends RuntimeException {
    private int $httpStatus;
    private string $clientErrorCode;

    public function __construct(string $message, int $httpStatus = 400, string $clientErrorCode = 'callback_failed') {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->clientErrorCode = $clientErrorCode;
    }

    public function status(): int {
        return $this->httpStatus;
    }

    public function clientErrorCode(): string {
        return $this->clientErrorCode;
    }
}

function auth_log_event(string $event, array $context = []): void {
    $parts = ['auth_event=' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', $event)];
    foreach ($context as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string)$value;
        } elseif (is_string($value)) {
            $value = substr($value, 0, 120);
        } else {
            continue;
        }
        $safeKey = preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string)$key);
        $safeValue = preg_replace('/[^A-Za-z0-9_.:@-]/', '_', (string)$value);
        $parts[] = $safeKey . '=' . $safeValue;
    }
    error_log(implode(' ', $parts));
}

function auth_email_hash(string $email): string {
    $email = strtolower(trim($email));
    return $email === '' ? '' : substr(hash('sha256', $email), 0, 16);
}

function auth_email_domain(string $email): string {
    $email = strtolower(trim($email));
    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return '';
    }
    return substr($email, $atPos + 1);
}

function auth_sanitized_exception_message(Throwable $e): string {
    $message = $e->getMessage();
    $message = preg_replace('/\\b(code|access_token|refresh_token|id_token|client_secret|mobile_auth_code|cookie|phpsessid)=([^\\s&]+)/i', '$1=[redacted]', $message) ?? $message;
    $message = preg_replace('/[A-Za-z0-9_-]{80,}/', '[redacted]', $message) ?? $message;
    $message = preg_replace('/\\s+/', ' ', $message) ?? $message;
    return substr(trim($message), 0, 180);
}

function auth_log_exception_event(string $event, Throwable $e, array $context = []): void {
    $context['error_class'] = get_class($e);
    if ($e instanceof AuthRouteException) {
        $context['http_status'] = $e->status();
        $context['client_error'] = $e->clientErrorCode();
    }
    $context['message'] = auth_sanitized_exception_message($e);
    auth_log_event($event, $context);
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
    auth_log_event('oauth_callback_started', ['platform' => $platform]);

    try {
        if (isset($_GET['error'])) {
            throw new AuthRouteException('Google sign-in was cancelled or denied', 401);
        }

        $state = validate_google_oauth_state($stateRaw);
        $platform = $state['platform'];
        auth_log_event('state_validated', [
            'platform' => $platform,
            'state_age_sec' => max(0, time() - (int)$state['iat']),
        ]);

        $authCode = trim((string)($_GET['code'] ?? ''));
        if ($authCode === '') {
            throw new AuthRouteException('Google authorization code missing', 400);
        }

        $client = google_oauth_client_or_fail();
        $token = $client->fetchAccessTokenWithAuthCode($authCode);
        if (!is_array($token) || isset($token['error'])) {
            throw new AuthRouteException('Google authorization failed', 401);
        }
        auth_log_event('google_token_exchange_ok', ['platform' => $platform]);

        $idToken = (string)($token['id_token'] ?? '');
        if ($idToken === '') {
            throw new AuthRouteException('Google identity token missing', 401);
        }

        $tokenInfo = verify_google_id_token_or_fail($idToken);
        auth_log_event('google_profile_received', [
            'platform' => $platform,
            'email_hash' => auth_email_hash((string)($tokenInfo['email'] ?? '')),
            'email_domain' => auth_email_domain((string)($tokenInfo['email'] ?? '')),
            'email_verified' => google_profile_email_verified($tokenInfo) ? '1' : '0',
        ]);
        auth_log_event('local_user_resolve_started', [
            'platform' => $platform,
            'email_hash' => auth_email_hash((string)($tokenInfo['email'] ?? '')),
            'email_domain' => auth_email_domain((string)($tokenInfo['email'] ?? '')),
        ]);
        $user = find_or_create_google_user($tokenInfo);
        $userId = local_user_id_from_row($user);
        auth_log_event('local_user_resolved', [
            'platform' => $platform,
            'user_id' => $userId,
            'email_hash' => auth_email_hash((string)($user['email'] ?? '')),
        ]);

        if ($platform === 'mobile') {
            auth_log_event('mobile_auth_code_create_started', ['user_id' => $userId]);
            $mobileCode = create_mobile_auth_code($userId, $user);
            auth_log_event('mobile_redirect_sent', ['user_id' => $userId]);
            redirect_to(build_redirect_url(mobile_auth_callback_url(), ['code' => $mobileCode]));
            return;
        }

        establish_login_session($user);
        redirect_to('/dashboard.html');
    } catch (AuthRouteException $e) {
        auth_log_exception_event('oauth_callback_failed', $e, ['platform' => $platform]);
        error_log('Google OAuth callback failed: ' . auth_sanitized_exception_message($e));
        if ($platform === 'mobile') {
            redirect_to_mobile_auth_failure($e->clientErrorCode());
            return;
        }
        redirect_to('/login.html?error=google_auth_failed');
    } catch (PDOException $e) {
        auth_log_exception_event('oauth_callback_database_error', $e, ['platform' => $platform]);
        error_log('Google OAuth callback database error: ' . auth_sanitized_exception_message($e) . "\n" . $e->getTraceAsString());
        if ($platform === 'mobile') {
            redirect_to_mobile_auth_failure();
            return;
        }
        redirect_to('/login.html?error=google_auth_failed');
    } catch (Throwable $e) {
        auth_log_exception_event('oauth_callback_unexpected_error', $e, ['platform' => $platform]);
        error_log('Google OAuth callback failed unexpectedly: ' . auth_sanitized_exception_message($e) . "\n" . $e->getTraceAsString());
        if ($platform === 'mobile') {
            redirect_to_mobile_auth_failure();
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
        auth_log_event('mobile_exchange_success', ['user_id' => local_user_id_from_row($user)]);
        complete_login($user);
    } catch (AuthRouteException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
    } catch (PDOException $e) {
        error_log('Mobile auth exchange database error: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
        respond(['ok' => false, 'error' => 'Mobile sign-in failed'], 500);
    } catch (Throwable $e) {
        error_log('Mobile auth exchange failed unexpectedly: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
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
    if (!google_profile_email_verified($payload)) {
        throw new AuthRouteException('Google account email is not verified', 403);
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

function find_or_create_google_user(array $tokenInfo): array {
    $googleId = trim((string)($tokenInfo['sub'] ?? ''));
    $email = strtolower(trim((string)($tokenInfo['email'] ?? '')));
    if ($googleId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new AuthRouteException('Invalid Google account', 401);
    }
    if (!google_profile_email_verified($tokenInfo)) {
        throw new AuthRouteException('Google account email is not verified', 403);
    }

    $allowedDomains = allowed_google_domains();
    if ($allowedDomains && !email_domain_allowed($email, $allowedDomains)) {
        throw new AuthRouteException('Google account not in allowed domain', 403, 'domain_not_allowed');
    }

    $pdo = db();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $user = fetch_google_user_by_google_id($pdo, $googleId, true);
        if ($user) {
            $user = assert_active_local_user($user);
            if ($startedTransaction) {
                $pdo->commit();
            }
            return $user;
        }

        $user = fetch_google_user_by_email($pdo, $email, true);
        if ($user) {
            $user = assert_google_user_resolved($user);
            $linkedGoogleId = trim((string)($user['google_id'] ?? ''));
            if ($linkedGoogleId !== '' && !hash_equals($linkedGoogleId, $googleId)) {
                throw new AuthRouteException('Google account is already linked to another user', 409);
            }

            if ($linkedGoogleId === '') {
                $link = $pdo->prepare(
                    'UPDATE users
                     SET google_id = ?, email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
                     WHERE id = ?'
                );
                $link->execute([$googleId, local_user_id_from_row($user)]);
                $user = fetch_google_user_by_email($pdo, $email, true);
                $user = assert_google_user_resolved($user);
            }

            if ($startedTransaction) {
                $pdo->commit();
            }
            return $user;
        }

        if (!google_auto_provision_enabled($allowedDomains)) {
            throw new AuthRouteException('Google account not found', 404);
        }

        $fullName = google_profile_full_name($tokenInfo, $email);
        $insert = $pdo->prepare(
            'INSERT INTO users (email, password_hash, full_name, google_id, global_role, status, email_verified_at)
             VALUES (?, NULL, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        try {
            $insert->execute([$email, $fullName, $googleId, 'volunteer', 'active']);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $user = fetch_google_user_by_google_id($pdo, $googleId, true)
                ?: fetch_google_user_by_email($pdo, $email, true);
            if (!$user) {
                throw $e;
            }
            $user = assert_active_local_user($user);
            if ($startedTransaction) {
                $pdo->commit();
            }
            return $user;
        }

        $userId = (int)$pdo->lastInsertId();
        $user = fetch_google_user_by_id($pdo, $userId, true);
        $user = assert_google_user_resolved($user);
        if ($startedTransaction) {
            $pdo->commit();
        }
        return $user;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fetch_google_user_by_google_id(PDO $pdo, string $googleId, bool $forUpdate = false): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 2' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$googleId]);
    $users = $stmt->fetchAll();
    if (count($users) > 1) {
        throw new AuthRouteException('Google account matches multiple portal users', 500);
    }
    return $users[0] ?? null;
}

function fetch_google_user_by_email(PDO $pdo, string $email, bool $forUpdate = false): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function fetch_google_user_by_id(PDO $pdo, int $userId, bool $forUpdate = false): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function google_profile_email_verified(array $tokenInfo): bool {
    if (!array_key_exists('email_verified', $tokenInfo)) {
        return false;
    }
    $verified = $tokenInfo['email_verified'];
    if (is_bool($verified)) {
        return $verified;
    }
    if (is_string($verified)) {
        return in_array(strtolower($verified), ['1', 'true', 'yes'], true);
    }
    return (bool)$verified;
}

function google_profile_full_name(array $tokenInfo, string $email): string {
    $name = trim(strip_tags((string)($tokenInfo['name'] ?? '')));
    if ($name === '') {
        $name = trim(
            strip_tags((string)($tokenInfo['given_name'] ?? '')) . ' ' .
            strip_tags((string)($tokenInfo['family_name'] ?? ''))
        );
    }
    $name = preg_replace('/\s+/', ' ', $name ?? '') ?? '';
    if ($name === '') {
        $localPart = (string)strstr($email, '@', true);
        $name = $localPart !== '' ? $localPart : 'Google User';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 190);
    }
    return substr($name, 0, 190);
}

function google_auto_provision_enabled(array $allowedDomains): bool {
    $raw = env_value('GOOGLE_AUTO_PROVISION', null);
    $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enabled !== true) {
        return false;
    }
    if (!$allowedDomains) {
        error_log('Google auto-provisioning is enabled but ignored because GOOGLE_ALLOWED_DOMAIN is not configured');
        return false;
    }
    return true;
}

function local_user_id_from_row(array $user): int {
    $id = $user['id'] ?? null;
    if (is_int($id)) {
        $userId = $id;
    } elseif (is_string($id) && ctype_digit($id)) {
        $userId = (int)$id;
    } else {
        $userId = 0;
    }
    if ($userId <= 0) {
        throw new AuthRouteException('Invalid user id in database row', 500);
    }
    return $userId;
}

function assert_google_user_resolved(?array $user): array {
    if (!$user) {
        throw new AuthRouteException('Google account not found', 404);
    }
    return assert_active_local_user($user);
}

function assert_active_local_user(array $user): array {
    local_user_id_from_row($user);
    if (($user['status'] ?? 'active') !== 'active') {
        throw new AuthRouteException('Account inactive', 403);
    }
    return $user;
}

function create_mobile_auth_code(int $userId, ?array $user = null): string {
    if ($user === null) {
        $user = fetch_google_user_by_id(db(), $userId);
        if (!$user) {
            auth_log_event('mobile_code_user_missing', ['user_id' => $userId]);
            throw new AuthRouteException('Google account not found', 404);
        }
    }
    $user = assert_active_local_user($user);
    $resolvedUserId = local_user_id_from_row($user);
    if ($resolvedUserId !== $userId) {
        throw new AuthRouteException('Invalid user id in database row', 500);
    }

    $code = base64url_encode(random_bytes(32));
    $codeHash = hash('sha256', $code);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 300);

    try {
        $stmt = db()->prepare(
            'INSERT INTO mobile_auth_codes (user_id, code_hash, expires_at)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $codeHash, $expiresAt]);
    } catch (PDOException $e) {
        auth_log_exception_event('mobile_auth_code_create_failed', $e, ['user_id' => $userId]);
        if ($e->getCode() === '42S02') { // Table not found
            throw new AuthRouteException('Mobile auth system is not fully initialized (missing table). Please run migrations.', 500);
        }
        if ($e->getCode() === '23000') {
            error_log('Mobile auth code insert failed for user_id=' . $userId . ': ' . auth_sanitized_exception_message($e));
            throw new AuthRouteException('Mobile sign-in could not be completed', 500);
        }
        throw $e;
    }

    auth_log_event('mobile_auth_code_created', ['user_id' => $userId, 'expires_at' => $expiresAt]);
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

function redirect_to_mobile_auth_failure(string $error = 'callback_failed'): void {
    $allowedErrors = ['callback_failed', 'domain_not_allowed'];
    if (!in_array($error, $allowedErrors, true)) {
        $error = 'callback_failed';
    }
    redirect_to(build_redirect_url(mobile_auth_callback_url(), [
        'error' => $error,
        'message' => 'Google sign-in could not be completed'
    ]));
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
    $raw = trim((string)env_value('GOOGLE_ALLOWED_DOMAIN', ''));
    $pluralRaw = trim((string)env_value('GOOGLE_ALLOWED_DOMAINS', ''));
    if ($pluralRaw !== '') {
        $raw = $raw === '' ? $pluralRaw : $raw . ',' . $pluralRaw;
    }
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
    $emailDomain = strtolower(substr($email, $atPos + 1));
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
    $userId = local_user_id_from_row($user);
    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([$userId]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function complete_login(array $user): void {
    establish_login_session($user);
    respond(['ok' => true, 'data' => ['user' => sanitize_user($user)]]);
}
