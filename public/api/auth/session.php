<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Lib\Env;
use App\Lib\Logger;

$auth = new AuthService();

try {
    $user = $auth->currentUser();
} catch (\Throwable $exception) {
    Logger::exception($exception, ['endpoint' => 'auth/session']);
    Response::json(['user' => null, 'message' => 'Unable to load session'], 500);
    exit;
}

if (!$user) {
    Response::json(['user' => null]);
    exit;
}

Response::json([
    'user' => $user,
    'csrfToken' => Session::csrfToken(),
    'wsToken' => $auth->issueJwt($user),
    'wsUrl' => Env::get('WS_URL'),
]);
