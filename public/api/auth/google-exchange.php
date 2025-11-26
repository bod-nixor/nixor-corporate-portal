<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Auth\GoogleVerifier;
use App\Lib\Response;
use App\Lib\Session;
use App\Lib\Env;
use App\Lib\Logger;

Session::start();

$input = json_decode(file_get_contents('php://input') ?: 'null', true);
$credential = $input['credential'] ?? null;

if (!$credential) {
    Response::json(['message' => 'Credential missing'], 422);
    exit;
}

try {
    $profile = (new GoogleVerifier())->verifyIdToken($credential);
    $auth = new AuthService();
    $user = $auth->loginWithGoogle([
        'sub' => $profile['sub'],
        'email' => $profile['email'],
        'name' => $profile['name'] ?? $profile['email'],
    ]);
    $jwt = $auth->issueJwt($user);
    Response::json([
        'user' => $user,
        'csrfToken' => Session::csrfToken(),
        'wsToken' => $jwt,
        'wsUrl' => Env::get('WS_URL'),
    ]);
} catch (\Throwable $exception) {
    Logger::exception($exception, ['endpoint' => 'auth/google-exchange']);
    Response::json(['message' => 'Authentication failed', 'error' => $exception->getMessage()], 401);
}
