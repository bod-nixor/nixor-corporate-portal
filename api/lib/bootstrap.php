<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/security.php';

apply_cors_headers();
set_security_headers();
header('Content-Type: application/json');

if (cors_is_preflight_request()) {
    http_response_code(204);
    exit;
}

$secureCookie = is_https();
$secureOverride = env_value('SESSION_COOKIE_SECURE');
if ($secureOverride !== null) {
    $secureOverrideValue = filter_var($secureOverride, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($secureOverrideValue !== null) {
        $secureCookie = $secureOverrideValue;
    }
}
$cookiePath = env_value('SESSION_COOKIE_PATH', '/');
$cookieDomain = env_value('SESSION_COOKIE_DOMAIN', '');
$sameSite = env_value('SESSION_COOKIE_SAMESITE', 'Lax');
if (cors_origin_requires_cross_site_session($_SERVER['HTTP_ORIGIN'] ?? '')) {
    $sameSite = 'None';
    $secureCookie = true;
}

ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'domain' => $cookieDomain ?: '',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => $sameSite
]);
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/responses.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/drive.php';
require_once __DIR__ . '/websocket.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/validation.php';
