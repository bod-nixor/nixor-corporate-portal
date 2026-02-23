<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/http.php';

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
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/drive.php';
require_once __DIR__ . '/websocket.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/security.php';

set_security_headers();
header('Content-Type: application/json');
