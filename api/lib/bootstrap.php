<?php
require_once __DIR__ . '/env.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
if (env_value('APP_ENV', 'local') !== 'local') {
    ini_set('session.cookie_secure', 1);
}
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$secureCookie = ini_get('session.cookie_secure') ? true : env_value('APP_ENV', 'local') !== 'local';
setcookie('csrf_token', $_SESSION['csrf_token'], 0, '/', '', $secureCookie, true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/responses.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/websocket.php';

header('Content-Type: application/json');
