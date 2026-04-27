<?php
function respond(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        header('Content-Type: application/json');
        http_response_code($status);
    }
    $json = json_encode($payload);
    if ($json === false) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to encode response',
            'meta' => ['code' => json_last_error()]
        ]);
    } else {
        echo $json;
    }
    exit;
}

function read_json(): array {
    $input = file_get_contents('php://input');
    if (!$input) {
        return [];
    }
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(['ok' => false, 'error' => 'Invalid JSON', 'meta' => ['message' => json_last_error_msg()]], 400);
    }
    return is_array($data) ? $data : [];
}

function csrf_debug_enabled(): bool {
    $flag = env_value('CSRF_DEBUG', '');
    if ($flag !== '') {
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }
    return env_value('APP_ENV', 'production') !== 'production';
}

function csrf_debug_log(string $reason, bool $submittedTokenPresent): void {
    if (!csrf_debug_enabled()) {
        return;
    }
    $origin = cors_normalize_origin($_SERVER['HTTP_ORIGIN'] ?? '');
    $parts = [
        'csrf_event=validation_failed',
        'reason=' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', $reason),
        'origin=' . ($origin !== '' ? preg_replace('/[^A-Za-z0-9_.:\/-]/', '_', $origin) : 'none'),
        'session_cookie_present=' . (isset($_COOKIE[session_name()]) ? 'yes' : 'no'),
        'session_csrf_present=' . (!empty($_SESSION['csrf_token']) ? 'yes' : 'no'),
        'submitted_token_present=' . ($submittedTokenPresent ? 'yes' : 'no'),
        'authenticated=' . (isset($_SESSION['user_id']) ? 'yes' : 'no'),
    ];
    error_log(implode(' ', $parts));
}

function require_csrf(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    if (csrf_request_allows_bearer_auth() && function_exists('request_has_valid_bearer_token') && request_has_valid_bearer_token()) {
        return;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $reason = !$token ? 'missing_token' : (empty($_SESSION['csrf_token']) ? 'missing_session' : 'mismatch');
        csrf_debug_log($reason, $token !== '');
        respond([
            'ok' => false,
            'error' => 'Invalid CSRF token',
            'meta' => ['reason' => $reason]
        ], 403);
    }
}

function csrf_request_allows_bearer_auth(): bool {
    $path = csrf_normalized_request_path();
    foreach (csrf_bearer_auth_allowed_prefixes() as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }
    }
    return false;
}

function csrf_normalized_request_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $path = rawurldecode($path);
    $path = preg_replace('#/+#', '/', $path) ?: '';
    $path = strtolower($path);
    $path = preg_replace('#^/api(?:/index\.php)?#', '', $path) ?: '';
    $path = '/' . ltrim($path, '/');
    $path = rtrim($path, '/');
    return $path === '' ? '/' : $path;
}

function csrf_bearer_auth_allowed_prefixes(): array {
    return [
        '/auth/mobile',
        '/admin',
        '/announcements',
        '/calendar',
        '/dashboard',
        '/drive',
        '/endeavours',
        '/entities',
        '/files',
        '/members',
        '/notifications',
        '/social',
        '/updates',
        '/users',
    ];
}
