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
            auth_enforce_subject_rate_limit('login_email', (string)($data['email'] ?? ''), 5, 900);
            $user = authenticate_password_credentials($data);
            complete_login($user);
        } catch (Throwable $e) {
            error_log('Password login failed unexpectedly: ' . auth_sanitized_exception_message($e));
            respond(['ok' => false, 'error' => 'Internal server error'], 500);
        }
    }

    if ($action === 'logout' && $method === 'POST') {
        require_csrf();
        destroy_login_session();
        respond(['ok' => true, 'data' => ['message' => 'Logged out']]);
    }

    if ($action === 'me' && $method === 'GET') {
        $user = current_user();
        $entities = [];
        if ($user) {
            $entityIds = rbac_entity_ids_for_permission($user, 'entity.view');
            if ($entityIds) {
                $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
                $stmt = db()->prepare("SELECT * FROM entities WHERE id IN ({$placeholders}) ORDER BY name");
                $stmt->execute($entityIds);
                $entities = $stmt->fetchAll();
            } elseif (in_array($user['global_role'], ['admin', 'board', 'student_affairs'], true)) {
                $entities = db()->query('SELECT * FROM entities ORDER BY name')->fetchAll();
            } else {
                $stmt = db()->prepare('SELECT e.* FROM entities e JOIN entity_memberships em ON e.id = em.entity_id WHERE em.user_id = ? ORDER BY e.name');
                $stmt->execute([$user['id']]);
                $entities = $stmt->fetchAll();
            }
        }
        respond([
            'ok' => true,
            'data' => [
                'user' => $user ? sanitize_user($user) : null,
                'entities' => $entities,
                'permissions' => $user ? rbac_permissions_for_user($user) : [],
                'roles' => $user ? rbac_roles_for_user($user) : [],
                'navigation' => $user ? rbac_visible_nav($user) : [],
            ]
        ]);
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

    if ($action === 'forgot-password' && $method === 'POST') {
        handle_forgot_password();
    }

    if ($action === 'reset-password' && ($segments[2] ?? '') === 'validate' && $method === 'POST') {
        handle_password_token_validate();
    }

    if ($action === 'reset-password' && $method === 'POST') {
        handle_password_token_reset();
    }

    if ($action === 'password' && ($segments[2] ?? '') === 'setup' && $method === 'POST') {
        handle_session_password_setup();
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

    if ($action === 'mobile' && ($segments[2] ?? '') === 'login' && $method === 'POST') {
        handle_mobile_password_login();
    }

    if ($action === 'mobile' && ($segments[2] ?? '') === 'logout' && $method === 'POST') {
        handle_mobile_logout();
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
            error_log('Google Identity Services database error: ' . auth_sanitized_exception_message($e));
            respond(['ok' => false, 'error' => 'Google sign-in failed'], 500);
        } catch (Throwable $e) {
            error_log('Google Identity Services failed unexpectedly: ' . auth_sanitized_exception_message($e));
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

function auth_enforce_subject_rate_limit(string $key, string $subject, int $limit, int $windowSeconds): void {
    $subject = strtolower(trim($subject));
    if ($subject === '') {
        return;
    }
    if (!rate_limit_subject($key, $subject, $limit, $windowSeconds)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
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

function authenticate_password_credentials(array $data): array {
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
    return $user;
}

function auth_generic_forgot_password_response(): void {
    respond([
        'ok' => true,
        'data' => [
            'message' => 'If an account exists, a reset link has been sent.',
        ],
    ]);
}

function handle_forgot_password(): void {
    if (!rate_limit('forgot_password', 6, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    require_csrf();
    $data = read_json();
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (!rate_limit_subject('forgot_password_email', $email, 4, 900)) {
            respond(['ok' => false, 'error' => 'Too many attempts'], 429);
        }
        try {
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && ($user['status'] ?? '') === 'active') {
                file_put_contents(
                    dirname(__DIR__, 2) . '/logs/reset-debug.log',
                    '[' . date('c') . "] forgot-password: real user found id=" . local_user_id_from_row($user) . " email=" . ($user['email'] ?? '') . "\n",
                    FILE_APPEND
                );
                
                $token = auth_create_password_token(local_user_id_from_row($user), 'password_reset');
                
                file_put_contents(
                    dirname(__DIR__, 2) . '/logs/reset-debug.log',
                    '[' . date('c') . "] forgot-password: token created\n",
                    FILE_APPEND
                );
                
                $mailSent = auth_send_password_token_email($user, 'password_reset', $token['token']);
                
                file_put_contents(
                    dirname(__DIR__, 2) . '/logs/reset-debug.log',
                    '[' . date('c') . '] forgot-password: mail result=' . ($mailSent ? 'true' : 'false') . "\n",
                    FILE_APPEND
                );

                error_log('forgot-password: reset email send result = ' . ($mailSent ? 'true' : 'false') . ' for user_id=' . local_user_id_from_row($user));
                auth_log_event('password_reset_requested', [
                    'user_id' => local_user_id_from_row($user),
                    'email_hash' => auth_email_hash($email),
                ]);
            }
        } catch (PDOException $e) {
            error_log('Forgot password database error: ' . auth_sanitized_exception_message($e));
        } catch (Throwable $e) {
            error_log('Forgot password failed unexpectedly: ' . auth_sanitized_exception_message($e));
        }
    }
    auth_generic_forgot_password_response();
}

function auth_read_password_token_payload(): array {
    $data = read_json();
    $token = trim((string)($data['token'] ?? ''));
    return [$data, $token];
}

function auth_respond_invalid_password_token(): void {
    respond(['ok' => false, 'error' => 'Invalid or expired password link'], 400);
}

function handle_password_token_validate(): void {
    if (!rate_limit('password_token_validate', 30, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    require_csrf();
    [$data, $token] = auth_read_password_token_payload();
    if ($token === '' || !auth_password_token_format_is_valid($token)) {
        auth_respond_invalid_password_token();
    }
    if (!rate_limit_subject('password_token_validate_token', $token, 10, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    try {
        $row = auth_fetch_password_token_row(db(), $token);
        if (!auth_password_token_row_is_usable($row)) {
            auth_respond_invalid_password_token();
        }
        respond([
            'ok' => true,
            'data' => [
                'valid' => true,
                'type' => $row['token_type'],
                'email' => auth_mask_email((string)$row['email']),
                'requirements' => password_policy_rules(),
            ],
        ]);
    } catch (PDOException $e) {
        error_log('Password token validation database error: ' . auth_sanitized_exception_message($e));
        respond(['ok' => false, 'error' => 'Unable to validate password link'], 500);
    }
}

function handle_password_token_reset(): void {
    if (!rate_limit('password_reset_submit', 10, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    require_csrf();
    [$data, $token] = auth_read_password_token_payload();
    $password = (string)($data['password'] ?? '');
    $confirmation = (string)($data['password_confirmation'] ?? ($data['confirm_password'] ?? ''));
    if ($token === '' || !auth_password_token_format_is_valid($token)) {
        auth_respond_invalid_password_token();
    }
    if (!rate_limit_subject('password_reset_submit_token', $token, 5, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    try {
        $preflightRow = auth_fetch_password_token_row(db(), $token);
    } catch (PDOException $e) {
        error_log('Password reset preflight database error: ' . auth_sanitized_exception_message($e));
        respond(['ok' => false, 'error' => 'Unable to update password'], 500);
    }
    if (!auth_password_token_row_is_usable($preflightRow)) {
        auth_respond_invalid_password_token();
    }
    require_strong_password($password, $confirmation, (string)$preflightRow['email'], (string)$preflightRow['full_name']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = auth_fetch_password_token_row($pdo, $token, true);
        if (!auth_password_token_row_is_usable($row)) {
            $pdo->commit();
            auth_respond_invalid_password_token();
        }
        $userId = (int)$row['user_id'];
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare(
            'UPDATE users
             SET password_hash = ?,
                 force_password_reset = 0,
                 password_setup_required = 0,
                 password_changed_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $update->execute([$hash, $userId]);
        $markUsed = $pdo->prepare('UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL');
        $markUsed->execute([(int)$row['id']]);
        if ($markUsed->rowCount() !== 1) {
            $pdo->commit();
            auth_respond_invalid_password_token();
        }
        auth_revoke_user_sessions($userId, false, $pdo);
        $pdo->commit();
        log_activity(null, 'user', $userId, 'password_changed', 'Password changed through secure reset/setup link', ['token_type' => $row['token_type']]);
        auth_log_event('password_reset_completed', ['user_id' => $userId, 'token_type' => $row['token_type']]);
        respond(['ok' => true, 'data' => ['message' => 'Password updated. You can now sign in.']]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            error_log('Password reset database error: ' . auth_sanitized_exception_message($e));
            respond(['ok' => false, 'error' => 'Unable to update password'], 500);
        }
        throw $e;
    }
}

function handle_session_password_setup(): void {
    if (!rate_limit('password_session_setup', 10, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }
    require_csrf();
    $user = current_user();
    if (!$user) {
        respond(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    if (!auth_user_requires_password_setup($user)) {
        respond(['ok' => false, 'error' => 'Password setup is not required for this account'], 400);
    }
    if (!rate_limit_subject('password_session_setup_user', (string)local_user_id_from_row($user), 5, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }

    $data = read_json();
    $password = (string)($data['password'] ?? '');
    $confirmation = (string)($data['password_confirmation'] ?? ($data['confirm_password'] ?? ''));
    require_strong_password($password, $confirmation, (string)$user['email'], (string)$user['full_name']);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $userId = local_user_id_from_row($user);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE users
             SET password_hash = ?,
                 force_password_reset = 0,
                 password_setup_required = 0,
                 password_changed_at = UTC_TIMESTAMP()
             WHERE id = ?'
        );
        $stmt->execute([$hash, $userId]);
        auth_revoke_user_sessions($userId, true, $pdo);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            error_log('Session password setup database error: ' . auth_sanitized_exception_message($e));
            respond(['ok' => false, 'error' => 'Unable to update password'], 500);
        }
        throw $e;
    }
    log_activity($userId, 'user', $userId, 'password_changed', 'Password changed during forced setup');
    auth_log_event('password_setup_completed', ['user_id' => $userId]);
    $fresh = fetch_google_user_by_id(db(), $userId) ?: $user;
    respond(['ok' => true, 'data' => ['message' => 'Password updated.', 'user' => sanitize_user($fresh)]]);
}

function handle_google_oauth_start(): void {
    if (!rate_limit('google_oauth_start', 20, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }

    try {
        $platform = (($_GET['platform'] ?? '') === 'mobile') ? 'mobile' : 'web';
        $next = auth_safe_next_path($_GET['next'] ?? '');
        $client = google_oauth_client_or_fail();
        $client->addScope('openid');
        $client->addScope('email');
        $client->addScope('profile');
        $client->setAccessType('online');
        $client->setIncludeGrantedScopes(true);
        $client->setState(create_google_oauth_state($platform, $next));

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
        redirect_to(auth_user_requires_password_setup($user) ? '/reset_password.html?mode=session' : ($state['next'] ?? '/dashboard.html'));
    } catch (AuthRouteException $e) {
        auth_log_exception_event('oauth_callback_failed', $e, ['platform' => $platform]);
        error_log('Google OAuth callback failed: ' . auth_sanitized_exception_message($e));
        if ($platform === 'mobile') {
            redirect_to_mobile_auth_failure($e->clientErrorCode());
            return;
        }
        redirect_to('/login.html?error=' . ($e->clientErrorCode() === 'domain_not_allowed' ? 'google_domain_not_allowed' : 'google_auth_failed'));
    } catch (PDOException $e) {
        auth_log_exception_event('oauth_callback_database_error', $e, ['platform' => $platform]);
        error_log('Google OAuth callback database error: ' . auth_sanitized_exception_message($e));
        if ($platform === 'mobile') {
            redirect_to_mobile_auth_failure();
            return;
        }
        redirect_to('/login.html?error=google_auth_failed');
    } catch (Throwable $e) {
        auth_log_exception_event('oauth_callback_unexpected_error', $e, ['platform' => $platform]);
        error_log('Google OAuth callback failed unexpectedly: ' . auth_sanitized_exception_message($e));
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
        $platform = mobile_platform_from_request($data);
        $session = create_mobile_session_token(local_user_id_from_row($user), $platform);
        auth_log_event('mobile_exchange_success', ['user_id' => local_user_id_from_row($user), 'platform' => $platform]);
        establish_login_session($user);
        respond([
            'ok' => true,
            'data' => [
                'user' => sanitize_user($user),
                'token' => $session['token'],
                'expiresAt' => mobile_session_expires_at_for_client($session['expires_at']),
                'requires_password_setup' => auth_user_requires_password_setup($user),
                'redirect' => auth_user_requires_password_setup($user) ? '/reset_password.html?mode=session' : null,
            ],
        ]);
    } catch (AuthRouteException $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], $e->status());
    } catch (PDOException $e) {
        error_log('Mobile auth exchange database error: ' . auth_sanitized_exception_message($e));
        respond(['ok' => false, 'error' => 'Mobile sign-in failed'], 500);
    } catch (Throwable $e) {
        error_log('Mobile auth exchange failed unexpectedly: ' . auth_sanitized_exception_message($e));
        respond(['ok' => false, 'error' => 'Mobile sign-in failed'], 500);
    }
}

function handle_mobile_password_login(): void {
    if (!rate_limit('login', 5, 900)) {
        respond(['ok' => false, 'error' => 'Too many attempts'], 429);
    }

    $data = read_json();
    try {
        auth_enforce_subject_rate_limit('login_email', (string)($data['email'] ?? ''), 5, 900);
        $user = authenticate_password_credentials($data);
        $platform = mobile_platform_from_request($data);
        $session = create_mobile_session_token(local_user_id_from_row($user), $platform);
        auth_log_event('mobile_password_login_success', [
            'user_id' => local_user_id_from_row($user),
            'platform' => $platform,
        ]);
        respond([
            'ok' => true,
            'data' => [
                'user' => sanitize_user($user),
                'token' => $session['token'],
                'expiresAt' => mobile_session_expires_at_for_client($session['expires_at']),
                'requires_password_setup' => auth_user_requires_password_setup($user),
                'redirect' => auth_user_requires_password_setup($user) ? '/reset_password.html?mode=session' : null,
            ],
        ]);
    } catch (PDOException $e) {
        error_log('Mobile password login database error: ' . auth_sanitized_exception_message($e));
        respond(['ok' => false, 'error' => 'Mobile sign-in failed'], 500);
    } catch (Throwable $e) {
        error_log('Mobile password login failed unexpectedly: ' . auth_sanitized_exception_message($e));
        respond(['ok' => false, 'error' => 'Mobile sign-in failed'], 500);
    }
}

function handle_mobile_logout(): void {
    if (!mobile_bearer_token_from_request()) {
        respond(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    try {
        $revoked = revoke_mobile_bearer_token();
        if (!$revoked) {
            respond(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        destroy_login_session();
        respond(['ok' => true, 'data' => ['message' => 'Logged out']]);
    } catch (PDOException $e) {
        error_log('Mobile logout database error: ' . auth_sanitized_exception_message($e));
        respond(['ok' => false, 'error' => 'Mobile logout failed'], 500);
    }
}

function mobile_platform_from_request(array $data): string {
    $platform = (string)($data['platform'] ?? ($_SERVER['HTTP_X_NCP_PLATFORM'] ?? ''));
    return normalize_mobile_platform($platform);
}

function mobile_session_expires_at_for_client(string $expiresAt): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiresAt)) {
        return str_replace(' ', 'T', $expiresAt) . 'Z';
    }
    return $expiresAt;
}

function sanitize_user(array $user): array {
    unset($user['password_hash']);
    unset($user['session_version']);
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
    return auth_base64url_encode($value);
}

function base64url_decode(string $value): string|false {
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function auth_safe_next_path($raw): string {
    $next = trim((string)$raw);
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_starts_with($next, '/api/')) {
        return '/dashboard.html';
    }
    return $next;
}

function create_google_oauth_state(string $platform, string $next = '/dashboard.html'): string {
    $platform = $platform === 'mobile' ? 'mobile' : 'web';
    $nonce = bin2hex(random_bytes(16));
    $payload = [
        'nonce' => $nonce,
        'platform' => $platform,
        'iat' => time(),
        'next' => auth_safe_next_path($next),
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
        'next' => $payload['next'],
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
    $next = auth_safe_next_path($payload['next'] ?? '/dashboard.html');
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
        'next' => $next,
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
            update_google_profile_fields($pdo, $user, $tokenInfo);
            $user = fetch_google_user_by_id($pdo, local_user_id_from_row($user), true) ?: $user;
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
            update_google_profile_fields($pdo, $user, $tokenInfo);
            $user = fetch_google_user_by_id($pdo, local_user_id_from_row($user), true) ?: $user;

            if ($startedTransaction) {
                $pdo->commit();
            }
            return $user;
        }

        if (!google_auto_provision_enabled($allowedDomains)) {
            throw new AuthRouteException('Google account not found', 404);
        }

        $fullName = google_profile_full_name($tokenInfo, $email);
        $pictureUrl = google_profile_picture_url($tokenInfo);
        $insert = $pdo->prepare(
            auth_users_has_google_picture_column()
                ? 'INSERT INTO users (email, password_hash, full_name, google_id, google_picture_url, global_role, status, email_verified_at)
                   VALUES (?, NULL, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
                : 'INSERT INTO users (email, password_hash, full_name, google_id, global_role, status, email_verified_at)
                   VALUES (?, NULL, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        try {
            $insert->execute(auth_users_has_google_picture_column()
                ? [$email, $fullName, $googleId, $pictureUrl, 'volunteer', 'active']
                : [$email, $fullName, $googleId, 'volunteer', 'active']);
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

function google_profile_picture_url(array $tokenInfo): ?string {
    $picture = trim((string)($tokenInfo['picture'] ?? ''));
    if ($picture === '' || strlen($picture) > 1024 || !filter_var($picture, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string)(parse_url($picture, PHP_URL_SCHEME) ?: ''));
    return in_array($scheme, ['https', 'http'], true) ? $picture : null;
}

function auth_users_has_google_picture_column(): bool {
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }
    try {
        $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'google_picture_url'");
        $hasColumn = (bool)$stmt->fetch();
    } catch (PDOException $e) {
        $hasColumn = false;
    }
    return $hasColumn;
}

function update_google_profile_fields(PDO $pdo, array $user, array $tokenInfo): void {
    if (!auth_users_has_google_picture_column()) {
        return;
    }
    $pictureUrl = google_profile_picture_url($tokenInfo);
    $stmt = $pdo->prepare('UPDATE users SET google_picture_url = ? WHERE id = ?');
    $stmt->execute([$pictureUrl, local_user_id_from_row($user)]);
}

function google_auto_provision_enabled(array $allowedDomains): bool {
    $raw = env_value('GOOGLE_AUTO_PROVISION', null);
    $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enabled !== true) {
        return false;
    }
    if (!$allowedDomains) {
        error_log('Google auto-provisioning is enabled but ignored because neither GOOGLE_ALLOWED_DOMAIN nor GOOGLE_ALLOWED_DOMAINS is configured');
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
        'error' => $error
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
        return ['nixorcollege.edu.pk'];
    }
    $parts = array_map('trim', explode(',', $raw));
    $domains = [];
    foreach ($parts as $domain) {
        if ($domain === '') {
            continue;
        }
        $domain = ltrim(strtolower($domain), '@');
        if ($domain === 'nixorcollege.edu.pk') {
            $domains[] = $domain;
        }
    }
    $domains = array_values(array_unique($domains));
    return $domains ?: ['nixorcollege.edu.pk'];
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

function destroy_login_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
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

function establish_login_session(array $user): void {
    $userId = local_user_id_from_row($user);
    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([$userId]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = (int)($user['session_version'] ?? 0);
}

function complete_login(array $user): void {
    establish_login_session($user);
    $requiresPasswordSetup = auth_user_requires_password_setup($user);
    respond([
        'ok' => true,
        'data' => [
            'user' => sanitize_user($user),
            'requires_password_setup' => $requiresPasswordSetup,
            'redirect' => $requiresPasswordSetup ? '/reset_password.html?mode=session' : null,
        ],
    ]);
}
