<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';

use App\Auth\AuthService;
use App\Lib\Session;
use App\Lib\Response;

Session::requireCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

$auth = new AuthService();
$auth->logout();

Response::noContent();
