<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/http.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
$secureCookie = is_https();
ini_set('session.cookie_secure', $secureCookie ? 1 : 0);
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
setcookie('csrf_token', $_SESSION['csrf_token'], 0, '/', '', $secureCookie, true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/responses.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/websocket.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/validation.php';

header('Content-Type: application/json');
