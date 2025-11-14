<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Response;
use App\Lib\Session;
use App\Lib\Env;

$auth = new AuthService();
$user = $auth->currentUser();

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
